<?php

namespace App\Enums;

/** Pending (receipt sent, waiting for the admin) → Approved (card linked) or Rejected (payment not verified). */
enum LoyaltyCardRequestStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    /** Admin wording. */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'To verify',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
        };
    }

    /** The app's wording. */
    public function customerLabel(): string
    {
        return match ($this) {
            self::Pending => 'Waiting for approval',
            self::Approved => 'Approved',
            self::Rejected => 'Not approved',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Approved => 'success',
            self::Rejected => 'danger',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
