@props(['tone' => 'neutral', 'icon' => null])
@php
    $tones = [
        'neutral' => 'bg-surface-container-high text-on-surface-variant',
        'primary' => 'bg-primary-container text-on-primary-container',
        'success' => 'bg-success-container text-success',
        'info' => 'bg-info-container text-info',
        'warning' => 'bg-warning-container text-warning',
        'danger' => 'bg-danger-container text-danger',
    ];
@endphp
<span {{ $attributes->class(['inline-flex items-center gap-1 whitespace-nowrap rounded-full px-2.5 py-1 text-xs font-semibold', $tones[$tone] ?? $tones['neutral']]) }}>
    @if ($icon)<span class="material-symbols-outlined text-sm" aria-hidden="true">{{ $icon }}</span>@endif
    {{ $slot }}
</span>
