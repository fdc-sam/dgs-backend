<?php

namespace Tests\Feature\Api;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Promotion;
use App\Models\Service;
use App\Models\User;
use App\Notifications\AppointmentNotification;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsSalon;
use Tests\TestCase;

class AppointmentBookingTest extends TestCase
{
    use BuildsSalon, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSalon();
    }

    public function test_customer_books_an_available_slot(): void
    {
        Notification::fake();
        $admin = User::factory()->admin()->create();
        $customer = $this->actingAsCustomer();

        $response = $this->postJson('/api/v1/appointments', $this->bookingPayload(['customer_notes' => 'Please use warm brown color.']))
            ->assertCreated()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.start_time', '09:00')
            ->assertJsonPath('data.end_time', '10:00')
            ->assertJsonPath('data.duration_minutes', 60)
            ->assertJsonPath('data.total_amount', 1000)
            ->assertJsonPath('data.staff.id', $this->stylist->id)
            ->assertJsonPath('data.items.0.service_name', $this->service->name)
            ->assertJsonPath('data.customer_notes', 'Please use warm brown color.')
            ->assertJsonPath('data.can_cancel', true);

        $this->assertMatchesRegularExpression('/^DG-20261001-000001$/', $response->json('data.appointment_number'));
        $this->assertDatabaseHas('appointments', ['customer_id' => $customer->id, 'buffer_minutes' => 15, 'starts_at' => self::DAY.' 09:00:00']);

        Notification::assertSentTo($customer, AppointmentNotification::class, fn ($n) => $n->type->value === 'APPOINTMENT_CREATED');
        Notification::assertSentTo($admin, AppointmentNotification::class);
        Notification::assertSentTo($this->stylist->user, AppointmentNotification::class);
    }

    public function test_appointment_numbers_increment_per_day(): void
    {
        $this->actingAsCustomer();
        $this->postJson('/api/v1/appointments', $this->bookingPayload())->assertCreated();

        $this->actingAsCustomer();
        $second = $this->postJson('/api/v1/appointments', $this->bookingPayload(['start_time' => '13:00']))->assertCreated();

        $this->assertSame('DG-20261001-000002', $second->json('data.appointment_number'));
    }

    public function test_any_staff_assigns_a_free_qualified_stylist(): void
    {
        $second = $this->makeStylist();
        Appointment::factory()->at(self::DAY.' 09:00')->create(['branch_id' => $this->branch->id, 'staff_profile_id' => $this->stylist->id]);
        $this->actingAsCustomer();

        $this->postJson('/api/v1/appointments', $this->bookingPayload(['staff_id' => null]))
            ->assertCreated()
            ->assertJsonPath('data.staff.id', $second->id);
    }

    public function test_price_snapshot_uses_branch_price_and_variant(): void
    {
        $this->service->branches()->updateExistingPivot($this->branch->id, ['custom_price' => 900]);
        $variant = $this->service->variants()->create(['name' => 'Long', 'price' => 1500, 'duration_minutes' => 90]);
        $this->actingAsCustomer();

        $this->postJson('/api/v1/appointments', $this->bookingPayload([
            'services' => [['service_id' => $this->service->id, 'service_variant_id' => $variant->id]],
        ]))->assertCreated()
            ->assertJsonPath('data.items.0.variant_name', 'Long')
            ->assertJsonPath('data.items.0.unit_price', 1500)
            ->assertJsonPath('data.end_time', '10:30');

        $this->actingAsCustomer();
        $this->postJson('/api/v1/appointments', $this->bookingPayload(['start_time' => '13:00']))
            ->assertCreated()->assertJsonPath('data.total_amount', 900);

        // Later catalog price changes do not alter history.
        $this->service->update(['regular_price' => 5000]);
        $this->assertSame('1500.00', Appointment::oldest('starts_at')->first()->items->first()->unit_price);
    }

    public function test_multiple_services_are_sequential_and_each_needs_a_stylist_who_offers_it(): void
    {
        $nails = Service::factory()->duration(30, 10)->atBranch($this->branch)->create(['regular_price' => 300]);
        $this->actingAsCustomer();

        // Nobody offers nails → the services list is rejected (each service is staffed on its own, see BOOKING.md).
        $this->postJson('/api/v1/appointments', $this->bookingPayload([
            'services' => [['service_id' => $this->service->id], ['service_id' => $nails->id]],
        ]))->assertStatus(422)->assertJsonPath('code', 'VALIDATION_ERROR')->assertJsonValidationErrors('services');

        $this->stylist->services()->attach($nails->id);
        $this->postJson('/api/v1/appointments', $this->bookingPayload([
            'services' => [['service_id' => $this->service->id], ['service_id' => $nails->id]],
        ]))->assertCreated()
            ->assertJsonPath('data.end_time', '10:30')
            ->assertJsonPath('data.subtotal', 1300)
            ->assertJsonCount(2, 'data.items');

        $this->assertSame(15, Appointment::first()->buffer_minutes); // max of buffers
    }

    public function test_percentage_promotion_discount(): void
    {
        $promo = Promotion::factory()->create(['discount_value' => 20]);
        $promo->services()->attach($this->service->id);
        $this->actingAsCustomer();

        $this->postJson('/api/v1/appointments', $this->bookingPayload(['promotion_id' => $promo->id]))
            ->assertCreated()
            ->assertJsonPath('data.discount_amount', 200)
            ->assertJsonPath('data.total_amount', 800)
            ->assertJsonPath('data.promotion.id', $promo->id);
    }

    public function test_special_price_and_fixed_promotions(): void
    {
        $special = Promotion::factory()->specialPrice(750)->create();
        $this->actingAsCustomer();
        $this->postJson('/api/v1/appointments', $this->bookingPayload(['promotion_id' => $special->id]))
            ->assertCreated()->assertJsonPath('data.total_amount', 750);

        $fixed = Promotion::factory()->fixed(5000)->create();
        $this->actingAsCustomer();
        $this->postJson('/api/v1/appointments', $this->bookingPayload(['promotion_id' => $fixed->id, 'start_time' => '13:00']))
            ->assertCreated()->assertJsonPath('data.total_amount', 0); // never negative
    }

    public function test_expired_or_other_branch_promotion_is_rejected(): void
    {
        $expired = Promotion::factory()->expired()->create();
        $this->actingAsCustomer();
        $this->postJson('/api/v1/appointments', $this->bookingPayload(['promotion_id' => $expired->id]))
            ->assertStatus(422)->assertJsonValidationErrors('promotion_id');

        $elsewhere = Promotion::factory()->create();
        $elsewhere->branches()->attach(Branch::factory()->create()->id);
        $this->postJson('/api/v1/appointments', $this->bookingPayload(['promotion_id' => $elsewhere->id]))
            ->assertStatus(422)->assertJsonValidationErrors('promotion_id');

        $this->assertSame(0, Appointment::count());
    }

    public function test_auto_confirm_setting(): void
    {
        app(SettingsService::class)->update(['auto_confirm_appointments' => true]);
        $this->actingAsCustomer();

        $this->postJson('/api/v1/appointments', $this->bookingPayload())
            ->assertCreated()->assertJsonPath('data.status', 'confirmed');
    }

    public function test_off_grid_or_closed_times_are_rejected(): void
    {
        $this->actingAsCustomer();

        foreach (['09:10', '17:30', '12:00', '07:00'] as $time) {
            $this->postJson('/api/v1/appointments', $this->bookingPayload(['start_time' => $time]))
                ->assertStatus(409)->assertJsonPath('code', 'SLOT_UNAVAILABLE');
        }
    }

    public function test_service_not_offered_at_branch_or_inactive(): void
    {
        $elsewhere = Service::factory()->create();
        $inactive = Service::factory()->inactive()->atBranch($this->branch)->create();
        $this->actingAsCustomer();

        $this->postJson('/api/v1/appointments', $this->bookingPayload(['services' => [['service_id' => $elsewhere->id]]]))
            ->assertStatus(422)->assertJsonValidationErrors('services.0.service_id');
        $this->postJson('/api/v1/appointments', $this->bookingPayload(['services' => [['service_id' => $inactive->id]]]))
            ->assertStatus(422)->assertJsonValidationErrors('services.0.service_id');
    }

    public function test_validation_errors(): void
    {
        $this->actingAsCustomer();

        $this->postJson('/api/v1/appointments', [])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['branch_id', 'appointment_date', 'start_time', 'services']);

        $this->postJson('/api/v1/appointments', $this->bookingPayload(['appointment_date' => '2026-09-01']))
            ->assertStatus(422)->assertJsonValidationErrors('appointment_date');
    }

    public function test_requires_authentication(): void
    {
        $this->postJson('/api/v1/appointments', $this->bookingPayload())
            ->assertUnauthorized()->assertJsonPath('code', 'UNAUTHENTICATED');
    }

    public function test_listing_scopes(): void
    {
        $customer = $this->actingAsCustomer();
        $make = fn (string $at, AppointmentStatus $status) => Appointment::factory()->at($at)->status($status)
            ->create(['customer_id' => $customer->id, 'branch_id' => $this->branch->id]);

        $upcoming = $make(self::DAY.' 09:00', AppointmentStatus::Confirmed);
        $past = $make('2026-09-20 09:00', AppointmentStatus::Completed);
        $cancelled = $make('2026-10-05 09:00', AppointmentStatus::Cancelled);
        Appointment::factory()->at(self::DAY.' 10:00')->create(['branch_id' => $this->branch->id]); // someone else's

        $this->getJson('/api/v1/appointments')->assertOk()->assertJsonCount(3, 'data')->assertJsonPath('meta.total', 3);
        $this->getJson('/api/v1/appointments?scope=upcoming')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $upcoming->id);
        $this->getJson('/api/v1/appointments?scope=past')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $past->id);
        $this->getJson('/api/v1/appointments?scope=cancelled')->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $cancelled->id);
    }
}
