<x-admin.layout title="Branches">
    <x-admin.page-header title="Branches" subtitle="Locations, contact details and opening hours.">
        @can('change-setup')<x-slot:actions><x-admin.button :href="route('branches.create')" icon="add_business">New branch</x-admin.button></x-slot:actions> @endcan
    </x-admin.page-header>

    @if ($branches->isEmpty())
        <x-admin.card><x-admin.empty-state icon="storefront" title="No branches yet" message="Add your first salon location.">@can('change-setup')<x-admin.button :href="route('branches.create')" icon="add_business">New branch</x-admin.button> @endcan</x-admin.empty-state></x-admin.card>
    @else
        <div class="grid gap-4 md:grid-cols-2">
            @foreach ($branches as $branch)
                <a href="{{ route('branches.edit', $branch) }}" class="group rounded-card border border-outline-variant/70 bg-white p-5 shadow-e1 transition hover:shadow-e2">
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p class="font-semibold group-hover:text-primary">{{ $branch->name }}</p>
                            <p class="mt-0.5 text-sm text-on-surface-variant">{{ $branch->address ?: 'No address yet' }}</p>
                        </div>
                        <x-admin.badge :tone="$branch->status->value === 'active' ? 'success' : 'neutral'">{{ $branch->status->label() }}</x-admin.badge>
                    </div>
                    <div class="mt-4 grid grid-cols-7 gap-1 text-center text-xs">
                        @foreach (['S', 'M', 'T', 'W', 'T', 'F', 'S'] as $i => $d)
                            @php $h = $branch->businessHours->firstWhere('day_of_week', $i); @endphp
                            <div @class(['rounded-lg py-1.5', 'bg-gold-soft text-on-primary-container' => $h?->is_open, 'bg-surface-container text-outline' => ! $h?->is_open]) title="{{ $h?->is_open ? substr($h->opening_time, 0, 5).'–'.substr($h->closing_time, 0, 5) : 'Closed' }}">
                                <span class="block font-semibold">{{ $d }}</span>
                                <span class="block">{{ $h?->is_open ? \Carbon\Carbon::parse($h->opening_time)->format('ga') : '—' }}</span>
                            </div>
                        @endforeach
                    </div>
                    <div class="mt-4 flex gap-6 text-sm text-on-surface-variant">
                        <span><strong class="text-on-surface">{{ $branch->staff_count }}</strong> staff</span>
                        <span><strong class="text-on-surface">{{ $branch->services_count }}</strong> services</span>
                        <span>{{ $branch->phone }}</span>
                    </div>
                </a>
            @endforeach
        </div>
    @endif
</x-admin.layout>
