@php use App\Enums\ReferralStatus; use App\Support\Fmt; @endphp
<x-admin.layout title="Referrals">
    <x-admin.page-header title="Referrals" subtitle="People customers referred. Once a referred person completes a service, send the referrer's reward to their GCash number." />

    <div class="scroll-row mb-4 flex gap-2 sm:flex-wrap" role="tablist">
        @foreach ([...array_combine(ReferralStatus::values(), array_map(fn ($s) => $s->label(), ReferralStatus::cases())), 'all' => 'All'] as $key => $label)
            <a href="{{ route('referrals.index', ['status' => $key]) }}" role="tab" @if ($status === $key) aria-selected="true" @endif
               @class(['inline-flex min-h-10 shrink-0 items-center gap-2 rounded-full px-4 text-sm font-medium', 'bg-secondary-container text-on-secondary-container' => $status === $key, 'border border-outline-variant hover:bg-surface-container' => $status !== $key])>
                {{ $label }} @if ($key !== 'all')<span class="text-xs opacity-70">{{ $counts[$key] ?? 0 }}</span>@endif
            </a>
        @endforeach
    </div>

    <x-admin.filter-bar :action="route('referrals.index')" :collapse="false" :except="['status']">
        <input type="hidden" name="status" value="{{ $status }}">
        <x-admin.input name="search" label="Search" :value="request('search')" placeholder="Name or number of either person" class="w-72" />
    </x-admin.filter-bar>

    <div x-data="{ target: { name: '', referrer: '', gcash: '', status: '', reward: '' } }">
        <x-admin.card :padding="false">
            @if ($referrals->isEmpty())
                <x-admin.empty-state icon="group_add" title="No referrals here" message="Customers add referrals from Profile → My referrals in the app." />
            @else
                <x-admin.table :headers="['Referred person', 'Referred by', 'Services', 'Visits', 'Status', '>Actions']" :wide="['Services']">
                    @foreach ($referrals as $r)
                        @php
                            $visit = $visits[$r->phone] ?? ['customer' => null, 'completed' => 0];
                            $referrer = $r->referrer;
                            $target = ['name' => $r->name, 'referrer' => $referrer?->name, 'gcash' => $referrer?->phone ?? '—',
                                'status' => route('referrals.status', $r), 'reward' => route('referrals.reward', $r)];
                        @endphp
                        <tr>
                            <td class="whitespace-nowrap">
                                <p class="font-medium">{{ $r->name }}</p>
                                <p class="text-sm">{{ $r->phone }}</p>
                                <p class="text-xs text-on-surface-variant">{{ $r->created_at->format('M j, Y') }}</p>
                            </td>
                            <td class="whitespace-nowrap">
                                @if ($referrer && ! $referrer->trashed())
                                    <a href="{{ route('customers.show', $referrer) }}" class="font-medium hover:underline">{{ $referrer->name }}</a>
                                @else
                                    <p class="font-medium">{{ $referrer?->name ?? 'Deleted customer' }}</p>
                                @endif
                                <p class="text-xs text-on-surface-variant">GCash: {{ $referrer?->phone ?? '—' }}</p>
                            </td>
                            <td class="max-w-xs">
                                <p class="text-sm">{{ $r->services->pluck('name')->join(', ') ?: '—' }}</p>
                                @if ($r->notes)<p class="mt-1 text-xs text-on-surface-variant">“{{ $r->notes }}”</p>@endif
                            </td>
                            <td class="whitespace-nowrap">
                                @if ($visit['completed'] > 0)
                                    <x-admin.badge tone="success" icon="check_circle">{{ $visit['completed'] }} completed {{ \Illuminate\Support\Str::plural('visit', $visit['completed']) }}</x-admin.badge>
                                @else
                                    <span class="text-sm text-on-surface-variant">No completed visit yet</span>
                                @endif
                                @if ($visit['customer'])
                                    <p class="mt-1 text-xs"><a href="{{ route('customers.show', $visit['customer']) }}" class="hover:underline">Has an app account since {{ $visit['customer']->created_at->format('M Y') }}</a></p>
                                @endif
                            </td>
                            <td>
                                <x-admin.badge :tone="['pending' => 'warning', 'contacted' => 'info', 'completed' => 'primary', 'rewarded' => 'success', 'declined' => 'danger'][$r->status->value]">{{ $r->status->label() }}</x-admin.badge>
                                @if ($r->status === ReferralStatus::Rewarded)
                                    <p class="mt-1 whitespace-nowrap text-xs text-on-surface-variant">{{ Fmt::peso($r->reward_amount) }} · Ref {{ $r->reward_reference }}</p>
                                    <p class="text-xs text-on-surface-variant">{{ $r->rewarded_at?->format('M j, Y') }}</p>
                                @endif
                                @if ($r->admin_note)<p class="mt-1 max-w-[14rem] text-xs text-on-surface-variant">{{ $r->admin_note }}</p>@endif
                            </td>
                            <td class="text-right">
                                <div class="flex flex-wrap justify-end gap-1">
                                    @if ($r->status === ReferralStatus::Completed)
                                        <x-admin.button variant="tonal" size="sm" icon="payments" @click="target = {{ \Illuminate\Support\Js::from($target) }}; $dispatch('open-modal', 'referral-reward')">Send reward</x-admin.button>
                                    @endif
                                    @if ($r->status === ReferralStatus::Pending)
                                        <form method="POST" action="{{ route('referrals.status', $r) }}">@csrf<input type="hidden" name="status" value="contacted">
                                            <x-admin.button type="submit" variant="text" size="sm">Contacted</x-admin.button></form>
                                    @endif
                                    @if (in_array($r->status, [ReferralStatus::Pending, ReferralStatus::Contacted], true))
                                        <form method="POST" action="{{ route('referrals.status', $r) }}">@csrf<input type="hidden" name="status" value="completed">
                                            <x-admin.button type="submit" variant="tonal" size="sm" icon="check">Service completed</x-admin.button></form>
                                    @endif
                                    @if (in_array($r->status, [ReferralStatus::Pending, ReferralStatus::Contacted, ReferralStatus::Completed], true))
                                        <x-admin.button variant="danger-text" size="sm" @click="target = {{ \Illuminate\Support\Js::from($target) }}; $dispatch('open-modal', 'referral-decline')">Not eligible</x-admin.button>
                                    @endif
                                    @if ($r->status === ReferralStatus::Declined)
                                        <form method="POST" action="{{ route('referrals.status', $r) }}">@csrf<input type="hidden" name="status" value="pending">
                                            <x-admin.button type="submit" variant="text" size="sm">Reopen</x-admin.button></form>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @endforeach
                </x-admin.table>
                <x-admin.pagination :paginator="$referrals" />
            @endif
        </x-admin.card>

        <x-admin.modal name="referral-reward" title="Send referral reward">
            <form method="POST" :action="target.reward" class="space-y-4">
                @csrf
                <p class="text-sm text-on-surface-variant">
                    Send the reward for referring <span class="font-semibold text-on-surface" x-text="target.name"></span> to
                    <span class="font-semibold text-on-surface" x-text="target.referrer"></span>'s GCash number, then record it here.
                </p>
                <div class="rounded-card bg-surface-container px-4 py-3">
                    <p class="text-xs text-on-surface-variant">GCash number</p>
                    <p class="text-lg font-semibold tracking-wide" x-text="target.gcash"></p>
                </div>
                <div class="grid gap-4 sm:grid-cols-2">
                    <x-admin.input name="amount" id="reward_amount" type="number" step="0.01" min="1" max="100000" label="Amount sent" prefix="₱" required />
                    <x-admin.input name="reference" id="reward_reference" label="GCash reference no." required />
                </div>
                <x-admin.input name="note" id="reward_note" label="Note for the customer (optional)" />
                <p class="text-xs text-on-surface-variant">The customer is notified in the app with the amount and reference number.</p>
                <div class="flex justify-end gap-2">
                    <x-admin.button variant="text" @click="$dispatch('close-modal', 'referral-reward')">Close</x-admin.button>
                    <x-admin.button type="submit" icon="payments">Record reward</x-admin.button>
                </div>
            </form>
        </x-admin.modal>

        <x-admin.modal name="referral-decline" title="Not eligible for a reward">
            <form method="POST" :action="target.status" class="space-y-4">
                @csrf
                <input type="hidden" name="status" value="declined">
                <p class="text-sm text-on-surface-variant">
                    <span class="font-semibold text-on-surface" x-text="target.referrer"></span>'s referral of
                    <span class="font-semibold text-on-surface" x-text="target.name"></span> will not earn a reward. The number can be referred again later.
                </p>
                <x-admin.textarea name="note" id="decline_note" label="Reason for the customer (optional)" rows="2" placeholder="e.g. Already a regular customer" />
                <div class="flex justify-end gap-2">
                    <x-admin.button variant="text" @click="$dispatch('close-modal', 'referral-decline')">Close</x-admin.button>
                    <x-admin.button type="submit" variant="danger">Mark not eligible</x-admin.button>
                </div>
            </form>
        </x-admin.modal>
    </div>
</x-admin.layout>
