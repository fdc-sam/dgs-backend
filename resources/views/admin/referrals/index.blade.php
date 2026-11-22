@php use App\Enums\ReferralStatus; use App\Support\Fmt; @endphp
<x-admin.layout title="Referrals" live="referrals">
    <x-admin.page-header title="Referrals" subtitle="Open a referral to view the details, choose services, and update its progress." />

    <div class="scroll-row mb-4 flex gap-2 sm:flex-wrap" role="tablist">
        @foreach ([...array_combine(ReferralStatus::values(), array_map(fn ($s) => $s->label(), ReferralStatus::cases())), 'all' => 'All'] as $key => $label)
            <a href="{{ route('referrals.index', ['status' => $key]) }}" role="tab" @if ($status === $key) aria-selected="true" @endif
               @class(['inline-flex min-h-10 shrink-0 items-center gap-2 rounded-full px-4 text-sm font-medium', 'bg-secondary-container text-on-secondary-container' => $status === $key, 'border border-outline-variant hover:bg-surface-container' => $status !== $key])>
                {{ $label }} @if ($key !== 'all')<x-admin.tab-count :count="$counts[$key] ?? 0" />@endif
            </a>
        @endforeach
    </div>

    <x-admin.filter-bar :action="route('referrals.index')" :collapse="false" :except="['status']">
        <input type="hidden" name="status" value="{{ $status }}">
        <x-admin.input name="search" label="Search" :value="request('search')" placeholder="Name or number of either person" class="w-72" />
    </x-admin.filter-bar>

    <x-admin.card :padding="false">
        @if ($referrals->isEmpty())
            <x-admin.empty-state icon="group_add" title="No referrals here" message="Customers add referrals from Profile → Earnings &amp; cash out in the app." />
        @else
            <x-admin.table :headers="['Referred person', 'Referred by', 'Services', 'Visits', '>Status']" :wide="['Services']"
                           :mobile-order="['Referred person' => 0, 'Status' => 1, 'Visits' => 2, 'Referred by' => 3, 'Services' => 4]"
                           :mobile-summary="['Status', 'Visits']">
                @foreach ($referrals as $r)
                    @php
                        $visit = $visits[$r->phone] ?? ['customer' => null, 'completed' => 0];
                        $referrer = $r->referrer;
                    @endphp
                    <tr role="link" tabindex="0" data-href="{{ route('referrals.show', $r) }}" onclick="window.location.href = this.dataset.href" onkeydown="if (event.key === 'Enter') window.location.href = this.dataset.href" class="cursor-pointer hover:bg-surface-container/60 focus-visible:outline-2 focus-visible:outline-primary">
                        <td class="whitespace-nowrap">
                            <p class="font-medium">{{ $r->name }}</p>
                            <p class="text-sm">{{ $r->phone }}</p>
                            <p class="text-xs text-on-surface-variant">{{ $r->created_at->format('M j, Y') }}</p>
                        </td>
                        <td class="whitespace-nowrap">
                            <p class="font-medium">{{ $referrer?->name ?? 'Deleted customer' }}</p>
                            <p class="text-xs text-on-surface-variant">{{ $referrer?->phone ?? '—' }}</p>
                        </td>
                        <td class="max-w-xs whitespace-normal break-words">
                            <p class="text-sm">{{ $r->services->pluck('name')->join(', ') ?: 'Not selected yet' }}</p>
                            @if ($r->notes)<p class="mt-1 text-xs text-on-surface-variant">“{{ $r->notes }}”</p>@endif
                        </td>
                        <td class="whitespace-nowrap">
                            @if ($visit['completed'] > 0)
                                <x-admin.badge tone="success" icon="check_circle">{{ $visit['completed'] }} completed {{ \Illuminate\Support\Str::plural('visit', $visit['completed']) }}</x-admin.badge>
                            @else
                                <span class="text-sm text-on-surface-variant">No completed visit yet</span>
                            @endif
                            @if ($visit['customer'])<p class="mt-1 text-xs text-on-surface-variant">App account · {{ $visit['customer']->created_at->format('M Y') }}</p>@endif
                        </td>
                        <td>
                            <x-admin.badge :tone="['pending' => 'warning', 'accepted' => 'info', 'in_progress' => 'primary', 'completed' => 'success', 'declined' => 'danger'][$r->status->value]">{{ $r->status->label() }}</x-admin.badge>
                            @if ($r->status === ReferralStatus::Completed)
                                <p class="mt-1 whitespace-nowrap text-xs text-on-surface-variant">@if ($r->reward_paid_directly){{ Fmt::peso($r->reward_amount) }} sent to GCash @else{{ Fmt::peso($r->reward_amount) }} added to earnings @endif</p>
                            @endif
                            @if ($r->admin_note)<p class="mt-1 max-w-[14rem] text-xs text-on-surface-variant">{{ $r->admin_note }}</p>@endif
                        </td>
                    </tr>
                @endforeach
            </x-admin.table>
            <x-admin.pagination :paginator="$referrals" />
        @endif
    </x-admin.card>
</x-admin.layout>
