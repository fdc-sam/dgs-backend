<?php

namespace Tests\Feature\Api;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Favorite;
use App\Models\Review;
use App\Models\User;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSalon;
use Tests\TestCase;

class AccountDeletionTest extends TestCase
{
    use BuildsSalon, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSalon();
    }

    private function appointmentFor(User $customer, string $at, AppointmentStatus $status = AppointmentStatus::Confirmed): Appointment
    {
        return Appointment::factory()->at($at)->status($status)->create([
            'customer_id' => $customer->id,
            'branch_id' => $this->branch->id,
            'staff_profile_id' => $this->stylist->id,
        ]);
    }

    private function customerWithData(): User
    {
        $customer = $this->customer(['email' => 'jane@example.com', 'phone' => '09171234567', 'password' => 'secret123']);
        $customer->customerProfile()->create(['address' => 'Carcar City']);
        Favorite::create(['user_id' => $customer->id, 'favoritable_type' => 'service', 'favoritable_id' => $this->service->id]);
        Review::factory()->create(['appointment_id' => $this->appointmentFor($customer, '2026-09-20 10:00', AppointmentStatus::Completed)->id]);

        return $customer;
    }

    public function test_customer_deletes_account_in_the_app(): void
    {
        $customer = $this->customerWithData();
        $token = $customer->createToken('phone', ['customer'])->plainTextToken;
        // 12 hours away: inside the 24-hour window where customers normally cannot cancel.
        $upcoming = $this->appointmentFor($customer, '2026-10-01 20:00');
        $past = $this->appointmentFor($customer, '2026-09-15 10:00', AppointmentStatus::Completed);

        $this->withToken($token)->deleteJson('/api/v1/auth/account', ['password' => 'secret123'])->assertOk();

        $deleted = User::withTrashed()->find($customer->id);
        $this->assertSoftDeleted($deleted);
        $this->assertSame('Deleted customer', $deleted->name);
        $this->assertStringEndsWith('@deleted.invalid', $deleted->email);
        $this->assertNull($deleted->phone);
        $this->assertNull($deleted->qr_code);
        $this->assertDatabaseMissing('customer_profiles', ['user_id' => $customer->id]);
        $this->assertDatabaseMissing('favorites', ['user_id' => $customer->id]);
        $this->assertDatabaseMissing('reviews', ['customer_id' => $customer->id]);
        $this->assertDatabaseMissing('personal_access_tokens', ['tokenable_id' => $customer->id]);

        $this->assertSame(AppointmentStatus::Cancelled, $upcoming->fresh()->status);
        $this->assertSame(AppointmentStatus::Completed, $past->fresh()->status);
        $this->assertSame('Deleted customer', $past->fresh()->customer->name);

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $this->postJson('/api/v1/auth/login', ['email' => 'jane@example.com', 'password' => 'secret123'])->assertStatus(422);

        // The email and phone are free again.
        $this->postJson('/api/v1/auth/register', [
            'name' => 'Jane Again', 'email' => 'jane@example.com', 'phone' => '09171234567',
            'password' => 'secret123', 'password_confirmation' => 'secret123',
        ])->assertCreated();
    }

    public function test_wrong_password_keeps_the_account(): void
    {
        $customer = $this->customer(['password' => 'secret123']);
        $token = $customer->createToken('phone', ['customer'])->plainTextToken;

        $this->withToken($token)->deleteJson('/api/v1/auth/account', ['password' => 'wrong-one'])
            ->assertStatus(422)->assertJsonValidationErrors('password');
        $this->withToken($token)->deleteJson('/api/v1/auth/account')->assertJsonValidationErrors('password');

        $this->assertNotSoftDeleted($customer);
    }

    public function test_customer_deletes_account_on_the_website(): void
    {
        $customer = $this->customerWithData();

        $this->get('/delete-account')->assertOk()->assertSee('Delete my account');

        $this->post('/delete-account', ['email' => 'jane@example.com', 'password' => 'wrong', 'confirm' => '1'])
            ->assertSessionHasErrors('email');
        $this->post('/delete-account', ['email' => 'jane@example.com', 'password' => 'secret123', 'confirm' => '0'])
            ->assertSessionHasErrors('confirm');
        $this->assertNotSoftDeleted($customer);

        $this->post('/delete-account', ['email' => 'JANE@example.com', 'password' => 'secret123', 'confirm' => '1'])
            ->assertRedirect('/delete-account')->assertSessionHas('deleted');
        $this->assertSoftDeleted(User::withTrashed()->find($customer->id));
    }

    public function test_website_cannot_delete_staff_accounts(): void
    {
        $staff = User::factory()->staff()->create(['email' => 'staff@example.com', 'password' => 'secret123']);

        $this->post('/delete-account', ['email' => 'staff@example.com', 'password' => 'secret123', 'confirm' => '1'])
            ->assertSessionHasErrors('email');
        $this->assertNotSoftDeleted($staff);
    }

    public function test_privacy_policy_page_and_api_fall_back_to_the_default(): void
    {
        $this->get('/privacy')->assertOk()->assertSee('INFORMATION WE COLLECT')->assertSee(url('/delete-account'));
        $this->assertStringContainsString('INFORMATION WE COLLECT', $this->getJson('/api/v1/settings')->json('data.privacy_policy'));

        app(SettingsService::class)->update(['privacy_policy' => 'Our own policy.']);
        $this->app->forgetInstance(SettingsService::class);

        $this->get('/privacy')->assertOk()->assertSee('Our own policy.')->assertDontSee('INFORMATION WE COLLECT');
        $this->getJson('/api/v1/settings')->assertJsonPath('data.privacy_policy', 'Our own policy.');
    }
}
