<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

/** Account/profile access (mapped to the User model). */
class ProfilePolicy
{
    public function view(User $actor, User $subject): bool
    {
        return $actor->id === $subject->id || $actor->isManager();
    }

    public function update(User $actor, User $subject): bool
    {
        if ($actor->id === $subject->id) {
            return true;
        }
        // Only super admins may edit other admins.
        if ($subject->role->isManager()) {
            return $actor->isSuperAdmin();
        }

        return $actor->isManager();
    }

    /** Suspend / reactivate an account. */
    public function changeStatus(User $actor, User $subject): bool
    {
        return $actor->id !== $subject->id && $this->update($actor, $subject)
            && ! ($subject->role === UserRole::SuperAdmin && ! $actor->isSuperAdmin());
    }
}
