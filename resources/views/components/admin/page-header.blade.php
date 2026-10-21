@props(['title', 'subtitle' => null, 'back' => null])
{{-- Phones stack the actions under the title, with direct buttons sharing the full width. --}}
<div class="mb-6 flex flex-col gap-4 sm:mb-8 sm:flex-row sm:flex-wrap sm:items-end sm:justify-between">
    <div class="min-w-0">
        @if ($back)
            <a href="{{ $back }}" class="-ml-1 mb-2 inline-flex min-h-9 items-center gap-1 pr-2 text-sm font-medium text-on-surface-variant hover:text-on-surface sm:min-h-0">
                <span class="material-symbols-outlined text-base" aria-hidden="true">arrow_back</span> Back
            </a>
        @endif
        <h1 class="text-[1.75rem] font-bold leading-tight tracking-tight break-words sm:text-3xl lg:text-[2.125rem]">{{ $title }}</h1>
        @if ($subtitle)
            <p class="mt-1 text-[0.9375rem] text-on-surface-variant sm:mt-1.5 sm:text-base">{{ $subtitle }}</p>
        @endif
    </div>
    @isset($actions)
        <div class="flex flex-wrap items-center gap-2 max-sm:[&>a]:flex-1 max-sm:[&>button]:flex-1">{{ $actions }}</div>
    @endisset
</div>
