@props(['action', 'collapse' => true, 'except' => []])
@php
    // Filters in use, counted on the phone toggle. except: query keys the page carries that aren't filters (view, date…).
    $active = collect(request()->except(['page', ...$except]))->filter(fn ($v) => filled($v))->count();
@endphp
{{-- Phones: collapse folds the fields behind a "Filters" toggle (layout in app.css); a lone search stays inline. sm+: one row. --}}
<form method="GET" action="{{ $action }}" @if ($collapse) x-data="{ open: false }" @endif
      {{ $attributes->class(['filter-bar mb-4 rounded-card border border-outline-variant bg-white p-4', 'max-sm:py-2' => $collapse, 'filter-bar-inline' => ! $collapse]) }}>
    @if ($collapse)
        <button type="button" class="flex min-h-11 w-full items-center gap-2.5 text-[0.9375rem] font-semibold sm:hidden" @click="open = !open" :aria-expanded="open">
            <span class="material-symbols-outlined text-outline" aria-hidden="true">tune</span>
            Filters
            @if ($active)
                <span class="rounded-full bg-gold px-2 py-0.5 text-xs font-semibold text-on-surface">{{ $active }}</span>
            @endif
            <span class="material-symbols-outlined ml-auto text-outline transition-transform" :class="open && 'rotate-180'" aria-hidden="true">expand_more</span>
        </button>
    @endif
    <div @class(['filter-fields sm:flex sm:flex-wrap sm:items-end sm:gap-3',
                 'max-sm:hidden max-sm:grid max-sm:grid-cols-2 max-sm:gap-3 max-sm:pt-2 max-sm:pb-2' => $collapse,
                 'max-sm:flex max-sm:items-end max-sm:gap-2' => ! $collapse])
         @if ($collapse) :class="{ 'max-sm:hidden': !open }" @endif>
        {{ $slot }}
        <div class="filter-actions flex gap-2">
            <x-admin.button type="submit" variant="tonal" icon="filter_list" :class="$collapse ? 'max-sm:flex-1' : null">
                @if ($collapse)
                    <span class="sm:hidden">Apply filters</span><span class="max-sm:hidden">Filter</span>
                @else
                    <span class="max-sm:sr-only">Filter</span>
                @endif
            </x-admin.button>
            @if (request()->query())
                <x-admin.button :href="$action" variant="text">Clear</x-admin.button>
            @endif
        </div>
    </div>
</form>
