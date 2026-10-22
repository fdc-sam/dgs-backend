<?php

namespace Tests\Feature\Admin;

use App\Enums\PaymentStatus;
use App\Enums\ReferralStatus;
use App\Models\Appointment;
use App\Models\Referral;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsSalon;
use Tests\TestCase;

class LiveStatusTest extends TestCase
{
    use BuildsSalon, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSalon();
    }

    private function booking(array $attributes = []): Appointment
    {
        return Appointment::factory()->at(self::DAY.' 10:00')->create($attributes + ['branch_id' => $this->branch->id, 'staff_profile_id' => $this->stylist->id]);
    }

    public function test_managers_get_badge_counts_and_markers_that_change_with_the_data(): void
    {
        $admin = User::factory()->admin()->create();
        $admin->notifications()->create(['id' => (string) Str::uuid(), 'type' => 'GENERAL', 'data' => ['title' => 'Hi']]);
        $this->booking(['payment_status' => PaymentStatus::Submitted]);
        Referral::create(['referrer_id' => $this->customer()->id, 'name' => 'Maria', 'phone' => '09175550101']);
        $this->actingAs($admin);

        $first = $this->getJson('/live')->assertOk()->assertHeader('Cache-Control', 'no-store, private')
            ->assertJson(['unread' => 1, 'new_referrals' => 1, 'payments_to_verify' => 1])->json('markers');
        $this->assertSame($first, $this->getJson('/live')->json('markers'), 'nothing changed, same markers');

        $this->travel(1)->minutes();
        $this->booking();
        Referral::sole()->update(['status' => ReferralStatus::Accepted]);
        $second = $this->getJson('/live')->assertJson(['new_referrals' => 0])->json('markers');
        $this->assertNotSame($first['appointments'], $second['appointments']);
        $this->assertNotSame($first['referrals'], $second['referrals']);
    }

    public function test_staff_watch_only_their_own_bookings_and_no_referrals(): void
    {
        $other = $this->makeStylist();
        $this->actingAs($this->stylist->user);
        $before = $this->getJson('/live')->assertOk()->assertJson(['new_referrals' => 0, 'payments_to_verify' => 0, 'markers' => ['referrals' => null]])
            ->json('markers.appointments');

        $this->booking(['staff_profile_id' => $other->id]);
        $this->assertSame($before, $this->getJson('/live')->json('markers.appointments'), "a colleague's booking is not on their list");

        $this->booking();
        $this->assertNotSame($before, $this->getJson('/live')->json('markers.appointments'));
    }

    public function test_pages_poll_and_watch_their_list(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        // @js escapes the quotes: JSON.parse('{\u0022watch\u0022:...}')
        $watch = fn (string $value) => '\u0022watch\u0022:'.$value;
        $this->get('/appointments')->assertOk()->assertSee('adminLive(JSON.parse(', false)->assertSee($watch('\u0022appointments\u0022'), false)
            ->assertSee('data-live-badge="unread"', false);
        $this->get('/referrals')->assertOk()->assertSee($watch('\u0022referrals\u0022'), false)->assertSee('data-live-badge="new_referrals"', false);
        $this->get('/customers')->assertOk()->assertSee($watch('null'), false);
    }

    public function test_only_signed_in_admin_users_can_poll(): void
    {
        $this->get('/live')->assertRedirect('/login');
        $this->actingAs($this->customer());
        $this->get('/live')->assertStatus(302);
    }
}
