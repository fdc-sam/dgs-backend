<?php

namespace Tests\Feature\Api;

use App\Enums\LoyaltyCardRequestStatus;
use App\Models\LoyaltyCard;
use App\Models\LoyaltyCardRequest;
use App\Models\User;
use App\Notifications\BroadcastNotification;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsSalon;
use Tests\TestCase;

/**
 * Loyalty card through a friend: a customer scans the QR of another customer, pays the ₱200 card price to
 * the salon by GCash or bank (reference number only); the admin approves, the digital card is linked and the friend's
 * ₱100 reward goes into their earnings. "Today" is 2026-10-01.
 */
class LoyaltyCardRequestTest extends TestCase
{
    use BuildsSalon, RefreshDatabase;

    private User $admin;

    private User $endorser;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSalon();
        Storage::fake('local');
        Storage::fake('public');
        Notification::fake();
        $this->admin = User::factory()->admin()->create();
        $this->setting(['payment_gcash_account_name' => 'DGS SALON', 'payment_gcash_number' => '09171234567']);
        $this->endorser = $this->customer(['name' => 'Maria Santos']);
        $this->endorser->forceFill(['loyalty_number' => '0000100'])->save();
        LoyaltyCard::create(['number' => '0000100', 'customer_id' => $this->endorser->id]);
        LoyaltyCard::create(['number' => '0000101']);
        LoyaltyCard::create(['number' => '0000102']);
    }

    public function test_the_app_gets_the_offer_and_its_payment_details_from_settings(): void
    {
        $this->getJson('/api/v1/settings')
            ->assertJsonPath('data.loyalty_endorsement.enabled', true)
            // Until a card price is saved, the old split (₱100 GCash + ₱100 cash) stays the price.
            ->assertJsonPath('data.loyalty_endorsement.card_price', 200)
            ->assertJsonPath('data.loyalty_endorsement.gcash_amount', 200)
            ->assertJsonPath('data.loyalty_endorsement.endorser_cash', 0)
            ->assertJsonPath('data.loyalty_endorsement.endorser_reward', 100)
            ->assertJsonPath('data.loyalty_endorsement.gcash_enabled', true)
            ->assertJsonPath('data.loyalty_endorsement.gcash_number', '09171234567')
            ->assertJsonPath('data.loyalty_endorsement.gcash_account_name', 'DGS SALON')
            ->assertJsonPath('data.loyalty_endorsement.bank_enabled', false)
            ->assertJsonPath('data.loyalty_endorsement.bank_account_number', null);

        // Bank transfer shows once an account number (or bank QR) is set.
        $this->setting(['loyalty_endorsement_bank_name' => 'BPI', 'loyalty_endorsement_bank_account_number' => '1234 5678 90']);
        $this->getJson('/api/v1/settings')
            ->assertJsonPath('data.loyalty_endorsement.bank_enabled', true)
            ->assertJsonPath('data.loyalty_endorsement.bank_name', 'BPI')
            ->assertJsonPath('data.loyalty_endorsement.bank_account_number', '1234 5678 90');
        $this->setting(['loyalty_endorsement_bank_account_number' => '']);

        // Nowhere to pay → off, whatever the switch says.
        $this->setting(['payment_gcash_number' => '']);
        $this->getJson('/api/v1/settings')->assertJsonPath('data.loyalty_endorsement.enabled', false);

        $this->setting(['payment_gcash_number' => '09171234567', 'loyalty_endorsement_enabled' => false]);
        $this->getJson('/api/v1/settings')->assertJsonPath('data.loyalty_endorsement.enabled', false);
        $this->actingAsCustomer();
        $this->getJson('/api/v1/loyalty-card-requests/endorser?code='.$this->endorser->qr_code)->assertStatus(409);
    }

    public function test_a_scanned_code_must_belong_to_another_customer_but_the_endorser_needs_no_card(): void
    {
        $customer = $this->actingAsCustomer();
        $lookup = fn (string $code) => $this->getJson('/api/v1/loyalty-card-requests/endorser?code='.urlencode($code));

        $lookup('hello')->assertUnprocessable()->assertJsonValidationErrors('code');
        $lookup('DGS-AAAA-BBBB-CCCC-DDDD-EEEE')->assertUnprocessable()->assertJsonValidationErrors('code');
        $lookup($customer->qr_code)->assertUnprocessable()
            ->assertJsonPath('errors.code.0', "This is your own invite code. Enter the code of the friend who invited you.");

        $noCard = $this->customer(['name' => 'Jun Cruz']);
        $lookup($noCard->qr_code)->assertOk()
            ->assertJsonPath('data.endorser.name', 'Jun C.')
            ->assertJsonPath('data.endorser.code', $noCard->qr_code);

        // Scanned as the phone reads it: lower case, no dashes.
        $lookup(strtolower(str_replace('-', '', $this->endorser->qr_code)))->assertOk()
            ->assertJsonPath('data.endorser.name', 'Maria S.')
            ->assertJsonPath('data.endorser.code', $this->endorser->qr_code);
    }

    public function test_a_customer_sends_the_reference_number_and_the_next_free_card_is_set_aside(): void
    {
        $customer = $this->actingAsCustomer($this->customer(['name' => 'Ana Reyes']));

        $this->submit(['reference_number' => null])->assertUnprocessable()->assertJsonValidationErrors('reference_number');
        $this->submit(['payment_method' => 'cash'])->assertUnprocessable()->assertJsonValidationErrors('payment_method');
        // No bank details set up yet.
        $this->submit(['payment_method' => 'bank'])->assertUnprocessable()->assertJsonValidationErrors('payment_method');

        // Sent as JSON with no screenshot; an older app's screenshot and "cash given" are ignored.
        $this->submit(['proof' => UploadedFile::fake()->image('receipt.png', 400, 800), 'cash_given' => '1'], asJson: false)->assertCreated()
            ->assertJsonPath('message', "Payment sent! We'll activate your membership once we've checked it.")
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.status_label', 'Waiting for approval')
            ->assertJsonPath('data.endorser_name', 'Maria S.')
            ->assertJsonPath('data.payment_method', 'gcash')
            ->assertJsonPath('data.gcash_amount', 200)
            ->assertJsonPath('data.endorser_cash', 100)
            ->assertJsonPath('data.reference_number', '1234567890123')
            ->assertJsonPath('data.card_number', null);

        $request = LoyaltyCardRequest::sole();
        $this->assertSame('0000101', $request->loyaltyCard->number);
        $this->assertNull($request->proof_path);
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertFalse($request->endorser_paid_directly);
        Notification::assertSentTo($this->admin, BroadcastNotification::class, fn ($n) => $n->title === 'Membership payment to verify'
            && $n->data['loyalty_card_request_id'] === $request->id);
        Notification::assertSentTo($this->endorser, BroadcastNotification::class, fn ($n) => $n->title === 'Your friend is becoming a member'
            && $n->data['screen'] === 'endorsements');

        // One at a time, and the card waits for the salon.
        $this->submit(['reference_number' => '9999999999999'])->assertStatus(409)
            ->assertJsonPath('message', "Your membership payment is already with the salon. We'll let you know once it's approved.");
        $this->getJson('/api/v1/loyalty-card-requests')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.status', 'pending');
        $this->assertNull($customer->fresh()->loyalty_number);

        // Another customer gets the next card and cannot reuse the Ref No.
        $this->actingAsCustomer();
        $this->submit()->assertUnprocessable()->assertJsonValidationErrors('reference_number');
        $this->submit(['reference_number' => '5555 666 777'])->assertCreated();
        $this->assertSame('0000102', LoyaltyCardRequest::latest('id')->first()->loyaltyCard->number);

        // Out of generated cards: a new number after the highest. Paid by bank this time.
        $this->setting(['loyalty_endorsement_bank_account_number' => '1234567890']);
        $this->actingAsCustomer();
        $this->submit(['reference_number' => 'bpi-777888999', 'payment_method' => 'bank'])->assertCreated()
            ->assertJsonPath('data.payment_method', 'bank')
            ->assertJsonPath('data.reference_number', 'BPI777888999');
        $this->assertSame('0000103', LoyaltyCardRequest::latest('id')->first()->loyaltyCard->number);
        Notification::assertSentTo($this->admin, BroadcastNotification::class, fn ($n) => str_contains($n->body, 'by bank transfer (ref BPI777888999)'));
    }

    public function test_customers_who_already_have_a_card_are_told_so(): void
    {
        $active = $this->customer();
        $active->forceFill(['loyalty_number' => '0000200'])->save();
        $this->actingAsCustomer($active);
        $this->submit()->assertStatus(409)->assertJsonPath('message', 'You are already a DGS member.');

        $expired = $this->customer();
        $expired->forceFill(['loyalty_number' => '0000201', 'loyalty_expires_at' => '2026-09-01'])->save();
        $this->actingAsCustomer($expired);
        $this->submit()->assertStatus(409)->assertJsonPath('message', 'Your membership has expired. Please renew it at the salon.');
    }

    public function test_approving_links_the_digital_card_for_a_year_and_adds_the_friends_reward_to_their_earnings(): void
    {
        $customer = $this->actingAsCustomer($this->customer(['name' => 'Ana Reyes']));
        $this->submit()->assertCreated();
        $request = LoyaltyCardRequest::sole();

        $this->actingAs($this->admin, 'web');
        $this->get('/loyalty-cards/requests')->assertOk()->assertSee('Ana Reyes')->assertSee('Maria Santos')->assertSee('LC-0000101')->assertSee('1234567890123');
        $this->get("/loyalty-cards/requests/{$request->id}")->assertOk()->assertSee('Approve and activate')->assertSee('LC-0000101')
            ->assertSee('GCash payment')->assertSee('added to their Wallet when you approve');
        $this->get("/loyalty-cards/requests/{$request->id}/proof")->assertNotFound();
        $this->get('/loyalty-cards')->assertOk()->assertSee('Reserved · request')->assertSee('For Ana Reyes');

        $this->post("/loyalty-cards/requests/{$request->id}/approve")->assertRedirect("/loyalty-cards/requests/{$request->id}")
            ->assertSessionHas('success', "Approved. Member ID LC-0000101 is now active in Ana Reyes's app. It expires on Oct 1, 2027.");

        $customer->refresh();
        $this->assertSame('0000101', $customer->loyalty_number);
        $this->assertSame('2027-10-01', $customer->loyalty_expires_at->toDateString());
        $this->assertTrue($customer->hasActiveLoyaltyCard());
        $this->assertSame($customer->id, LoyaltyCard::where('number', '0000101')->value('customer_id'));
        $this->assertSame(LoyaltyCardRequestStatus::Approved, $request->fresh()->status);
        Notification::assertSentTo($customer, BroadcastNotification::class, fn ($n) => $n->title === 'Welcome to DGS Membership'
            && str_contains($n->body, 'LC-0000101') && $n->data['screen'] === 'loyalty_card');
        Notification::assertSentTo($this->endorser, BroadcastNotification::class, fn ($n) => $n->title === "You've earned ₱100.00"
            && str_contains($n->body, 'We added ₱100.00 to your Wallet'));

        // Twice does nothing.
        $this->post("/loyalty-cards/requests/{$request->id}/approve")->assertSessionHasErrors('request');

        Sanctum::actingAs($customer);
        $this->getJson('/api/v1/loyalty-card-requests')->assertJsonPath('data.0.status', 'approved')->assertJsonPath('data.0.card_number', 'LC-0000101');
        $this->getJson('/api/v1/auth/me')->assertJsonPath('data.loyalty_number', '0000101');

        Sanctum::actingAs($this->endorser);
        $this->getJson('/api/v1/loyalty-endorsements')->assertOk()
            ->assertJsonPath('data.can_endorse', true)
            ->assertJsonPath('data.endorser_cash', 100)
            ->assertJsonPath('data.summary.earned', 100)
            ->assertJsonPath('data.summary.approved_count', 1)
            ->assertJsonPath('data.summary.pending', 0)
            ->assertJsonPath('data.items.0.friend_name', 'Ana Reyes')
            ->assertJsonPath('data.items.0.status', 'approved')
            ->assertJsonPath('data.items.0.amount', 100);
        $this->getJson('/api/v1/earnings')->assertOk()
            ->assertJsonPath('data.balance', 100)
            ->assertJsonPath('data.card_endorsements', 100);
    }

    public function test_requests_from_before_the_change_paid_the_friend_in_cash_and_do_not_add_to_their_balance(): void
    {
        $this->actingAsCustomer();
        $this->submit()->assertCreated();
        $request = LoyaltyCardRequest::sole();
        $request->update(['endorser_paid_directly' => true]); // as the migration marks the old ones

        $this->actingAs($this->admin, 'web');
        $this->get("/loyalty-cards/requests/{$request->id}")->assertOk()->assertSee('cash to the friend who invited them');
        $this->post("/loyalty-cards/requests/{$request->id}/approve")->assertSessionHasNoErrors();
        Notification::assertSentTo($this->endorser, BroadcastNotification::class, fn ($n) => str_contains($n->body, 'cash from'));

        Sanctum::actingAs($this->endorser);
        $this->getJson('/api/v1/earnings')->assertJsonPath('data.balance', 0)->assertJsonPath('data.card_endorsements', 100);
    }

    public function test_a_card_linked_by_hand_meanwhile_is_swapped_for_the_next_free_one(): void
    {
        $customer = $this->actingAsCustomer();
        $this->submit()->assertCreated();
        $request = LoyaltyCardRequest::sole();

        // The customer page no longer offers the reserved card to others.
        $other = $this->customer();
        $this->actingAs($this->admin, 'web');
        $this->get("/customers/{$other->id}")->assertOk()->assertDontSee('LC-0000101')->assertSee('LC-0000102');

        // Typed in by hand anyway.
        $this->post("/customers/{$other->id}/loyalty-card", ['loyalty_number' => '0000101'])->assertSessionHasNoErrors();
        $this->post("/loyalty-cards/requests/{$request->id}/approve")->assertSessionHasNoErrors();
        $this->assertSame('0000102', $customer->fresh()->loyalty_number);
        $this->assertSame('0000102', $request->fresh()->loyaltyCard->number);
    }

    public function test_rejecting_frees_the_card_and_the_customer_can_send_a_new_receipt(): void
    {
        $customer = $this->actingAsCustomer();
        $this->submit()->assertCreated();
        $request = LoyaltyCardRequest::sole();

        $this->actingAs($this->admin, 'web');
        $this->post("/loyalty-cards/requests/{$request->id}/reject", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->post("/loyalty-cards/requests/{$request->id}/reject", ['reason' => "We couldn't find this Ref No. in our GCash history"])
            ->assertRedirect("/loyalty-cards/requests/{$request->id}");
        Notification::assertSentTo($customer, BroadcastNotification::class, fn ($n) => $n->title === 'We could not verify your payment'
            && str_contains($n->body, "We couldn't find this Ref No. in our GCash history. You can send"));
        $this->post("/loyalty-cards/requests/{$request->id}/approve")->assertSessionHasErrors('request');
        $this->assertNull($customer->fresh()->loyalty_number);

        Sanctum::actingAs($customer);
        $this->getJson('/api/v1/loyalty-card-requests')->assertJsonPath('data.0.status', 'rejected')
            ->assertJsonPath('data.0.status_label', 'Not approved')
            ->assertJsonPath('data.0.rejection_reason', "We couldn't find this Ref No. in our GCash history");
        // The same Ref No. may be sent again (e.g. after checking it), and the freed card is set aside again.
        $this->submit()->assertCreated();
        $this->assertSame('0000101', LoyaltyCardRequest::latest('id')->first()->loyaltyCard->number);

        Sanctum::actingAs($this->endorser);
        $this->getJson('/api/v1/loyalty-endorsements')
            ->assertJsonPath('data.summary.earned', 0)
            ->assertJsonPath('data.summary.pending', 100)
            ->assertJsonPath('data.summary.pending_count', 1)
            ->assertJsonCount(2, 'data.items');
    }

    public function test_a_waiting_request_goes_with_a_deleted_account_and_moves_with_a_test_account(): void
    {
        $customer = $this->actingAsCustomer($this->customer(['password' => 'secret123']));
        $this->submit()->assertCreated();
        $this->assertSame(1, LoyaltyCardRequest::query()->pending()->count());

        $customer->setTestAccount(true);
        $this->assertTrue(LoyaltyCardRequest::withoutGlobalScopes()->sole()->is_test);
        $customer->setTestAccount(false);

        $this->deleteJson('/api/v1/auth/account', ['password' => 'secret123'])->assertOk();
        $this->assertSame(0, LoyaltyCardRequest::withoutGlobalScopes()->count());
        // Its card number is free again.
        $this->actingAsCustomer();
        $this->submit()->assertCreated();
        $this->assertSame('0000101', LoyaltyCardRequest::sole()->loyaltyCard->number);
    }

    public function test_the_super_admin_sets_the_price_reward_and_payment_options_in_settings(): void
    {
        $this->actingAs(User::factory()->superAdmin()->create());
        $base = [
            'salon_name' => 'DGS Hair Salon & Spa', 'currency' => 'PHP', 'currency_symbol' => '₱',
            'booking_minimum_notice_hours' => 2, 'booking_maximum_days' => 60, 'booking_slot_interval_minutes' => 30,
            'cancellation_hours' => 24, 'payment_gcash_number' => '', 'payment_gcash_enabled' => '0',
            'loyalty_endorsement_enabled' => '1', 'loyalty_endorsement_card_price' => 250, 'loyalty_endorsement_endorser_cash' => 50,
        ];
        $this->get('/settings')->assertOk()->assertSee('Membership in the app (through a friend\'s invite)', false)->assertSee('Paid to the salon')
            ->assertSee('Bank QR code')->assertDontSee('Paid to the friend in cash');

        $this->put('/settings', $base)->assertSessionHasErrors('loyalty_endorsement_qr');
        // A bank QR alone is enough: customers then pay by bank only.
        $this->put('/settings', [...$base, 'loyalty_endorsement_bank_qr' => UploadedFile::fake()->image('bank.png', 400, 400),
            'loyalty_endorsement_bank_name' => ' BDO ', 'loyalty_endorsement_bank_account_name' => 'DGS SALON'])->assertSessionHasNoErrors();

        app(SettingsService::class)->flush();
        $this->getJson('/api/v1/settings')
            ->assertJsonPath('data.loyalty_endorsement.enabled', true)
            ->assertJsonPath('data.loyalty_endorsement.card_price', 250)
            ->assertJsonPath('data.loyalty_endorsement.endorser_reward', 50)
            ->assertJsonPath('data.loyalty_endorsement.gcash_enabled', false)
            ->assertJsonPath('data.loyalty_endorsement.bank_enabled', true)
            ->assertJsonPath('data.loyalty_endorsement.bank_name', 'BDO')
            ->assertJsonPath('data.loyalty_endorsement.bank_account_name', 'DGS SALON');
        $this->assertNotNull(app(SettingsService::class)->get('loyalty_endorsement_bank_qr'));
        $this->assertNotNull(json_decode($this->getJson('/api/v1/settings')->getContent(), true)['data']['loyalty_endorsement']['bank_qr_url']);

        $this->put('/settings', [...$base, 'loyalty_endorsement_qr' => UploadedFile::fake()->image('qr.png', 400, 400)])->assertSessionHasNoErrors();
        app(SettingsService::class)->flush();
        $this->getJson('/api/v1/settings')->assertJsonPath('data.loyalty_endorsement.gcash_enabled', true);
        $this->assertNotNull(app(SettingsService::class)->get('loyalty_endorsement_gcash_qr'));
    }

    private function submit(array $overrides = [], bool $asJson = true): \Illuminate\Testing\TestResponse
    {
        $payload = array_filter(array_replace([
            'code' => $this->endorser->qr_code,
            'payment_method' => 'gcash',
            'reference_number' => '1234 567 890 123',
        ], $overrides), fn ($v) => $v !== null);

        return $asJson
            ? $this->postJson('/api/v1/loyalty-card-requests', $payload)
            : $this->post('/api/v1/loyalty-card-requests', $payload, ['Accept' => 'application/json']);
    }

    private function setting(array $values): void
    {
        app(SettingsService::class)->update($values);
    }
}
