<?php

namespace Tests\Feature\Api;

use App\Models\AuditLog;
use App\Models\User;
use App\Services\AccountDeletionService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsSalon;
use Tests\TestCase;

/** Loyalty cards are linked and renewed by the salon in the admin; each is good for a year. "Today" is 2026-10-01. */
class LoyaltyCardTest extends TestCase
{
    use BuildsSalon, RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSalon();
    }

    public function test_customers_cannot_add_change_or_remove_their_card_in_the_app(): void
    {
        $customer = $this->actingAsCustomer();
        $this->getJson('/api/v1/auth/me')->assertJsonPath('data.loyalty_number', null)
            ->assertJsonPath('data.loyalty_expires_at', null)->assertJsonPath('data.loyalty_expired', false);

        // Older app versions still offer it: they are told to ask at the counter, and nothing changes.
        $this->putJson('/api/v1/auth/loyalty-card', ['loyalty_number' => 'LC-0000353'])->assertForbidden()
            ->assertJsonPath('message', 'Your loyalty card is added and renewed by the salon. Please ask at the counter.');
        $this->assertNull($customer->fresh()->loyalty_number);

        $customer->forceFill(['loyalty_number' => '0000353'])->save();
        $this->deleteJson('/api/v1/auth/loyalty-card')->assertForbidden();
        $this->assertSame('0000353', $customer->fresh()->loyalty_number);
    }

    public function test_numbers_are_digits_of_the_configured_length_and_belong_to_one_customer(): void
    {
        $customer = $this->customer();
        $this->actingAs(User::factory()->admin()->create(), 'web');
        $link = fn (string $number) => $this->post("/customers/{$customer->id}/loyalty-card", ['loyalty_number' => $number]);

        foreach (['353', '000035', '00003533', 'LC-ABCD353', 'XY-0000353'] as $bad) {
            $link($bad)->assertSessionHasErrors('loyalty_number');
        }
        $link('353')->assertSessionHasErrors(['loyalty_number' => 'Enter the 7-digit card number printed on the loyalty card, e.g. LC-0000353.']);

        $this->customer()->forceFill(['loyalty_number' => '0000353'])->save();
        $link('LC-0000353')->assertSessionHasErrors(['loyalty_number' => 'This loyalty card is already linked to another account. Please check the number.']);

        // The salon can change the length (and prefix) in config if new cards are printed differently.
        config(['salon.loyalty.min_digits' => 10, 'salon.loyalty.max_digits' => 10]);
        $link('LC-0000354')->assertSessionHasErrors(['loyalty_number' => 'Enter the 10-digit card number printed on the loyalty card, e.g. LC-0000000353.']);
        $link('LC-0000000354')->assertSessionHasNoErrors();
        $this->assertSame('0000000354', $customer->fresh()->loyalty_number);
    }

    public function test_the_salon_links_a_card_for_a_year_and_finds_and_removes_it_in_the_admin(): void
    {
        $customer = $this->customer(['name' => 'Ana Reyes']);
        $other = $this->customer();
        $other->forceFill(['loyalty_number' => '0000353'])->save();
        $this->actingAs(User::factory()->admin()->create(), 'web');
        $link = fn (string $number) => $this->post("/customers/{$customer->id}/loyalty-card", ['loyalty_number' => $number]);

        $this->get("/customers/{$customer->id}")->assertOk()->assertSee('No loyalty card linked yet.')->assertSee('Link card')
            ->assertSee('The card is good for 1 year from today.');
        $link('LC-0000353')->assertSessionHasErrors('loyalty_number');
        $link('0000354')->assertRedirect()->assertSessionHas('success', 'Loyalty card LC-0000354 linked to Ana Reyes. It expires on Oct 1, 2027.');
        $customer->refresh();
        $this->assertSame('0000354', $customer->loyalty_number);
        $this->assertSame('2027-10-01', $customer->loyalty_expires_at->toDateString());
        $this->assertTrue(AuditLog::where('action', 'customer.loyalty_number')->where('entity_id', $customer->id)
            ->get()->contains(fn ($log) => $log->new_values === ['loyalty_number' => '0000354', 'loyalty_expires_at' => '2027-10-01']));
        $this->get("/customers/{$customer->id}")->assertSee('LC-0000354')->assertSee('Expires on Oct 1, 2027')
            ->assertDontSee('Renew card')->assertSee('A new number counts as a new card and starts a fresh 1 year.');

        // Saving the same number later keeps its year; a different number is a new card with a year of its own.
        $this->travel(2)->months();
        $link('LC-0000354')->assertSessionHasNoErrors();
        $this->assertSame('2027-10-01', $customer->fresh()->loyalty_expires_at->toDateString());
        $link('0000355')->assertSessionHas('success', 'Loyalty card LC-0000355 linked to Ana Reyes. It expires on Dec 1, 2027.');

        $this->get('/customers?search=LC-0000355')->assertOk()
            ->assertViewHas('customers', fn ($page) => $page->total() === 1 && $page->first()->is($customer));
        // The list marks card holders with their card number; customers without a card get no chip.
        $this->get('/customers')->assertOk()->assertSee('title="Loyalty card holder · expires Dec 1, 2027"', false)
            ->assertSee('data-href="'.route('customers.show', $customer).'"', false) // the whole row opens the customer
            ->assertSeeInOrder(['Ana Reyes', 'LC-0000355'])->assertSee('LC-0000353')->assertDontSee('· Expired');

        $this->post("/customers/{$customer->id}/loyalty-card", ['loyalty_number' => ''])->assertSessionHas('success', 'Loyalty card removed from Ana Reyes.');
        $customer->refresh();
        $this->assertNull($customer->loyalty_number);
        $this->assertNull($customer->loyalty_expires_at);
    }

    public function test_a_card_expires_after_a_year_and_the_salon_renews_it(): void
    {
        $customer = $this->customer(['name' => 'Ana Reyes']);
        $customer->forceFill(['loyalty_number' => '0000353'])->save();
        $this->assertSame('2027-10-01', $customer->loyalty_expires_at->toDateString());
        $this->actingAs(User::factory()->admin()->create(), 'web');
        $renew = fn () => $this->post("/customers/{$customer->id}/loyalty-card/renew");

        // Too early: the card still has most of its year.
        $renew()->assertSessionHas('error', 'Loyalty card LC-0000353 does not expire until Oct 1, 2027. It can be renewed in its last 30 days or once it has expired.');

        // In its last 30 days the admin sees it is ending and can renew early: the new year is added on to the old one.
        $this->travelTo(CarbonImmutable::parse('2027-09-21 10:00'));
        $this->assertTrue($customer->fresh()->hasActiveLoyaltyCard());
        $this->get("/customers/{$customer->id}")->assertSee('Expires in 10 days')->assertSee('Renew card')
            ->assertSee('Adds 1 year to the current expiry date, so no days are lost.');
        $renew()->assertSessionHas('success', 'Loyalty card LC-0000353 renewed. It now expires on Oct 1, 2028.');

        // From the day it expires: a red "Expired" on the list, the customer's page and in the app.
        $this->travelTo(CarbonImmutable::parse('2028-10-01 09:00'));
        $this->assertTrue($customer->fresh()->loyaltyCardExpired());
        $this->get('/customers')->assertOk()->assertSee('title="Loyalty card expired on Oct 1, 2028"', false)
            ->assertSeeInOrder(['Ana Reyes', 'LC-0000353', '· Expired']);
        $this->get("/customers/{$customer->id}")->assertSee('aria-label="Loyalty card LC-0000353, Ana Reyes, expired"', false)
            ->assertSee('regular prices until it is renewed')->assertSee('Renew card')->assertSee('Good for 1 year from today.');

        // Renewing an expired card starts its new year on the day it is renewed.
        $this->travelTo(CarbonImmutable::parse('2028-11-15 09:00'));
        $renew()->assertSessionHas('success', 'Loyalty card LC-0000353 renewed. It now expires on Nov 15, 2029.');
        $this->assertTrue($customer->fresh()->hasActiveLoyaltyCard());
        $this->assertSame(2, AuditLog::where('action', 'customer.loyalty_renewed')->where('entity_id', $customer->id)->count());

        // A customer without a card has nothing to renew.
        $this->post("/customers/{$this->customer()->id}/loyalty-card/renew")->assertSessionHas('error');
    }

    public function test_the_app_shows_an_expired_card_and_it_gets_the_regular_prices(): void
    {
        $this->service->update(['loyalty_price' => 800]);
        $customer = $this->actingAsCustomer();
        $customer->forceFill(['loyalty_number' => '0000353'])->save();

        $this->getJson("/api/v1/services/{$this->service->id}")->assertOk()->assertJsonPath('data.loyalty_discounted_price', 800);
        $this->postJson('/api/v1/appointments', $this->bookingPayload())->assertCreated()->assertJsonPath('data.total_amount', 800);

        $this->travelTo(CarbonImmutable::parse('2027-10-01 08:00'));
        $this->getJson('/api/v1/auth/me')->assertJsonPath('data.loyalty_number', '0000353')
            ->assertJsonPath('data.loyalty_expires_at', '2027-10-01')->assertJsonPath('data.loyalty_expired', true);
        $this->getJson("/api/v1/services/{$this->service->id}")->assertOk()->assertJsonPath('data.loyalty_discounted_price', null);
        $this->postJson('/api/v1/appointments', $this->bookingPayload(['appointment_date' => '2027-10-02']))->assertCreated()
            ->assertJsonPath('data.total_amount', 1000);
    }

    public function test_staff_cannot_change_cards_and_the_admin_app_finds_customers_by_card(): void
    {
        $customer = $this->customer();
        $customer->forceFill(['loyalty_number' => '0000353'])->save();

        $this->actingAs($this->stylist->user, 'web');
        $this->post("/customers/{$customer->id}/loyalty-card", ['loyalty_number' => '0000354'])->assertForbidden();
        $this->post("/customers/{$customer->id}/loyalty-card/renew")->assertForbidden();

        Sanctum::actingAs(User::factory()->admin()->create(), ['admin-app']);
        $this->getJson('/api/v1/admin/customers/lookup?code=LC-0000353')->assertOk()
            ->assertJsonPath('data.customer.id', $customer->id)->assertJsonPath('data.customer.loyalty_number', '0000353')
            ->assertJsonPath('data.customer.loyalty_expires_at', '2027-10-01')->assertJsonPath('data.customer.loyalty_expired', false);
        $this->getJson('/api/v1/admin/customers/lookup?code=0000999')->assertNotFound();
    }

    public function test_deleting_the_account_frees_the_card_number(): void
    {
        $customer = $this->customer();
        $customer->forceFill(['loyalty_number' => '0000353'])->save();

        app(AccountDeletionService::class)->delete($customer);

        $deleted = User::withTrashed()->find($customer->id);
        $this->assertNull($deleted->loyalty_number);
        $this->assertNull($deleted->loyalty_expires_at);
        $this->actingAs(User::factory()->admin()->create(), 'web');
        $other = $this->customer();
        $this->post("/customers/{$other->id}/loyalty-card", ['loyalty_number' => 'LC-0000353'])->assertSessionHasNoErrors();
    }
}
