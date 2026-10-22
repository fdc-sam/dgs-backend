<x-admin.layout title="Appointments" live="appointments">
    <x-admin.page-header title="Appointments" subtitle="Search, filter and manage every booking." />

    <x-admin.filter-bar :action="route('appointments.index')">
        <x-admin.input name="customer" label="Customer / booking #" :value="request('customer')" placeholder="Name, email, phone, DG-…" class="w-full sm:w-64" />
        <x-admin.input name="date" type="date" label="Date" :value="request('date')" class="w-40" />
        <x-admin.input name="from" type="date" label="From" :value="request('from')" class="w-40" />
        <x-admin.input name="to" type="date" label="To" :value="request('to')" class="w-40" />
        <x-admin.select name="status" label="Status" :options="$statuses" :value="request('status')" placeholder="Any" class="w-40" />
        <x-admin.select name="branch_id" label="Branch" :options="$branches" :value="request('branch_id')" placeholder="All branches" class="w-48" />
        @unless (auth()->user()->isStaff())
            <x-admin.select name="staff_id" label="Staff" :options="$staff" :value="request('staff_id')" placeholder="All staff" class="w-48" />
        @endunless
        <x-admin.select name="service_id" label="Service" :options="$services" :value="request('service_id')" placeholder="All services" class="w-48" />
    </x-admin.filter-bar>

    <x-admin.card :padding="false">
        @if ($appointments->isEmpty())
            <x-admin.empty-state icon="event_busy" title="No appointments found" message="Try adjusting the filters." />
        @else
            <x-admin.table :headers="['Booking', 'Customer', 'Service', 'When', '>Total', 'Status', '>Actions']">
                @foreach ($appointments as $a)
                    <tr>
                        {{-- Related details share a cell (number + branch, service + stylist, date + time) so the table fits a laptop screen. --}}
                        <td class="min-w-36">
                            <a href="{{ route('appointments.show', $a) }}" class="whitespace-nowrap font-mono text-[0.6875rem] font-semibold tracking-tight text-primary hover:underline">{{ $a->appointment_number }}</a>
                            <p class="text-xs text-on-surface-variant">{{ $a->branch->name }}</p>
                        </td>
                        <td>
                            <p class="font-medium">{{ $a->customer->name }}</p>
                            @can('manage')<p class="text-xs text-on-surface-variant">{{ $a->customer->phone }}</p> @endcan
                        </td>
                        <td>
                            <p class="max-w-48 truncate" title="{{ $a->items->pluck('service_name')->join(', ') }}">{{ $a->items->pluck('service_name')->join(', ') }}</p>
                            <p class="max-w-48 truncate text-xs text-on-surface-variant">{{ $a->staff?->user?->name ?? '—' }}</p>
                        </td>
                        <td class="whitespace-nowrap">
                            {{ $a->appointment_date->format('M j, Y') }}
                            <p class="text-xs text-on-surface-variant">{{ $a->startsAt()->format('g:i A') }}</p>
                        </td>
                        <td class="whitespace-nowrap text-right font-medium">₱{{ number_format($a->total_amount, 2) }}</td>
                        <td>
                            <x-admin.status-badge :status="$a->status" />
                            @if ($a->payment_status !== \App\Enums\PaymentStatus::NotRequired)
                                <x-admin.badge :tone="$a->payment_status->color()" icon="payments" class="mt-1">{{ $a->payment_status->label() }}</x-admin.badge>
                            @endif
                        </td>
                        <td class="text-right">
                            <div class="flex justify-end gap-1">
                                @if ($a->status->canTransitionTo(\App\Enums\AppointmentStatus::Confirmed) && auth()->user()->can('updateStatus', $a))
                                    <form method="POST" action="{{ route('appointments.status', $a) }}">
                                        @csrf <input type="hidden" name="status" value="confirmed">
                                        <x-admin.button type="submit" variant="tonal" size="sm" icon="check">Confirm</x-admin.button>
                                    </form>
                                @endif
                                <x-admin.button :href="route('appointments.show', $a)" variant="text" size="sm" aria-label="View {{ $a->appointment_number }}">View</x-admin.button>
                            </div>
                        </td>
                    </tr>
                @endforeach
            </x-admin.table>
            <x-admin.pagination :paginator="$appointments" />
        @endif
    </x-admin.card>
</x-admin.layout>
