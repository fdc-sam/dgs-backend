<?php

namespace App\Enums;

/** Pending (waiting for the admin) → Completed (sent to GCash, reference recorded) or Rejected; the customer may cancel while pending. */
enum CashOutStatus: string
{
    case Pending = 'pending';
    case Completed = 'completed';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    /** Admin wording. */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'To send',
            self::Completed => 'Sent',
            self::Rejected => 'Rejected',
            self::Cancelled => 'Cancelled',
        };
    }

    /** The app's wording. */
    public function customerLabel(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Completed => 'Sent',
            self::Rejected => 'Not sent',
            self::Cancelled => 'Cancelled',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'warning',
            self::Completed => 'success',
            self::Rejected => 'danger',
            self::Cancelled => 'neutral',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
