@props(['label', 'value', 'icon', 'tone' => 'primary', 'hint' => null, 'href' => null])
@php
    $tones = [
        'primary' => 'bg-primary-container text-on-primary-container',
        'gold' => 'bg-gold-soft text-primary',
        'success' => 'bg-success-container text-success',
        'info' => 'bg-info-container text-info',
        'warning' => 'bg-warning-container text-warning',
    ];
@endphp
<{{ $href ? 'a' : 'div' }} @if ($href) href="{{ $href }}" @endif
    {{ $attributes->class(['flex items-center gap-4 rounded-card border border-outline-variant/70 bg-white p-5 shadow-e1', 'transition hover:shadow-e2' => $href]) }}>
    <span class="flex h-12 w-12 shrink-0 items-center justify-center rounded-2xl {{ $tones[$tone] ?? $tones['primary'] }}">
        <span class="material-symbols-outlined icon-fill" aria-hidden="true">{{ $icon }}</span>
    </span>
    <span class="min-w-0 flex-1">
        <span class="block min-h-10 line-clamp-2 text-sm leading-5 text-on-surface-variant">{{ $label }}</span>
        <span class="block min-h-8 truncate text-2xl font-semibold leading-8 tracking-tight">{{ $value }}</span>
        <span class="block min-h-4 text-xs leading-4 text-outline">@if ($hint){{ $hint }}@else&nbsp;@endif</span>
    </span>
</{{ $href ? 'a' : 'div' }}>
