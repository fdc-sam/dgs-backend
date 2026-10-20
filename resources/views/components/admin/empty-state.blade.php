@props(['icon' => 'inbox', 'title' => 'Nothing here yet', 'message' => null])
<div {{ $attributes->class(['flex flex-col items-center justify-center px-6 py-14 text-center']) }}>
    <span class="mb-4 flex h-16 w-16 items-center justify-center rounded-2xl bg-gold-soft text-gold-strong">
        <span class="material-symbols-outlined text-3xl" aria-hidden="true">{{ $icon }}</span>
    </span>
    <p class="text-base font-semibold">{{ $title }}</p>
    @if ($message)<p class="mt-1 max-w-sm text-sm text-on-surface-variant">{{ $message }}</p>@endif
    @if ($slot->isNotEmpty())<div class="mt-5">{{ $slot }}</div>@endif
</div>
