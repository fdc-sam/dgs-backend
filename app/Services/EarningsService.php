<?php

namespace App\Services;

use App\Enums\CashOutStatus;
use App\Enums\LoyaltyCardRequestStatus;
use App\Enums\ReferralStatus;
use App\Exceptions\ApiException;
use App\Models\CashOutRequest;
use App\Models\LoyaltyCardRequest;
use App\Models\Referral;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * A customer's in-app earnings: referral rewards (set by the admin when completing a referral) and card endorsement
 * rewards (when a friend's loyalty card is approved) add to the balance; a cash-out is taken off once the admin has
 * sent it to the customer's GCash. One cash-out request can wait at a time.
 *
 * Rewards from before earnings moved into the app were sent to GCash / paid in cash directly (paid_directly); they
 * count in the lifetime totals but not in the balance.
 */
class EarningsService
{
    public const MIN_AMOUNT = 1;

    public function __construct(
        private readonly NotificationService $notifications,
        private readonly AuditLogger $audit,
    ) {}

    /**
     * @return array{balance: float, available: float, total_earned: float, referral_rewards: float, card_endorsements: float,
     *               cashed_out: float, pending_cash_out: float, has_pending: bool}
     */
    public function summary(User $customer): array
    {
        $referrals = Referral::withoutGlobalScopes()->where('referrer_id', $customer->id)->where('status', ReferralStatus::Completed->value);
        $endorsements = LoyaltyCardRequest::withoutGlobalScopes()->where('endorser_id', $customer->id)->where('status', LoyaltyCardRequestStatus::Approved->value);
        $cashOuts = CashOutRequest::withoutGlobalScopes()->where('customer_id', $customer->id);

        $referralRewards = (float) (clone $referrals)->sum('reward_amount');
        $cardEndorsements = (float) (clone $endorsements)->sum('endorser_cash');
        $credited = (float) (clone $referrals)->where('reward_paid_directly', false)->sum('reward_amount')
            + (float) (clone $endorsements)->where('endorser_paid_directly', false)->sum('endorser_cash');
        $cashedOut = (float) (clone $cashOuts)->where('status', CashOutStatus::Completed->value)->sum('amount');
        $pending = (float) (clone $cashOuts)->where('status', CashOutStatus::Pending->value)->sum('amount');

        $balance = max(0, round($credited - $cashedOut, 2));

        return [
            'balance' => $balance,
            // One request at a time: nothing more can be asked for while one waits.
            'available' => $pending > 0 ? 0.0 : $balance,
            'total_earned' => round($referralRewards + $cardEndorsements, 2),
            'referral_rewards' => round($referralRewards, 2),
            'card_endorsements' => round($cardEndorsements, 2),
            'cashed_out' => round($cashedOut, 2),
            'pending_cash_out' => round($pending, 2),
            'has_pending' => $pending > 0,
        ];
    }

    /** The customer asks for an amount of their balance to be sent to a GCash number. */
    public function request(User $customer, float $amount, string $gcashNumber, string $gcashName): CashOutRequest
    {
        $amount = round($amount, 2);
        $cashOut = DB::transaction(function () use ($customer, $amount, $gcashNumber, $gcashName) {
            // Two taps at once: the second one sees the first request.
            User::query()->whereKey($customer->id)->lockForUpdate()->first();
            $summary = $this->summary($customer);
            if ($summary['has_pending']) {
                throw ApiException::conflict('You already have a cash-out request waiting. We\'ll let you know once it is sent.');
            }
            if ($amount < self::MIN_AMOUNT) {
                throw ValidationException::withMessages(['amount' => 'Enter at least ₱'.self::MIN_AMOUNT.'.']);
            }
            if ($amount > $summary['available']) {
                throw ValidationException::withMessages(['amount' => $summary['available'] > 0
                    ? 'You can cash out up to ₱'.number_format($summary['available'], 2).'.'
                    : 'You have no earnings to cash out yet.']);
            }

            return CashOutRequest::create([
                'customer_id' => $customer->id,
                'amount' => $amount,
                'gcash_number' => $gcashNumber,
                'gcash_name' => trim($gcashName),
                'is_test' => (bool) $customer->is_test,
            ]);
        });

        $this->audit->log('cash_out.requested', $cashOut, null, ['amount' => $amount, 'gcash_number' => $gcashNumber]);
        $this->notifications->cashOutRequested($cashOut->load('customer'));

        return $cashOut;
    }

    /** The customer changed their mind before the admin sent it. */
    public function cancel(CashOutRequest $cashOut): CashOutRequest
    {
        $this->settle($cashOut, CashOutStatus::Cancelled, []);
        $this->audit->log('cash_out.cancelled', $cashOut, ['status' => CashOutStatus::Pending->value], ['status' => CashOutStatus::Cancelled->value]);

        return $cashOut;
    }

    /** The admin sent the money to the customer's GCash: record the reference, take it off the balance, tell the customer. */
    public function complete(CashOutRequest $cashOut, User $actor, string $reference, ?string $note = null): CashOutRequest
    {
        $reference = PaymentService::normalizeReference($reference);
        $this->settle($cashOut, CashOutStatus::Completed, [
            'reference_number' => $reference,
            'admin_note' => filled($note) ? trim($note) : null,
            'processed_by' => $actor->id,
            'processed_at' => now(),
        ]);
        $this->audit->log('cash_out.completed', $cashOut, ['status' => CashOutStatus::Pending->value], [
            'status' => CashOutStatus::Completed->value,
            'amount' => (float) $cashOut->amount,
            'reference_number' => $reference,
            'gcash_number' => $cashOut->gcash_number,
        ]);
        $this->notifications->cashOutCompleted($cashOut->load('customer'));

        return $cashOut;
    }

    /** Not sent (e.g. wrong GCash details): the amount stays in the balance and the customer sees the reason. */
    public function reject(CashOutRequest $cashOut, User $actor, string $reason): CashOutRequest
    {
        $this->settle($cashOut, CashOutStatus::Rejected, [
            'admin_note' => trim($reason),
            'processed_by' => $actor->id,
            'processed_at' => now(),
        ]);
        $this->audit->log('cash_out.rejected', $cashOut, ['status' => CashOutStatus::Pending->value], [
            'status' => CashOutStatus::Rejected->value,
            'reason' => $cashOut->admin_note,
        ]);
        $this->notifications->cashOutRejected($cashOut->load('customer'));

        return $cashOut;
    }

    /** Moves a still-pending request to its final status; a second admin (or the customer) acting at once gets an error. */
    private function settle(CashOutRequest $cashOut, CashOutStatus $status, array $attributes): void
    {
        DB::transaction(function () use ($cashOut, $status, $attributes) {
            $locked = CashOutRequest::withoutGlobalScopes()->whereKey($cashOut->id)->lockForUpdate()->firstOrFail();
            if (! $locked->isPending()) {
                throw ValidationException::withMessages(['request' => 'This cash-out request was already '.strtolower($locked->status->label()).'.']);
            }
            $locked->update(['status' => $status, ...$attributes]);
            $cashOut->setRawAttributes($locked->getAttributes(), true);
        });
    }
}
