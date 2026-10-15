@php use App\Enums\PaymentStatus; @endphp
@can('reviewPayment', $a)
    <x-admin.modal name="reject-payment" title="Reject GCash receipt">
        <form method="POST" action="{{ route('appointments.payment.reject', $a) }}" class="space-y-4">
            @csrf
            <p class="text-sm text-on-surface-variant">The customer sees this reason and gets {{ app(\App\Services\PaymentService::class)->windowMinutes() }} more minutes to send a new receipt. The slot stays on hold until then.</p>
            <x-admin.textarea name="reason" id="payment_reject_reason" label="Reason (shared with the customer)" rows="3" required
                              placeholder="e.g. We couldn't find this Ref No. in our GCash history." />
            <div class="flex flex-wrap gap-2 text-xs">
                @foreach (['We couldn\'t find this Ref No. in our GCash history.', 'The amount sent is less than the deposit.', 'The screenshot is unreadable. Please send a clearer one.'] as $preset)
                    <button type="button" class="rounded-full border border-outline-variant px-3 py-1.5 hover:bg-surface-container"
                            @click="$el.closest('form').querySelector('textarea').value = @js($preset)">{{ $preset }}</button>
                @endforeach
            </div>
            <div class="flex justify-end gap-2">
                <x-admin.button variant="text" @click="$dispatch('close-modal', 'reject-payment')">Close</x-admin.button>
                <x-admin.button type="submit" variant="danger">Reject receipt</x-admin.button>
            </div>
        </form>
    </x-admin.modal>

    @if ($a->payment_status->canRecordManually())
        <x-admin.modal name="record-payment" title="Record a GCash payment">
            <form method="POST" action="{{ route('appointments.payment.approve', $a) }}" class="space-y-4">
                @csrf
                <p class="text-sm text-on-surface-variant">For a deposit the customer sent outside the app (e.g. the receipt came by chat). Check it in the salon's GCash history first.
                    @if (! $a->status->isActive()) This booking is {{ strtolower($a->status->label()) }}, so it won't be restored: record the money, then refund or rebook. @endif
                </p>
                <x-admin.input name="reference_number" label="GCash Ref No." required class="font-mono" />
                <x-admin.input name="amount" id="record_amount" type="number" step="0.01" min="1" label="Amount received" prefix="₱" :value="number_format((float) ((float) $a->deposit_amount > 0 ? $a->deposit_amount : $a->total_amount), 2, '.', '')" required />
                <div class="flex justify-end gap-2">
                    <x-admin.button variant="text" @click="$dispatch('close-modal', 'record-payment')">Close</x-admin.button>
                    <x-admin.button type="submit">Record payment</x-admin.button>
                </div>
            </form>
        </x-admin.modal>
    @endif

    @if ($a->payment_status === PaymentStatus::Paid)
        <x-admin.modal name="refund-payment" title="Mark deposit as refunded">
            <form method="POST" action="{{ route('appointments.payment.refund', $a) }}" class="space-y-4">
                @csrf
                <p class="text-sm text-on-surface-variant">Send ₱{{ number_format($a->amount_paid, 2) }} back to the customer's GCash first, then record it here. The customer is notified.</p>
                <x-admin.input name="note" label="Note (internal, optional)" placeholder="e.g. Refund Ref No. 1234567890123" />
                <div class="flex justify-end gap-2">
                    <x-admin.button variant="text" @click="$dispatch('close-modal', 'refund-payment')">Close</x-admin.button>
                    <x-admin.button type="submit">Mark as refunded</x-admin.button>
                </div>
            </form>
        </x-admin.modal>
    @endif
@endcan
