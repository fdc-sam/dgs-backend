<?php

namespace App\Support\Booking;

use Carbon\CarbonImmutable;

/** Half-open time interval [start, end). */
final class Interval
{
    public function __construct(
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
    ) {}

    public function overlaps(self $other): bool
    {
        return $this->start->lt($other->end) && $other->start->lt($this->end);
    }

    public function contains(self $other): bool
    {
        return $other->start->gte($this->start) && $other->end->lte($this->end);
    }
}
