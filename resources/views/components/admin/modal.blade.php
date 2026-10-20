@props(['name', 'title', 'maxWidth' => 'max-w-lg'])
{{-- Open with: <button @click="$dispatch('open-modal', '{{ $name }}')"> --}}
<div x-data="{ open: false }"
     x-on:open-modal.window="if ($event.detail === '{{ $name }}') { open = true; $nextTick(() => $refs.panel.querySelector('input,select,textarea,button')?.focus()) }"
     x-on:close-modal.window="if ($event.detail === '{{ $name }}') open = false"
     x-on:keydown.escape.window="open = false"
     x-show="open" x-cloak class="fixed inset-0 z-50 flex items-end justify-center p-4 sm:items-center"
     role="dialog" aria-modal="true" aria-labelledby="modal-{{ $name }}-title">
    <div x-show="open" x-transition.opacity class="fixed inset-0 bg-on-surface/50" @click="open = false" aria-hidden="true"></div>
    <div x-ref="panel" x-show="open" x-transition class="relative w-full {{ $maxWidth }} rounded-card bg-white p-6 shadow-e2">
        <div class="mb-4 flex items-start justify-between gap-4">
            <h2 id="modal-{{ $name }}-title" class="text-lg font-semibold">{{ $title }}</h2>
            <button type="button" class="-m-2 inline-flex h-10 w-10 items-center justify-center rounded-full hover:bg-surface-container-high" @click="open = false" aria-label="Close">
                <span class="material-symbols-outlined" aria-hidden="true">close</span>
            </button>
        </div>
        {{ $slot }}
    </div>
</div>
