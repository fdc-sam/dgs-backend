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

        $this->putJson('/api/v1/auth/loyalty-card', ['loyalty_number' => '1234 5678-9012'])->assertOk()
            ->assertJsonPath('message', 'Loyalty card saved.')
            ->assertJsonPath('data.loyalty_number', '123456789012'); // spaces and dashes dropped
        $this->getJson('/api/v1/auth/me')->assertJsonPath('data.loyalty_number', '123456789012');
        $this->assertTrue(AuditLog::where('action', 'customer.loyalty_number')->where('entity_id', $customer->id)->exists());

        // Saving the same number again is fine; removing frees it.
        $this->putJson('/api/v1/auth/loyalty-card', ['loyalty_number' => '123456789012'])->assertOk();
        $this->deleteJson('/api/v1/auth/loyalty-card')->assertOk()->assertJsonPath('data.loyalty_number', null);
    }

    public function test_numbers_are_digits_of_the_configured_length_and_belong_to_one_customer(): void
    {
        $this->actingAsCustomer();
        foreach (['1234567', 'ABCD12345678', '123456789012345678901', ''] as $bad) {
            $this->putJson('/api/v1/auth/loyalty-card', ['loyalty_number' => $bad])->assertUnprocessable()->assertJsonValidationErrors('loyalty_number');
        }
        $this->putJson('/api/v1/auth/loyalty-card', ['loyalty_number' => '1234567'])
            ->assertJsonPath('errors.loyalty_number.0', 'Enter the 8 to 20 digit number printed on the loyalty card.');

        $this->putJson('/api/v1/auth/loyalty-card', ['loyalty_number' => '12345678'])->assertOk();
        Sanctum::actingAs($this->customer());
        $this->putJson('/api/v1/auth/loyalty-card', ['loyalty_number' => '1234 5678'])->assertUnprocessable()
            ->assertJsonPath('errors.loyalty_number.0', 'This loyalty card is already linked to another account. Please check the number.');

        config(['salon.loyalty.min_digits' => 12, 'salon.loyalty.max_digits' => 12]);
        $this->putJson('/api/v1/auth/loyalty-card', ['loyalty_number' => '1111 2222 333'])->assertUnprocessable();
        $this->putJson('/api/v1/auth/loyalty-card', ['loyalty_number' => '1111 2222 3333'])->assertOk();
    }

    public function test_the_salon_links_finds_and_removes_a_card_in_the_admin(): void
    {
        $customer = $this->customer(['name' => 'Ana Reyes']);
        $other = $this->customer();
        $other->forceFill(['loyalty_number' => '99998888'])->save();
        $this->actingAs(User::factory()->admin()->create(), 'web');

        $this->get("/customers/{$customer->id}")->assertOk()->assertSee('No loyalty card linked yet.')->assertSee('Link card');
        $this->post("/customers/{$customer->id}/loyalty-card", ['loyalty_number' => '9999 8888'])->assertSessionHasErrors('loyalty_number');
        $this->post("/customers/{$customer->id}/loyalty-card", ['loyalty_number' => '5555 6666 7777'])->assertRedirect()
            ->assertSessionHas('success', 'Loyalty card 5555 6666 7777 linked to Ana Reyes.');
        $this->assertSame('555566667777', $customer->fresh()->loyalty_number);
        $this->get("/customers/{$customer->id}")->assertSee('5555 6666 7777');

        $this->get('/customers?search=5555%206666%207777')->assertOk()
            ->assertViewHas('customers', fn ($page) => $page->total() === 1 && $page->first()->is($customer));

        $this->post("/customers/{$customer->id}/loyalty-card", ['loyalty_number' => ''])->assertSessionHas('success', 'Loyalty card removed from Ana Reyes.');
        $this->assertNull($customer->fresh()->loyalty_number);
    }

    public function test_staff_cannot_change_cards_and_the_admin_app_finds_customers_by_card(): void
    {
        $customer = $this->customer();
        $customer->forceFill(['loyalty_number' => '123412341234'])->save();

        $this->actingAs($this->stylist->user, 'web');
        $this->post("/customers/{$customer->id}/loyalty-card", ['loyalty_number' => '11112222'])->assertForbidden();

        Sanctum::actingAs(User::factory()->admin()->create(), ['admin-app']);
        $this->getJson('/api/v1/admin/customers/lookup?code=1234%201234%201234')->assertOk()
            ->assertJsonPath('data.customer.id', $customer->id)->assertJsonPath('data.customer.loyalty_number', '123412341234');
        $this->getJson('/api/v1/admin/customers/lookup?code=00000000')->assertNotFound();
    }

    public function test_deleting_the_account_frees_the_card_number(): void
    {
        $customer = $this->actingAsCustomer();
        $this->putJson('/api/v1/auth/loyalty-card', ['loyalty_number' => '12345678'])->assertOk();

        app(AccountDeletionService::class)->delete($customer);

        $this->assertNull(User::withTrashed()->find($customer->id)->loyalty_number);
        Sanctum::actingAs($this->customer());
        $this->putJson('/api/v1/auth/loyalty-card', ['loyalty_number' => '12345678'])->assertOk();
    }
}
