@php
    use App\Enums\AppointmentStatus;
    use App\Enums\PaymentStatus;
    $status = $a->payment_status;
    $holding = $status->isHolding() && $a->payment_due_at && $a->status->isActive();
    $canReview = auth()->user()->can('reviewPayment', $a);
@endphp
<x-admin.card title="GCash deposit" id="payment">
    <div class="flex items-center justify-between gap-3">
        <x-admin.badge :tone="$status->color()">{{ $status->label() }}</x-admin.badge>
        @if ($holding)
            <span class="text-xs font-medium text-warning">Held until {{ $a->payment_due_at->format('g:i A') }} · {{ $a->payment_due_at->diffForHumans() }}</span>
        @endif
    </div>

    <dl class="mt-4 space-y-2 text-sm">
        <div class="flex justify-between"><dt class="text-on-surface-variant">Deposit</dt><dd class="font-medium">₱{{ number_format($a->deposit_amount, 2) }}</dd></div>
        @if ((float) $a->amount_paid > 0)
            <div class="flex justify-between"><dt class="text-on-surface-variant">Received</dt><dd class="font-medium text-success">₱{{ number_format($a->amount_paid, 2) }}</dd></div>
        @endif
        <div class="flex justify-between"><dt class="text-on-surface-variant">Balance at the salon</dt><dd class="font-semibold">₱{{ number_format($a->balanceDue(), 2) }}</dd></div>
        @if ($a->paid_at)
            <div class="flex justify-between"><dt class="text-on-surface-variant">Paid</dt><dd>{{ $a->paid_at->format('M j, g:i A') }}</dd></div>
        @endif
    </dl>

    @if ($waiting)
        <div class="mt-5 border-t border-outline-variant/60 pt-4">
            <p class="text-sm font-semibold">Receipt to check</p>
            <p class="mt-1 text-sm">Ref No. <span class="font-mono font-semibold">{{ $waiting->reference_number }}</span></p>
            <p class="text-xs text-on-surface-variant">Sent {{ $waiting->created_at->format('M j, g:i A') }}</p>
            @if ($waiting->proof_path)
                <a href="{{ route('payments.proof', $waiting) }}" target="_blank" rel="noopener" class="mt-3 block" title="Open full size">
                    <img src="{{ route('payments.proof', $waiting) }}" alt="GCash receipt uploaded by the customer" class="max-h-96 w-full rounded-xl border border-outline-variant bg-surface-container object-contain">
                </a>
            @endif
            <p class="mt-3 rounded-xl bg-gold-soft px-3 py-2 text-xs">Open the salon's GCash app and find this Ref No. and amount in the transaction history before approving.</p>

            @if ($canReview)
                <form method="POST" action="{{ route('appointments.payment.approve', $a) }}" class="mt-4 space-y-3">
                    @csrf
                    <x-admin.input name="amount" type="number" step="0.01" min="1" label="Amount received" prefix="₱" :value="number_format((float) $a->deposit_amount, 2, '.', '')" required
                                   hint="Change it if the customer sent a different amount." />
                    <x-admin.button type="submit" icon="check_circle" class="w-full">Approve payment{{ in_array($a->status, [AppointmentStatus::Pending, AppointmentStatus::Rescheduled], true) ? ' & confirm' : '' }}</x-admin.button>
                </form>
                <x-admin.button variant="danger-text" icon="block" class="mt-2 w-full" @click="$dispatch('open-modal', 'reject-payment')">Reject receipt</x-admin.button>
            @endif
        </div>
    @elseif ($canReview && ($status->canRecordManually() || ($status->acceptsProof() && $a->status->isActive()) || ($status === PaymentStatus::Paid && in_array($a->status, [AppointmentStatus::Cancelled, AppointmentStatus::NoShow], true))))
        <div class="mt-5 flex flex-col gap-2 border-t border-outline-variant/60 pt-4">
            @if ($status->canRecordManually())
                <x-admin.button variant="tonal" icon="receipt_long" class="w-full" @click="$dispatch('open-modal', 'record-payment')">Record payment</x-admin.button>
            @endif
            @if ($status->acceptsProof() && $a->status->isActive())
                <form method="POST" action="{{ route('appointments.payment.waive', $a) }}" data-confirm="Hold this booking without a deposit? The customer will be told it's confirmed.">
                    @csrf
                    <x-admin.button type="submit" variant="outlined" icon="money_off" class="w-full">Waive deposit</x-admin.button>
                </form>
            @endif
            @if ($status === PaymentStatus::Paid && in_array($a->status, [AppointmentStatus::Cancelled, AppointmentStatus::NoShow], true))
                <x-admin.button variant="outlined" icon="undo" class="w-full" @click="$dispatch('open-modal', 'refund-payment')">Mark as refunded</x-admin.button>
            @endif
        </div>
    @endif

    @php $past = $receipts->reject(fn ($r) => $waiting && $r->is($waiting)); @endphp
    @if ($past->isNotEmpty())
        <div class="mt-5 border-t border-outline-variant/60 pt-4">
            <p class="text-sm font-semibold">Earlier receipts</p>
            <ul class="mt-2 space-y-3 text-sm">
                @foreach ($past as $r)
                    <li>
                        <div class="flex items-center justify-between gap-2">
                            <span class="font-mono text-xs">{{ $r->reference_number }}</span>
                            <x-admin.badge :tone="$r->status === 'approved' ? 'success' : ($r->status === 'rejected' ? 'danger' : 'info')">{{ ucfirst($r->status) }}</x-admin.badge>
                        </div>
                        <p class="text-xs text-on-surface-variant">
                            {{ $r->created_at->format('M j, g:i A') }}
                            @if ($r->amount_received) · ₱{{ number_format($r->amount_received, 2) }} @endif
                            @if ($r->reviewer) · {{ $r->reviewer->name }} @endif
                            @if ($r->proof_path) · <a href="{{ route('payments.proof', $r) }}" target="_blank" rel="noopener" class="text-primary hover:underline">screenshot</a> @else · recorded by staff @endif
                        </p>
                        @if ($r->rejection_reason)<p class="text-xs text-danger">“{{ $r->rejection_reason }}”</p>@endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</x-admin.card>
