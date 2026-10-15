<?php

namespace App\Support\Booking;

use Carbon\CarbonImmutable;

final class Slot
{
    /** @param  list<string>  $staffIds  staff free for this slot, best candidate first */
    public function __construct(
        public readonly CarbonImmutable $start,
        public readonly CarbonImmutable $end,
        public readonly bool $available,
        public readonly array $staffIds = [],
    ) {}
}
