@props(['title', 'subtitle' => null, 'back' => null])
<div class="mb-8 flex flex-wrap items-end justify-between gap-4">
    <div class="min-w-0">
        @if ($back)
            <a href="{{ $back }}" class="mb-2 inline-flex items-center gap-1 text-sm font-medium text-on-surface-variant hover:text-on-surface">
                <span class="material-symbols-outlined text-base" aria-hidden="true">arrow_back</span> Back
            </a>
        @endif
        <h1 class="text-3xl font-bold tracking-tight sm:text-[2.125rem]">{{ $title }}</h1>
        @if ($subtitle)
            <p class="mt-1.5 text-base text-on-surface-variant">{{ $subtitle }}</p>
        @endif
    </div>
    @isset($actions)
        <div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>
    @endisset
</div>
