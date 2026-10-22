<?php

namespace App\Policies;

use App\Enums\ReferralStatus;
use App\Models\Referral;
use App\Models\User;

class ReferralPolicy
{
    /** A customer can take back their own referral until the salon starts working on it. */
    public function delete(User $user, Referral $referral): bool
    {
        return $referral->referrer_id === $user->id && $referral->status === ReferralStatus::Pending;
    }
}
