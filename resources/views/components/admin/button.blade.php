@props(['variant' => 'filled', 'href' => null, 'icon' => null, 'size' => 'md', 'type' => 'button'])
@php
    $base = 'inline-flex items-center justify-center gap-2 rounded-full font-medium transition disabled:cursor-not-allowed disabled:opacity-50 whitespace-nowrap';
    $sizes = ['sm' => 'min-h-9 px-3.5 text-sm', 'md' => 'min-h-11 px-5 text-sm'];
    $variants = [
        'filled' => 'bg-primary text-on-primary shadow-e1 hover:bg-primary-hover',
        'tonal' => 'bg-secondary-container text-on-secondary-container hover:brightness-95',
        'outlined' => 'border border-outline text-primary hover:bg-primary/5',
        'text' => 'text-primary hover:bg-primary/8',
        'danger' => 'bg-danger text-white hover:brightness-95',
        'danger-text' => 'text-danger hover:bg-danger-container/60',
    ];
    $classes = $base.' '.($sizes[$size] ?? $sizes['md']).' '.($variants[$variant] ?? $variants['filled']);
@endphp
@if ($href)
    <a href="{{ $href }}" {{ $attributes->class($classes) }}>
        @if ($icon)<span class="material-symbols-outlined" aria-hidden="true">{{ $icon }}</span>@endif{{ $slot }}
    </a>
@else
    <button type="{{ $type }}" {{ $attributes->class($classes) }}>
        @if ($icon)<span class="material-symbols-outlined" aria-hidden="true">{{ $icon }}</span>@endif{{ $slot }}
    </button>
@endif
