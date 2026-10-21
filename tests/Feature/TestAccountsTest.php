<?php

namespace Tests\Feature;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Review;
use App\Models\Service;
use App\Models\User;
use App\Models\WalkInVisit;
use App\Notifications\AppointmentNotification;
use App\Services\FileUploadService;
use App\Services\ReportService;
use App\Support\Reports\DateRange;
use App\Support\TestMode;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsSalon;
use Tests\TestCase;

/**
 * Test accounts: test admins, staff and customers see only test data and live ones only live data;
 * test bookings never take live slots or real stock; the shared salon setup is view-only for test accounts.
 */
class TestAccountsTest extends TestCase
{
    use BuildsSalon, RefreshDatabase;

    private User $liveAdmin;

    private User $testAdmin;

    private User $liveCustomer;

    private User $testCustomer;

    private Appointment $liveBooking;

    private Appointment $testBooking;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSalon();

        $this->liveAdmin = User::factory()->admin()->create(['name' => 'Live Larry']);
        $this->testAdmin = $this->onTestSide(User::factory()->admin()->create(['name' => 'Test Tom']));
        $this->liveCustomer = $this->customer(['name' => 'Live Lucy', 'email' => 'lucy@example.com', 'password' => 'secret123']);
        $this->testCustomer = $this->onTestSide($this->customer(['name' => 'Test Tina', 'email' => 'tina@example.com', 'password' => 'secret123']));

