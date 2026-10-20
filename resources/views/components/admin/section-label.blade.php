@props(['title', 'hint' => null, 'href' => null, 'link' => null])
{{-- Small uppercase heading over a group of cards: "BUSINESS SNAPSHOT  Today at a glance        → Reports" --}}
<div {{ $attributes->class(['mb-4 flex flex-wrap items-baseline gap-x-4 gap-y-1']) }}>
    <h2 class="eyebrow">{{ $title }}</h2>
    @if ($hint)<p class="text-sm italic text-outline">{{ $hint }}</p>@endif
    @if ($href)
        <a href="{{ $href }}" class="ml-auto inline-flex items-center gap-1 text-sm font-medium text-on-surface hover:underline">
            <span class="material-symbols-outlined text-base" aria-hidden="true">arrow_forward</span>{{ $link }}
        </a>
    @endif
</div>
