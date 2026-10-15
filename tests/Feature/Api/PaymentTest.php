<?php

namespace Tests\Feature\Api;

use App\Models\Appointment;
use App\Models\Payment;
use App\Models\Service;
use App\Models\User;
use App\Notifications\AppointmentNotification;
use App\Services\PaymentService;
use App\Services\SettingsService;
use App\Support\Booking\ServiceSelection;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsSalon;
use Tests\TestCase;

/** Manual GCash deposits: hold → receipt → verify, plus expiry, rejection, waiver and refunds. */
class PaymentTest extends TestCase
{
    use BuildsSalon, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSalon();
        Storage::fake('local');
        Storage::fake('public');
        Notification::fake();
        $this->admin = User::factory()->admin()->create();
        $this->enableGcash();
    }

    public function test_bookings_need_no_deposit_while_gcash_is_off(): void
    {
        $this->enableGcash(['payment_gcash_enabled' => false]);
        $this->actingAsCustomer();

        $this->postJson('/api/v1/appointments', $this->bookingPayload())
            ->assertCreated()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.payment.status', 'not_required')
            ->assertJsonPath('data.payment.deposit_amount', 0)
            ->assertJsonPath('data.payment.balance_due', 1000)
            ->assertJsonPath('data.payment.can_pay', false);
        $this->getJson('/api/v1/settings')
            ->assertJsonPath('data.payment_gcash_enabled', false)
            ->assertJsonPath('data.payment_gcash_qr_url', null)
            ->assertJsonPath('data.payment_gcash_number', '09171234567'); // still shown for per-service QR payments
    }

    public function test_booking_holds_the_slot_until_the_deposit_deadline(): void
    {
        $this->setting(['auto_confirm_appointments' => true]); // never confirm before the deposit is verified
        $customer = $this->actingAsCustomer();

        $this->postJson('/api/v1/appointments', $this->bookingPayload())
            ->assertCreated()
            ->assertJsonPath('message', 'Booking received! Pay the deposit to keep your slot.')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.payment.status', 'awaiting_payment')
            ->assertJsonPath('data.payment.method', 'gcash')
            ->assertJsonPath('data.payment.deposit_amount', 200)
            ->assertJsonPath('data.payment.balance_due', 1000)
            ->assertJsonPath('data.payment.due_at', '2026-10-01T08:30:00+08:00')
            ->assertJsonPath('data.payment.can_pay', true);

        Notification::assertSentTo($customer, AppointmentNotification::class,
            fn ($n) => $n->type->value === 'APPOINTMENT_CREATED' && str_contains($n->body, 'Pay the ₱200.00 GCash deposit by 8:30 AM'));
        $this->assertNotContains('09:00', $this->availableTimes(), 'the held slot stays blocked');
    }

    public function test_deposit_rules(): void
    {
        $this->actingAsCustomer();
        $this->setting(['payment_deposit_value' => 5000]);
        $this->postJson('/api/v1/appointments', $this->bookingPayload())->assertJsonPath('data.payment.deposit_amount', 1000); // capped at the total

        $this->actingAsCustomer();
        $this->setting(['payment_deposit_type' => 'percent', 'payment_deposit_value' => 15]);
        $this->service->update(['regular_price' => 999]);
        $this->postJson('/api/v1/appointments', $this->bookingPayload(['start_time' => '10:30']))->assertJsonPath('data.payment.deposit_amount', 150); // 149.85 rounded up

        $this->actingAsCustomer();
        $this->setting(['payment_deposit_type' => 'full']);
        $this->postJson('/api/v1/appointments', $this->bookingPayload(['start_time' => '14:00']))->assertJsonPath('data.payment.deposit_amount', 999);
    }

    public function test_deadline_never_passes_the_appointment_start(): void
    {
        $this->setting(['payment_window_minutes' => 600, 'booking_minimum_notice_hours' => 0]);
        $this->actingAsCustomer();

        $this->postJson('/api/v1/appointments', $this->bookingPayload(['appointment_date' => '2026-10-01', 'start_time' => '10:00']))
            ->assertCreated()
            ->assertJsonPath('data.payment.due_at', '2026-10-01T10:00:00+08:00');
    }

    public function test_customer_uploads_a_receipt_for_staff_to_verify(): void
    {
        $customer = $this->actingAsCustomer();
        $appointment = $this->book();

        $this->pay($appointment, '1234 567 891234')
            ->assertOk()
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.payment.status', 'for_verification')
            ->assertJsonPath('data.payment.reference_number', '1234567891234')
            ->assertJsonPath('data.payment.can_pay', false)
            ->assertJsonPath('data.payment.due_at', null);

        $payment = Payment::sole();
        $this->assertSame($customer->id, $payment->customer_id);
        $this->assertSame('200.00', $payment->amount);
        Storage::disk('local')->assertExists($payment->proof_path);
        $this->assertContains($payment->proof_path, Storage::disk('local')->allFiles(), 'exact-case match for Linux servers');
        Storage::disk('public')->assertMissing($payment->proof_path);
        Notification::assertSentTo($this->admin, AppointmentNotification::class, fn ($n) => $n->type->value === 'PAYMENT_SUBMITTED');
        Notification::assertNotSentTo($this->stylist->user, AppointmentNotification::class, fn ($n) => $n->type->value === 'PAYMENT_SUBMITTED');

        $this->pay($appointment, '9999999999999')->assertStatus(409)->assertJsonPath('message', 'Your receipt is already with the salon for checking.');
    }

    public function test_receipt_validation_and_ownership(): void
    {
        $this->actingAsCustomer();
        $appointment = $this->book();

        $this->postJson("/api/v1/appointments/{$appointment->id}/payment", ['reference_number' => '12#45'])
            ->assertUnprocessable()->assertJsonValidationErrors(['reference_number', 'proof']);

        $this->actingAsCustomer();
        $this->pay($appointment)->assertForbidden();
    }

    public function test_a_reference_number_backs_only_one_booking(): void
    {
        $this->actingAsCustomer();
        $first = $this->book();
        $this->pay($first, '1111222233334')->assertOk();

        $this->actingAsCustomer();
        $second = $this->book('13:00');
        $this->pay($second, '1111-2222-33334')->assertUnprocessable()->assertJsonValidationErrors('reference_number');
    }

    public function test_approving_the_receipt_confirms_the_booking(): void
    {
        $customer = $this->actingAsCustomer();
        $appointment = $this->book();
        $this->pay($appointment)->assertOk();

        $this->actingAs($this->admin)
            ->post("/appointments/{$appointment->id}/payment/approve", ['amount' => '200'])
            ->assertSessionHasNoErrors()->assertSessionHas('success');

        $appointment->refresh();
        $this->assertSame('confirmed', $appointment->status->value);
        $this->assertSame('paid', $appointment->payment_status->value);
        $this->assertSame(800.0, $appointment->balanceDue());
        $this->assertSame('approved', Payment::sole()->status);
        Notification::assertSentTo($customer, AppointmentNotification::class,
            fn ($n) => $n->type->value === 'PAYMENT_CONFIRMED' && str_contains($n->body, 'Your appointment is confirmed'));

        Sanctum::actingAs($customer);
        $this->getJson("/api/v1/appointments/{$appointment->id}")
            ->assertJsonPath('data.payment.status', 'paid')
            ->assertJsonPath('data.payment.amount_paid', 200)
            ->assertJsonPath('data.payment.balance_due', 800);
    }

    public function test_rejected_receipt_gets_a_new_window_and_can_be_resent(): void
    {
        $customer = $this->actingAsCustomer();
        $appointment = $this->book();
        $this->pay($appointment, '5555555555555')->assertOk();

        $this->travel(20)->minutes();
        $this->actingAs($this->admin)
            ->post("/appointments/{$appointment->id}/payment/reject", ['reason' => 'Amount is short'])
            ->assertSessionHas('success');

        Notification::assertSentTo($customer, AppointmentNotification::class,
            fn ($n) => $n->type->value === 'PAYMENT_REJECTED' && str_contains($n->body, 'Amount is short') && str_contains($n->body, '8:50 AM'));

        Sanctum::actingAs($customer);
        $this->getJson("/api/v1/appointments/{$appointment->id}")
            ->assertJsonPath('data.payment.status', 'rejected')
            ->assertJsonPath('data.payment.rejection_reason', 'Amount is short')
            ->assertJsonPath('data.payment.due_at', '2026-10-01T08:50:00+08:00')
            ->assertJsonPath('data.payment.can_pay', true);

        // The same reference may be sent again with a clearer screenshot.
        $this->pay($appointment, '5555555555555')->assertOk()->assertJsonPath('data.payment.status', 'for_verification');
        $this->assertSame(2, Payment::count());
    }

    public function test_unpaid_bookings_are_reminded_once_then_released(): void
    {
        $customer = $this->actingAsCustomer();
        $appointment = $this->book();
        $this->actingAsCustomer();
        $submitted = $this->book('13:00');
        $this->pay($submitted)->assertOk();

        $this->travel(21)->minutes();
        $this->artisan('appointments:release-unpaid')->assertSuccessful();
        $this->artisan('appointments:release-unpaid')->assertSuccessful();
        Notification::assertSentToTimes($customer, AppointmentNotification::class, 2); // created + one reminder
        $this->assertNotNull($appointment->fresh()->payment_reminded_at);

        $this->travel(10)->minutes();
        $this->artisan('appointments:release-unpaid')->expectsOutputToContain('released 1 unpaid booking(s)')->assertSuccessful();

        $appointment->refresh();
        $this->assertSame('cancelled', $appointment->status->value);
        $this->assertSame('expired', $appointment->payment_status->value);
        $this->assertNull($appointment->cancelled_by);
        Notification::assertSentTo($customer, AppointmentNotification::class, fn ($n) => $n->type->value === 'PAYMENT_EXPIRED');
        $this->assertSame('pending', $submitted->fresh()->status->value, 'a receipt under review keeps its slot');
        $this->assertContains('09:00', $this->availableTimes(), 'the released slot can be booked again');

        Sanctum::actingAs($customer);
        $this->pay($appointment)->assertStatus(409);
    }

    public function test_staff_confirming_without_a_deposit_stops_the_clock(): void
    {
        $this->actingAsCustomer();
        $appointment = $this->book();

        $this->actingAs($this->admin)->post("/appointments/{$appointment->id}/status", ['status' => 'confirmed'])->assertSessionHas('success');
        $this->travel(2)->hours();
        $this->artisan('appointments:release-unpaid');

        $appointment->refresh();
        $this->assertSame('confirmed', $appointment->status->value);
        $this->assertSame('awaiting_payment', $appointment->payment_status->value, 'the customer may still pay in the app');
        $this->assertNull($appointment->payment_due_at);
    }

    public function test_customer_can_release_an_unpaid_hold_inside_the_cancellation_window(): void
    {
        $this->setting(['cancellation_hours' => 48]);
        $this->actingAsCustomer();
        $appointment = $this->book();

        $this->postJson("/api/v1/appointments/{$appointment->id}/cancel")
            ->assertOk()
            ->assertJsonPath('data.status', 'cancelled')
            ->assertJsonPath('data.payment.status', 'void');
    }

    public function test_waiving_the_deposit_confirms_the_booking(): void
    {
        $customer = $this->actingAsCustomer();
        $appointment = $this->book();

        $this->actingAs($this->admin)->post("/appointments/{$appointment->id}/payment/waive")->assertSessionHas('success');

        $appointment->refresh();
        $this->assertSame('confirmed', $appointment->status->value);
        $this->assertSame('waived', $appointment->payment_status->value);
        $this->assertSame(1000.0, $appointment->balanceDue());
        Notification::assertSentTo($customer, AppointmentNotification::class, fn ($n) => $n->type->value === 'APPOINTMENT_CONFIRMED');
    }

    public function test_late_payment_on_a_released_booking_is_recorded_then_refunded(): void
    {
        $customer = $this->actingAsCustomer();
        $appointment = $this->book();
        $this->travel(31)->minutes();
        $this->artisan('appointments:release-unpaid');

        $this->actingAs($this->admin)
            ->post("/appointments/{$appointment->id}/payment/approve", ['amount' => '200', 'reference_number' => '7777 888 999000'])
            ->assertSessionHasNoErrors();
        $appointment->refresh();
        $this->assertSame('cancelled', $appointment->status->value, 'a released booking is not restored');
        $this->assertSame('paid', $appointment->payment_status->value);
        $this->assertNull(Payment::sole()->proof_path);

        $this->post("/appointments/{$appointment->id}/payment/refund", ['note' => 'Refund ref 123'])->assertSessionHas('success');
        $appointment->refresh();
        $this->assertSame('refunded', $appointment->payment_status->value);
        $this->assertSame('0.00', $appointment->amount_paid);
        Notification::assertSentTo($customer, AppointmentNotification::class, fn ($n) => $n->type->value === 'PAYMENT_REFUNDED');
    }

    public function test_refund_is_only_for_cancelled_or_no_show_bookings(): void
    {
        $this->actingAsCustomer();
        $appointment = $this->book();
        $this->pay($appointment)->assertOk();
        $this->actingAs($this->admin)->post("/appointments/{$appointment->id}/payment/approve", ['amount' => '200']);

        $this->post("/appointments/{$appointment->id}/payment/refund")->assertSessionHas('error');
        $this->assertSame('paid', $appointment->fresh()->payment_status->value);
    }

    public function test_counter_bookings_never_need_a_deposit(): void
    {
        $customer = $this->customer();
        Sanctum::actingAs($this->admin, ['admin-app']);

        $this->postJson("/api/v1/admin/customers/{$customer->id}/appointments", [
            'branch_id' => $this->branch->id,
            'appointment_date' => '2026-10-01',
            'start_time' => '09:00',
            'services' => [['service_id' => $this->service->id]],
        ])->assertCreated()->assertJsonPath('data.payment.status', 'not_required');
    }

    public function test_receipt_image_is_for_managers_only(): void
    {
        $this->actingAsCustomer();
        $appointment = $this->book();
        $this->pay($appointment)->assertOk();
        $payment = Payment::sole();

        $this->actingAs($this->admin)->get("/payments/{$payment->id}/proof")->assertOk();
        $this->get('/payments')->assertOk()->assertSee($appointment->appointment_number);
        $this->get("/appointments/{$appointment->id}")->assertOk()->assertSee('Receipt to check');

        $this->actingAs($this->stylist->user)->get("/payments/{$payment->id}/proof")->assertForbidden();
    }

    public function test_a_service_qr_sets_a_fixed_downpayment_and_the_balance_follows(): void
    {
        $this->enableGcash(['payment_gcash_enabled' => false]); // per-service QRs work on their own
        $this->service->update(['deposit_amount' => 50, 'gcash_qr' => 'salon/payments/haircut-50.webp']);
        $customer = $this->actingAsCustomer();

        $this->getJson("/api/v1/services/{$this->service->id}")->assertJsonPath('data.deposit_amount', 50);
        $id = $this->postJson('/api/v1/appointments', $this->bookingPayload())
            ->assertCreated()
            ->assertJsonPath('data.payment.status', 'awaiting_payment')
            ->assertJsonPath('data.payment.deposit_amount', 50)
            ->assertJsonPath('data.items.0.deposit_amount', 50)
            ->assertJsonPath('data.payment.qr_codes.0.label', $this->service->name)
            ->assertJsonPath('data.payment.qr_codes.0.amount', 50)
            ->assertJsonPath('data.payment.qr_codes.0.fixed_amount', true)
            ->assertJsonPath('data.payment.qr_codes.0.qr_url', fn ($url) => str_ends_with($url, '/storage/salon/payments/haircut-50.webp'))
            ->assertJsonCount(1, 'data.payment.qr_codes')
            ->json('data.id');
        $appointment = Appointment::findOrFail($id);
        $this->pay($appointment)->assertOk()->assertJsonPath('data.payment.qr_codes', []);

        $this->actingAs($this->admin)->post("/appointments/{$id}/payment/approve", ['amount' => '50'])->assertSessionHas('success');
        $this->get("/appointments/{$id}")->assertOk()->assertSee('Balance to pay at the salon')->assertSee('₱950.00');

        Sanctum::actingAs($customer);
        $this->getJson("/api/v1/appointments/{$id}")
            ->assertJsonPath('data.status', 'confirmed')
            ->assertJsonPath('data.payment.amount_paid', 50)
            ->assertJsonPath('data.payment.balance_due', 950);
    }

    public function test_a_service_qr_wins_over_the_salon_wide_rule(): void
    {
        $this->service->update(['deposit_amount' => 50, 'gcash_qr' => 'salon/payments/haircut-50.webp']);
        $this->actingAsCustomer();

        $this->postJson('/api/v1/appointments', $this->bookingPayload())
            ->assertJsonPath('data.payment.deposit_amount', 50)
            ->assertJsonCount(1, 'data.payment.qr_codes');
    }

    public function test_mixed_bookings_add_service_qrs_and_the_salon_rule(): void
    {
        $this->enableGcash(['payment_deposit_type' => 'percent', 'payment_deposit_value' => 20, 'payment_gcash_qr' => 'salon/payments/salon.webp']);
        $haircut = Service::factory()->create(['regular_price' => 100, 'deposit_amount' => 50, 'gcash_qr' => 'salon/payments/haircut-50.webp']);
        $selection = fn (Service $s, float $price) => new ServiceSelection($s, null, $price, 30, 0);
        $payments = app(PaymentService::class);

        $this->assertSame(['total' => 250.0, 'items' => [50.0, 0.0]],
            $payments->depositsFor(collect([$selection($haircut, 100), $selection($this->service, 1000)]), 1100));
        // A promotion that lowers the total never cuts a fixed-amount QR, only the salon-wide part.
        $this->assertSame(['total' => 80.0, 'items' => [50.0, 0.0]],
            $payments->depositsFor(collect([$selection($haircut, 100), $selection($this->service, 1000)]), 80));
        $this->assertSame(['total' => 50.0, 'items' => [50.0]], $payments->depositsFor(collect([$selection($haircut, 100)]), 40));
    }

    public function test_admin_sets_a_service_downpayment_qr(): void
    {
        $this->actingAs($this->admin);
        $form = fn (array $extra = []) => [
            'category_id' => $this->service->category_id, 'name' => $this->service->name, 'regular_price' => 100,
            'duration_minutes' => 60, 'status' => 'active',
            'branches' => [$this->branch->id => ['enabled' => '1']], 'staff' => [$this->stylist->id],
            ...$extra,
        ];
        $url = "/services/{$this->service->id}";

        $this->put($url, $form(['deposit_amount' => 50]))->assertSessionHasErrors('gcash_qr');
        $this->put($url, $form(['gcash_qr' => UploadedFile::fake()->image('qr.png', 300, 300)]))->assertSessionHasErrors('deposit_amount');
        $this->put($url, $form(['deposit_amount' => 150, 'gcash_qr' => UploadedFile::fake()->image('qr.png', 300, 300)]))->assertSessionHasErrors('deposit_amount');

        $this->put($url, $form(['deposit_amount' => 50, 'gcash_qr' => UploadedFile::fake()->image('qr.png', 300, 300)]))->assertSessionHasNoErrors();
        $service = $this->service->fresh();
        $this->assertSame(50.0, $service->ownDeposit());
        Storage::disk('public')->assertExists($service->gcash_qr);
        $this->get('/services')->assertSee('₱50.00 downpayment');

        // Saving again without a new file keeps the QR; removing clears both.
        $this->put($url, $form(['deposit_amount' => 50]))->assertSessionHasNoErrors();
        $this->assertNotNull($this->service->fresh()->gcash_qr);
        $this->put($url, $form(['deposit_amount' => 50, 'remove_gcash_qr' => '1']))->assertSessionHasNoErrors();
        $this->assertNull($this->service->fresh()->ownDeposit());
        Storage::disk('public')->assertMissing($service->gcash_qr);
    }

    public function test_admin_sets_up_gcash_in_settings(): void
    {
        $this->setting(['payment_gcash_enabled' => false, 'payment_gcash_number' => '', 'payment_gcash_qr' => '']);
        $this->actingAs(User::factory()->superAdmin()->create());
        $base = [
            'salon_name' => 'DGS Hair Salon & Spa', 'currency' => 'PHP', 'currency_symbol' => '₱',
            'booking_minimum_notice_hours' => 2, 'booking_maximum_days' => 60, 'booking_slot_interval_minutes' => 30,
            'cancellation_hours' => 24, 'payment_gcash_enabled' => '1', 'payment_gcash_account_name' => 'DGS SALON',
            'payment_deposit_type' => 'percent', 'payment_deposit_value' => 20, 'payment_window_minutes' => 45,
        ];

        $this->put('/settings', $base)->assertSessionHasErrors('payment_qr');
        $this->put('/settings', [...$base, 'payment_deposit_value' => 150, 'payment_gcash_number' => '+63 917 123 4567'])->assertSessionHasErrors('payment_deposit_value');

        $this->put('/settings', [...$base, 'payment_gcash_number' => '+63 917 123 4567', 'payment_qr' => UploadedFile::fake()->image('qr.png', 400, 400)])
            ->assertSessionHasNoErrors();

        app(SettingsService::class)->flush();
        $this->getJson('/api/v1/settings')
            ->assertJsonPath('data.payment_gcash_enabled', true)
            ->assertJsonPath('data.payment_gcash_number', '09171234567')
            ->assertJsonPath('data.payment_deposit_type', 'percent')
            ->assertJsonPath('data.payment_window_minutes', 45)
            ->assertJsonPath('data.payment_gcash_qr_url', fn ($url) => str_contains($url, '/storage/salon/payments/') && str_ends_with($url, '.webp'));
    }

    private function enableGcash(array $overrides = []): void
    {
        $this->setting([
            'payment_gcash_enabled' => true,
            'payment_gcash_account_name' => 'DGS SALON',
            'payment_gcash_number' => '09171234567',
            'payment_deposit_type' => 'fixed',
            'payment_deposit_value' => 200,
            'payment_window_minutes' => 30,
            ...$overrides,
        ]);
    }

    private function setting(array $values): void
    {
        app(SettingsService::class)->update($values);
    }

    private function book(string $time = '09:00'): Appointment
    {
        $id = $this->postJson('/api/v1/appointments', $this->bookingPayload(['start_time' => $time]))->assertCreated()->json('data.id');

        return Appointment::findOrFail($id);
    }

    private function pay(Appointment $appointment, string $reference = '1234567890123')
    {
        return $this->post("/api/v1/appointments/{$appointment->id}/payment", [
            'reference_number' => $reference,
            'proof' => UploadedFile::fake()->image('receipt.jpg', 540, 1200),
        ], ['Accept' => 'application/json']);
    }
}
