<?php

namespace App\Support\Booking;

use App\Models\Branch;
use App\Models\Service;
use App\Models\ServiceVariant;

/** One requested service (optionally a variant) with its resolved price and timing at a branch. */
final class ServiceSelection
{
    public function __construct(
        public readonly Service $service,
        public readonly ?ServiceVariant $variant,
        public readonly float $unitPrice,
        public readonly int $durationMinutes,
        public readonly int $bufferMinutes,
        public readonly ?string $staffProfileId = null,
        public readonly float $loyaltyDiscount = 0,
    ) {}

    public static function resolve(Service $service, ?ServiceVariant $variant, Branch $branch, ?string $staffProfileId = null): self
    {
        $pivot = $service->branches->firstWhere('id', $branch->id)?->pivot;
        $basePrice = $pivot?->custom_price ?? $service->regular_price;

        return new self(
            service: $service,
            variant: $variant,
            unitPrice: (float) ($variant?->price ?? $basePrice),
            durationMinutes: $variant?->duration_minutes ?? $service->duration_minutes,
            bufferMinutes: $service->buffer_minutes,
            staffProfileId: $staffProfileId,
        );
    }

    public function name(): string
    {
        return $this->service->name;
    }
}
