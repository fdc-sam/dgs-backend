<x-admin.layout title="Staff">
    <x-admin.page-header title="Staff" subtitle="Stylists and therapists, their branches, services and schedules.">
        <x-slot:actions><x-admin.button :href="route('staff.create')" icon="person_add">Add staff</x-admin.button></x-slot:actions>
    </x-admin.page-header>

    <x-admin.filter-bar :action="route('staff.index')">
        <x-admin.input name="search" label="Search" :value="request('search')" placeholder="Name" class="w-64" />
        <x-admin.select name="branch_id" label="Branch" :options="$branches" :value="request('branch_id')" placeholder="All branches" class="w-52" />
    </x-admin.filter-bar>

    @if ($staff->isEmpty())
        <x-admin.card><x-admin.empty-state icon="badge" title="No staff yet" message="Add your team so customers can book them.">
            <x-admin.button :href="route('staff.create')" icon="person_add">Add staff</x-admin.button>
        </x-admin.empty-state></x-admin.card>
    @else
        <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            @foreach ($staff as $member)
                <a href="{{ route('staff.edit', $member) }}" class="group rounded-card border border-outline-variant/70 bg-white p-5 shadow-e1 transition hover:shadow-e2">
                    <div class="flex items-center gap-4">
                        @if ($member->user?->profile_photo)
                            <img src="{{ $member->user->profilePhotoUrl() }}" alt="" class="h-14 w-14 rounded-full object-cover">
                        @else
                            <span class="flex h-14 w-14 items-center justify-center rounded-full bg-primary-container text-lg font-semibold text-on-primary-container" aria-hidden="true">{{ mb_substr($member->user?->name ?? '?', 0, 1) }}</span>
                        @endif
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-semibold group-hover:text-primary">{{ $member->user?->name }}</p>
                            <p class="truncate text-sm text-on-surface-variant">{{ $member->position ?: 'Staff' }}</p>
                        </div>
                        <x-admin.badge :tone="$member->status->value === 'active' ? 'success' : 'neutral'">{{ $member->status->label() }}</x-admin.badge>
                    </div>
                    <div class="mt-4 flex flex-wrap gap-1.5">
                        @foreach ($member->branches as $b)<x-admin.badge :tone="$b->pivot->is_primary ? 'primary' : 'neutral'" icon="storefront">{{ $b->name }}</x-admin.badge>@endforeach
                    </div>
                    <div class="mt-4 flex gap-6 text-sm text-on-surface-variant">
                        <span><strong class="text-on-surface">{{ $member->services_count }}</strong> services</span>
                        <span><strong class="text-on-surface">{{ $member->appointments_count }}</strong> upcoming</span>
                    </div>
                </a>
            @endforeach
        </div>
    @endif
</x-admin.layout>
