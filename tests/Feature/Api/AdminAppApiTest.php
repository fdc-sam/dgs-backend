<?php

namespace Tests\Feature\Api;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\AppointmentNotification;
use App\Services\AppointmentService;
use App\Support\CustomerCode;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsSalon;
use Tests\TestCase;

/** The DGS Admin mobile app API: sign-in, QR look-up, check-in, status changes and counter (walk-in) bookings. */
class AdminAppApiTest extends TestCase
{
    use BuildsSalon, RefreshDatabase;

    private const TODAY = '2026-10-01';

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSalon();
        // Customers booking through the app are subject to the minimum-notice rule; these tests create the customers'
        // bookings freely so they can focus on what the admin app does with them.
        Setting::updateOrCreate(['key' => 'booking_minimum_notice_hours'], ['value' => 0]);
    }

    private function manager(): User
    {
        $user = User::factory()->admin()->create();
        Sanctum::actingAs($user, ['admin-app']);

        return $user;
    }

    private function stylistUser(): User
    {
        $user = $this->stylist->user;
        Sanctum::actingAs($user, ['admin-app']);

        return $user;
    }

    /** A customer's booking for the stylist, today at the given time. */
    private function bookToday(User $customer, string $time = '09:00', ?string $date = null): Appointment
    {
        return app(AppointmentService::class)->book($customer, $this->bookingPayload(['appointment_date' => $date ?? self::TODAY, 'start_time' => $time]));
    }

    // ---------------------------------------------------------------- sign-in

    public function test_admin_signs_in_and_receives_branches_and_permissions(): void
    {
        $admin = User::factory()->admin()->create(['email' => 'boss@example.com', 'password' => 'secret123']);

        $response = $this->postJson('/api/v1/admin/auth/login', ['email' => 'Boss@Example.com', 'password' => 'secret123', 'device_name' => 'Front desk tablet'])
            ->assertOk()
            ->assertJsonPath('data.user.id', $admin->id)
            ->assertJsonPath('data.user.role', 'admin')
            ->assertJsonPath('data.user.permissions.create_walk_in', true)
            ->assertJsonPath('data.user.branches.0.id', $this->branch->id);

        $this->withToken($response->json('data.token'))->getJson('/api/v1/admin/auth/me')->assertOk()->assertJsonPath('data.email', 'boss@example.com');
    }

    public function test_staff_sign_in_with_limited_permissions_and_their_own_branches(): void
    {
        $user = $this->stylist->user;
        $user->update(['password' => 'secret123']);

        $this->postJson('/api/v1/admin/auth/login', ['email' => $user->email, 'password' => 'secret123'])
            ->assertOk()
            ->assertJsonPath('data.user.role', 'staff')
            ->assertJsonPath('data.user.permissions.create_walk_in', false)
            ->assertJsonPath('data.user.permissions.view_customer_details', false)
            ->assertJsonPath('data.user.branches.0.id', $this->branch->id);
    }

    public function test_customers_and_inactive_accounts_cannot_sign_in(): void
    {
        $this->customer(['email' => 'c@example.com', 'password' => 'secret123']);
        $this->postJson('/api/v1/admin/auth/login', ['email' => 'c@example.com', 'password' => 'secret123'])->assertForbidden()->assertJsonPath('code', 'FORBIDDEN');

        User::factory()->admin()->create(['email' => 'off@example.com', 'password' => 'secret123', 'status' => 'suspended']);
        $this->postJson('/api/v1/admin/auth/login', ['email' => 'off@example.com', 'password' => 'secret123'])->assertForbidden();

        User::factory()->admin()->create(['email' => 'a@example.com', 'password' => 'secret123']);
        $this->postJson('/api/v1/admin/auth/login', ['email' => 'a@example.com', 'password' => 'nope'])->assertStatus(422)->assertJsonValidationErrors('email');
    }

    public function test_tokens_do_not_cross_between_the_customer_app_and_the_admin_app(): void
    {
        $this->actingAsCustomer();
        $this->getJson('/api/v1/admin/auth/me')->assertForbidden();
        $this->getJson('/api/v1/admin/appointments')->assertForbidden();

        $this->manager();
        $this->getJson('/api/v1/auth/me')->assertForbidden();

        // A customer-app token (ability "customer") is refused even if the user later became an admin.
        $promoted = User::factory()->admin()->create();
        Sanctum::actingAs($promoted, ['customer']);
        $this->getJson('/api/v1/admin/auth/me')->assertForbidden();
    }

    public function test_the_admin_endpoints_need_a_token(): void
    {
        $this->getJson('/api/v1/admin/appointments')->assertUnauthorized();
        $this->getJson('/api/v1/admin/customers/lookup?code=DGS-AAAA')->assertUnauthorized();
    }

    // ---------------------------------------------------------------- QR look-up

    public function test_scanning_a_code_returns_the_customer_and_todays_appointment(): void
    {
        $customer = $this->customer(['name' => 'Maria Santos']);
        $today = $this->bookToday($customer);
        $this->bookToday($customer, '09:00', '2026-10-05');
        $this->manager();

        $this->getJson('/api/v1/admin/customers/lookup?code='.urlencode($customer->qr_code))
            ->assertOk()
            ->assertJsonPath('data.customer.id', $customer->id)
            ->assertJsonPath('data.customer.name', 'Maria Santos')
            ->assertJsonPath('data.customer.qr_code', $customer->qr_code)
            ->assertJsonPath('data.customer.limited', false)
            ->assertJsonPath('data.today.0.id', $today->id)
            ->assertJsonPath('data.today.0.actions', ['check_in', 'confirm', 'start', 'complete', 'no_show', 'cancel', 'edit_notes'])
            ->assertJsonCount(1, 'data.upcoming')
            ->assertJsonPath('data.can_walk_in', true);
    }

    public function test_a_code_typed_or_scanned_in_any_form_is_accepted(): void
    {
        $customer = $this->customer();
        $this->manager();

        foreach ([strtolower($customer->qr_code), str_replace('-', ' ', $customer->qr_code), str_replace('-', '', $customer->qr_code)] as $form) {
            $this->getJson('/api/v1/admin/customers/lookup?code='.urlencode($form))->assertOk()->assertJsonPath('data.customer.id', $customer->id);
        }
    }

    public function test_an_unknown_or_malformed_code_is_reported_clearly(): void
    {
        $this->manager();

        $this->getJson('/api/v1/admin/customers/lookup?code=DGS-AAAA-AAAA-AAAA-AAAA-AAAA')->assertNotFound()->assertJsonPath('code', 'NOT_FOUND');
        $this->getJson('/api/v1/admin/customers/lookup?code=hello')->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR')->assertJsonValidationErrors('code');
        $this->getJson('/api/v1/admin/customers/lookup')->assertStatus(422);
    }

    public function test_an_admin_account_code_never_resolves_to_a_customer(): void
    {
        $this->manager();
        $this->getJson('/api/v1/admin/customers/lookup?code='.urlencode(CustomerCode::generate()))->assertNotFound();
    }

    public function test_staff_scanning_a_code_only_see_their_own_appointments_and_little_about_the_customer(): void
    {
        $customer = $this->customer(['name' => 'Maria Santos', 'phone' => '09171234567']);
        $mine = $this->bookToday($customer, '09:00');
        $otherStylist = $this->makeStylist();
        $others = app(AppointmentService::class)->book($customer, $this->bookingPayload(['staff_id' => $otherStylist->id, 'start_time' => '14:00', 'appointment_date' => self::TODAY]));
        $this->stylistUser();

        $response = $this->getJson('/api/v1/admin/customers/lookup?code='.urlencode($customer->qr_code))->assertOk();

        $response->assertJsonPath('data.customer.name', 'Maria Santos')
            ->assertJsonPath('data.customer.limited', true)
            ->assertJsonMissingPath('data.customer.phone')
            ->assertJsonMissingPath('data.customer.email')
            ->assertJsonMissingPath('data.customer.qr_code')
            ->assertJsonMissingPath('data.customer.stats')
            ->assertJsonPath('data.can_walk_in', false)
            ->assertJsonCount(1, 'data.today')
            ->assertJsonPath('data.today.0.id', $mine->id);
        $this->assertNotContains($others->id, collect($response->json('data.today'))->pluck('id')->all());
    }

    public function test_customer_details_by_id_and_the_client_list_search(): void
    {
        $customer = $this->customer(['name' => 'Findable Person']);
        $this->customer(['name' => 'Someone Else']);
        $this->manager();

        $this->getJson("/api/v1/admin/customers/{$customer->id}")->assertOk()->assertJsonPath('data.customer.name', 'Findable Person');
        $this->getJson('/api/v1/admin/customers?search='.urlencode('Findable'))->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $customer->id);
        $this->getJson('/api/v1/admin/customers?search='.urlencode($customer->qr_code))->assertOk()->assertJsonPath('data.0.id', $customer->id);

        $this->stylistUser();
        $this->getJson('/api/v1/admin/customers')->assertForbidden();
    }

    // ---------------------------------------------------------------- check-in & status

    public function test_check_in_confirms_a_pending_booking_records_the_arrival_and_tells_the_customer(): void
    {
        Notification::fake();
        Setting::updateOrCreate(['key' => 'auto_confirm_appointments'], ['value' => false]);
        $customer = $this->customer();
        $appointment = $this->bookToday($customer);
        $this->assertSame(AppointmentStatus::Pending, $appointment->status);
        $admin = $this->manager();

        $this->postJson("/api/v1/admin/appointments/{$appointment->id}/check-in")
            ->assertOk()
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.actions', ['start', 'complete', 'no_show', 'cancel', 'edit_notes']);

        $appointment->refresh();
        $this->assertNotNull($appointment->checked_in_at);
        $this->assertSame($admin->id, $appointment->checked_in_by);
        Notification::assertSentTo($customer, AppointmentNotification::class);
        $this->assertDatabaseHas('audit_logs', ['entity_id' => $appointment->id, 'action' => 'appointment.checked_in']);
    }

    public function test_checking_in_twice_is_harmless(): void
    {
        $appointment = $this->bookToday($this->customer());
        $this->manager();

        $this->postJson("/api/v1/admin/appointments/{$appointment->id}/check-in")->assertOk();
        $first = $appointment->fresh()->checked_in_at;

        $this->travel(10)->minutes();
        $this->postJson("/api/v1/admin/appointments/{$appointment->id}/check-in")->assertOk()->assertJsonPath('message', 'Already checked in.');
        $this->assertEquals($first, $appointment->fresh()->checked_in_at);
    }

    public function test_a_finished_or_cancelled_appointment_cannot_be_checked_in(): void
    {
        $appointment = $this->bookToday($this->customer());
        $appointment->update(['status' => AppointmentStatus::Cancelled]);
        $this->manager();

        $this->postJson("/api/v1/admin/appointments/{$appointment->id}/check-in")->assertStatus(409)->assertJsonPath('code', 'CONFLICT');
    }

    public function test_the_service_runs_through_start_and_complete(): void
    {
        $appointment = $this->bookToday($this->customer());
        $this->manager();
        $this->postJson("/api/v1/admin/appointments/{$appointment->id}/check-in")->assertOk();

        $this->postJson("/api/v1/admin/appointments/{$appointment->id}/status", ['status' => 'in_progress'])->assertOk()->assertJsonPath('data.status', 'in_progress')->assertJsonPath('data.actions', ['complete', 'edit_notes']);
        $this->postJson("/api/v1/admin/appointments/{$appointment->id}/status", ['status' => 'completed'])->assertOk()->assertJsonPath('data.status', 'completed')->assertJsonPath('data.actions', ['edit_notes']);
        $this->postJson("/api/v1/admin/appointments/{$appointment->id}/status", ['status' => 'no_show'])->assertStatus(409);
        $this->postJson("/api/v1/admin/appointments/{$appointment->id}/status", ['status' => 'cancelled'])->assertStatus(422);
    }

    public function test_no_show_and_cancel(): void
    {
        $noShow = $this->bookToday($this->customer(), '09:00');
        $cancelled = $this->bookToday($this->customer(), '14:00');
        $this->manager();

        $this->postJson("/api/v1/admin/appointments/{$noShow->id}/status", ['status' => 'no_show'])->assertOk()->assertJsonPath('data.status', 'no_show');
        $this->postJson("/api/v1/admin/appointments/{$cancelled->id}/cancel", ['reason' => 'Customer called'])->assertOk()->assertJsonPath('data.status', 'cancelled')->assertJsonPath('data.cancellation_reason', 'Customer called');
    }

    public function test_staff_can_work_their_own_appointments_but_not_cancel_or_touch_others(): void
    {
        $mine = $this->bookToday($this->customer(), '09:00');
        $other = $this->makeStylist();
        $theirs = app(AppointmentService::class)->book($this->customer(), $this->bookingPayload(['staff_id' => $other->id, 'start_time' => '14:00', 'appointment_date' => self::TODAY]));
        $this->stylistUser();

        $this->postJson("/api/v1/admin/appointments/{$mine->id}/check-in")->assertOk()->assertJsonPath('data.actions', ['start', 'complete', 'no_show', 'edit_notes']);
        $this->postJson("/api/v1/admin/appointments/{$mine->id}/status", ['status' => 'in_progress'])->assertOk();
        $this->postJson("/api/v1/admin/appointments/{$mine->id}/cancel")->assertForbidden();

        $this->getJson("/api/v1/admin/appointments/{$theirs->id}")->assertForbidden();
        $this->postJson("/api/v1/admin/appointments/{$theirs->id}/check-in")->assertForbidden();
        $this->postJson("/api/v1/admin/appointments/{$theirs->id}/status", ['status' => 'completed'])->assertForbidden();
    }

    public function test_internal_notes_can_be_saved(): void
    {
        $appointment = $this->bookToday($this->customer());
        $this->manager();

        $this->putJson("/api/v1/admin/appointments/{$appointment->id}/notes", ['admin_notes' => 'Prefers warm brown'])->assertOk()->assertJsonPath('data.admin_notes', 'Prefers warm brown');
    }

    // ---------------------------------------------------------------- the day's list

    public function test_todays_list_has_a_summary_and_respects_role_and_branch(): void
    {
        $a = $this->bookToday($this->customer(), '09:00');
        $b = $this->bookToday($this->customer(), '14:00');
        $this->bookToday($this->customer(), '09:00', '2026-10-02'); // another day
        $otherStylist = $this->makeStylist();
        $c = app(AppointmentService::class)->book($this->customer(), $this->bookingPayload(['staff_id' => $otherStylist->id, 'start_time' => '10:00', 'appointment_date' => self::TODAY]));
        $b->update(['status' => AppointmentStatus::Completed]);
        $this->manager();
        $this->postJson("/api/v1/admin/appointments/{$a->id}/check-in")->assertOk();

        $response = $this->getJson('/api/v1/admin/appointments')->assertOk()
            ->assertJsonPath('meta.date', self::TODAY)
            ->assertJsonPath('meta.summary.total', 3)
            ->assertJsonPath('meta.summary.completed', 1)
            ->assertJsonPath('meta.summary.arrived', 1)
            ->assertJsonPath('meta.summary.confirmed', 1) // $a, confirmed by the check-in
            ->assertJsonPath('meta.summary.pending', 1); // $c, still waiting for the salon
        $this->assertSame([$a->id, $c->id, $b->id], collect($response->json('data'))->pluck('id')->all()); // by start time

        $this->getJson('/api/v1/admin/appointments?status=completed')->assertJsonCount(1, 'data')->assertJsonPath('meta.summary.total', 3);
        $this->getJson('/api/v1/admin/appointments?date=2026-10-02')->assertJsonCount(1, 'data');
        $this->getJson('/api/v1/admin/appointments?branch_id='.$this->makeBranchId())->assertJsonCount(0, 'data');

        $this->stylistUser();
        $this->getJson('/api/v1/admin/appointments')->assertOk()->assertJsonPath('meta.summary.total', 2)->assertJsonCount(2, 'data');
    }

    private function makeBranchId(): string
    {
        return Branch::factory()->create()->id;
    }

    // ---------------------------------------------------------------- walk-in (counter) bookings

    public function test_a_manager_books_a_walk_in_that_is_confirmed_and_checked_in(): void
    {
        Notification::fake();
        $customer = $this->customer();
        $admin = $this->manager();

        $response = $this->postJson("/api/v1/admin/customers/{$customer->id}/appointments", $this->bookingPayload(['appointment_date' => self::TODAY, 'start_time' => '10:00']))
            ->assertCreated()
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.source', 'walk_in')
            ->assertJsonPath('data.customer.id', $customer->id)
            ->assertJsonPath('data.start_time', '10:00')
            ->assertJsonPath('data.actions', ['start', 'complete', 'no_show', 'cancel', 'edit_notes']);

        $appointment = Appointment::findOrFail($response->json('data.id'));
        $this->assertNotNull($appointment->checked_in_at);
        $this->assertSame($admin->id, $appointment->checked_in_by);
        $this->assertSame($this->stylist->id, $appointment->staff_profile_id);
        Notification::assertSentTo($customer, AppointmentNotification::class);
        $this->assertSame(1, Appointment::count());
    }

    public function test_a_walk_in_ignores_the_customer_notice_rule_but_not_opening_hours_or_the_past(): void
    {
        Setting::updateOrCreate(['key' => 'booking_minimum_notice_hours'], ['value' => 24]);
        $customer = $this->customer();
        $this->manager();

        // The customer app would refuse a booking only an hour away; at the counter it is fine.
        $this->travelTo(CarbonImmutable::parse(self::TODAY.' 10:00'));
        $this->postJson("/api/v1/admin/customers/{$customer->id}/appointments", $this->bookingPayload(['appointment_date' => self::TODAY, 'start_time' => '10:30']))->assertCreated();

        $this->postJson("/api/v1/admin/customers/{$this->customer()->id}/appointments", $this->bookingPayload(['appointment_date' => self::TODAY, 'start_time' => '17:30']))->assertStatus(409); // runs past closing
        $this->postJson("/api/v1/admin/customers/{$this->customer()->id}/appointments", $this->bookingPayload(['appointment_date' => self::TODAY, 'start_time' => '09:00']))->assertStatus(409); // already past
    }

    public function test_a_walk_in_cannot_double_book_a_stylist_or_the_customer(): void
    {
        $this->manager();
        $first = $this->customer();
        $this->postJson("/api/v1/admin/customers/{$first->id}/appointments", $this->bookingPayload(['appointment_date' => self::TODAY, 'start_time' => '10:00']))->assertCreated();

        $this->postJson("/api/v1/admin/customers/{$this->customer()->id}/appointments", $this->bookingPayload(['appointment_date' => self::TODAY, 'start_time' => '10:30']))
            ->assertStatus(409)->assertJsonPath('code', 'SLOT_UNAVAILABLE');

        $second = $this->makeStylist();
        $this->postJson("/api/v1/admin/customers/{$first->id}/appointments", $this->bookingPayload(['staff_id' => $second->id, 'appointment_date' => self::TODAY, 'start_time' => '10:30']))
            ->assertStatus(409)->assertJsonPath('message', 'You already have an appointment at this time.');
    }

    public function test_staff_cannot_book_walk_ins_and_inactive_customers_are_refused(): void
    {
        $customer = $this->customer();
        $this->stylistUser();
        $this->postJson("/api/v1/admin/customers/{$customer->id}/appointments", $this->bookingPayload(['appointment_date' => self::TODAY, 'start_time' => '10:00']))->assertForbidden();

        $this->manager();
        $customer->update(['status' => 'suspended']);
        $this->postJson("/api/v1/admin/customers/{$customer->id}/appointments", $this->bookingPayload(['appointment_date' => self::TODAY, 'start_time' => '10:00']))->assertForbidden();
        $this->postJson('/api/v1/admin/customers/'.User::factory()->admin()->create()->id.'/appointments', $this->bookingPayload())->assertNotFound();
    }

    public function test_walk_in_validation(): void
    {
        $customer = $this->customer();
        $this->manager();

        $this->postJson("/api/v1/admin/customers/{$customer->id}/appointments", [])->assertStatus(422)->assertJsonValidationErrors(['branch_id', 'appointment_date', 'start_time', 'services']);
    }

    // ---------------------------------------------------------------- counter availability

    public function test_counter_availability_offers_times_the_customer_app_would_not(): void
    {
        Setting::updateOrCreate(['key' => 'booking_minimum_notice_hours'], ['value' => 24]);
        $this->travelTo(CarbonImmutable::parse(self::TODAY.' 10:07'));
        $this->manager();
        $query = http_build_query(['branch_id' => $this->branch->id, 'service_id' => $this->service->id, 'date' => self::TODAY]);

        $counter = collect($this->getJson('/api/v1/admin/availability?'.$query)->assertOk()->json('data.slots'))->where('available', true)->pluck('start_time')->all();
        $this->assertContains('10:30', $counter);
        $this->assertContains('14:00', $counter);
        $this->assertNotContains('09:30', $counter); // the past
        $this->assertNotContains('12:00', $counter); // the stylist's break

        $this->assertSame([], $this->availableTimes(['date' => self::TODAY])); // the customer app: nothing, because of the notice rule
    }

    public function test_start_now_is_the_next_five_minute_mark_when_someone_is_free(): void
    {
        $this->travelTo(CarbonImmutable::parse(self::TODAY.' 10:07:30'));
        $this->manager();
        $query = http_build_query(['branch_id' => $this->branch->id, 'service_id' => $this->service->id, 'date' => self::TODAY]);

        $this->getJson('/api/v1/admin/availability?'.$query)->assertOk()->assertJsonPath('data.start_now.start_time', '10:10')->assertJsonPath('data.start_now.end_time', '11:10');

        // Not today, or nobody free right now: no "start now".
        $this->getJson('/api/v1/admin/availability?'.str_replace(self::TODAY, '2026-10-02', $query))->assertJsonPath('data.start_now', null);
        $this->travelTo(CarbonImmutable::parse(self::TODAY.' 12:20')); // the stylist's break
        $this->getJson('/api/v1/admin/availability?'.$query)->assertJsonPath('data.start_now', null);
    }
}
