<x-admin.layout title="Loyalty Cards">
    <x-admin.page-header title="Loyalty Cards" subtitle="Generate numbered cards, see which are available, and find each card holder.">
        <x-slot:actions>
            <span class="rounded-full bg-surface-container px-3 py-1.5 text-sm">{{ number_format($availableCount) }} available</span>
            @if ($reservedCount > 0)<span class="rounded-full bg-surface-container px-3 py-1.5 text-sm">{{ number_format($reservedCount) }} reserved</span>@endif
            <span class="rounded-full bg-surface-container px-3 py-1.5 text-sm">{{ number_format($assignedCount) }} assigned</span>
        </x-slot:actions>
    </x-admin.page-header>

    @include('admin.loyalty-cards.partials.tabs')

    <x-admin.card title="Generate loyalty cards" subtitle="Creates the next unused card numbers with the configured prefix and number length." class="mb-6">
        <form method="POST" action="{{ route('loyalty-cards.generate') }}" class="flex flex-wrap items-end gap-3">
            @csrf
            <x-admin.input name="quantity" type="number" min="1" max="1000" label="Number of cards" value="{{ old('quantity', 25) }}" required class="w-full sm:w-52" />
            <x-admin.button type="submit" icon="add_card">Generate cards</x-admin.button>
        </form>
        @error('quantity')<p class="mt-2 text-sm text-danger">{{ $message }}</p>@enderror
        <p class="mt-3 text-xs text-on-surface-variant">New numbers start after the highest existing loyalty card number.</p>
    </x-admin.card>

    <x-admin.filter-bar :action="route('loyalty-cards.index')" :collapse="false">
        <x-admin.input name="search" label="Search cards or holders" :value="request('search')" placeholder="Card number, name, email or phone" class="w-full sm:w-80" />
        <x-admin.select name="status" label="Assignment" :options="['all' => 'All cards', 'available' => 'Available', 'assigned' => 'Assigned']" :value="request('status', 'all')" class="w-full sm:w-48" />
    </x-admin.filter-bar>

    <x-admin.card :padding="false">
        @if ($cards->isEmpty())
            <x-admin.empty-state icon="credit_card" title="No loyalty cards found" message="Generate a batch of cards to start building your inventory." />
        @else
            <x-admin.table :headers="['Card number', 'Status', 'Customer holder', 'Phone', 'Expires', 'Actions']"
                           :mobile-order="['Card number' => 0, 'Status' => 1, 'Customer holder' => 2, 'Phone' => 3, 'Expires' => 4, 'Actions' => 5]"
                           :mobile-summary="['Status', 'Customer holder']">
                @foreach ($cards as $card)
                    @php $holder = $card->customer; $reservation = $holder ? null : ($reservations[$card->id] ?? null); @endphp
                    <tr>
                        <td class="font-mono font-semibold tracking-wide">{{ \App\Support\LoyaltyNumber::format($card->number) }}</td>
                        <td>
                            @if ($reservation)
                                <x-admin.badge tone="warning">Reserved · request</x-admin.badge>
                            @else
                                <x-admin.badge :tone="$holder ? ($holder->hasActiveLoyaltyCard() ? 'success' : 'warning') : 'info'">{{ $holder ? ($holder->hasActiveLoyaltyCard() ? 'Assigned · active' : 'Assigned · expired') : 'Available' }}</x-admin.badge>
                            @endif
                        </td>
                        <td>
                            @if ($holder)
                                <a href="{{ route('customers.show', $holder) }}" class="font-medium text-primary hover:underline">{{ $holder->name }}</a>
                            @elseif ($reservation)
                                <a href="{{ route('loyalty-cards.requests.show', $reservation) }}" class="font-medium text-primary hover:underline">For {{ $reservation->customer?->name }}</a>
                            @else
                                <span class="text-on-surface-variant">—</span>
                            @endif
                        </td>
                        <td>{{ $holder?->phone ?? '—' }}</td>
                        <td>{{ $holder?->loyalty_expires_at?->format('M j, Y') ?? '—' }}</td>
                        <td>
                            @if ($holder)
                                <form method="POST" action="{{ route('loyalty-cards.disconnect', $card) }}"
                                      data-confirm="Disconnect {{ \App\Support\LoyaltyNumber::format($card->number) }} from {{ $holder->name }}? The card will become available and the customer will lose loyalty card pricing.">
                                    @csrf
                                    <x-admin.button type="submit" variant="danger-text" size="sm">Disconnect</x-admin.button>
                                </form>
                            @else
                                <span class="text-on-surface-variant">—</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </x-admin.table>
            <x-admin.pagination :paginator="$cards" />
        @endif
    </x-admin.card>
</x-admin.layout>
