<?php

namespace Tests\Feature\Api;

use App\Enums\CashOutStatus;
use App\Enums\ReferralStatus;
use App\Models\CashOutRequest;
use App\Models\Referral;
use App\Models\User;
use App\Notifications\BroadcastNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Laravel\Sanctum\Sanctum;
use Tests\Concerns\BuildsSalon;
use Tests\TestCase;

/**
 * Earnings and cash-outs: completed referral rewards add to the customer's balance; the customer asks for some or all of
 * it in GCash, the admin sends it and records the reference number, and only then is it taken off the balance.
 */
class CashOutTest extends TestCase
{
    use BuildsSalon, RefreshDatabase;

    private User $admin;

    private User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpSalon();
        Notification::fake();
        $this->admin = User::factory()->admin()->create();
        $this->customer = $this->cardHolder(['name' => 'Ana Reyes', 'phone' => '09170000001']);
        $this->reward(300);
    }

    public function test_the_app_shows_the_balance_and_suggests_the_customers_gcash(): void
    {
        Sanctum::actingAs($this->customer);
        $this->getJson('/api/v1/earnings')->assertOk()
            ->assertJsonPath('data.balance', 300)
            ->assertJsonPath('data.available', 300)
            ->assertJsonPath('data.total_earned', 300)
            ->assertJsonPath('data.referral_rewards', 300)
            ->assertJsonPath('data.card_endorsements', 0)
            ->assertJsonPath('data.cashed_out', 0)
            ->assertJsonPath('data.pending_cash_out', 0)
            ->assertJsonPath('data.has_pending', false)
            ->assertJsonPath('data.min_amount', 1)
            ->assertJsonPath('data.loyalty_card_active', true)
            ->assertJsonPath('data.gcash_number', '09170000001')
            ->assertJsonPath('data.gcash_name', 'Ana Reyes')
            ->assertJsonCount(0, 'data.cash_outs');
    }

    public function test_the_amount_must_be_within_the_balance(): void
    {
        Sanctum::actingAs($this->customer);
        $this->cashOut(['amount' => 300.01])->assertUnprocessable()->assertJsonPath('errors.amount.0', 'You can cash out up to ₱300.00.');
        $this->cashOut(['amount' => 0])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->cashOut(['amount' => 10.555])->assertUnprocessable()->assertJsonValidationErrors('amount');
        $this->cashOut(['gcash_number' => '12345'])->assertUnprocessable()->assertJsonValidationErrors('gcash_number');
        $this->cashOut(['gcash_name' => ''])->assertUnprocessable()->assertJsonValidationErrors('gcash_name');

        Sanctum::actingAs($this->cardHolder());
        $this->cashOut(['amount' => 1])->assertUnprocessable()->assertJsonPath('errors.amount.0', 'You have no earnings to cash out yet.');
        $this->assertSame(0, CashOutRequest::count());
    }

    public function test_a_customer_asks_for_all_earnings_and_the_admin_sends_it(): void
    {
        Sanctum::actingAs($this->customer);
        $this->cashOut(['amount' => 300, 'gcash_number' => '+63 917 000 0002'])->assertCreated()
            ->assertJsonPath('message', "Cash-out request sent! We'll notify you once it's in your GCash.")
            ->assertJsonPath('data.amount', 300)
            ->assertJsonPath('data.gcash_number', '09170000002')
            ->assertJsonPath('data.status', 'pending')
            ->assertJsonPath('data.status_label', 'Pending')
            ->assertJsonPath('data.reference_number', null);
        $cashOut = CashOutRequest::sole();
        Notification::assertSentTo($this->admin, BroadcastNotification::class, fn ($n) => $n->title === 'Cash-out to send'
            && $n->data['cash_out_id'] === $cashOut->id);

        // Still in the balance until it is sent; nothing more can be asked for meanwhile.
        $this->getJson('/api/v1/earnings')->assertJsonPath('data.balance', 300)->assertJsonPath('data.available', 0)
            ->assertJsonPath('data.pending_cash_out', 300)->assertJsonPath('data.has_pending', true)
            ->assertJsonPath('data.cash_outs.0.id', $cashOut->id);
        $this->cashOut(['amount' => 1])->assertStatus(409);

        // The admin sees who to pay and where.
        $this->actingAs($this->admin, 'web');
        $this->get('/dashboard')->assertSee('cash-outs to send');
        $this->get('/cash-outs')->assertOk()->assertSee('Ana Reyes')->assertSee('09170000002')
            ->assertSee('₱300.00')->assertSee('Mark as sent')->assertSee('data-live-badge="cash_outs_to_send"', false);
        $this->getJson('/live')->assertJsonPath('cash_outs_to_send', 1);

        $this->post("/cash-outs/{$cashOut->id}/complete", [])->assertSessionHasErrors('reference');
        $this->post("/cash-outs/{$cashOut->id}/complete", ['reference' => '1009 882 334455', 'note' => 'Thank you!'])
            ->assertRedirect()->assertSessionHas('success', '₱300.00 marked as sent to Ana Reyes. They have been notified.');
        $cashOut->refresh();
        $this->assertSame(CashOutStatus::Completed, $cashOut->status);
        $this->assertSame('1009882334455', $cashOut->reference_number);
        $this->assertSame($this->admin->id, $cashOut->processed_by);
        Notification::assertSentTo($this->customer, BroadcastNotification::class, fn ($n) => $n->type->value === 'CASH_OUT'
            && $n->title === 'Cash out sent'
            && $n->body === 'We sent ₱300.00 to your GCash 09170000002. Reference no. 1009882334455. Thank you!'
            && $n->data['cash_out_id'] === $cashOut->id);

        // Twice does nothing.
        $this->post("/cash-outs/{$cashOut->id}/complete", ['reference' => '555666777'])->assertSessionHasErrors('request');
        $this->get('/cash-outs?status=completed')->assertSee('Ref')->assertSee('1009882334455');

        Sanctum::actingAs($this->customer);
        $this->getJson('/api/v1/earnings')->assertJsonPath('data.balance', 0)->assertJsonPath('data.available', 0)
            ->assertJsonPath('data.cashed_out', 300)->assertJsonPath('data.total_earned', 300)
            ->assertJsonPath('data.cash_outs.0.status_label', 'Sent')
            ->assertJsonPath('data.cash_outs.0.reference_number', '1009882334455');

        // New earnings can be cashed out again.
        $this->reward(50);
        $this->getJson('/api/v1/earnings')->assertJsonPath('data.balance', 50)->assertJsonPath('data.available', 50);
    }

    public function test_a_rejected_cash_out_stays_in_the_balance(): void
    {
        Sanctum::actingAs($this->customer);
        $this->cashOut(['amount' => 120.50])->assertCreated();
        $cashOut = CashOutRequest::sole();

        $this->actingAs($this->admin, 'web');
        $this->post("/cash-outs/{$cashOut->id}/reject", ['reason' => ''])->assertSessionHasErrors('reason');
        $this->post("/cash-outs/{$cashOut->id}/reject", ['reason' => "The GCash name doesn't match the number"])->assertSessionHas('success');
        $this->assertSame(CashOutStatus::Rejected, $cashOut->fresh()->status);
        Notification::assertSentTo($this->customer, BroadcastNotification::class, fn ($n) => $n->title === 'Cash out not sent'
            && str_contains($n->body, "The GCash name doesn't match the number. The amount is still in your earnings"));

        Sanctum::actingAs($this->customer);
        $this->getJson('/api/v1/earnings')->assertJsonPath('data.balance', 300)->assertJsonPath('data.available', 300)
            ->assertJsonPath('data.cash_outs.0.status_label', 'Not sent')
            ->assertJsonPath('data.cash_outs.0.admin_note', "The GCash name doesn't match the number");
    }

    public function test_the_customer_can_cancel_only_their_own_waiting_request(): void
    {
        Sanctum::actingAs($this->customer);
        $id = $this->cashOut(['amount' => 100])->json('data.id');

        Sanctum::actingAs($this->customer());
        $this->deleteJson("/api/v1/cash-outs/{$id}")->assertNotFound();

        Sanctum::actingAs($this->customer);
        $this->deleteJson("/api/v1/cash-outs/{$id}")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->deleteJson("/api/v1/cash-outs/{$id}")->assertStatus(409);
        $this->getJson('/api/v1/earnings')->assertJsonPath('data.available', 300);

        $this->actingAs($this->admin, 'web');
        $this->post("/cash-outs/{$id}/complete", ['reference' => '1234567890'])->assertSessionHasErrors('request');
    }

    public function test_cash_outs_are_for_managers_and_move_with_a_test_account(): void
    {
        Sanctum::actingAs($this->customer);
        $this->cashOut(['amount' => 100])->assertCreated();

        $this->actingAs(User::factory()->staff()->create(), 'web');
        $this->get('/cash-outs')->assertForbidden();

        $this->customer->setTestAccount(true);
        $this->assertTrue(CashOutRequest::withoutGlobalScopes()->sole()->is_test);
    }

    public function test_only_active_loyalty_card_holders_can_cash_out(): void
    {
        foreach ([
            'no card' => ['loyalty_number' => null, 'loyalty_expires_at' => null],
            'expired card' => ['loyalty_expires_at' => now()->subDay()->toDateString()],
        ] as $case => $card) {
            $this->customer->forceFill($card)->save();
            Sanctum::actingAs($this->customer->fresh());

            $this->getJson('/api/v1/earnings')->assertOk()
                ->assertJsonPath('data.loyalty_card_active', false, $case)
                ->assertJsonPath('data.available', 300);
            $this->cashOut(['amount' => 100])->assertForbidden()
                ->assertJsonPath('message', \App\Services\EarningsService::CARD_REQUIRED_MESSAGE);
        }
        $this->assertSame(0, CashOutRequest::count());

        // Renewed: cashing out works again.
        $this->customer->forceFill(['loyalty_number' => '0000501', 'loyalty_expires_at' => now()->addYear()->toDateString()])->save();
        Sanctum::actingAs($this->customer->fresh());
        $this->cashOut(['amount' => 100])->assertCreated();
    }

    /** A customer with an active loyalty card (only they can cash out). */
    private function cardHolder(array $attributes = []): User
    {
        static $number = 500;

        return $this->customer([
            'loyalty_number' => sprintf('%07d', ++$number),
            'loyalty_expires_at' => now()->addYear()->toDateString(),
            ...$attributes,
        ]);
    }

    private function reward(float $amount): void
    {
        Referral::create([
            'referrer_id' => $this->customer->id, 'name' => 'Juan Reyes', 'phone' => '0917555'.random_int(1000, 9999),
            'status' => ReferralStatus::Completed, 'reward_amount' => $amount, 'rewarded_at' => now(),
        ]);
    }

    private function cashOut(array $overrides = []): \Illuminate\Testing\TestResponse
    {
        return $this->postJson('/api/v1/cash-outs', array_replace(['amount' => 300, 'gcash_number' => '09170000001', 'gcash_name' => 'Ana Reyes'], $overrides));
    }
}
