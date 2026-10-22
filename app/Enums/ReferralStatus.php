<?php

namespace App\Enums;

/** New → Contacted → Service completed → Reward sent, or Not eligible at any point before the reward. */
enum ReferralStatus: string
{
    case Pending = 'pending';
    case Contacted = 'contacted';
    case Completed = 'completed';
    case Rewarded = 'rewarded';
    case Declined = 'declined';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'New',
            self::Contacted => 'Contacted',
            self::Completed => 'Service completed',
            self::Rewarded => 'Reward sent',
            self::Declined => 'Not eligible',
        };
    }

    /** Statuses an admin can move a referral to by hand (the reward has its own form). */
    public static function manual(): array
    {
        return [self::Pending, self::Contacted, self::Completed, self::Declined];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
