<?php

namespace App\Support\Booking;

use Carbon\CarbonImmutable;

/** Availability result for one branch/service/(staff)/date. */
final class DaySchedule
{
    /** @param  list<Slot>  $slots */
    public function __construct(
        public readonly CarbonImmutable $date,
        public readonly string $branchId,
        public readonly ?string $serviceId,
        public readonly ?string $staffId,
        public readonly int $durationMinutes,
        public readonly bool $isOpen,
        public readonly ?string $closedReason,
        public readonly array $slots,
    ) {}

    public function hasAvailableSlot(): bool
    {
        foreach ($this->slots as $slot) {
            if ($slot->available) {
                return true;
            }
        }

        return false;
    }

    public function slotAt(string $time): ?Slot
    {
        foreach ($this->slots as $slot) {
            if ($slot->start->format('H:i') === substr($time, 0, 5)) {
                return $slot;
            }
        }

        return null;
    }
}
