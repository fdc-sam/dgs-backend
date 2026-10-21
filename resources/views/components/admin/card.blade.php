@props(['title' => null, 'subtitle' => null, 'padding' => true])
<section {{ $attributes->class(['min-w-0 rounded-card border border-outline-variant bg-white']) }}>
    @if ($title || isset($actions))
        <header @class(['flex flex-wrap items-center justify-between gap-x-3 gap-y-2 px-5 pt-5 sm:px-6', 'border-b border-outline-variant pb-4' => ! $padding, 'pb-1' => $padding])>
            <div class="min-w-0">
                @if ($title)<h2 class="text-base font-semibold tracking-tight">{{ $title }}</h2>@endif
                @if ($subtitle)<p class="mt-0.5 text-sm text-on-surface-variant">{{ $subtitle }}</p>@endif
            </div>
            @isset($actions)<div class="flex flex-wrap items-center gap-2">{{ $actions }}</div>@endisset
        </header>
    @endif
    <div @class(['p-5 sm:p-6' => $padding])>{{ $slot }}</div>
</section>
