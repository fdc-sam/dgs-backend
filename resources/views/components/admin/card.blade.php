@props(['title' => null, 'subtitle' => null, 'padding' => true])
<section {{ $attributes->class(['rounded-card border border-outline-variant bg-white']) }}>
    @if ($title || isset($actions))
        <header @class(['flex flex-wrap items-center justify-between gap-3 px-6 pt-5', 'border-b border-outline-variant pb-4' => ! $padding, 'pb-1' => $padding])>
            <div>
                @if ($title)<h2 class="text-base font-semibold tracking-tight">{{ $title }}</h2>@endif
                @if ($subtitle)<p class="mt-0.5 text-sm text-on-surface-variant">{{ $subtitle }}</p>@endif
            </div>
            @isset($actions)<div class="flex items-center gap-2">{{ $actions }}</div>@endisset
        </header>
    @endif
    <div @class(['p-6' => $padding])>{{ $slot }}</div>
</section>
