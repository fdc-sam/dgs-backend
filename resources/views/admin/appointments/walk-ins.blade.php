<x-admin.layout title="Walk-in Customers">
    <x-admin.page-header title="Walk-in Customers" subtitle="Guest service visits recorded at the salon. These do not create appointments or customer accounts.">
        <x-slot:actions>
            <x-admin.button :href="route('walk-ins.create')" icon="add">Record walk-in</x-admin.button>
        </x-slot:actions>
    </x-admin.page-header>

    <x-admin.filter-bar :action="route('walk-ins.index')">
        <x-admin.input name="search" label="Search" :value="request('search')" placeholder="Guest name, phone or service" class="w-full sm:w-72" />
    </x-admin.filter-bar>

    <x-admin.card :padding="false" class="min-w-0 max-w-full">
        @if ($visits->isEmpty())
            <x-admin.empty-state icon="how_to_reg" title="No walk-in visits yet" message="Record a guest's salon services here without creating an account or appointment." />
        @else
            <x-admin.table :headers="['Guest', 'Services', 'Status', 'Branch', 'Staff', 'Visited', '>Total']" class="min-w-0 max-w-full overscroll-x-contain">
                @foreach ($visits as $visit)
                    <tr role="link" tabindex="0" data-href="{{ route('walk-ins.show', $visit) }}" onclick="window.location.href = this.dataset.href" onkeydown="if (event.key === 'Enter') window.location.href = this.dataset.href" class="cursor-pointer hover:bg-surface-container/60 focus-visible:outline-2 focus-visible:outline-primary">
                        <td>
                            <p class="font-medium">{{ $visit->guest_name ?: 'Walk-in guest' }}</p>
                            <p class="text-xs text-on-surface-variant">{{ $visit->phone ?: 'No phone provided' }}</p>
                        </td>
                        <td class="max-w-64 whitespace-normal break-words">{{ $visit->items->map(fn ($item) => $item->service_name.($item->staff?->user?->name ? ' · '.$item->staff->user->name : ''))->join(', ') }}</td>
                        <td><x-admin.badge :tone="$visit->status === 'completed' ? 'success' : 'info'">{{ $visit->status === 'completed' ? 'Completed' : 'In progress' }}</x-admin.badge></td>
                        <td class="whitespace-nowrap">{{ $visit->branch?->name ?? '—' }}</td>
                        <td class="whitespace-nowrap">{{ $visit->staff?->user?->name ?? '—' }}</td>
                        <td class="whitespace-nowrap">{{ $visit->visited_at->format('M j, Y · g:i A') }}</td>
                        <td class="whitespace-nowrap text-right font-medium">₱{{ number_format($visit->total_amount, 2) }}</td>
                    </tr>
                @endforeach
            </x-admin.table>
            <x-admin.pagination :paginator="$visits" />
        @endif
    </x-admin.card>
</x-admin.layout>
