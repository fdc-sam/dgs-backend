<x-admin.layout title="Customers">
    <x-admin.page-header title="Customers" subtitle="Everyone who has registered in the app." />

    <x-admin.filter-bar :action="route('customers.index')">
        <x-admin.input name="search" label="Search" :value="request('search')" placeholder="Name, email, phone, QR code or loyalty card" class="w-72" />
        <x-admin.select name="status" label="Status" :options="['active' => 'Active', 'inactive' => 'Inactive', 'suspended' => 'Suspended']" :value="request('status')" placeholder="Any" class="w-40" />
    </x-admin.filter-bar>

    <x-admin.card :padding="false">
        @if ($customers->isEmpty())
            <x-admin.empty-state icon="groups" title="No customers found" />
        @else
            <x-admin.table :headers="['Customer', 'Bookings', '>Lifetime value', 'Last visit', 'Joined', 'Status']">
                @foreach ($customers as $c)
                    <tr>
                        <td>
                            <a href="{{ route('customers.show', $c) }}" class="font-medium text-primary hover:underline">{{ $c->name }}</a>
                            <p class="text-xs text-on-surface-variant">{{ $c->email }}@if ($c->phone) · <span class="whitespace-nowrap">{{ $c->phone }}</span>@endif</p>
                        </td>
                        <td class="whitespace-nowrap">
                            {{ $c->appointments_count }}
                            <p class="text-xs text-on-surface-variant">{{ $c->completed_count }} completed</p>
                        </td>
                        <td class="text-right">₱{{ number_format($c->lifetime_value ?? 0, 2) }}</td>
                        <td class="whitespace-nowrap">{{ $c->last_visit ? \Carbon\Carbon::parse($c->last_visit)->format('M j, Y') : '—' }}</td>
                        <td class="whitespace-nowrap">{{ $c->created_at->format('M j, Y') }}</td>
                        <td><x-admin.badge :tone="['active' => 'success', 'suspended' => 'danger'][$c->status->value] ?? 'neutral'">{{ $c->status->label() }}</x-admin.badge></td>
                    </tr>
                @endforeach
            </x-admin.table>
            <x-admin.pagination :paginator="$customers" />
        @endif
    </x-admin.card>
</x-admin.layout>
