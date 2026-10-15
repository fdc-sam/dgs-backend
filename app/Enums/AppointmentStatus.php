<?php

namespace App\Enums;

enum AppointmentStatus: string
{
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case Rescheduled = 'rescheduled';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case NoShow = 'no_show';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Confirmed => 'Confirmed',
            self::Rescheduled => 'Rescheduled',
            self::InProgress => 'In Progress',
            self::Completed => 'Completed',
            self::Cancelled => 'Cancelled',
            self::NoShow => 'No-show',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }

    /** Statuses whose time range blocks the assigned staff member. */
    public static function blocking(): array
    {
        return [self::Pending, self::Confirmed, self::Rescheduled, self::InProgress, self::Completed];
    }

    /** @return list<string> */
    public static function blockingValues(): array
    {
        return array_map(fn (self $s) => $s->value, self::blocking());
    }

    public function isActive(): bool
    {
        return in_array($this, [self::Pending, self::Confirmed, self::Rescheduled], true);
    }

    public function isFinal(): bool
    {
        return in_array($this, [self::Completed, self::Cancelled, self::NoShow], true);
    }

    /** @return list<self> */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Pending => [self::Confirmed, self::InProgress, self::Completed, self::Cancelled, self::NoShow, self::Rescheduled],
            self::Confirmed => [self::InProgress, self::Completed, self::Cancelled, self::NoShow, self::Rescheduled],
            self::Rescheduled => [self::Confirmed, self::InProgress, self::Completed, self::Cancelled, self::NoShow, self::Rescheduled],
            self::InProgress => [self::Completed],
            self::Completed, self::Cancelled, self::NoShow => [],
        };
    }

    public function canTransitionTo(self $to): bool
    {
        return in_array($to, $this->allowedTransitions(), true);
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending, self::Rescheduled => 'warning',
            self::Confirmed, self::InProgress => 'info',
            self::Completed => 'success',
            self::Cancelled, self::NoShow => 'danger',
        };
    }
}
