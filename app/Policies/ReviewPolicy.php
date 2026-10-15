<?php

namespace App\Policies;

use App\Enums\ReviewStatus;
use App\Models\Review;
use App\Models\User;

class ReviewPolicy
{
    /** Approved reviews are public; pending/rejected ones only for the author and managers. */
    public function view(?User $user, Review $review): bool
    {
        return $review->status === ReviewStatus::Approved
            || ($user && ($user->id === $review->customer_id || $user->isManager()));
    }

    public function create(User $user): bool
    {
        return $user->isCustomer() && $user->isActive();
    }

    public function moderate(User $user, Review $review): bool
    {
        return $user->isManager();
    }

    public function delete(User $user, Review $review): bool
    {
        return $user->isManager();
    }
}
