@props(['label', 'value', 'icon' => null, 'tone' => 'primary', 'hint' => null, 'href' => null])
@php
    // Tone colours the figure itself (green = good, orange = needs a look); neutral tones stay ink.
    $valueTones = [
        'success' => 'text-success',
        'warning' => 'text-warning',
        'danger' => 'text-danger',
    ];
@endphp
<{{ $href ? 'a' : 'div' }} @if ($href) href="{{ $href }}" @endif
    {{ $attributes->class(['group flex flex-col rounded-card border border-outline-variant bg-white p-6', 'transition hover:border-outline/60 hover:shadow-e2' => $href]) }}>
    <span class="flex items-start justify-between gap-3">
        <span class="eyebrow line-clamp-2 min-h-8 leading-4 text-on-surface-variant" title="{{ $label }}">{{ $label }}</span>
        @if ($icon)
            <span class="material-symbols-outlined shrink-0 text-xl text-outline/70 transition-colors group-hover:text-gold" style="font-variation-settings: 'wght' 300" aria-hidden="true">{{ $icon }}</span>
        @endif
    </span>
    <span @class(['mt-3 block truncate text-3xl font-bold leading-10 tracking-tight', $valueTones[$tone] ?? 'text-on-surface'])>{{ $value }}</span>
    <span class="mt-1 block min-h-5 text-sm leading-5 text-on-surface-variant">@if ($hint){{ $hint }}@else&nbsp;@endif</span>
</{{ $href ? 'a' : 'div' }}>
