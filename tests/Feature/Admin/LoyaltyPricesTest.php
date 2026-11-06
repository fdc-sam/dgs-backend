<?php

namespace Tests\Feature\Admin;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsSalon;
use Tests\TestCase;

/**
 * Loyalty card prices on a service (Services → edit): an on/off switch, then two tabs. Less: an amount or percent off every option
 * and branch price (loyalty_discount). Tiered member pricing: the lapsed price (return_price), for a card holder who has
 * not had the service within the period, and the maintenance price (loyalty_price) for everyone else with an active card. Regular price ₱1,000; "today"
 * is 2026-10-01.
 */
class LoyaltyPricesTest extends TestCase
{
    use BuildsSalon, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSalon();
        $this->service->update(['loyalty_enabled' => true]);
    }

    public function test_the_admin_picks_less_or_tiered_pricing_with_a_note_for_each_box(): void
    {
        $this->actingAs(User::factory()->admin()->create(), 'web');

        // Regular price first, then the loyalty prices: a tab each for Less and Tiered (lapsed box first, maintenance second).
        $this->get("/services/{$this->service->id}/edit")->assertOk()
            ->assertSeeInOrder(['Pricing &amp; timing', 'Loyalty card prices', 'Less (discount)', 'Tiered member pricing',
                'How Less works', 'name="loyalty_discount"', 'name="loyalty_discount_type"',
                'How tiered pricing works', 'Lapsed price', 'Maintenance price', 'Enable lapsed price'], false)
            ->assertSee('name="loyalty_pricing" value="less"', false) // a new or untouched service starts on Less
            ->assertSeeInOrder(['name="return_price"', 'name="loyalty_price"'], false)
            ->assertSee('No visit within the period: card holder who has not had this service within the period below.')
            ->assertSee('Visited within the period: card holder who had this service within the period below.')
            ->assertSee('Example: regular ₱1,500, lapsed price ₱1,000, maintenance price ₱500.')
            ->assertSee('A card holder who had this service in the last 30 days pays ₱500; one who has not pays ₱1,000.');

        $save = fn (array $prices) => $this->put("/services/{$this->service->id}", $prices + [
            'category_id' => $this->service->category_id, 'name' => $this->service->name, 'regular_price' => 1000,
            'duration_minutes' => 60, 'status' => 'active', 'branches' => [$this->branch->id => ['enabled' => '1']], 'staff' => [$this->stylist->id],
            'loyalty_enabled' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $save(['loyalty_pricing' => 'tiered', 'loyalty_discount' => '200', 'return_price' => '800', 'loyalty_price' => '500',
            'first_visit_discount_enabled' => '1', 'first_visit_discount_period' => 30, 'first_visit_discount_unit' => 'days']);
        $this->service->refresh();
        $this->assertSame(['tiered', '500.00', '800.00', true], [$this->service->loyalty_pricing, $this->service->loyalty_price, $this->service->return_price, $this->service->first_visit_discount_enabled]);
        $this->get("/services/{$this->service->id}/edit")->assertSee('name="loyalty_pricing" value="tiered"', false);

        // Back to Less, 50% off: the tiered prices are kept for later, but only the discount is used.
        $save(['loyalty_pricing' => 'less', 'loyalty_discount' => '50', 'loyalty_discount_type' => 'percent', 'return_price' => '800', 'loyalty_price' => '500']);
        $this->service->refresh();
        $this->assertSame(['less', '50.00', 'percent', '500.00'], [$this->service->loyalty_pricing, $this->service->loyalty_discount, $this->service->loyalty_discount_type, $this->service->loyalty_price]);

        // A percent can't go over 100; a blank box (or 0) means that price is not used.
        $this->put("/services/{$this->service->id}", ['loyalty_discount' => '150', 'loyalty_discount_type' => 'percent'])->assertSessionHasErrors('loyalty_discount');
        $save(['loyalty_pricing' => 'tiered', 'loyalty_discount' => '', 'return_price' => '', 'loyalty_price' => '0']);
        $this->service->refresh();
        $this->assertSame(['0.00', null, null], [$this->service->loyalty_discount, $this->service->loyalty_price, $this->service->return_price]);
    }

    public function test_the_switch_turns_loyalty_prices_off_without_losing_them(): void
    {
        $admin = User::factory()->admin()->create();
        $holder = $this->cardHolder();
        $this->actingAs($admin, 'web');

        // A new service starts with the switch off.
        $this->get('/services/create')->assertOk()
            ->assertSee('role="switch"', false)->assertSee('name="loyalty_enabled"', false)->assertSee('loyaltyOn: false', false)
            ->assertSee('Loyalty card prices are off for this service');

        // Signs the admin back in each time: loyaltyPriceFor() switches to the customer's app login.
        $editPage = fn () => $this->actingAs($admin, 'web')->get("/services/{$this->service->id}/edit")->assertOk();
        $save = fn (array $values) => $this->actingAs($admin, 'web')->put("/services/{$this->service->id}", $values + [
            'category_id' => $this->service->category_id, 'name' => $this->service->name, 'regular_price' => 1000, 'duration_minutes' => 60,
            'status' => 'active', 'branches' => [$this->branch->id => ['enabled' => '1']], 'staff' => [$this->stylist->id],
            'loyalty_pricing' => 'less', 'loyalty_discount' => '200', 'loyalty_discount_type' => 'amount',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $save(['loyalty_enabled' => '1']);
        $this->assertSame(800, $this->loyaltyPriceFor($holder));
        $editPage()->assertSee('loyaltyOn: true', false);

        // Off: the regular price everywhere (app, booking, card perks), but the Less ₱200 stays saved.
        $save(['loyalty_enabled' => '0']);
        $this->service->refresh();
        $this->assertSame([false, '200.00'], [$this->service->loyalty_enabled, $this->service->loyalty_discount]);
        $this->assertNull($this->loyaltyPriceFor($holder));
        $this->getJson("/api/v1/services/{$this->service->id}")->assertJsonPath('data.loyalty_enabled', false)
            ->assertJsonPath('data.loyalty_rule', null)->assertJsonPath('data.loyalty_less', null);
        $this->postJson('/api/v1/appointments', $this->bookingPayload())->assertCreated()->assertJsonPath('data.total_amount', 1000);
        $this->getJson('/api/v1/settings')->assertJsonPath('data.loyalty_service_prices', []);
        $editPage()->assertSee('loyaltyOn: false', false)->assertSee('value="200"', false);

        // Back on: the saved discount applies again.
        $save(['loyalty_enabled' => '1']);
        $this->assertSame(800, $this->loyaltyPriceFor($holder));
    }

    public function test_less_takes_an_amount_or_percent_off_every_option(): void
    {
        $holder = $this->cardHolder();
        $long = $this->service->variants()->create(['name' => 'Long', 'price' => 2000, 'duration_minutes' => 60]);
        $withLong = ['services' => [['service_id' => $this->service->id, 'service_variant_id' => $long->id]]];

        // Less ₱200: ₱1,000 → ₱800, and the ₱2,000 option → ₱1,800.
        $this->service->update(['loyalty_pricing' => 'less', 'loyalty_discount' => 200, 'loyalty_discount_type' => 'amount']);
        $this->assertSame(800, $this->loyaltyPriceFor($holder));
        $this->getJson("/api/v1/services/{$this->service->id}")->assertJsonPath('data.loyalty_rule', ['type' => 'amount', 'value' => 200]);
        $this->postJson('/api/v1/appointments', $this->bookingPayload($withLong))->assertCreated()->assertJsonPath('data.total_amount', 1800);

        // 50% off: ₱1,000 → ₱500, and the ₱2,000 option → ₱1,000.
        $this->service->update(['loyalty_discount' => 50, 'loyalty_discount_type' => 'percent']);
        $this->assertSame(500, $this->loyaltyPriceFor($holder));
        $this->postJson('/api/v1/appointments', $this->bookingPayload($withLong + ['start_time' => '13:00']))->assertCreated()->assertJsonPath('data.total_amount', 1000);

        // Tiered prices left over from before are ignored while Less is picked; no card means the regular price.
        $this->service->update(['loyalty_price' => 100]);
        $this->assertSame(500, $this->loyaltyPriceFor($holder));
        $this->assertNull($this->loyaltyPriceFor($this->customer()));
    }

    public function test_tiered_card_holders_pay_the_maintenance_price_and_the_lapsed_price_after_no_visit(): void
    {
        $this->service->update(['loyalty_pricing' => 'tiered', 'loyalty_price' => 500, 'return_price' => 800, 'first_visit_discount_enabled' => true,
            'first_visit_discount_period' => 30, 'first_visit_discount_unit' => 'days']);

        // Signed up today: their first visit counts from signup, so they are within the 30 days.
        $this->assertSame(500, $this->loyaltyPriceFor($this->cardHolder()));

        // Signed up 60 days ago and never had the service: the return price, in the app and when booking.
        $lapsed = $this->cardHolder(['created_at' => now()->subDays(60)]);
        $this->assertSame(800, $this->loyaltyPriceFor($lapsed));
        $this->getJson("/api/v1/services/{$this->service->id}")->assertJsonPath('data.loyalty_rule', ['type' => 'price', 'value' => 800]);
        $this->postJson('/api/v1/appointments', $this->bookingPayload())->assertCreated()->assertJsonPath('data.total_amount', 800);

        // A visit 40 days ago is outside the period; one 10 days ago is inside it.
        $this->completedVisit($lapsed, 40);
        $this->assertSame(800, $this->loyaltyPriceFor($lapsed));
        $this->completedVisit($lapsed, 10);
        $this->assertSame(500, $this->loyaltyPriceFor($lapsed));

        // With the return price turned off, every card holder pays the card holder price.
        $this->service->update(['first_visit_discount_enabled' => false]);
        $this->assertSame(500, $this->loyaltyPriceFor($this->cardHolder(['created_at' => now()->subDays(60)])));

        // No card, or an expired one: the regular price.
        $this->assertNull($this->loyaltyPriceFor($this->customer()));
        $expired = $this->cardHolder();
        $expired->forceFill(['loyalty_expires_at' => now()->subDay()->toDateString()])->save();
        $this->assertNull($this->loyaltyPriceFor($expired));
    }

    public function test_a_cheaper_option_wins_and_an_empty_box_falls_back(): void
    {
        $this->service->update(['loyalty_pricing' => 'tiered', 'loyalty_price' => 500, 'return_price' => null, 'first_visit_discount_enabled' => true,
            'first_visit_discount_period' => 30, 'first_visit_discount_unit' => 'days']);

        // No return price: a lapsed card holder pays the card holder price.
        $lapsed = $this->cardHolder(['created_at' => now()->subDays(60)]);
        $this->assertSame(500, $this->loyaltyPriceFor($lapsed));

        // An option already cheaper than the loyalty price is charged at its own price.
        $short = $this->service->variants()->create(['name' => 'Short', 'price' => 400, 'duration_minutes' => 60]);
        $this->postJson('/api/v1/appointments', $this->bookingPayload(['services' => [['service_id' => $this->service->id, 'service_variant_id' => $short->id]]]))
            ->assertCreated()->assertJsonPath('data.total_amount', 400);

        // No card holder price: a card holder within the period pays the regular price.
        $this->service->update(['loyalty_price' => null, 'return_price' => 800]);
        $this->assertNull($this->loyaltyPriceFor($this->cardHolder()));
    }

    private function cardHolder(array $attributes = []): User
    {
        $customer = $this->customer($attributes);
        $customer->forceFill(['loyalty_number' => fake()->unique()->numerify('#######')])->save();

        return $customer;
    }

    /** The loyalty price the app shows this customer for the service (null = regular price). */
    private function loyaltyPriceFor(User $customer): mixed
    {
        Sanctum::actingAs($customer);

        return $this->getJson("/api/v1/services/{$this->service->id}")->assertOk()->json('data.loyalty_discounted_price');
    }

    private function completedVisit(User $customer, int $daysAgo): void
    {
        $at = now()->subDays($daysAgo);
        $appointment = Appointment::factory()->at($at->format('Y-m-d').' 10:00')->status(AppointmentStatus::Completed)
            ->create(['customer_id' => $customer->id, 'branch_id' => $this->branch->id, 'completed_at' => $at]);
        $appointment->items()->create(['service_id' => $this->service->id, 'service_name' => $this->service->name, 'unit_price' => 1000, 'duration_minutes' => 60, 'subtotal' => 1000]);
    }
}
