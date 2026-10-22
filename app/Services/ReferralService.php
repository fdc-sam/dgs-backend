<?php

namespace App\Services;

use App\Enums\ReferralStatus;
use App\Models\Referral;

/** Admin side of referrals: moving them along and recording the GCash reward. The referrer is told at each step. */
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

    /** The owner sent the reward to the referrer's GCash: record the amount and tell the referrer to check GCash. */
    public function complete(Referral $referral, float $amount, ?string $reference = null, ?string $note = null): Referral
    {
        $old = ['status' => $referral->status->value];
        $referral->update([
            'status' => ReferralStatus::Completed,
            'reward_amount' => $amount,
            'reward_reference' => filled($reference) ? trim($reference) : null,
            'rewarded_at' => now(),
            'admin_note' => filled($note) ? trim($note) : null,
        ]);
        $this->audit->log('referral.completed', $referral, $old, [
            'status' => ReferralStatus::Completed->value,
            'reward_amount' => $amount,
            'reward_reference' => $referral->reward_reference,
            'gcash_number' => $referral->referrer?->phone,
        ]);
        $this->notifications->referralUpdated($referral);

        return $referral;
    }
}
