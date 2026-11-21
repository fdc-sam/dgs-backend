<?php

namespace App\Support;

use App\Enums\UserRole;
use App\Models\User;

/** Resolve an admin email or its unambiguous username (the email's local part) to the stored email. */
class AdminLoginIdentifier
{
    public static function resolve(string $identifier): string
    {
        $identifier = strtolower(trim($identifier));
        if (str_contains($identifier, '@')) {
            return $identifier;
        }

        $matches = User::query()->whereIn('role', [UserRole::SuperAdmin->value, UserRole::Admin->value, UserRole::Staff->value])
            ->get(['email'])
            ->filter(fn (User $user) => strtolower(strstr($user->email, '@', true) ?: '') === $identifier);

        return $matches->count() === 1 ? strtolower($matches->first()->email) : $identifier;
    }
}
