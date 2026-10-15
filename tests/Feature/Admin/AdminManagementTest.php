<?php

namespace Tests\Feature\Admin;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Product;
use App\Models\Promotion;
use App\Models\Review;
use App\Models\Service;
use App\Models\ServiceCategory;
use App\Models\StaffProfile;
use App\Models\User;
use App\Models\WalkInVisit;
use App\Notifications\AppointmentNotification;
use App\Notifications\BroadcastNotification;
use App\Services\ReportService;
use App\Services\SettingsService;
use App\Support\Reports\DateRange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsSalon;
use Tests\TestCase;

class AdminManagementTest extends TestCase
{
    use BuildsSalon, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSalon();
        $this->actingAs(User::factory()->superAdmin()->create());
    }

    public function test_create_service_with_variants_branch_price_staff_and_image(): void
    {
        Storage::fake('public');
        $category = ServiceCategory::factory()->create();

        $this->post('/services', [
            'category_id' => $category->id,
            'name' => 'Hair Rebond',
            'regular_price' => 2500,
            'duration_minutes' => 180,
            'buffer_minutes' => 30,
            'status' => 'active',
            'is_featured' => '1',
            'image' => UploadedFile::fake()->image('rebond.jpg', 800, 600),
            'branches' => [$this->branch->id => ['enabled' => '1', 'custom_price' => '2300']],
            'staff' => [$this->stylist->id],
            'variants' => [
                ['name' => 'Short', 'price' => 2500, 'duration_minutes' => 150],
                ['name' => 'Long', 'price' => 4000, 'duration_minutes' => ''],
            ],
        ])->assertRedirect('/services')->assertSessionHasNoErrors();

        $service = Service::where('name', 'Hair Rebond')->firstOrFail();
        $this->assertSame('hair-rebond', $service->slug);
        $this->assertTrue($service->is_featured);
        $this->assertSame('2300.00', $service->branches->first()->pivot->custom_price);
        $this->assertSame([$this->stylist->id], $service->staff->pluck('id')->all());
        $this->assertSame(['Short', 'Long'], $service->variants()->orderBy('price')->pluck('name')->all());
        $this->assertNull($service->variants()->where('name', 'Long')->value('duration_minutes'));
        Storage::disk('public')->assertExists($service->image);

        // Removing an option soft-deletes it.
        $short = $service->variants()->where('name', 'Short')->first();
        $this->put("/services/{$service->id}", [
            'category_id' => $category->id, 'name' => 'Hair Rebond', 'regular_price' => 2600, 'duration_minutes' => 180, 'status' => 'active',
            'branches' => [$this->branch->id => ['enabled' => '1']],
            'variants' => [['id' => $short->id, 'name' => 'Short hair', 'price' => 2600]],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $this->assertSame(['Short hair'], $service->variants()->pluck('name')->all());
        $this->assertSoftDeleted('service_variants', ['name' => 'Long']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'service.updated', 'entity_id' => $service->id]);
    }

    public function test_service_validation(): void
    {
        $this->post('/services', ['name' => '', 'regular_price' => -1, 'duration_minutes' => 1])
            ->assertSessionHasErrors(['name', 'category_id', 'regular_price', 'duration_minutes', 'status']);
    }

    public function test_inventory_products_and_the_services_that_use_them(): void
    {
        $this->post('/products', ['name' => 'Hair color', 'brand' => 'Acme', 'unit' => 'tube', 'unit_cost' => '120.50'])
            ->assertRedirect('/products')->assertSessionHasNoErrors();
        $color = Product::where('name', 'Hair color')->firstOrFail();
        $developer = Product::create(['name' => 'Developer', 'unit' => 'ml', 'unit_cost' => '0.50']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'product.created', 'entity_id' => $color->id]);

        $service = fn (array $products) => [
            'category_id' => $this->service->category_id, 'name' => $this->service->name, 'regular_price' => 1000, 'duration_minutes' => 60,
            'status' => 'active', 'branches' => [$this->branch->id => ['enabled' => '1']], 'staff' => [$this->stylist->id], 'products' => $products,
        ];
        $this->put("/services/{$this->service->id}", $service([['product_id' => $color->id, 'quantity' => 2], ['product_id' => $developer->id, 'quantity' => '60']]))
            ->assertRedirect('/services')->assertSessionHasNoErrors();
        $this->assertSame(271.0, $this->service->fresh()->productCost()); // 2 × 120.50 + 60 × 0.50

        $this->get('/products')->assertOk()->assertSee('Hair color')->assertSee($this->service->name);
        $this->get("/products/{$color->id}/edit")->assertOk()->assertSee($this->service->name);
        $this->get("/services/{$this->service->id}/edit")->assertOk()->assertSee('Products used')->assertSee('Hair color (Acme)');

        $this->put("/services/{$this->service->id}", $service([['product_id' => $color->id, 'quantity' => 1], ['product_id' => $color->id, 'quantity' => 2]]))
            ->assertSessionHasErrors('products.1.product_id');
        $this->put("/services/{$this->service->id}", $service([['product_id' => $color->id, 'quantity' => 0]]))
            ->assertSessionHasErrors('products.0.quantity');
        $this->post('/products', ['name' => '', 'unit' => '', 'unit_cost' => -1])->assertSessionHasErrors(['name', 'unit', 'unit_cost']);
        $this->post('/products', ['name' => 'Bleach', 'unit' => 'bucket', 'unit_cost' => 10])->assertSessionHasErrors('unit'); // not in the Unit dropdown

        // Deleting a product takes it off every service.
        $this->delete("/products/{$developer->id}")->assertRedirect('/products');
        $this->assertSoftDeleted($developer);
        $this->assertSame([$color->id], $this->service->products()->pluck('products.id')->all());
        $this->put("/services/{$this->service->id}", $service([['product_id' => $developer->id, 'quantity' => 1]]))
            ->assertSessionHasErrors('products.0.product_id');
    }

    public function test_reports_use_the_same_figures_as_the_dashboard(): void
    {
        $other = Branch::factory()->create(['name' => 'Second Branch']);
        $this->service->branches()->attach($other, ['is_available' => true]);
        $this->stylist->branches()->attach($other);
        $color = Product::create(['name' => 'Hair color', 'unit' => 'tube', 'unit_cost' => 100]);
        $this->service->products()->attach($color, ['quantity' => 1]); // ₱100 product cost per service

        // A ₱1,000 appointment with a ₱200 discount, and a ₱1,000 walk-in at another branch with a stylist.
        $appointment = Appointment::factory()->at(now()->toDateString().' 10:00')->confirmed()
            ->create(['branch_id' => $this->branch->id, 'staff_profile_id' => $this->stylist->id, 'subtotal' => 1000, 'discount_amount' => 200, 'total_amount' => 800]);
        $appointment->items()->create(['service_id' => $this->service->id, 'service_name' => $this->service->name, 'staff_profile_id' => $this->stylist->id, 'unit_price' => 1000, 'duration_minutes' => 60, 'subtotal' => 1000]);
        $this->post("/appointments/{$appointment->id}/status", ['status' => 'completed']);
        $this->post('/walk-ins', ['branch_id' => $other->id, 'services' => [$this->service->id], 'staff_by_service' => [$this->service->id => $this->stylist->id]])->assertSessionHasNoErrors();
        $this->post('/walk-ins/'.WalkInVisit::firstOrFail()->id.'/complete');

        $reports = app(ReportService::class);
        $range = new DateRange(now()->startOfDay(), now()->startOfDay(), 'today');
        $dashboard = $reports->dashboard(auth()->user());
        $sales = $reports->sales($range)->totals;

        $this->assertSame(2000.0, $sales['before_discounts']);
        $this->assertSame(200.0, $sales['discounts']);
        $this->assertSame($dashboard['revenue'], $sales['gross']);                       // ₱1,800 on both
        $this->assertSame($dashboard['product_cost'], $sales['product_cost']);           // ₱200 on both
        $this->assertSame($dashboard['revenue'] - $dashboard['product_cost'], $sales['net']); // ₱1,600 on both
        $this->assertSame(['Gross revenue', 'Net revenue', 'Completed visits', 'Average per visit'], array_keys($reports->sales($range)->summary));

        $branches = collect($reports->branches($range)->rows)->keyBy('branch');
        $this->assertSame(1000.0, $branches['Second Branch']['revenue']);              // walk-in only branch
        $this->assertSame(1, $branches['Second Branch']['walk_ins']);
        $this->assertSame(1800.0, $reports->branches($range)->totals['revenue']);

        $service = $reports->services($range)->rows[0];
        $this->assertSame([1, 1, 2000.0], [$service['completed'], $service['walk_ins'], $service['revenue']]);

        $staff = $reports->staff($range)->rows[0];
        $this->assertSame([1, 1, 1800.0], [$staff['completed'], $staff['walk_ins'], $staff['revenue']]);

        $this->get('/reports/sales')->assertOk()->assertSee('Gross revenue')->assertSee('Product cost')->assertSee('Before discounts');
        $this->get('/reports')->assertOk()->assertSee('Net revenue = gross minus product cost');
    }

    public function test_completed_services_record_product_cost_for_net_revenue(): void
    {
        $color = Product::create(['name' => 'Hair color', 'unit' => 'tube', 'unit_cost' => 150]);
        $this->service->products()->attach($color, ['quantity' => 2]); // ₱300 per service

        $appointment = Appointment::factory()->at(now()->toDateString().' 10:00')->confirmed()
            ->create(['branch_id' => $this->branch->id, 'staff_profile_id' => $this->stylist->id, 'subtotal' => 1000, 'total_amount' => 1000]);
        $appointment->items()->create(['service_id' => $this->service->id, 'service_name' => $this->service->name, 'unit_price' => 1000, 'duration_minutes' => 60, 'subtotal' => 1000]);
        $this->post("/appointments/{$appointment->id}/status", ['status' => 'completed'])->assertSessionHas('success');
        $this->assertSame('300.00', $appointment->items()->value('product_cost'));

        $this->post('/walk-ins', ['branch_id' => $this->branch->id, 'services' => [$this->service->id]])->assertSessionHasNoErrors();
        $visit = WalkInVisit::firstOrFail();
        $this->assertSame('0.00', $visit->items()->value('product_cost')); // not used up until it is done
        $this->post("/walk-ins/{$visit->id}/complete")->assertRedirect();
        $this->assertSame('300.00', $visit->items()->value('product_cost'));

        // New unit costs apply from now on; what was already recorded stays.
        $color->update(['unit_cost' => 500]);

        $stats = app(ReportService::class)->dashboard(auth()->user());
        $this->assertSame(2000.0, $stats['revenue']);
        $this->assertSame(600.0, $stats['product_cost']);
        $this->assertSame(600.0, app(ReportService::class)->dashboard(auth()->user(), 'month')['product_cost']);
        $this->assertSame(['Gross revenue', 'Net revenue'], array_column($stats['revenue_chart']['series'], 'label'));
        $this->assertSame(1400.0, last($stats['revenue_chart']['series'][1]['data']));

        $this->get('/dashboard')->assertOk()
            ->assertSeeInOrder(['Gross revenue · Today', '₱2,000.00', 'Net revenue · Today', '₱1,400.00', 'After ₱600.00 in products']);

        $this->actingAs($this->stylist->user);
        $this->get('/dashboard')->assertOk()->assertDontSee('Net revenue')->assertDontSee('All branches');
    }

    public function test_dashboard_filters_by_branch_and_period(): void
    {
        $other = Branch::factory()->create(['name' => 'Second Branch']);
        $book = function (Branch $branch, string $at, float $total) {
            $appointment = Appointment::factory()->at($at)->confirmed()
                ->create(['branch_id' => $branch->id, 'staff_profile_id' => $this->stylist->id, 'subtotal' => $total, 'total_amount' => $total]);
            $appointment->items()->create(['service_id' => $this->service->id, 'service_name' => $this->service->name, 'unit_price' => $total, 'duration_minutes' => 60, 'subtotal' => $total]);
            $this->post("/appointments/{$appointment->id}/status", ['status' => 'completed'])->assertSessionHas('success');
        };
        $book($this->branch, now()->toDateString().' 10:00', 1000);
        $book($other, now()->toDateString().' 11:00', 800);
        $book($this->branch, now()->startOfMonth()->toDateString().' 09:00', 500);

        $reports = app(ReportService::class);
        $user = auth()->user();
        $todayAll = now()->isSameDay(now()->startOfMonth()) ? 2300.0 : 1800.0;
        $this->assertSame($todayAll, $reports->dashboard($user, 'today')['revenue']);
        $this->assertSame(800.0, $reports->dashboard($user, 'today', $other->id)['revenue']);
        $this->assertSame(1500.0, $reports->dashboard($user, 'month', $this->branch->id)['revenue']);
        $this->assertSame(2, $reports->dashboard($user, 'month', $this->branch->id)['completed_count']);
        $this->assertSame('today', $reports->dashboard($user, 'bogus')['period']);

        $this->get('/dashboard?period=month&branch_id='.$other->id)->assertOk()
            ->assertSee('Gross revenue · This month')->assertSee('₱800.00')->assertDontSee('₱1,500.00')
            ->assertSee('Second Branch');
        $this->get('/dashboard?branch_id=not-a-branch')->assertOk()->assertSee('All branches');

        // Staff always see their own appointments, whatever branch is asked for.
        $this->actingAs($this->stylist->user);
        $this->assertNull($reports->dashboard($this->stylist->user, 'today', $other->id)['branch_id']);
    }

    public function test_create_staff_with_schedule_and_day_off(): void
    {
        $this->post('/staff', [
            'name' => 'New Stylist',
            'email' => 'NEW@example.com',
            'phone' => '0917 555 1234',
            'password' => 'secret123',
            'position' => 'Stylist',
            'status' => 'active',
            'branches' => [$this->branch->id],
            'services' => [$this->service->id],
            'schedule' => [
                1 => ['is_working' => '1', 'branch_id' => $this->branch->id, 'start_time' => '10:00', 'end_time' => '19:00', 'break_start' => '13:00', 'break_end' => '14:00'],
                2 => ['is_working' => '0'],
            ],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $staff = StaffProfile::whereHas('user', fn ($q) => $q->where('email', 'new@example.com'))->firstOrFail();
        $this->assertSame('staff', $staff->user->role->value);
        $this->assertSame('09175551234', $staff->user->phone);
        $this->assertSame(1, $staff->schedules()->count());
        $this->assertTrue((bool) $staff->branches->first()->pivot->is_primary);

        $this->post("/staff/{$staff->id}/days-off", ['date' => self::DAY, 'reason' => 'Leave'])->assertRedirect();
        $this->assertDatabaseHas('staff_days_off', ['staff_profile_id' => $staff->id, 'reason' => 'Leave']);

        // New staff can sign in to the admin portal.
        auth()->logout();
        $this->post('/login', ['email' => 'new@example.com', 'password' => 'secret123'])->assertRedirect(route('dashboard'));
    }

    public function test_staff_schedule_validation(): void
    {
        $this->post('/staff', [
            'name' => 'X', 'email' => 'x@example.com', 'password' => 'secret123', 'status' => 'active', 'branches' => [$this->branch->id],
            'schedule' => [1 => ['is_working' => '1', 'branch_id' => $this->branch->id, 'start_time' => '18:00', 'end_time' => '09:00']],
        ])->assertSessionHasErrors('schedule.1.end_time');
    }

    public function test_branch_crud_with_hours_and_closure(): void
    {
        $hours = collect(range(0, 6))->map(fn ($d) => ['is_open' => $d === 0 ? '0' : '1', 'opening_time' => '10:00', 'closing_time' => '20:00'])->all();

        $this->post('/branches', ['name' => 'Makati Branch', 'status' => 'active', 'hours' => $hours])->assertRedirect()->assertSessionHasNoErrors();
        $branch = Branch::where('name', 'Makati Branch')->firstOrFail();
        $this->assertFalse($branch->businessHours()->where('day_of_week', 0)->value('is_open'));
        $this->assertSame('10:00:00', $branch->businessHours()->where('day_of_week', 1)->value('opening_time'));

        $this->post("/branches/{$branch->id}/closures", ['date' => self::DAY, 'reason' => 'Holiday'])->assertRedirect();
        $this->assertDatabaseHas('branch_closures', ['branch_id' => $branch->id, 'reason' => 'Holiday']);

        $hours[1]['closing_time'] = '09:00';
        $this->put("/branches/{$branch->id}", ['name' => 'Makati Branch', 'status' => 'active', 'hours' => $hours])->assertSessionHasErrors('hours.1.closing_time');
    }

    public function test_promotion_create_preview_publish_with_broadcast(): void
    {
        Notification::fake();
        $customer = $this->customer();

        $this->post('/promotions', [
            'name' => 'Sample Spa Week', 'discount_type' => 'percentage', 'discount_value' => 15,
            'start_at' => now()->subHour()->format('Y-m-d\TH:i'), 'end_at' => now()->addWeek()->format('Y-m-d\TH:i'),
            'status' => 'draft', 'services' => [$this->service->id],
        ])->assertRedirect();

        $promotion = Promotion::where('name', 'Sample Spa Week')->firstOrFail();
        $this->get("/promotions/{$promotion->id}/preview")->assertOk()->assertSee('15% off');
        $this->getJson('/api/v1/promotions')->assertJsonCount(0, 'data'); // draft hidden

        $this->post("/promotions/{$promotion->id}/publish", ['notify' => '1'])->assertRedirect('/promotions');
        $this->getJson('/api/v1/promotions')->assertJsonCount(1, 'data');
        Notification::assertSentTo($customer, BroadcastNotification::class, fn ($n) => $n->data['promotion_id'] === $promotion->id);
    }

    public function test_promotion_validation_requires_value_for_type(): void
    {
        $this->post('/promotions', ['name' => 'X', 'discount_type' => 'percentage', 'discount_value' => 150, 'start_at' => '2026-10-01T00:00', 'end_at' => '2026-09-01T00:00', 'status' => 'draft'])
            ->assertSessionHasErrors(['discount_value', 'end_at']);
        $this->post('/promotions', ['name' => 'X', 'discount_type' => 'special_price', 'start_at' => '2026-10-01T00:00', 'end_at' => '2026-10-09T00:00', 'status' => 'draft'])
            ->assertSessionHasErrors('promo_price');
    }

    public function test_appointment_lifecycle_actions_notify_customer(): void
    {
        Notification::fake();
        $appointment = Appointment::factory()->at(self::DAY.' 10:00')->create(['branch_id' => $this->branch->id, 'staff_profile_id' => $this->stylist->id]);
        $appointment->items()->create(['service_id' => $this->service->id, 'service_name' => 'Cut', 'unit_price' => 1000, 'duration_minutes' => 60, 'subtotal' => 1000]);

        $this->post("/appointments/{$appointment->id}/status", ['status' => 'confirmed'])->assertSessionHas('success');
        $this->assertSame(AppointmentStatus::Confirmed, $appointment->fresh()->status);

        $this->post("/appointments/{$appointment->id}/reschedule", ['appointment_date' => self::DAY, 'start_time' => '14:15'])->assertSessionHas('success');
        $this->assertSame('14:15:00', $appointment->fresh()->start_time); // admins may choose off-grid times

        $this->getJson("/appointments/{$appointment->id}/slots?date=".self::DAY)->assertOk()->assertJsonStructure(['slots']);

        $this->put("/appointments/{$appointment->id}/notes", ['admin_notes' => 'Prefers quiet'])->assertSessionHas('success');
        $this->post("/appointments/{$appointment->id}/cancel", ['reason' => 'Stylist sick'])->assertSessionHas('success');
        $this->post("/appointments/{$appointment->id}/status", ['status' => 'completed'])->assertSessionHas('error'); // cancelled is final

        $types = collect(Notification::sent($appointment->customer, AppointmentNotification::class))->map(fn ($n) => $n->type->value)->all();
        $this->assertSame(['APPOINTMENT_CONFIRMED', 'APPOINTMENT_RESCHEDULED', 'APPOINTMENT_CANCELLED'], $types);
    }

    public function test_admin_reschedule_rejects_conflicts(): void
    {
        Appointment::factory()->at(self::DAY.' 14:00')->create(['branch_id' => $this->branch->id, 'staff_profile_id' => $this->stylist->id]);
        $appointment = Appointment::factory()->at(self::DAY.' 10:00')->create(['branch_id' => $this->branch->id, 'staff_profile_id' => $this->stylist->id]);
        $appointment->items()->create(['service_id' => $this->service->id, 'service_name' => 'Cut', 'unit_price' => 1000, 'duration_minutes' => 60, 'subtotal' => 1000]);

        $this->post("/appointments/{$appointment->id}/reschedule", ['appointment_date' => self::DAY, 'start_time' => '14:30'])
            ->assertSessionHas('error');
        $this->assertSame('10:00:00', $appointment->fresh()->start_time);
    }

    public function test_review_moderation(): void
    {
        $review = Review::factory()->create();

        $this->post("/reviews/{$review->id}/approve")->assertRedirect();
        $this->getJson('/api/v1/reviews')->assertJsonCount(1, 'data');
        $this->post("/reviews/{$review->id}/reject")->assertRedirect();
        $this->getJson('/api/v1/reviews')->assertJsonCount(0, 'data');
        $this->delete("/reviews/{$review->id}")->assertRedirect();
        $this->assertSoftDeleted($review);
    }

    public function test_customer_suspension_revokes_tokens(): void
    {
        $customer = $this->customer();
        $customer->createToken('app');

        $this->post("/customers/{$customer->id}/status", ['status' => 'suspended'])->assertRedirect();
        $this->assertSame('suspended', $customer->fresh()->status->value);
        $this->assertSame(0, $customer->tokens()->count());
    }

    public function test_settings_update_changes_booking_rules(): void
    {
        $this->put('/settings', [
            'salon_name' => 'DGS Hair Salon & Spa', 'currency' => 'PHP', 'currency_symbol' => '₱',
            'booking_minimum_notice_hours' => 4, 'booking_maximum_days' => 30, 'booking_slot_interval_minutes' => 15,
            'cancellation_hours' => 12, 'auto_confirm_appointments' => '1', 'auto_approve_reviews' => '0',
            'reminder_offsets' => '48, 3',
        ])->assertSessionHasNoErrors()->assertSessionHas('success');

        $settings = app(SettingsService::class);
        $settings->flush();
        $this->assertSame(15, $settings->int('booking_slot_interval_minutes'));
        $this->assertTrue($settings->bool('auto_confirm_appointments'));
        $this->assertSame([2880, 180], $settings->reminderOffsets());
        $this->getJson('/api/v1/settings')->assertJsonPath('data.cancellation_hours', 12);
    }

    public function test_broadcast_notification(): void
    {
        Notification::fake();
        $customers = User::factory()->count(3)->create();
        User::factory()->suspended()->create();

        $this->post('/notifications/broadcast', ['type' => 'GENERAL', 'title' => 'Holiday hours', 'body' => 'We close early on Friday.'])
            ->assertSessionHas('success', 'Notification queued for 3 customer(s).');
        Notification::assertSentTo($customers, BroadcastNotification::class);
    }

    public function test_cannot_delete_category_with_services(): void
    {
        $this->delete('/categories/'.$this->service->category_id)->assertSessionHas('error');
        $this->assertNotSoftDeleted('service_categories', ['id' => $this->service->category_id]);
    }
}
