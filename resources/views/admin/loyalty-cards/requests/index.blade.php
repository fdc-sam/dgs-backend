@php use App\Enums\LoyaltyCardRequestStatus; use App\Support\Fmt; use App\Support\LoyaltyNumber; @endphp
<x-admin.layout title="Loyalty card requests">
    <x-admin.page-header title="Loyalty Cards" subtitle="Customers who asked for a card in the app through a friend. Check each GCash receipt, then approve to activate the card in their app." />

    @include('admin.loyalty-cards.partials.tabs')

    @unless ($enabled)
        <div class="mb-4 flex items-start gap-3 rounded-card border border-warning/40 bg-warning-container px-4 py-3 text-sm text-warning" role="status">
            <span class="material-symbols-outlined" aria-hidden="true">info</span>
            <p>Customers can't ask for a card through a friend right now. Turn it on and add a GCash QR or number in
                @can('super-admin')<a href="{{ route('settings.edit') }}#loyalty" class="font-semibold underline">Settings → Loyalty card &amp; referrals</a>@else Settings (super admin) @endcan.</p>
        </div>
    @endunless

    <div class="scroll-row mb-4 flex gap-2 sm:flex-wrap" role="tablist">
        @foreach (LoyaltyCardRequestStatus::cases() as $tab)
            <a href="{{ route('loyalty-cards.requests.index', ['status' => $tab->value]) }}" role="tab" @if ($status === $tab) aria-selected="true" @endif
               @class(['inline-flex min-h-10 shrink-0 items-center gap-2 rounded-full px-4 text-sm font-medium', 'bg-secondary-container text-on-secondary-container' => $status === $tab, 'border border-outline-variant hover:bg-surface-container' => $status !== $tab])>
                {{ $tab->label() }} <span class="text-xs opacity-70">{{ $counts[$tab->value] ?? 0 }}</span>
            </a>
        @endforeach
    </div>

    <x-admin.filter-bar :action="route('loyalty-cards.requests.index')" :collapse="false" :except="['status']">
        <input type="hidden" name="status" value="{{ $status->value }}">
        <x-admin.input name="search" label="Search" :value="request('search')" placeholder="Name, phone, Ref No. or card number" class="w-full sm:w-80" />
    </x-admin.filter-bar>

    <x-admin.card :padding="false">
        @if ($items->isEmpty())
            <x-admin.empty-state icon="credit_card" :title="$status === LoyaltyCardRequestStatus::Pending ? 'No requests to check' : 'Nothing here'"
                                 :message="$status === LoyaltyCardRequestStatus::Pending ? 'When a customer scans a friend\'s QR in the app and sends their GCash receipt, it shows here.' : null" />
        @else
            <x-admin.table :headers="['Customer', 'Endorsed by', '>GCash amount', 'Ref No.', 'Card no.', $status === LoyaltyCardRequestStatus::Pending ? 'Sent' : 'Reviewed', 'Status', '>Actions']"
                           :mobile-order="['Customer' => 0, 'GCash amount' => 1, 'Status' => 2, 'Sent' => 3, 'Reviewed' => 3, 'Endorsed by' => 4, 'Ref No.' => 5, 'Card no.' => 6, 'Actions' => 7]"
                           :mobile-summary="['Status', $status === LoyaltyCardRequestStatus::Pending ? 'Sent' : 'Reviewed']"
                           :mobile-labels="['Ref No.' => 'Reference number', 'Card no.' => 'Card number']">
                @foreach ($items as $r)
                    <tr>
                        <td>
                            <p class="font-medium">{{ $r->customer?->name }}</p>
                            <p class="text-xs text-on-surface-variant">{{ $r->customer?->phone }}</p>
                        </td>
                        <td>
                            <p class="font-medium">{{ $r->endorser?->name }}</p>
                            <p class="text-xs text-on-surface-variant">gets {{ Fmt::peso($r->endorser_cash) }} cash</p>
                        </td>
                        <td class="whitespace-nowrap text-right font-medium">{{ Fmt::peso($r->gcash_amount) }}</td>
                        <td class="font-mono text-xs">{{ $r->reference_number }}</td>
                        <td class="whitespace-nowrap font-mono text-xs font-semibold">{{ LoyaltyNumber::format($r->loyaltyCard?->number) ?? '—' }}</td>
                        <td class="whitespace-nowrap">
                            @if ($status === LoyaltyCardRequestStatus::Pending)
                                {{ $r->created_at->format('M j, g:i A') }}
                                <p class="text-xs text-on-surface-variant max-md:hidden">{{ $r->created_at->diffForHumans() }}</p>
                            @else
                                {{ $r->reviewed_at?->format('M j, g:i A') ?? '—' }}
                            @endif
                        </td>
                        <td><x-admin.badge :tone="$r->status->color()">{{ $r->status->label() }}</x-admin.badge></td>
                        <td class="text-right">
                            <x-admin.button :href="route('loyalty-cards.requests.show', $r)" :variant="$r->isPending() ? 'tonal' : 'text'" size="sm"
                                            aria-label="Open the request from {{ $r->customer?->name }}">{{ $r->isPending() ? 'Review' : 'View' }}</x-admin.button>
                        </td>
                    </tr>
                @endforeach
            </x-admin.table>
            <x-admin.pagination :paginator="$items" />
        @endif
    </x-admin.card>
</x-admin.layout>
