<?php

namespace Tests\Feature\Api;

use App\Enums\LoyaltyCardRequestStatus;
use App\Models\LoyaltyCard;
use App\Models\LoyaltyCardRequest;
use App\Models\User;
use App\Notifications\BroadcastNotification;
use App\Services\EarningsService;
use App\Services\SettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\BuildsSalon;
use Tests\TestCase;

/**
 * Invite a friend to DGS Membership: Maria shares her link (/i/{code}); Ana signs up with it, pays for her membership
 * without typing any code, and Maria earns her reward once the salon approves it. A friend who signed up without the
 * link types the code within a week; staff can record the inviter when activating at the counter. "Today" is 2026-10-01.
 */
class InviteTest extends TestCase
{
    use BuildsSalon, RefreshDatabase;

    private User $maria;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSalon();
        Notification::fake();
        $this->setting(['payment_gcash_account_name' => 'DGS SALON', 'payment_gcash_number' => '09171234567']);
        $this->maria = $this->customer(['name' => 'Maria Santos']);
        LoyaltyCard::create(['number' => '0000101']);
        LoyaltyCard::create(['number' => '0000102']);
    }

    public function test_every_customer_gets_a_short_invite_code_with_their_first_name(): void
    {
        $this->assertMatchesRegularExpression('/^MARIA[A-HJ-NP-Z2-9]{4}$/', $this->maria->invite_code);
        $this->assertMatchesRegularExpression('/^DGS[A-HJ-NP-Z2-9]{4}$/', $this->customer(['name' => '李 Wei'])->invite_code);
        $this->assertMatchesRegularExpression('/^JOSE[A-HJ-NP-Z2-9]{4}$/', $this->customer(['name' => 'José Rizal'])->invite_code);
    }

    public function test_the_link_page_opens_the_app_or_the_play_store_with_the_code(): void
    {
        $this->get('/i/'.strtolower($this->maria->invite_code))->assertOk()
            ->assertSee('Maria S. invited you to')
            ->assertSee('intent://', false)
            ->assertSee('package=com.dgs.salon', false)
            ->assertSee('referrer=invite_code%3D'.$this->maria->invite_code, false)
            ->assertSee($this->maria->invite_code);

        $this->get('/i/NOBODY99')->assertOk()->assertSee('Join')->assertDontSee('invited you');

        // No certificate configured: an empty list (Android then shows the page, which opens the app with a tap).
        $this->getJson('/.well-known/assetlinks.json')->assertOk()->assertExactJson([]);
        config(['salon.android.cert_sha256' => ['AA:BB']]);
        $this->getJson('/.well-known/assetlinks.json')->assertOk()
            ->assertJsonPath('0.target.package_name', 'com.dgs.salon')
            ->assertJsonPath('0.target.sha256_cert_fingerprints', ['AA:BB']);
    }

    public function test_signing_up_with_the_link_remembers_the_friend_and_the_payment_needs_no_code(): void
    {
        $this->getJson('/api/v1/invites/'.$this->maria->invite_code)->assertOk()
            ->assertJsonPath('data.valid', true)->assertJsonPath('data.name', 'Maria S.');
        $this->getJson('/api/v1/invites/WRONG123')->assertOk()->assertJsonPath('data.valid', false);

        $this->register(['invite_code' => strtolower($this->maria->invite_code)])->assertCreated()
            ->assertJsonPath('data.user.invited_by.name', 'Maria S.')
            ->assertJsonPath('data.user.invited_by.code', $this->maria->invite_code);
        $ana = User::where('email', 'ana@example.com')->sole();
        $this->assertTrue($ana->invitedBy->is($this->maria));
        Notification::assertSentTo($this->maria, BroadcastNotification::class, fn ($n) => $n->title === 'Ana joined DGS');

        $this->actingAsCustomer($ana);
        $this->postJson('/api/v1/loyalty-card-requests', ['payment_method' => 'gcash', 'reference_number' => '1234567890123'])
            ->assertCreated()->assertJsonPath('data.endorser_name', 'Maria S.');
        $this->assertTrue(LoyaltyCardRequest::sole()->endorser->is($this->maria));

        // Maria's Invites page: her link, and Ana waiting for approval.
        $this->actingAsCustomer($this->maria);
        $this->getJson('/api/v1/invites')->assertOk()
            ->assertJsonPath('data.code', $this->maria->invite_code)
            ->assertJsonPath('data.link', url('/i/'.$this->maria->invite_code))
            ->assertJsonPath('data.reward', 100)
            ->assertJsonPath('data.can_enter_code', true)
            ->assertJsonPath('data.items.0.friend_name', 'Ana Reyes')
            ->assertJsonPath('data.items.0.status', 'pending')
            ->assertJsonPath('data.summary.pending', 100);
    }

    public function test_a_wrong_code_never_stops_the_sign_up(): void
    {
        $this->register(['invite_code' => 'NOPE12345'])->assertCreated()->assertJsonPath('data.user.invited_by', null);
    }

    public function test_friends_who_joined_show_until_they_pay_and_the_code_can_be_typed_within_a_week(): void
    {
        $ana = $this->customer(['name' => 'Ana Reyes']);
        $this->actingAsCustomer($ana);

        $this->postJson('/api/v1/invites/claim', ['code' => $ana->invite_code])->assertUnprocessable()
            ->assertJsonPath('errors.code.0', 'This is your own invite code. Enter the code of the friend who invited you.');
        $this->postJson('/api/v1/invites/claim', ['code' => 'NOPE12345'])->assertUnprocessable()->assertJsonValidationErrors('code');
        $this->postJson('/api/v1/invites/claim', ['code' => $this->maria->invite_code])->assertOk()
            ->assertJsonPath('message', 'Thanks! Maria S. invited you.');
        $this->postJson('/api/v1/invites/claim', ['code' => $this->maria->invite_code])->assertUnprocessable()
            ->assertJsonPath('errors.code.0', "You already joined through a friend's invite.");

        $this->actingAsCustomer($this->maria);
        $this->getJson('/api/v1/invites')->assertOk()
            ->assertJsonPath('data.items.0.friend_name', 'Ana Reyes')
            ->assertJsonPath('data.items.0.status', 'joined')
            ->assertJsonPath('data.summary.joined_count', 1);

        // Too late: more than a week after signing up.
        $late = $this->customer(['name' => 'Jun Cruz']);
        $late->forceFill(['created_at' => now()->subDays(8)])->save();
        $this->actingAsCustomer($late);
        $this->getJson('/api/v1/invites')->assertJsonPath('data.can_enter_code', false);
        $this->postJson('/api/v1/invites/claim', ['code' => $this->maria->invite_code])->assertUnprocessable();
    }

    public function test_without_an_inviter_the_payment_asks_for_a_code_and_remembers_it(): void
    {
        $ana = $this->actingAsCustomer($this->customer(['name' => 'Ana Reyes']));
        $pay = fn (array $extra = []) => $this->postJson('/api/v1/loyalty-card-requests', ['payment_method' => 'gcash', 'reference_number' => '1234567890123'] + $extra);

        $pay()->assertUnprocessable()->assertJsonPath('errors.code.0', 'Enter the invite code of the friend who invited you.');
        $pay(['code' => $this->maria->invite_code])->assertCreated();
        $this->assertTrue($ana->fresh()->invitedBy->is($this->maria));
    }

    public function test_a_pasted_invite_link_works_like_the_code(): void
    {
        $this->actingAsCustomer($this->customer(['name' => 'Ana Reyes']));
        $link = route('invite.show', $this->maria->invite_code);

        $this->getJson('/api/v1/loyalty-card-requests/endorser?code='.urlencode($link))
            ->assertOk()->assertJsonPath('data.endorser.name', 'Maria S.');
        $this->getJson('/api/v1/loyalty-card-requests/endorser?code='.strtolower($this->maria->invite_code))->assertOk();
    }

    public function test_staff_record_the_inviter_at_the_counter_and_the_friend_earns_the_reward(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $ana = $this->customer(['name' => 'Ana Reyes', 'phone' => '09181112222']);
        $card = LoyaltyCard::where('number', '0000101')->sole();

        $this->post("/customers/{$ana->id}/loyalty-card", ['loyalty_card_id' => $card->id, 'invited_by' => '0999 000 1111'])
            ->assertSessionHasErrors(['invited_by' => 'No customer found with that invite code or mobile number.']);
        $this->maria->forceFill(['phone' => '09190001111'])->save();

        $this->post("/customers/{$ana->id}/loyalty-card", ['loyalty_card_id' => $card->id, 'invited_by' => '+63 919 000 1111'])
            ->assertSessionHas('success', 'Ana Reyes is now a member (Member ID LC-0000101) until Oct 1, 2027. Maria S. earned ₱100.00 for inviting them.');

        $request = LoyaltyCardRequest::sole();
        $this->assertSame(LoyaltyCardRequest::METHOD_COUNTER, $request->payment_method);
        $this->assertSame(LoyaltyCardRequestStatus::Approved, $request->status);
        $this->assertTrue($ana->fresh()->invitedBy->is($this->maria));
        $this->assertSame(100.0, app(EarningsService::class)->summary($this->maria)['balance']);
        Notification::assertSentTo($this->maria, BroadcastNotification::class, fn ($n) => $n->title === "You've earned ₱100.00");
        Notification::assertNotSentTo($ana, BroadcastNotification::class);

        // Linking again (a different number) doesn't pay twice.
        $this->post("/customers/{$ana->id}/loyalty-card", ['loyalty_card_id' => LoyaltyCard::where('number', '0000102')->sole()->id]);
        $this->assertSame(1, LoyaltyCardRequest::count());
    }

    private function register(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/auth/register', array_replace([
            'name' => 'Ana Reyes',
            'email' => 'ana@example.com',
            'phone' => '09171112222',
            'password' => 'secret123',
            'password_confirmation' => 'secret123',
            'device_name' => 'Pixel',
        ], $overrides));
    }

    private function setting(array $values): void
    {
        app(SettingsService::class)->update($values);
    }
}
