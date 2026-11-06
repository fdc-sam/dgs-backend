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
 * Loyalty card prices on a service (Services → edit): the return price, for a card holder who has not had the service
 * within the period, and the card holder price for everyone else with an active card. Regular price ₱1,000; "today"
 * is 2026-10-01.
 */
class LoyaltyPricesTest extends TestCase
{
    use BuildsSalon, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSalon();
    }

    public function test_the_admin_sets_both_prices_with_a_note_for_each_box(): void
    {
        $this->actingAs(User::factory()->admin()->create(), 'web');

        // Regular price first, then the loyalty prices: return price (first box), card holder price (second box).
        $this->get("/services/{$this->service->id}/edit")->assertOk()
            ->assertSeeInOrder(['Pricing &amp; timing', 'Loyalty card prices', 'How the prices are chosen', 'Return price', 'Card holder price', 'Enable return price'], false)
            ->assertSeeInOrder(['name="return_price"', 'name="loyalty_price"'], false)
            ->assertSee('Card holder with no visit for this service within the period below.')
            ->assertSee('Every other customer with an active loyalty card.')
            ->assertSee('A card holder who had this service in the last 30 days pays ₱500; one who has not pays ₱1,000.');

        $save = fn (array $prices) => $this->put("/services/{$this->service->id}", $prices + [
            'category_id' => $this->service->category_id, 'name' => $this->service->name, 'regular_price' => 1000,
            'duration_minutes' => 60, 'status' => 'active', 'branches' => [$this->branch->id => ['enabled' => '1']], 'staff' => [$this->stylist->id],
        ])->assertRedirect()->assertSessionHasNoErrors();

        $save(['return_price' => '800', 'loyalty_price' => '500', 'first_visit_discount_enabled' => '1', 'first_visit_discount_period' => 30, 'first_visit_discount_unit' => 'days']);
        $this->service->refresh();
        $this->assertSame(['500.00', '800.00', true], [$this->service->loyalty_price, $this->service->return_price, $this->service->first_visit_discount_enabled]);

        // A blank box (or 0) means that price is not used.
        $save(['return_price' => '', 'loyalty_price' => '0']);
        $this->service->refresh();
        $this->assertSame([null, null], [$this->service->loyalty_price, $this->service->return_price]);
    }

    public function test_card_holders_pay_the_card_holder_price_and_the_return_price_after_no_visit(): void
    {
        $this->service->update(['loyalty_price' => 500, 'return_price' => 800, 'first_visit_discount_enabled' => true,
            'first_visit_discount_period' => 30, 'first_visit_discount_unit' => 'days']);

        // Signed up today: their first visit counts from signup, so they are within the 30 days.
        $this->assertSame(500, $this->loyaltyPriceFor($this->cardHolder()));

        // Signed up 60 days ago and never had the service: the return price, in the app and when booking.
        $lapsed = $this->cardHolder(['created_at' => now()->subDays(60)]);
        $this->assertSame(800, $this->loyaltyPriceFor($lapsed));
        $this->getJson("/api/v1/services/{$this->service->id}")->assertJsonPath('data.loyalty_price', 800);
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
        $this->service->update(['loyalty_price' => 500, 'return_price' => null, 'first_visit_discount_enabled' => true,
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
