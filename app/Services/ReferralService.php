<?php

namespace App\Services;

use App\Enums\ReferralStatus;
use App\Models\Referral;

/** Admin side of referrals: moving them along and recording the GCash reward. The referrer is told at each milestone. */
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

        if (in_array($status, [ReferralStatus::Completed, ReferralStatus::Declined], true)) {
            $this->notifications->referralUpdated($referral);
        }

        return $referral;
    }

    public function reward(Referral $referral, float $amount, string $reference, ?string $note = null): Referral
    {
        $old = ['status' => $referral->status->value];
        $referral->update([
            'status' => ReferralStatus::Rewarded,
            'reward_amount' => $amount,
            'reward_reference' => trim($reference),
            'rewarded_at' => now(),
            'admin_note' => filled($note) ? trim($note) : null,
        ]);
        $this->audit->log('referral.rewarded', $referral, $old, [
            'status' => ReferralStatus::Rewarded->value,
            'reward_amount' => $amount,
            'reward_reference' => $referral->reward_reference,
            'gcash_number' => $referral->referrer?->phone,
        ]);
        $this->notifications->referralUpdated($referral);

        return $referral;
    }
}
