<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\AccountDeletionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsSalon;
use Tests\TestCase;

class LoyaltyCardTest extends TestCase
{
    use BuildsSalon, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSalon();
    }

    public function test_a_customer_links_the_card_they_bought(): void
    {
        $customer = $this->actingAsCustomer();
        $this->getJson('/api/v1/auth/me')->assertJsonPath('data.loyalty_number', null);

        // Typed as printed on the card ("CARD NO. LC-0000353"): only the digits are kept, leading zeros included.
        $this->putJson('/api/v1/auth/loyalty-card', ['loyalty_number' => 'LC-0000353'])->assertOk()
            ->assertJsonPath('message', 'Loyalty card saved.')
            ->assertJsonPath('data.loyalty_number', '0000353');
        $this->getJson('/api/v1/auth/me')->assertJsonPath('data.loyalty_number', '0000353');
        $this->assertTrue(AuditLog::where('action', 'customer.loyalty_number')->where('entity_id', $customer->id)->exists());

        // Saving the same number again is fine (with or without the prefix); removing frees it.
        $this->putJson('/api/v1/auth/loyalty-card', ['loyalty_number' => '0000 353'])->assertOk();
        $this->putJson('/api/v1/auth/loyalty-card', ['loyalty_number' => 'lc0000353'])->assertOk()->assertJsonPath('data.loyalty_number', '0000353');
        $this->deleteJson('/api/v1/auth/loyalty-card')->assertOk()->assertJsonPath('data.loyalty_number', null);
    }

    public function test_numbers_are_digits_of_the_configured_length_and_belong_to_one_customer(): void
    {
        $this->actingAsCustomer();
        foreach (['353', '000035', '00003533', 'LC-ABCD353', 'XY-0000353', ''] as $bad) {
            $this->putJson('/api/v1/auth/loyalty-card', ['loyalty_number' => $bad])->assertUnprocessable()->assertJsonValidationErrors('loyalty_number');
        }
        $this->putJson('/api/v1/auth/loyalty-card', ['loyalty_number' => '353'])
            ->assertJsonPath('errors.loyalty_number.0', 'Enter the 7-digit card number printed on the loyalty card, e.g. LC-0000353.');

        $this->putJson('/api/v1/auth/loyalty-card', ['loyalty_number' => '0000353'])->assertOk();
        Sanctum::actingAs($this->customer());
        $this->putJson('/api/v1/auth/loyalty-card', ['loyalty_number' => 'LC-0000353'])->assertUnprocessable()
            ->assertJsonPath('errors.loyalty_number.0', 'This loyalty card is already linked to another account. Please check the number.');

        // The salon can change the length (and prefix) in config if new cards are printed differently.
        config(['salon.loyalty.min_digits' => 10, 'salon.loyalty.max_digits' => 10]);
        $this->putJson('/api/v1/auth/loyalty-card', ['loyalty_number' => 'LC-0000354'])->assertUnprocessable()
            ->assertJsonPath('errors.loyalty_number.0', 'Enter the 10-digit card number printed on the loyalty card, e.g. LC-0000000353.');
        $this->putJson('/api/v1/auth/loyalty-card', ['loyalty_number' => 'LC-0000000354'])->assertOk();
    }

    public function test_the_salon_links_finds_and_removes_a_card_in_the_admin(): void
    {
        $customer = $this->customer(['name' => 'Ana Reyes']);
        $other = $this->customer();
        $other->forceFill(['loyalty_number' => '0000353'])->save();
        $this->actingAs(User::factory()->admin()->create(), 'web');

        $this->get("/customers/{$customer->id}")->assertOk()->assertSee('No loyalty card linked yet.')->assertSee('Link card');
        $this->post("/customers/{$customer->id}/loyalty-card", ['loyalty_number' => 'LC-0000353'])->assertSessionHasErrors('loyalty_number');
        $this->post("/customers/{$customer->id}/loyalty-card", ['loyalty_number' => '0000354'])->assertRedirect()
            ->assertSessionHas('success', 'Loyalty card LC-0000354 linked to Ana Reyes.');
        $this->assertSame('0000354', $customer->fresh()->loyalty_number);
        $this->get("/customers/{$customer->id}")->assertSee('LC-0000354');

        $this->get('/customers?search=LC-0000354')->assertOk()
            ->assertViewHas('customers', fn ($page) => $page->total() === 1 && $page->first()->is($customer));
        // The list marks card holders with their card number; customers without a card get no chip.
        $this->get('/customers')->assertOk()->assertSee('title="Loyalty card holder"', false)
            ->assertSee('data-href="'.route('customers.show', $customer).'"', false) // the whole row opens the customer
            ->assertSeeInOrder(['Ana Reyes', 'LC-0000354'])->assertSee('LC-0000353');

        $this->post("/customers/{$customer->id}/loyalty-card", ['loyalty_number' => ''])->assertSessionHas('success', 'Loyalty card removed from Ana Reyes.');
        $this->assertNull($customer->fresh()->loyalty_number);
    }

    public function test_staff_cannot_change_cards_and_the_admin_app_finds_customers_by_card(): void
    {
        $customer = $this->customer();
        $customer->forceFill(['loyalty_number' => '0000353'])->save();

        $this->actingAs($this->stylist->user, 'web');
        $this->post("/customers/{$customer->id}/loyalty-card", ['loyalty_number' => '0000354'])->assertForbidden();

        Sanctum::actingAs(User::factory()->admin()->create(), ['admin-app']);
        $this->getJson('/api/v1/admin/customers/lookup?code=LC-0000353')->assertOk()
            ->assertJsonPath('data.customer.id', $customer->id)->assertJsonPath('data.customer.loyalty_number', '0000353');
        $this->getJson('/api/v1/admin/customers/lookup?code=0000999')->assertNotFound();
    }

    public function test_deleting_the_account_frees_the_card_number(): void
    {
        $customer = $this->actingAsCustomer();
        $this->putJson('/api/v1/auth/loyalty-card', ['loyalty_number' => 'LC-0000353'])->assertOk();

        app(AccountDeletionService::class)->delete($customer);

        $this->assertNull(User::withTrashed()->find($customer->id)->loyalty_number);
        Sanctum::actingAs($this->customer());
        $this->putJson('/api/v1/auth/loyalty-card', ['loyalty_number' => 'LC-0000353'])->assertOk();
    }
}
