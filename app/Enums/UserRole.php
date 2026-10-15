<?php

namespace App\Enums;

enum UserRole: string
{
    case SuperAdmin = 'super_admin';
    case Admin = 'admin';
    case Staff = 'staff';
    case Customer = 'customer';

    public function label(): string
    {
        return match ($this) {
            self::SuperAdmin => 'Super Admin',
            self::Admin => 'Admin',
            self::Staff => 'Staff',
            self::Customer => 'Customer',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Roles allowed to sign in to the admin web application. */
    public function canAccessAdmin(): bool
    {
        return in_array($this, [self::SuperAdmin, self::Admin, self::Staff], true);
    }

    public function isManager(): bool
    {
        return in_array($this, [self::SuperAdmin, self::Admin], true);
    }
}
