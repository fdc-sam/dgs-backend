<?php

namespace App\Support\Booking;

/** A staff member's bookable window on a given date, with break and existing bookings. */
final class StaffDay
{
    /** @param  list<Interval>  $busy */
    public function __construct(
        public readonly string $staffId,
        public readonly Interval $shift,
        public readonly ?Interval $break,
        public array $busy,
        public readonly bool $primaryAtBranch,
    ) {}

    public function isFree(Interval $occupancy): bool
    {
        if (! $this->shift->contains($occupancy)) {
            return false;
        }
        if ($this->break && $this->break->overlaps($occupancy)) {
            return false;
        }
        foreach ($this->busy as $busy) {
            if ($busy->overlaps($occupancy)) {
                return false;
            }
        }

        return true;
    }
}
