@props(['action'])
<form method="GET" action="{{ $action }}" {{ $attributes->class(['mb-4 flex flex-wrap items-end gap-3 rounded-card border border-outline-variant bg-white p-4']) }}>
    {{ $slot }}
    <div class="flex gap-2">
        <x-admin.button type="submit" variant="tonal" icon="filter_list">Filter</x-admin.button>
        @if (request()->query())
            <x-admin.button :href="$action" variant="text">Clear</x-admin.button>
        @endif
    </div>
</form>
