<?php

namespace Tests\Feature\Admin;

use App\Models\Promotion;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsSalon;
use Tests\TestCase;

/**
 * Promotions must never end up pricing services they were not made for: deleting a service or branch, editing an older
 * promotion, or saving a price without services.
 */
class PromotionRulesTest extends TestCase
{
    use BuildsSalon, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSalon();
    }

    public function test_deleting_the_service_a_promotion_is_for_does_not_make_it_apply_to_every_service(): void
    {
        $other = $this->service->replicate(['slug'])->fill(['name' => 'Rebond', 'slug' => 'rebond', 'regular_price' => 3000]);
        $other->save();
        $promo = Promotion::factory()->fixed(299)->create(['name' => 'Rebond ₱299']);
        $promo->services()->attach($other->id);

        $this->actingAs(User::factory()->admin()->create(), 'web');
        $this->delete("/services/{$other->id}")->assertRedirect();

        // Not offered in the app, and refused when booking another service with it.
        $this->getJson('/api/v1/promotions')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/promotions/{$promo->id}")->assertJsonPath('data.is_active', false)->assertJsonPath('data.services', []);
        $this->actingAsCustomer();
        $this->postJson('/api/v1/appointments', $this->bookingPayload(['promotion_id' => $promo->id]))
            ->assertStatus(422)->assertJsonValidationErrors('promotion_id');

        // The admin list says so, and the edit form explains it.
        $this->actingAs(User::factory()->admin()->create(), 'web');
        $this->get('/promotions')->assertOk()->assertSee('None (deleted)');
        $this->get("/promotions/{$promo->id}/edit")->assertOk()->assertSee('Deleted since this promotion was made: Rebond.');
    }

    public function test_deleting_the_branch_a_promotion_is_for_does_not_make_it_apply_to_every_branch(): void
    {
        $other = $this->branch->replicate(['slug'])->fill(['name' => 'Old Branch', 'slug' => 'old-branch']);
        $other->save();
        $promo = Promotion::factory()->create();
        $promo->branches()->attach($other->id);
        $other->delete();

        $this->getJson('/api/v1/promotions')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAsCustomer();
        $this->postJson('/api/v1/appointments', $this->bookingPayload(['promotion_id' => $promo->id]))
            ->assertStatus(422)->assertJsonValidationErrors('promotion_id');
    }

    public function test_editing_an_older_percentage_promotion_keeps_it_a_percentage(): void
    {
        $promo = Promotion::factory()->create(['discount_value' => 20]); // 20% off
        $promo->services()->attach($this->service->id);
        $this->actingAs(User::factory()->admin()->create(), 'web');

        $this->get("/promotions/{$promo->id}/edit")->assertOk()
            ->assertSee('name="discount_type" value="percentage"', false)->assertSee('Percent off (%)')
            ->assertSee('This promotion was made as')->assertDontSee('name="discount_type" value="fixed"', false);

        // What the form posts when only the end date is changed.
        $this->put("/promotions/{$promo->id}", [
            'name' => $promo->name, 'discount_type' => 'percentage', 'discount_value' => '20', 'status' => 'active',
            'start_at' => now()->subDay()->format('Y-m-d\TH:i'), 'end_at' => now()->addMonths(2)->format('Y-m-d\TH:i'),
            'services' => [$this->service->id],
        ])->assertSessionHasNoErrors()->assertRedirect();

        $promo->refresh();
        $this->assertSame(['percentage', '20.00'], [$promo->discount_type->value, $promo->discount_value]);
        $this->actingAsCustomer();
        $this->postJson('/api/v1/appointments', $this->bookingPayload(['promotion_id' => $promo->id]))
            ->assertCreated()->assertJsonPath('data.total_amount', 800); // ₱1,000 less 20%, not ₱20
    }

    public function test_a_price_needs_services_and_one_without_is_never_used(): void
    {
        $this->actingAs(User::factory()->admin()->create(), 'web');
        $this->post('/promotions', [
            'name' => 'Everything ₱299', 'discount_type' => 'fixed', 'discount_value' => '299', 'status' => 'active',
            'start_at' => now()->subDay()->format('Y-m-d\TH:i'), 'end_at' => now()->addMonth()->format('Y-m-d\TH:i'),
        ])->assertSessionHasErrors(['services' => 'Pick the services this price is for. A price can\'t apply to every service.']);
        $this->get('/promotions/create')->assertOk()->assertSee('Pick the services this price is for.');

        // One saved earlier without services is not offered and is refused when booking.
        $old = Promotion::factory()->fixed(299)->create();
        $this->getJson('/api/v1/promotions')->assertOk()->assertJsonCount(0, 'data');
        $this->actingAsCustomer();
        $this->postJson('/api/v1/appointments', $this->bookingPayload(['promotion_id' => $old->id]))
            ->assertStatus(422)->assertJsonValidationErrors('promotion_id');
        $this->actingAs(User::factory()->admin()->create(), 'web');
        $this->get('/promotions')->assertOk()->assertSee('None picked');

        // A percentage for every service is still allowed.
        $this->getJson('/api/v1/promotions'); // (no auth needed)
        Promotion::factory()->create();
        $this->getJson('/api/v1/promotions')->assertJsonCount(1, 'data');
    }
}
