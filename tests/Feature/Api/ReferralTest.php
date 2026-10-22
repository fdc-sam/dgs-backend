<?php

namespace Tests\Feature\Api;

use App\Enums\AppointmentStatus;
use App\Enums\ReferralStatus;
use App\Models\Appointment;
use App\Models\AuditLog;
use App\Models\Referral;
use App\Models\Service;
use App\Models\User;
use App\Services\AccountDeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsSalon;
use Tests\TestCase;

class ReferralTest extends TestCase
{
    use BuildsSalon, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSalon();
    }

    private function refer(array $overrides = []): TestResponse
    {
        return $this->postJson('/api/v1/referrals', array_replace([
            'name' => 'Maria Santos',
            'phone' => '+63 917-555-0101',
            'service_ids' => [$this->service->id],
            'notes' => 'Best reached after 5 PM',
        ], $overrides));
    }

    public function test_a_customer_refers_someone_and_sees_it_in_their_list(): void
    {
        $customer = $this->actingAsCustomer($this->customer(['phone' => '09170000001']));

        $this->refer()->assertCreated()
            ->assertJsonPath('data.name', 'Maria Santos')
            ->assertJsonPath('data.phone', '09175550101') // normalised like customer numbers
            ->assertJsonPath('data.services.0.name', $this->service->name)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.status_label', 'New')
            ->assertJsonPath('data.can_delete', true);

        $this->getJson('/api/v1/referrals')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.notes', 'Best reached after 5 PM');
        $referral = Referral::sole();
        $this->assertSame($customer->id, $referral->referrer_id);
        $this->assertFalse($referral->is_test);
        $this->assertTrue(AuditLog::where('entity_type', 'referral')->where('action', 'referral.created')->exists());

        // Other customers do not see it.
        Sanctum::actingAs($this->customer());
        $this->getJson('/api/v1/referrals')->assertOk()->assertJsonCount(0, 'data');
    }

    public function test_referral_rules(): void
    {
        $this->actingAsCustomer($this->customer(['phone' => '09170000001']));
        $hidden = Service::factory()->create(['status' => 'inactive']);

        $this->refer(['phone' => '0917 000 0001'])->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->refer(['phone' => '12345'])->assertUnprocessable()->assertJsonValidationErrors('phone');
        $this->refer(['service_ids' => []])->assertUnprocessable()->assertJsonValidationErrors('service_ids');
        $this->refer(['service_ids' => [$hidden->id]])->assertUnprocessable()->assertJsonValidationErrors('service_ids.0');
        $this->refer(['name' => ''])->assertUnprocessable()->assertJsonValidationErrors('name');

        // One referral per number, from anyone, until the salon rules it not eligible.
        $this->refer()->assertCreated();
        Sanctum::actingAs($this->customer());
        $this->refer()->assertUnprocessable()->assertJsonValidationErrors(['phone' => 'already been referred']);
        Referral::sole()->update(['status' => ReferralStatus::Declined]);
        $this->refer()->assertCreated();
    }

    public function test_a_customer_can_remove_a_referral_only_while_it_is_new(): void
    {
        $this->actingAsCustomer();
        $first = $this->refer()->json('data.id');
        $second = $this->refer(['phone' => '09175550102'])->json('data.id');
        Referral::find($second)->update(['status' => ReferralStatus::Accepted]);

        $this->getJson('/api/v1/referrals')->assertJsonPath('data.0.can_delete', false); // newest first: the accepted one
        $this->deleteJson("/api/v1/referrals/{$second}")->assertForbidden();
        Sanctum::actingAs($this->customer());
        $this->deleteJson("/api/v1/referrals/{$first}")->assertForbidden();

        Sanctum::actingAs(Referral::find($first)->referrer);
        $this->deleteJson("/api/v1/referrals/{$first}")->assertOk();
        $this->assertNull(Referral::find($first));
    }

    public function test_admin_accepts_works_on_and_completes_the_referral_with_a_gcash_reward(): void
    {
        $referrer = $this->actingAsCustomer($this->customer(['name' => 'Ana Reyes', 'email' => 'ana@example.com', 'phone' => '09170000001']));
        $this->refer();
        $referral = Referral::sole();
        // The referred person signed up and finished a booking.
        $friend = $this->customer(['phone' => '09175550101']);
        Appointment::factory()->at(self::DAY.' 10:00')->create(['customer_id' => $friend->id, 'branch_id' => $this->branch->id,
            'staff_profile_id' => $this->stylist->id, 'status' => AppointmentStatus::Completed]);
        $titles = fn () => $referrer->notifications()->get()->sortBy('created_at')->pluck('data.title')->values()->all();

        $this->actingAs(User::factory()->admin()->create(), 'web');
        $this->get('/dashboard')->assertSee('Referrals');
        $this->get('/referrals')->assertOk()->assertSee('Maria Santos')->assertSee('Ana Reyes')->assertSee('GCash: 09170000001')
            ->assertSee('1 completed visit')->assertSee('Has an app account')->assertSee('Accept');

        $this->post("/referrals/{$referral->id}/status", ['status' => 'accepted'])->assertRedirect();
        $this->assertSame(['Referral accepted'], $titles());

        $this->get('/referrals?status=accepted')->assertSee('In progress');
        $this->post("/referrals/{$referral->id}/status", ['status' => 'in_progress'])->assertRedirect();
        $inProgress = $referrer->notifications()->get()->firstWhere('data.title', 'Referral in progress');
        $this->assertStringContainsString($this->service->name, $inProgress->data['body']);

        // Complete: the dialog shows who to pay and where.
        $this->get('/referrals?status=in_progress')->assertSee('Complete referral')->assertSee('ana@example.com');
        $this->post("/referrals/{$referral->id}/complete", [])->assertSessionHasErrors('amount');
        $this->post("/referrals/{$referral->id}/complete", ['amount' => 150, 'note' => 'Thank you!'])->assertRedirect()->assertSessionHas('success');
        $referral->refresh();
        $this->assertSame(ReferralStatus::Completed, $referral->status);
        $this->assertSame('150.00', $referral->reward_amount);
        $this->assertNull($referral->reward_reference); // optional
        $this->assertNotNull($referral->rewarded_at);

        $done = $referrer->notifications()->get()->firstWhere('data.title', 'Referral successful!');
        $this->assertSame('Your referral of Maria Santos was successful. We sent ₱150.00 to your GCash number 09170000001. Please check your GCash. Thank you!', $done->data['body']);
        $this->assertSame($referral->id, $done->data['data']['referral_id']);
        $this->assertSame('REFERRAL', $done->type);

        // A completed referral is final.
        $this->post("/referrals/{$referral->id}/status", ['status' => 'declined'])->assertSessionHas('error');
        $this->post("/referrals/{$referral->id}/complete", ['amount' => 150])->assertSessionHas('error');
        $this->assertSame(ReferralStatus::Completed, $referral->fresh()->status);

        Sanctum::actingAs($referrer);
        $this->getJson('/api/v1/referrals')->assertJsonPath('data.0.status', 'completed')->assertJsonPath('data.0.status_label', 'Completed')
            ->assertJsonPath('data.0.reward_amount', 150);
    }

    public function test_completing_records_the_gcash_reference_when_given(): void
    {
        $referrer = $this->actingAsCustomer();
        $this->refer();
        $referral = Referral::sole();

        $this->actingAs(User::factory()->admin()->create(), 'web');
        $this->post("/referrals/{$referral->id}/complete", ['amount' => 200, 'reference' => '1009 882 334'])->assertRedirect();

        $this->assertSame('1009 882 334', $referral->fresh()->reward_reference);
        $this->assertStringContainsString('Reference no. 1009 882 334.', $referrer->notifications()->sole()->data['body']);
    }

    public function test_old_referral_statuses_are_renamed(): void
    {
        $this->actingAsCustomer();
        $this->refer();
        $this->refer(['phone' => '09175550102']);
        $this->refer(['phone' => '09175550103']);
        $ids = Referral::orderBy('phone')->pluck('id');
        foreach (['contacted', 'completed', 'rewarded'] as $i => $old) {
            DB::table('referrals')->where('id', $ids[$i])->update(['status' => $old]);
        }

        (require database_path('migrations/2026_10_22_120000_rename_referral_statuses.php'))->up();

        $this->assertSame(['accepted', 'in_progress', 'completed'], DB::table('referrals')->orderBy('phone')->pluck('status')->all());
    }

    public function test_not_eligible_with_a_reason_and_reopening(): void
    {
        $referrer = $this->actingAsCustomer();
        $this->refer();
        $referral = Referral::sole();

        $this->actingAs(User::factory()->admin()->create(), 'web');
        $this->post("/referrals/{$referral->id}/status", ['status' => 'declined', 'note' => 'Already a regular customer.'])->assertRedirect();
        $this->assertStringContainsString('Already a regular customer.', $referrer->notifications()->sole()->data['body']);
        $this->post("/referrals/{$referral->id}/status", ['status' => 'completed'])->assertSessionHasErrors('status'); // only through Complete
        $this->post("/referrals/{$referral->id}/status", ['status' => 'pending'])->assertRedirect();
        $this->assertSame(ReferralStatus::Pending, $referral->fresh()->status);
        $this->assertNull($referral->fresh()->admin_note);
    }

    public function test_staff_cannot_open_referrals(): void
    {
        $this->actingAs($this->stylist->user, 'web');
        $this->get('/referrals')->assertForbidden();
    }

    public function test_test_customers_referrals_stay_on_the_test_side(): void
    {
        $tester = $this->customer();
        $tester->forceFill(['is_test' => true])->save();
        $this->actingAsCustomer($tester);
        $this->refer()->assertCreated();
        $this->assertTrue(Referral::withoutGlobalScopes()->sole()->is_test);

        // The same number can still be referred on the live side.
        Sanctum::actingAs($this->customer());
        $this->refer()->assertCreated();

        $this->actingAs(User::factory()->admin()->create(), 'web');
        $this->get('/referrals')->assertOk()->assertViewHas('referrals', fn ($page) => $page->total() === 1 && ! $page->first()->is_test);

        $tester->setTestAccount(false);
        $this->assertSame(0, Referral::withoutGlobalScopes()->where('is_test', true)->count());
    }

    public function test_deleting_the_account_removes_unpaid_referrals_but_keeps_paid_ones(): void
    {
        $referrer = $this->actingAsCustomer();
        $this->refer();
        $this->refer(['phone' => '09175550102']);
        Referral::where('phone', '09175550102')->update(['status' => ReferralStatus::Completed, 'reward_amount' => 100]);

        app(AccountDeletionService::class)->delete($referrer);

        $this->assertSame(['09175550102'], Referral::pluck('phone')->all());
    }
}