        // Same stylist, same day: live at 10:00, test at 14:00.
        $this->liveBooking = $this->booking($this->liveCustomer, '10:00', 1000);
        $this->testBooking = $this->booking($this->testCustomer, '14:00', 700);
    }

    public function test_records_take_their_customers_side(): void
    {
        $this->assertFalse($this->liveBooking->is_test);
        $this->assertTrue($this->testBooking->is_test);
        $this->assertTrue(Review::factory()->create(['appointment_id' => $this->testBooking->id, 'customer_id' => $this->testCustomer->id])->is_test);

        // A walk-in takes the side of the admin recording it.
        $this->actingAs($this->testAdmin);
        $visit = WalkInVisit::create(['branch_id' => $this->branch->id, 'created_by' => $this->testAdmin->id, 'visited_at' => now(), 'status' => 'in_progress', 'total_amount' => 0]);
        $this->assertTrue($visit->is_test);
    }

    public function test_each_side_sees_only_its_own_customers_and_bookings(): void
    {
        $this->actingAs($this->liveAdmin);
        $this->get('/customers')->assertOk()->assertSee('Live Lucy')->assertDontSee('Test Tina');
        $this->get('/appointments')->assertOk()->assertSee($this->liveBooking->appointment_number)->assertDontSee($this->testBooking->appointment_number);
        $this->get("/appointments/{$this->testBooking->id}")->assertNotFound();
        $this->get("/customers/{$this->testCustomer->id}")->assertNotFound();
        $this->get('/dashboard')->assertOk()->assertDontSee('Test account.');

        $this->actingAs($this->testAdmin);
        $this->get('/customers')->assertOk()->assertSee('Test Tina')->assertDontSee('Live Lucy');
        $this->get('/appointments')->assertOk()->assertSee($this->testBooking->appointment_number)->assertDontSee($this->liveBooking->appointment_number);
        $this->get("/appointments/{$this->liveBooking->id}")->assertNotFound();
        $this->get("/customers/{$this->liveCustomer->id}")->assertNotFound();
        $this->get("/customers/{$this->testCustomer->id}")->assertOk();
        $this->get('/dashboard')->assertOk()->assertSee('Test account.');
    }

    public function test_a_test_super_admin_cannot_open_a_live_payment_receipt(): void
    {
        Storage::fake(FileUploadService::privateDisk());
        Storage::disk(FileUploadService::privateDisk())->put('receipts/live.jpg', 'image');
        $payment = Payment::create(['appointment_id' => $this->liveBooking->id, 'customer_id' => $this->liveCustomer->id,
            'amount' => 200, 'reference_number' => '1234567890123', 'proof_path' => 'receipts/live.jpg']);

        $this->actingAs($this->onTestSide(User::factory()->superAdmin()->create()));
        $this->get("/payments/{$payment->id}/proof")->assertNotFound();

        $this->actingAs(User::factory()->superAdmin()->create());
        $this->get("/payments/{$payment->id}/proof")->assertOk();
    }

    public function test_test_bookings_never_block_live_slots_and_the_other_way_round(): void
    {
        // A guest browses the live side: only the live booking takes the stylist's time.
        $live = $this->availableTimes(['staff_id' => $this->stylist->id]);
        $this->assertNotContains('10:00', $live);
        $this->assertContains('14:00', $live);

        $this->actingAsCustomer($this->testCustomer);
        $test = $this->availableTimes(['staff_id' => $this->stylist->id]);
        $this->assertContains('10:00', $test);
        $this->assertNotContains('14:00', $test);

        // The test customer can book the time a live customer already has.
        $this->postJson('/api/v1/appointments', $this->bookingPayload(['start_time' => '10:00']))->assertCreated();
        $this->assertTrue(Appointment::withoutGlobalScopes()->where('customer_id', $this->testCustomer->id)->where('start_time', '10:00:00')->sole()->is_test);
    }

    public function test_alerts_about_a_test_booking_go_to_test_accounts_only(): void
    {
        Notification::fake();
        $liveSuper = User::factory()->superAdmin()->create();
        $this->actingAsCustomer($this->testCustomer);

        $this->postJson('/api/v1/appointments', $this->bookingPayload(['start_time' => '16:00']))->assertCreated();

        Notification::assertSentTo($this->testAdmin, AppointmentNotification::class);
        Notification::assertNotSentTo([$this->liveAdmin, $liveSuper, $this->stylist->user], AppointmentNotification::class);
    }

    public function test_reports_and_dashboard_count_only_the_viewers_side(): void
    {
        $this->liveBooking->update(['status' => AppointmentStatus::Completed]);
        $this->testBooking->update(['status' => AppointmentStatus::Completed]);
        $reports = app(ReportService::class);
        $day = CarbonImmutable::parse(self::DAY);
        $bookings = fn () => collect($reports->services(new DateRange($day, $day, 'custom'))->rows)->sum('bookings');

        $this->actingAs($this->liveAdmin);
        $dashboard = $reports->dashboard($this->liveAdmin, 'week');
        $this->assertSame(1000.0, $dashboard['revenue']);
        $this->assertSame(1, $dashboard['customers']);
        $this->assertSame(1, $bookings());

        $this->actingAs($this->testAdmin);
        $dashboard = $reports->dashboard($this->testAdmin, 'week');
        $this->assertSame(700.0, $dashboard['revenue']);
        $this->assertSame(1, $dashboard['customers']);
        $this->assertSame(1, $bookings());
    }

    public function test_public_reviews_are_live_reviews_only(): void
    {
        Review::factory()->approved()->create(['appointment_id' => $this->liveBooking->id, 'customer_id' => $this->liveCustomer->id, 'review' => 'Lovely live visit']);
        Review::factory()->approved()->create(['appointment_id' => $this->testBooking->id, 'customer_id' => $this->testCustomer->id, 'review' => 'Just testing']);

        $this->getJson('/api/v1/reviews')->assertOk()->assertJsonCount(1, 'data')->assertSee('Lovely live visit')->assertDontSee('Just testing');
    }

    public function test_test_admins_can_view_but_not_change_the_salon_setup(): void
    {
        $this->actingAs($this->testAdmin);
        $this->get('/services')->assertOk()->assertSee('View')->assertDontSee('New service');
        $form = $this->get("/services/{$this->service->id}/edit")->assertOk()->assertDontSee('Save changes')->getContent();
        $this->assertMatchesRegularExpression('/<fieldset class="min-w-0"\s+disabled\s*>/', $form);

        $this->post('/categories', ['name' => 'Sneaky', 'status' => 'active'])->assertRedirect()->assertSessionHas('error');
        $this->delete("/services/{$this->service->id}")->assertSessionHas('error');
        $this->assertNotSoftDeleted($this->service);
        $this->assertDatabaseMissing('service_categories', ['name' => 'Sneaky']);

        // A test super admin is limited the same way, even on super-admin pages.
        $this->actingAs($this->onTestSide(User::factory()->superAdmin()->create()));
        $this->get('/settings')->assertOk()->assertDontSee('Save settings');
        $this->put('/settings', ['salon_name' => 'Hacked'])->assertSessionHas('error');
        $this->put("/admin-accounts/{$this->testAdmin->id}/account-type", ['account_type' => 'live'])->assertSessionHas('error');
        $this->assertTrue($this->testAdmin->fresh()->is_test);

        $this->actingAs($this->liveAdmin);
        $this->get('/services')->assertOk()->assertSee('New service');
        $form = $this->get("/services/{$this->service->id}/edit")->assertOk()->assertSee('Save changes')->getContent();
        $this->assertDoesNotMatchRegularExpression('/<fieldset class="min-w-0"\s+disabled/', $form);
    }

    public function test_test_bookings_record_product_cost_but_leave_real_stock_alone(): void
    {
        $shampoo = Product::create(['name' => 'Shampoo', 'unit' => 'ml', 'unit_cost' => 0.5, 'stock_quantity' => 1000]);
        $this->service->products()->attach($shampoo, ['quantity' => 15]);

        $this->actingAs($this->testAdmin);
        $this->post("/appointments/{$this->testBooking->id}/status", ['status' => 'completed'])->assertSessionHas('success');

        $this->assertSame('1000.00', $shampoo->fresh()->stock_quantity);
        $this->assertSame('7.50', $this->testBooking->items()->first()->product_cost);

        $this->actingAs($this->liveAdmin);
        $this->post("/appointments/{$this->liveBooking->id}/status", ['status' => 'completed'])->assertSessionHas('success');
        $this->assertSame('985.00', $shampoo->fresh()->stock_quantity);
    }

    public function test_super_admin_moves_a_customer_and_their_history_between_sides(): void
    {
        $review = Review::factory()->create(['appointment_id' => $this->liveBooking->id, 'customer_id' => $this->liveCustomer->id]);

        $this->actingAs($this->liveAdmin);
        $this->post("/customers/{$this->liveCustomer->id}/account-type", ['account_type' => 'test'])->assertForbidden();

        $this->actingAs(User::factory()->superAdmin()->create());
        $this->get("/customers/{$this->liveCustomer->id}")->assertOk()->assertSee('Test or live')->assertSee('Update status');
        $this->post("/customers/{$this->liveCustomer->id}/account-type", ['account_type' => 'test'])
            ->assertRedirect('/customers')->assertSessionHas('success');

        $this->assertTrue($this->liveCustomer->fresh()->is_test);
        $this->assertTrue(Appointment::withoutGlobalScopes()->find($this->liveBooking->id)->is_test);
        $this->assertTrue(Review::withoutGlobalScopes()->find($review->id)->is_test);
        $this->get("/customers/{$this->liveCustomer->id}")->assertNotFound();
    }

    public function test_admin_accounts_page(): void
    {
        $this->actingAs($super = User::factory()->superAdmin()->create());
        $this->get('/admin-accounts')->assertOk()->assertSee('Test Tom')->assertSee('Live Larry');

        $this->put("/admin-accounts/{$this->liveAdmin->id}/account-type", ['account_type' => 'test'])->assertSessionHas('success');
        $this->assertTrue($this->liveAdmin->fresh()->is_test);
        $this->put("/admin-accounts/{$super->id}/account-type", ['account_type' => 'test'])->assertSessionHas('error');
        $this->assertFalse($super->fresh()->is_test);

        $this->actingAs($this->testAdmin);
        $this->get('/admin-accounts')->assertForbidden();
    }

    public function test_super_admin_adds_admin_accounts(): void
    {
        $form = ['name' => 'Tess Tester', 'email' => ' Tess@Example.com ', 'role' => 'admin', 'account_type' => 'test',
            'password' => 'secret123', 'password_confirmation' => 'secret123'];

        $this->actingAs(User::factory()->superAdmin()->create());
        $this->get('/admin-accounts')->assertOk()->assertSee('New admin');
        $this->get('/admin-accounts/create')->assertOk()->assertSee('Create admin');
        $this->post('/admin-accounts', [...$form, 'password_confirmation' => 'different1'])->assertSessionHasErrors('password');
        $this->post('/admin-accounts', [...$form, 'password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $this->post('/admin-accounts', $form)->assertRedirect('/admin-accounts')->assertSessionHas('success');

        $tess = User::where('email', 'tess@example.com')->sole();
        $this->assertSame('admin', $tess->role->value);
        $this->assertTrue($tess->is_test);
        $this->assertTrue($tess->isActive());
        $this->assertNotNull($tess->email_verified_at);
        $this->assertTrue(Hash::check('secret123', $tess->password));
        $this->post('/admin-accounts', [...$form, 'email' => 'tess@example.com'])->assertSessionHasErrors('email');

        // Only live super admins: plain admins are refused, test super admins see the page without the button.
        $this->actingAs($this->liveAdmin);
        $this->get('/admin-accounts/create')->assertForbidden();
        $this->actingAs($this->onTestSide(User::factory()->superAdmin()->create()));
        $this->get('/admin-accounts')->assertOk()->assertDontSee('New admin');
        $this->post('/admin-accounts', [...$form, 'email' => 'other@example.com'])->assertSessionHas('error');
        $this->assertDatabaseMissing('users', ['email' => 'other@example.com']);
    }

    public function test_staff_form_sets_a_test_sign_in_for_super_admins_only(): void
    {
        $payload = fn (string $type) => [
            'name' => $this->stylist->user->name, 'email' => $this->stylist->user->email, 'status' => 'active',
            'branches' => [$this->branch->id], 'services' => [$this->service->id], 'account_type' => $type,
        ];

        $this->actingAs($this->liveAdmin);
        $this->put("/staff/{$this->stylist->id}", $payload('test'))->assertSessionHasNoErrors();
        $this->assertFalse($this->stylist->user->fresh()->is_test);

        $this->actingAs(User::factory()->superAdmin()->create());
        $this->put("/staff/{$this->stylist->id}", $payload('test'))->assertSessionHasNoErrors();
        $this->assertTrue($this->stylist->user->fresh()->is_test);
    }

    public function test_admin_app_shows_a_test_admin_only_test_data(): void
    {
        Sanctum::actingAs($this->testAdmin, ['admin-app']);

        $this->getJson('/api/v1/admin/auth/me')->assertOk()->assertJsonPath('data.is_test', true);
        $this->getJson('/api/v1/admin/appointments?date='.self::DAY)->assertOk()
            ->assertSee($this->testBooking->appointment_number)->assertDontSee($this->liveBooking->appointment_number);
        $this->getJson("/api/v1/admin/customers/{$this->liveCustomer->id}")->assertNotFound();
        $this->getJson('/api/v1/admin/customers/lookup?code='.$this->liveCustomer->qr_code)->assertNotFound();
    }

    public function test_a_test_customer_deleting_on_the_website_cancels_their_bookings(): void
    {
        $this->post('/delete-account', ['email' => 'tina@example.com', 'password' => 'secret123', 'confirm' => '1'])->assertSessionHas('deleted');

        $this->assertSame(AppointmentStatus::Cancelled, Appointment::withoutGlobalScopes()->find($this->testBooking->id)->status);
        $this->assertSame(AppointmentStatus::Confirmed, Appointment::withoutGlobalScopes()->find($this->liveBooking->id)->status);
    }

    public function test_console_code_sees_both_sides(): void
    {
        $this->assertNull(TestMode::side());
        $this->assertSame(2, Appointment::count());
        $this->assertSame(1, TestMode::on(true, fn () => Appointment::count()));
    }

    private function onTestSide(User $user): User
    {
        $user->forceFill(['is_test' => true])->save();

        return $user;
    }

    private function booking(User $customer, string $time, int $total): Appointment
    {
        $appointment = Appointment::factory()->at(self::DAY.' '.$time)->confirmed()->create([
            'customer_id' => $customer->id, 'branch_id' => $this->branch->id, 'staff_profile_id' => $this->stylist->id,
            'subtotal' => $total, 'total_amount' => $total,
        ]);
        $appointment->items()->create([
            'service_id' => $this->service->id, 'staff_profile_id' => $this->stylist->id, 'service_name' => $this->service->name,
            'unit_price' => $total, 'duration_minutes' => 60, 'subtotal' => $total,
        ]);

        return $appointment;
    }
}
