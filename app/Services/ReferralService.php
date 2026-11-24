<?php

namespace App\Services;

use App\Enums\ReferralStatus;
use App\Models\Referral;
use Illuminate\Validation\ValidationException;

/** Admin side of referrals: moving them along and setting the reward added to the referrer's earnings. The referrer is told at each step. */
class ReferralService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly NotificationService $notifications,
    ) {}

    public function setStatus(Referral $referral, ReferralStatus $status, ?string $note = null): Referral
    {
        $old = ['status' => $referral->status->value, 'admin_note' => $referral->admin_note];
        $referral->update(['status' => $status, 'admin_note' => filled($note) ? trim($note) : null]);
        $this->audit->log('referral.'.$status->value, $referral, $old, ['status' => $status->value, 'admin_note' => $referral->admin_note]);

        if (in_array($status, [ReferralStatus::Accepted, ReferralStatus::InProgress, ReferralStatus::Declined], true)) {
            $this->notifications->referralUpdated($referral);
        }

        return $referral;
    }

    /** The referral is done: the reward is added to the referrer's in-app earnings (they cash it out later, see EarningsService). */
    public function complete(Referral $referral, float $amount, ?string $note = null): Referral
    {
        // A catalog service or a custom charge: either shows the referred person was served.
        if (! $referral->items()->exists()) {
            throw ValidationException::withMessages([
                'services' => 'Add at least one service or charge before completing this referral.',
            ]);
        }

        $old = ['status' => $referral->status->value];
        $referral->update([
            'status' => ReferralStatus::Completed,
            'reward_amount' => $amount,
            'reward_reference' => null,
            'reward_paid_directly' => false,
            'rewarded_at' => now(),
            'admin_note' => filled($note) ? trim($note) : null,
        ]);
        $this->audit->log('referral.completed', $referral, $old, [
            'status' => ReferralStatus::Completed->value,
            'reward_amount' => $amount,
        ]);
        $this->notifications->referralUpdated($referral);

        return $referral;
    }
}
