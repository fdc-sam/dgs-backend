@php use App\Enums\PaymentStatus; @endphp
<x-admin.layout title="Payments">
    <x-admin.page-header title="Payments" subtitle="GCash deposits. Check each receipt against the salon's GCash transaction history before approving." />

    @unless ($enabled)
        <div class="mb-4 flex items-start gap-3 rounded-card border border-warning/40 bg-warning-container px-4 py-3 text-sm text-warning" role="status">
            <span class="material-symbols-outlined" aria-hidden="true">info</span>
            <p>No booking asks for a GCash deposit yet. Add a downpayment QR to a service (Services → Edit → GCash downpayment)@can('super-admin'), or turn on the salon-wide deposit in <a href="{{ route('settings.edit') }}#payments" class="font-semibold underline">Settings</a>@endcan.</p>
        </div>
    @endunless

    <div class="mb-6 flex flex-wrap gap-x-7 border-b border-outline-variant" role="tablist">
        @foreach ($tabs as $tab)
            <a href="{{ route('payments.index', ['status' => $tab->value]) }}" role="tab" @if ($status === $tab) aria-selected="true" @endif
               @class(['-mb-px inline-flex items-center gap-2 border-b-2 pb-3 pt-1 text-[0.9375rem] font-medium transition-colors', 'border-gold text-on-surface' => $status === $tab, 'border-transparent text-on-surface-variant hover:text-on-surface' => $status !== $tab])>
                {{ $tab->label() }} <span class="text-xs opacity-70">{{ $counts[$tab->value] ?? 0 }}</span>
            </a>
        @endforeach
    </div>

    <x-admin.filter-bar :action="route('payments.index')">
        <input type="hidden" name="status" value="{{ $status->value }}">
        <x-admin.input name="search" label="Search" :value="request('search')" placeholder="Booking #, Ref No., customer" class="w-full sm:w-72" />
    </x-admin.filter-bar>

    <x-admin.card :padding="false">
        @if ($appointments->isEmpty())
            <x-admin.empty-state icon="payments" :title="$status === PaymentStatus::Submitted ? 'No receipts to check' : 'Nothing here'"
                                 :message="$status === PaymentStatus::Submitted ? 'New GCash receipts from customers will appear here.' : null" />
        @else
            <x-admin.table :headers="['Appointment #', 'Customer', 'Appointment', '>Deposit', 'Ref No.', $status->isHolding() ? 'Pay by' : 'Updated', 'Status', '>Actions']">
                @foreach ($appointments as $a)
                    <tr>
                        <td class="font-mono text-xs"><a href="{{ route('appointments.show', $a) }}" class="font-semibold text-primary hover:underline">{{ $a->appointment_number }}</a></td>
                        <td>
                            <p class="font-medium">{{ $a->customer->name }}</p>
                            <p class="text-xs text-on-surface-variant">{{ $a->customer->phone }}</p>
                        </td>
                        <td class="whitespace-nowrap">{{ $a->startsAt()->format('M j, g:i A') }}<p class="text-xs text-on-surface-variant">{{ $a->branch->name }}</p></td>
                        <td class="whitespace-nowrap text-right font-medium">₱{{ number_format($a->deposit_amount, 2) }}
                            @if ((float) $a->amount_paid > 0 && (float) $a->amount_paid !== (float) $a->deposit_amount)
                                <p class="text-xs font-normal text-on-surface-variant">received ₱{{ number_format($a->amount_paid, 2) }}</p>
                            @endif
                        </td>
                        <td class="font-mono text-xs">{{ $a->latestPayment?->reference_number ?? '—' }}</td>
                        <td class="whitespace-nowrap">
                            @if ($status->isHolding())
                                {{ $a->payment_due_at?->format('g:i A') ?? '—' }}
                                @if ($a->payment_due_at)<p class="text-xs text-on-surface-variant">{{ $a->payment_due_at->diffForHumans() }}</p>@endif
                            @else
                                {{ $a->updated_at->format('M j, g:i A') }}
                            @endif
                        </td>
                        <td><x-admin.badge :tone="$a->payment_status->color()">{{ $a->payment_status->label() }}</x-admin.badge></td>
                        <td class="text-right">
                            <x-admin.button :href="route('appointments.show', $a).'#payment'" :variant="$status === PaymentStatus::Submitted ? 'tonal' : 'text'" size="sm"
                                            aria-label="Open {{ $a->appointment_number }}">{{ $status === PaymentStatus::Submitted ? 'Review' : 'View' }}</x-admin.button>
                        </td>
                    </tr>
                @endforeach
            </x-admin.table>
            <x-admin.pagination :paginator="$appointments" />
        @endif
    </x-admin.card>
</x-admin.layout>
