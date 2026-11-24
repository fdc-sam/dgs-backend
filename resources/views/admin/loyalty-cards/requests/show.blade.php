@php use App\Enums\LoyaltyCardRequestStatus; use App\Support\Fmt; use App\Support\LoyaltyNumber; @endphp
<x-admin.layout :title="'Membership request · '.$r->customer?->name">
    <x-admin.page-header :title="$r->customer?->name ?? 'Deleted customer'" :back="route('loyalty-cards.requests.index', ['status' => $r->status->value])"
                         :subtitle="'Asked for membership through a friend\'s invite · '.$r->created_at->format('M j, Y g:i A')">
        <x-slot:actions>
            <x-admin.badge :tone="$r->status->color()">{{ $r->status->label() }}</x-admin.badge>
        </x-slot:actions>
    </x-admin.page-header>

    @error('request')
        <div class="mb-4 rounded-card border border-danger/40 bg-danger-container px-4 py-3 text-sm text-danger" role="alert">{{ $message }}</div>
    @enderror

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-6 lg:col-span-2">
            <x-admin.card :title="$r->status === LoyaltyCardRequestStatus::Approved ? 'Membership' : 'Membership to activate'"
                          :subtitle="$r->isPending() ? 'This Member ID is set aside for the request. It becomes active in the customer\'s app as soon as you approve, for '.LoyaltyNumber::validity().'.' : null">
                <div class="flex flex-col gap-5 xl:flex-row xl:items-start">
                    <x-admin.loyalty-card :number="$r->loyaltyCard?->number" :name="$r->customer?->name" class="xl:w-80 xl:shrink-0" />
                    <dl class="grid flex-1 gap-3 text-sm">
                        <div><dt class="text-on-surface-variant">Member ID</dt><dd class="font-mono font-semibold">{{ LoyaltyNumber::format($r->loyaltyCard?->number) ?? 'Picked on approval' }}</dd></div>
                        @if ($r->status === LoyaltyCardRequestStatus::Approved && $r->customer?->loyalty_expires_at)
                            <div><dt class="text-on-surface-variant">Expires on</dt><dd class="font-medium">{{ $r->customer->loyalty_expires_at->format('M j, Y') }}</dd></div>
                        @endif
                        @if ($r->endorser_paid_directly)
                            <div><dt class="text-on-surface-variant">Membership price</dt><dd class="font-medium">{{ Fmt::peso((float) $r->gcash_amount + (float) $r->endorser_cash) }}
                                <span class="text-on-surface-variant">= {{ Fmt::peso($r->gcash_amount) }} GCash to the salon + {{ Fmt::peso($r->endorser_cash) }} cash to the friend who invited them</span></dd></div>
                        @else
                            <div><dt class="text-on-surface-variant">Membership price</dt><dd class="font-medium">{{ Fmt::peso($r->gcash_amount) }} <span class="text-on-surface-variant">paid to the salon</span></dd></div>
                            @if ((float) $r->endorser_cash > 0)
                                <div><dt class="text-on-surface-variant">Friend's reward</dt><dd>{{ Fmt::peso($r->endorser_cash) }} <span class="text-on-surface-variant">added to their Wallet when you approve</span></dd></div>
                            @endif
                        @endif
                        <div><dt class="text-on-surface-variant">Digital membership</dt><dd>Shows in the customer's app (Profile → Membership). Nothing to hand over.</dd></div>
                    </dl>
                </div>
            </x-admin.card>

            <div class="grid gap-6 sm:grid-cols-2">
                <x-admin.card title="Customer">
                    @if ($r->customer && ! $r->customer->trashed())
                        <p class="font-medium"><a href="{{ route('customers.show', $r->customer) }}" class="text-primary hover:underline">{{ $r->customer->name }}</a></p>
                        <p class="text-sm text-on-surface-variant">{{ $r->customer->phone ?? '—' }}</p>
                        <p class="text-sm text-on-surface-variant">{{ $r->customer->email }}</p>
                        @if ($r->isPending() && $r->customer->hasLoyaltyCard())
                            <p class="mt-3 rounded-xl bg-warning-container px-3 py-2 text-xs text-warning">Already a member ({{ LoyaltyNumber::format($r->customer->loyalty_number) }}){{ $r->customer->loyaltyCardExpired() ? ', expired' : '' }}. Reject this request, or remove their membership first.</p>
                        @endif
                    @else
                        <p class="text-sm text-on-surface-variant">This customer deleted their account.</p>
                    @endif
                </x-admin.card>
                <x-admin.card title="Invited by">
                    @if ($r->endorser && ! $r->endorser->trashed())
                        <p class="font-medium"><a href="{{ route('customers.show', $r->endorser) }}" class="text-primary hover:underline">{{ $r->endorser->name }}</a></p>
                        <p class="text-sm text-on-surface-variant">{{ $r->endorser->phone ?? '—' }}</p>
                        <p class="mt-2 text-sm">Member ID {{ LoyaltyNumber::format($r->endorser->loyalty_number) ?? '—' }}
                            @if ($r->endorser->hasLoyaltyCard())<x-admin.badge :tone="$r->endorser->hasActiveLoyaltyCard() ? 'success' : 'warning'">{{ $r->endorser->hasActiveLoyaltyCard() ? 'Active' : 'Expired' }}</x-admin.badge>@endif
                        </p>
                        <p class="mt-1 text-xs text-on-surface-variant">{{ $endorserStats['approved'] }} {{ \Illuminate\Support\Str::plural('invite', $endorserStats['approved']) }} approved · {{ Fmt::peso($endorserStats['earned']) }} earned</p>
                    @else
                        <p class="text-sm text-on-surface-variant">This customer deleted their account.</p>
                    @endif
                </x-admin.card>
            </div>
        </div>

        <div class="space-y-6">
            @php $counter = $r->payment_method === \App\Models\LoyaltyCardRequest::METHOD_COUNTER; $bank = $r->payment_method === \App\Models\LoyaltyCardRequest::METHOD_BANK; @endphp
            <x-admin.card :title="$counter ? 'Paid at the salon' : ($bank ? 'Bank payment' : 'GCash payment')" id="payment">
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between gap-3"><dt class="text-on-surface-variant">Paid by</dt><dd class="font-semibold">{{ $counter ? 'Paid at the salon' : ($bank ? 'Bank transfer' : 'GCash') }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-on-surface-variant">Amount to the salon</dt><dd class="font-semibold">{{ Fmt::peso($r->gcash_amount) }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-on-surface-variant">Ref No.</dt><dd class="{{ $counter ? 'font-semibold' : 'font-mono font-semibold' }}">{{ $counter ? 'Paid at the salon' : $r->reference_number }}</dd></div>
                    <div class="flex justify-between gap-3"><dt class="text-on-surface-variant">Sent</dt><dd>{{ $r->created_at->format('M j, g:i A') }}</dd></div>
                    @if ($r->endorser_paid_directly)
                        <div class="flex justify-between gap-3"><dt class="text-on-surface-variant">Cash to the friend</dt><dd>{{ Fmt::peso($r->endorser_cash) }} <span class="text-on-surface-variant">(customer confirmed)</span></dd></div>
                    @endif
                </dl>
                @if ($r->proof_path)
                    <a href="{{ route('loyalty-cards.requests.proof', $r) }}" target="_blank" rel="noopener" class="mt-4 block" title="Open full size">
                        <img src="{{ route('loyalty-cards.requests.proof', $r) }}" alt="Receipt uploaded by the customer" class="max-h-96 w-full rounded-xl border border-outline-variant bg-surface-container object-contain">
                    </a>
                @endif

                @if ($r->isPending())
                    @unless ($counter)
                    <p class="mt-4 rounded-xl bg-gold-soft px-3 py-2 text-xs">Open the salon's {{ $bank ? 'bank' : 'GCash' }} app and find this Ref No. and amount in the transaction history before approving.</p>
                    @endunless
                    <form method="POST" action="{{ route('loyalty-cards.requests.approve', $r) }}" class="mt-4"
                          data-confirm="Approve and activate {{ LoyaltyNumber::format($r->loyaltyCard?->number) ?? 'a membership' }} for {{ $r->customer?->name }}?">
                        @csrf
                        <x-admin.button type="submit" icon="check_circle" class="w-full">Approve</x-admin.button>
                    </form>
                    <x-admin.button variant="danger-text" icon="block" class="mt-2 w-full" @click="$dispatch('open-modal', 'reject-card-request')">Reject</x-admin.button>
                @else
                    <div class="mt-5 border-t border-outline-variant/60 pt-4 text-sm">
                        <p>{{ $r->status->label() }} {{ $r->reviewed_at?->format('M j, Y g:i A') }}@if ($r->reviewer) by {{ $r->reviewer->name }}@endif</p>
                        @if ($r->rejection_reason)<p class="mt-1 text-danger">“{{ $r->rejection_reason }}”</p>@endif
                    </div>
                @endif
            </x-admin.card>
        </div>
    </div>

    @if ($r->isPending())
        <x-admin.modal name="reject-card-request" title="Reject membership request">
            <form method="POST" action="{{ route('loyalty-cards.requests.reject', $r) }}" class="space-y-4">
                @csrf
                <p class="text-sm text-on-surface-variant">The customer sees this reason in the app and can send a new reference number. The Member ID goes back to the available ones.</p>
                <x-admin.textarea name="reason" id="card_request_reject_reason" label="Reason (shared with the customer)" rows="3" required
                                  placeholder="e.g. We couldn't find this Ref No. in our payment history." />
                <div class="flex flex-wrap gap-2 text-xs">
                    @foreach (['We couldn\'t find this Ref No. in our '.($bank ? 'bank' : 'GCash').' history.', 'The amount sent is less than '.Fmt::peso($r->gcash_amount).'.', 'This Ref No. doesn\'t match a payment to the salon. Please check it and send it again.'] as $preset)
                        <button type="button" class="rounded-full border border-outline-variant px-3 py-1.5 hover:bg-surface-container"
                                @click="$el.closest('form').querySelector('textarea').value = @js($preset)">{{ $preset }}</button>
                    @endforeach
                </div>
                <div class="flex justify-end gap-2">
                    <x-admin.button variant="text" @click="$dispatch('close-modal', 'reject-card-request')">Close</x-admin.button>
                    <x-admin.button type="submit" variant="danger">Reject</x-admin.button>
                </div>
            </form>
        </x-admin.modal>
    @endif
</x-admin.layout>
