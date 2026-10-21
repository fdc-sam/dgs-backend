@props(['label', 'value', 'icon' => null, 'tone' => 'primary', 'hint' => null, 'href' => null])
@php
    // Tone colours the figure itself (green = good, orange = needs a look); neutral tones stay ink.
    $valueTones = [
        'success' => 'text-success',
        'warning' => 'text-warning',
        'danger' => 'text-danger',
    ];
    // Two-up on phones: long figures (₱160,700.00) step down a size instead of being cut off.
    $length = mb_strlen((string) $value);
    $phoneSize = $length > 10 ? 'max-sm:text-lg' : ($length > 7 ? 'max-sm:text-xl' : '');
@endphp
<{{ $href ? 'a' : 'div' }} @if ($href) href="{{ $href }}" @endif
    {{ $attributes->class(['group flex min-w-0 flex-col rounded-card border border-outline-variant bg-white p-4 sm:p-6', 'transition hover:border-outline/60 hover:shadow-e2 active:bg-surface-container/60' => $href]) }}>
    <span class="flex items-start justify-between gap-2 sm:gap-3">
        <span class="eyebrow line-clamp-2 min-h-8 text-[0.6875rem] leading-4 tracking-[0.08em] text-on-surface-variant sm:text-xs sm:tracking-[0.12em]" title="{{ $label }}">{{ $label }}</span>
        @if ($icon)
            {{-- Phones drop the icon so two-up labels keep their full width (on a wrapper: the icon font's own CSS sets display). --}}
            <span class="shrink-0 max-sm:hidden"><span class="material-symbols-outlined text-xl text-outline/70 transition-colors group-hover:text-gold" style="font-variation-settings: 'wght' 300" aria-hidden="true">{{ $icon }}</span></span>
        @endif
    </span>
    <span @class(['mt-2 block truncate text-2xl font-bold leading-8 tracking-tight sm:mt-3 sm:text-3xl sm:leading-10', $phoneSize, $valueTones[$tone] ?? 'text-on-surface'])>{{ $value }}</span>
    <span class="mt-1 block min-h-5 truncate text-[0.8125rem] leading-5 text-on-surface-variant sm:text-sm" @if ($hint) title="{{ $hint }}" @endif>@if ($hint){{ $hint }}@else&nbsp;@endif</span>
</{{ $href ? 'a' : 'div' }}>
