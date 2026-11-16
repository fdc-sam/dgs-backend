@php use App\Enums\CashOutStatus; use App\Support\Fmt; @endphp
<x-admin.layout title="Cash-outs" live="cash_outs">
    <x-admin.page-header title="Cash-outs" subtitle="Customers asking for their earnings from referrals and card endorsements. Send the amount to the GCash number shown, then enter the GCash reference number. The customer is notified and the amount leaves their balance." />

    {{-- The send / reject forms live in dialogs that are closed again after a redirect: show their errors here. --}}
    @foreach (['request', 'reference', 'reason'] as $field)
        @error($field)
            <div class="mb-4 rounded-card border border-danger/40 bg-danger-container px-4 py-3 text-sm text-danger" role="alert">{{ $message }}</div>
        @enderror
    @endforeach

    <div class="scroll-row mb-4 flex gap-2 sm:flex-wrap" role="tablist">
        @foreach ([...array_combine(CashOutStatus::values(), array_map(fn ($s) => $s->label(), CashOutStatus::cases())), 'all' => 'All'] as $key => $label)
            <a href="{{ route('cash-outs.index', ['status' => $key]) }}" role="tab" @if ($status === $key) aria-selected="true" @endif
               @class(['inline-flex min-h-10 shrink-0 items-center gap-2 rounded-full px-4 text-sm font-medium', 'bg-secondary-container text-on-secondary-container' => $status === $key, 'border border-outline-variant hover:bg-surface-container' => $status !== $key])>
                {{ $label }} @if ($key !== 'all')<span class="text-xs opacity-70">{{ $counts[$key] ?? 0 }}</span>@endif
            </a>
        @endforeach
    </div>

    <x-admin.filter-bar :action="route('cash-outs.index')" :collapse="false" :except="['status']">
        <input type="hidden" name="status" value="{{ $status }}">
        <x-admin.input name="search" label="Search" :value="request('search')" placeholder="Customer, GCash number or reference no." class="w-full sm:w-80" />
    </x-admin.filter-bar>

    <div x-data="{ copied: false, target: { customer: '', gcashName: '', gcashNumber: '', amount: '', balance: '', complete: '', reject: '' } }">
        <x-admin.card :padding="false">
            @if ($items->isEmpty())
                <x-admin.empty-state icon="account_balance_wallet" :title="$status === CashOutStatus::Pending->value ? 'No cash-outs to send' : 'Nothing here'"
                                     :message="$status === CashOutStatus::Pending->value ? 'Customers ask for their earnings from Profile → My referrals → Cash out in the app.' : null" />
            @else
                <x-admin.table :headers="['Customer', 'Send to GCash', '>Amount', 'Requested', 'Status', '>Actions']"
                               :mobile-order="['Customer' => 0, 'Amount' => 1, 'Status' => 2, 'Send to GCash' => 3, 'Requested' => 4, 'Actions' => 5]"
                               :mobile-summary="['Amount', 'Status']">
                    @foreach ($items as $c)
                        @php
                            $customer = $c->customer;
                            $balance = $customer ? ($balances[$customer->id] ?? null) : null;
                            $target = ['customer' => $customer?->name ?? 'Deleted customer', 'gcashName' => $c->gcash_name, 'gcashNumber' => $c->gcash_number,
                                'amount' => Fmt::peso($c->amount), 'balance' => $balance !== null ? Fmt::peso($balance) : '—',
                                'complete' => route('cash-outs.complete', $c), 'reject' => route('cash-outs.reject', $c)];
                        @endphp
                        <tr>
                            <td class="whitespace-nowrap">
                                @if ($customer && ! $customer->trashed())
                                    <a href="{{ route('customers.show', $customer) }}" class="font-medium hover:underline">{{ $customer->name }}</a>
                                @else
                                    <p class="font-medium">{{ $customer?->name ?? 'Deleted customer' }}</p>
                                @endif
                                <p class="text-xs text-on-surface-variant">{{ $customer?->phone ?? '—' }}</p>
                                @if ($c->isPending() && $balance !== null)
                                    <p class="text-xs text-on-surface-variant">Balance {{ Fmt::peso($balance) }}</p>
                                @endif
                            </td>
                            <td class="whitespace-nowrap">
                                <p class="font-medium">{{ $c->gcash_name }}</p>
                                <p class="font-mono text-sm">{{ $c->gcash_number }}</p>
                            </td>
                            <td class="whitespace-nowrap text-right font-semibold">{{ Fmt::peso($c->amount) }}</td>
                            <td class="whitespace-nowrap">
                                {{ $c->created_at->format('M j, g:i A') }}
                                <p class="text-xs text-on-surface-variant max-md:hidden">{{ $c->created_at->diffForHumans() }}</p>
                            </td>
                            <td>
                                <x-admin.badge :tone="$c->status->color()">{{ $c->status->label() }}</x-admin.badge>
                                @if ($c->status === CashOutStatus::Completed)
                                    <p class="mt-1 whitespace-nowrap text-xs text-on-surface-variant">Ref <span class="font-mono">{{ $c->reference_number }}</span></p>
                                @endif
                                @if ($c->processed_at)
                                    <p class="whitespace-nowrap text-xs text-on-surface-variant">{{ $c->processed_at->format('M j, Y') }}@if ($c->processor) · {{ $c->processor->name }}@endif</p>
                                @endif
                                @if ($c->admin_note)<p class="mt-1 max-w-[14rem] text-xs text-on-surface-variant">“{{ $c->admin_note }}”</p>@endif
                            </td>
                            <td class="text-right">
                                @if ($c->isPending())
                                    <div class="flex flex-wrap justify-end gap-1">
                                        <x-admin.button size="sm" icon="send" @click="target = {{ \Illuminate\Support\Js::from($target) }}; copied = false; $dispatch('open-modal', 'cash-out-complete')">Mark as sent</x-admin.button>
                                        <x-admin.button variant="danger-text" size="sm" @click="target = {{ \Illuminate\Support\Js::from($target) }}; $dispatch('open-modal', 'cash-out-reject')">Reject</x-admin.button>
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </x-admin.table>
                <x-admin.pagination :paginator="$items" />
            @endif
        </x-admin.card>

        <x-admin.modal name="cash-out-complete" title="Send cash-out" max-width="max-w-xl">
            <form method="POST" :action="target.complete" class="space-y-4">
                @csrf
                <p class="text-sm text-on-surface-variant">
                    Send the amount to this GCash number first, then enter the reference number from GCash. The customer is notified and the amount is taken off their balance.
                </p>

                <div class="rounded-card bg-surface-container px-4 py-3">
                    <p class="eyebrow">Send to</p>
                    <p class="mt-1 font-semibold" x-text="target.gcashName"></p>
                    <p class="text-sm text-on-surface-variant">Requested by <span x-text="target.customer"></span> · balance <span x-text="target.balance"></span></p>
                    <div class="mt-3 flex flex-wrap items-end justify-between gap-2">
                        <div>
                            <p class="text-xs text-on-surface-variant">GCash number</p>
                            <p class="text-xl font-semibold tracking-wide" x-text="target.gcashNumber"></p>
                        </div>
                        <x-admin.button variant="text" size="sm" icon="content_copy"
                                        @click="navigator.clipboard?.writeText(target.gcashNumber); copied = true; setTimeout(() => copied = false, 2000)">
                            <span x-text="copied ? 'Copied' : 'Copy number'"></span>
                        </x-admin.button>
                    </div>
                    <div class="mt-3 border-t border-outline-variant/60 pt-3">
                        <p class="text-xs text-on-surface-variant">Amount</p>
                        <p class="text-xl font-semibold" x-text="target.amount"></p>
                    </div>
                </div>

                <x-admin.input name="reference" id="cash_out_reference" label="GCash reference no." required maxlength="40" autocomplete="off" placeholder="e.g. 1234 567 890123" />
                <x-admin.input name="note" id="cash_out_note" label="Message to the customer (optional)" maxlength="500" placeholder="e.g. Thank you for recommending us!" />
                <div class="flex justify-end gap-2">
                    <x-admin.button variant="text" @click="$dispatch('close-modal', 'cash-out-complete')">Close</x-admin.button>
                    <x-admin.button type="submit" icon="check_circle">Mark as sent and notify</x-admin.button>
                </div>
            </form>
        </x-admin.modal>

        <x-admin.modal name="cash-out-reject" title="Reject cash-out">
            <form method="POST" :action="target.reject" class="space-y-4">
                @csrf
                <p class="text-sm text-on-surface-variant">
                    <span class="font-semibold text-on-surface" x-text="target.customer"></span>'s cash-out of
                    <span class="font-semibold text-on-surface" x-text="target.amount"></span> will not be sent. The amount stays in their earnings and they can ask again.
                </p>
                <x-admin.textarea name="reason" id="cash_out_reject_reason" label="Reason (shared with the customer)" rows="2" required
                                  placeholder="e.g. The GCash number and name don't match. Please check and request again." />
                <div class="flex justify-end gap-2">
                    <x-admin.button variant="text" @click="$dispatch('close-modal', 'cash-out-reject')">Close</x-admin.button>
                    <x-admin.button type="submit" variant="danger">Reject</x-admin.button>
                </div>
            </form>
        </x-admin.modal>
    </div>
</x-admin.layout>
