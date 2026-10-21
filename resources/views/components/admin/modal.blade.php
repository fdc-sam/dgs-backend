@props(['name', 'title', 'maxWidth' => 'max-w-lg'])
{{-- Open with: <button @click="$dispatch('open-modal', '{{ $name }}')"> --}}
<div x-data="{ open: false }"
     x-on:open-modal.window="if ($event.detail === '{{ $name }}') { open = true; $nextTick(() => $refs.panel.querySelector('input,select,textarea,button')?.focus()) }"
     x-on:close-modal.window="if ($event.detail === '{{ $name }}') open = false"
     x-on:keydown.escape.window="open = false"
     x-show="open" x-cloak class="fixed inset-0 z-50 flex items-end justify-center sm:items-center sm:p-4"
     role="dialog" aria-modal="true" aria-labelledby="modal-{{ $name }}-title">
    <div x-show="open" x-transition.opacity class="fixed inset-0 bg-on-surface/50" @click="open = false" aria-hidden="true"></div>
    {{-- Phones: a bottom sheet that scrolls when its form is taller than the screen. --}}
    <div x-ref="panel" x-show="open"
         x-transition:enter="transition duration-200 ease-out" x-transition:enter-start="max-sm:translate-y-8 opacity-0 sm:scale-95" x-transition:enter-end="translate-y-0 opacity-100 sm:scale-100"
         x-transition:leave="transition duration-150 ease-in" x-transition:leave-start="opacity-100" x-transition:leave-end="opacity-0"
         class="relative max-h-[92dvh] w-full {{ $maxWidth }} overflow-y-auto overscroll-contain rounded-t-[1.5rem] bg-white p-5 pb-[max(1.25rem,env(safe-area-inset-bottom))] shadow-e2 sm:rounded-card sm:p-6">
        <div class="mx-auto -mt-2 mb-3 h-1 w-10 rounded-full bg-outline-variant sm:hidden" aria-hidden="true"></div>
        <div class="mb-4 flex items-start justify-between gap-4">
            <h2 id="modal-{{ $name }}-title" class="text-lg font-semibold">{{ $title }}</h2>
            <button type="button" class="-m-2 inline-flex h-10 w-10 items-center justify-center rounded-full hover:bg-surface-container-high" @click="open = false" aria-label="Close">
                <span class="material-symbols-outlined" aria-hidden="true">close</span>
            </button>
        </div>
        {{ $slot }}
    </div>
</div>
