@props(['title' => null, 'subtitle' => null, 'padding' => true])
<section {{ $attributes->class(['rounded-card border border-outline-variant/70 bg-white shadow-e1']) }}>
    @if ($title || isset($actions))
        <header class="flex flex-wrap items-center justify-between gap-3 border-b border-outline-variant/60 px-5 py-4">
            <div>
                @if ($title)<h2 class="text-base font-semibold">{{ $title }}</h2>@endif
                @if ($subtitle)<p class="mt-0.5 text-sm text-on-surface-variant">{{ $subtitle }}</p>@endif
            </div>
            @isset($actions)<div class="flex items-center gap-2">{{ $actions }}</div>@endisset
        </header>
    @endif
    <div @class(['p-5' => $padding])>{{ $slot }}</div>
</section>
