{{--
    The customer's loyalty card drawn like the printed one (and the app's): black with a gold wave, the DGS badge,
    CARD NO. and CUSTOMER NAME. Sizes are in container units, so it keeps its proportions at any width.
--}}
@props(['number' => null, 'name' => null])
@php
    $formatted = $number ? \App\Support\LoyaltyNumber::format($number) : null;
    $gradient = 'loyalty-gold-'.\Illuminate\Support\Str::random(6);
@endphp
<div {{ $attributes->class(['@container relative aspect-[1.586] w-full max-w-sm overflow-hidden rounded-2xl bg-linear-135 from-card-black-light to-card-black to-65% text-card-text shadow-e2 ring-1 ring-card-gold/40']) }}
     role="img" aria-label="{{ $formatted ? "Loyalty card {$formatted}, {$name}" : 'No loyalty card linked' }}">
    <svg class="absolute inset-0 size-full" viewBox="0 0 320 202" preserveAspectRatio="none" aria-hidden="true">
        <defs>
            <linearGradient id="{{ $gradient }}" gradientUnits="userSpaceOnUse" x1="0" y1="0" x2="320" y2="74">
                <stop offset="0" stop-color="var(--color-card-gold-dark)" />
                <stop offset="0.55" stop-color="var(--color-card-gold-light)" />
                <stop offset="1" stop-color="var(--color-card-gold)" />
            </linearGradient>
        </defs>
        <path d="M0,0 H320 V74 C270,40 180,20 0,22 Z" fill="url(#{{ $gradient }})" />
        <path d="M0,30 C180,28 270,48 320,84" fill="none" stroke="var(--color-card-gold)" stroke-opacity="0.85" stroke-width="1.2" vector-effect="non-scaling-stroke" />
    </svg>
    <img src="{{ \App\Support\Fmt::versionedAsset('images/dgs-logo-256.png') }}" alt="" class="absolute top-[6%] right-[5%] aspect-square h-[33%] rounded-full bg-card-black p-[0.6cqw]">
    <div class="absolute top-[24%] right-[31%] left-[5.5%]">
        <p class="truncate text-[length:4.3cqw] leading-tight font-black tracking-wide text-card-gold uppercase">DGS Hair Salon &amp; Spa</p>
        <p class="truncate text-[length:2.7cqw] tracking-[0.18em] uppercase opacity-70">Style. Beauty. Confidence.</p>
    </div>
    <div class="absolute inset-x-[5.5%] bottom-[7%]">
        <p class="text-[length:2.8cqw] tracking-[0.12em] uppercase opacity-70">Card no.</p>
        <p @class(['font-mono text-[length:7.5cqw] leading-tight font-bold tracking-wider text-card-gold-light', 'opacity-50' => ! $formatted])>{{ $formatted ?? 'LC-•••••••' }}</p>
        <div class="mt-[2.5cqw] flex items-end justify-between gap-3">
            <div class="min-w-0">
                <p class="text-[length:2.8cqw] tracking-[0.12em] uppercase opacity-70">Customer name</p>
                <p class="truncate text-[length:4.4cqw] leading-snug font-semibold">{{ $name }}</p>
            </div>
            <p class="shrink-0 text-[length:3.6cqw] leading-snug font-medium tracking-[0.16em] text-card-gold uppercase">Loyalty card</p>
        </div>
    </div>
</div>
