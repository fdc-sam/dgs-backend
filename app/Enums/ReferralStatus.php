<?php

namespace App\Enums;

/** New → Accepted → In progress → Completed (reward sent to the referrer's GCash), or Not eligible before that. */
enum ReferralStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Declined = 'declined';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'New',
            self::Accepted => 'Accepted',
            self::InProgress => 'In progress',
            self::Completed => 'Completed',
            self::Declined => 'Not eligible',
        };
    }

    /** Statuses an admin can move a referral to with a button (Completed has its own form with the reward). */
    public static function manual(): array
    {
        return [self::Pending, self::Accepted, self::InProgress, self::Declined];
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
