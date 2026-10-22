@php
    $peso = fn ($v) => \App\Support\Fmt::peso($v);
    $isStaff = auth()->user()->isStaff();
    $branchName = $stats['branch_id'] ? $branches[$stats['branch_id']] : null;
    $range = array_filter(['from' => $stats['from']->toDateString(), 'to' => $stats['to']->toDateString(), 'branch_id' => $stats['branch_id']]);
    $greeting = 'Good '.(now()->hour < 12 ? 'morning' : (now()->hour < 18 ? 'afternoon' : 'evening')).', '.\Illuminate\Support\Str::before(auth()->user()->name.' ', ' ');
    $label = $stats['period_label'];
    $hint = $stats['period_hint'];
    // Things a manager should act on, most urgent first; only non-zero ones are listed.
    $attention = $isStaff ? [] : array_filter([
        ['count' => $paymentsToVerify, 'icon' => 'receipt_long', 'title' => 'GCash payments to verify', 'text' => 'Customers sent a receipt — confirm or reject it.', 'href' => route('payments.index')],
        ['count' => $stats['pending_count'], 'icon' => 'pending_actions', 'title' => 'bookings awaiting confirmation', 'text' => 'Upcoming appointments still marked pending.',
            'href' => route('appointments.index', array_filter(['status' => 'pending', 'branch_id' => $stats['branch_id']]))],
        ['count' => $lowStockCount, 'icon' => 'inventory_2', 'title' => 'products low or out of stock', 'text' => 'At or below their restock alert.', 'href' => route('products.index', ['stock' => 'low'])],
    ], fn ($row) => $row['count'] > 0);
@endphp
<x-admin.layout title="Dashboard" live="appointments">
    <x-admin.page-header title="Dashboard" :subtitle="$greeting">
        @can('manage')
            <x-slot:actions>
                <x-admin.button :href="route('walk-ins.create')" icon="add">New walk-in</x-admin.button>
            </x-slot:actions>
        @endcan
    </x-admin.page-header>

    {{-- Filters: branch + period. Either one reloads the dashboard. --}}
    <div class="mb-8 flex flex-wrap items-center gap-3 sm:-mt-3 sm:mb-10">
        @unless ($isStaff)
            <form method="GET" action="{{ route('dashboard') }}" class="relative w-full sm:w-60" x-data>
                <input type="hidden" name="period" value="{{ $stats['period'] }}">
                <span class="material-symbols-outlined pointer-events-none absolute top-1/2 left-3.5 z-10 -translate-y-1/2 text-xl text-outline" style="font-variation-settings: 'wght' 300" aria-hidden="true">storefront</span>
                <select name="branch_id" aria-label="Branch" data-no-placeholder data-container-class="select2-with-icon" @change="$el.form.submit()">
                    <option value="">All branches</option>
                    @foreach ($branches as $id => $name)
                        <option value="{{ $id }}" @selected($stats['branch_id'] === $id)>{{ $name }}</option>
                    @endforeach
                </select>
            </form>
        @endunless
        <nav class="flex rounded-full bg-surface-container-high p-1 max-sm:w-full sm:inline-flex" aria-label="Period">
            @foreach (\App\Services\ReportService::PERIODS as $key => $periodLabel)
                @php $active = $stats['period'] === $key; @endphp
                <a href="{{ route('dashboard', array_filter(['period' => $key, 'branch_id' => $stats['branch_id']])) }}" @if ($active) aria-current="true" @endif
                   @class(['inline-flex min-h-9 items-center justify-center gap-2 rounded-full px-5 text-sm font-medium transition max-sm:flex-1',
                           'bg-white text-on-surface shadow-e1' => $active, 'text-on-surface-variant hover:text-on-surface' => ! $active])>
                    @if ($active)<span class="h-1.5 w-1.5 rounded-full bg-gold" aria-hidden="true"></span>@endif{{ $periodLabel }}
                </a>
            @endforeach
        </nav>
        <span class="inline-flex items-center gap-2 text-sm font-medium text-on-surface-variant">
            <span class="h-2 w-2 rounded-full bg-success" aria-hidden="true"></span>{{ now()->format('l, F j') }}
        </span>
    </div>

    {{-- Snapshot --}}
    <x-admin.section-label title="Business snapshot" :hint="$label.' at a glance · '.($isStaff ? 'your appointments' : ($branchName ?? 'all branches'))" />
    <div class="grid grid-cols-2 gap-3 sm:gap-5 xl:grid-cols-4">
        <x-admin.stat :label="'Appointments · '.$label" :value="$stats['appointments_count']" icon="today" :hint="$hint" :href="route('appointments.index', $range)" />
        <x-admin.stat label="Pending confirmation" :value="$stats['pending_count']" icon="pending_actions" :tone="$stats['pending_count'] ? 'warning' : 'primary'" hint="Upcoming"
                      :href="route('appointments.index', array_filter(['status' => 'pending', 'branch_id' => $stats['branch_id']]))" />
        <x-admin.stat :label="'Completed · '.$label" :value="$stats['completed_count']" icon="task_alt" :hint="$isStaff ? $hint : 'Appointments and walk-ins'" />
        @if ($isStaff)
            <x-admin.stat :label="'Revenue · '.$label" :value="$peso($stats['revenue'])" icon="payments" :hint="$hint" />
        @else
            <x-admin.stat label="Customers" :value="number_format($stats['customers'])" icon="groups" :hint="'+'.$stats['new_customers'].' new · '.strtolower($label)" :href="route('customers.index')" />
        @endif
    </div>

    {{-- Business health (managers): money figures, then the trend. --}}
    @unless ($isStaff)
        <x-admin.section-label class="mt-12" title="Business health" :hint="'Completed services · '.strtolower($label)" :href="route('reports.index')" link="Reports" />
        <div class="rounded-card border border-outline-variant bg-white p-4 sm:p-6">
            @php
                $tiles = [
                    ['Gross revenue · '.$label, $peso($stats['revenue']), $hint, null],
                    ['Net revenue · '.$label, $peso($stats['revenue'] - $stats['product_cost']), 'After '.$peso($stats['product_cost']).' in products', 'text-success'],
                    ['Product cost · '.$label, $peso($stats['product_cost']), 'Inventory used', null],
                    ['Average per visit', $peso($stats['average_ticket']), $label.' · gross', null],
                ];
            @endphp
            <div class="grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
                @foreach ($tiles as [$tileLabel, $tileValue, $tileHint, $tileTone])
                    <div class="rounded-2xl border border-outline-variant bg-surface/60 p-3.5 sm:p-5">
                        <p class="line-clamp-2 min-h-10 text-[0.8125rem] leading-5 text-on-surface-variant sm:min-h-0 sm:text-sm">{{ $tileLabel }}</p>
                        <p @class(['mt-1 truncate text-lg font-bold leading-7 tracking-tight sm:mt-1.5 sm:text-[1.625rem] sm:leading-9', $tileTone])>{{ $tileValue }}</p>
                        <p class="mt-1 truncate text-xs text-on-surface-variant sm:text-sm" title="{{ $tileHint }}">{{ $tileHint }}</p>
                    </div>
                @endforeach
            </div>
            <p class="mt-4 flex items-start gap-2 text-[0.8125rem] text-outline sm:mt-5 sm:text-sm">
                <span class="material-symbols-outlined shrink-0 text-base" aria-hidden="true">info</span>
                Net revenue is what completed services were charged minus the products they used (Inventory) — not accounting profit. Full breakdowns are in Reports.
            </p>
        </div>
    @endunless

    <x-admin.card title="Revenue trend · 14 days" :subtitle="$isStaff ? 'Completed services' : 'Completed services · net is after product cost'" class="mt-6">
        <x-slot:actions><span class="text-sm font-semibold">{{ $peso($stats['revenue']) }} <span class="font-normal text-on-surface-variant">{{ strtolower($label) }}</span></span></x-slot:actions>
        <div class="h-56 sm:h-72"><canvas data-chart='@json($stats['revenue_chart'])' aria-label="Revenue chart for the last 14 days" role="img"></canvas></div>
    </x-admin.card>

    {{-- Needs your attention (managers) --}}
    @unless ($isStaff)
        <x-admin.section-label class="mt-12" title="Needs your attention" hint="Sorted by urgency · tap to act" />
        <div class="overflow-hidden rounded-card border border-outline-variant bg-white">
            @forelse ($attention as $row)
                <a href="{{ $row['href'] }}" class="group relative flex items-center gap-3 border-b border-outline-variant px-5 py-4 last:border-0 hover:bg-surface-container/70 sm:gap-4 sm:px-6">
                    <span class="absolute inset-y-3 left-0 w-1 rounded-r-full {{ $loop->first ? 'bg-danger' : 'bg-gold' }}" aria-hidden="true"></span>
                    <span class="flex h-11 w-11 shrink-0 items-center justify-center rounded-xl bg-surface-container text-on-surface">
                        <span class="material-symbols-outlined" style="font-variation-settings: 'wght' 300" aria-hidden="true">{{ $row['icon'] }}</span>
                    </span>
                    <span class="min-w-0 flex-1">
                        <span class="block font-semibold">{{ $row['count'] }} {{ $row['title'] }}</span>
                        <span class="block text-sm text-on-surface-variant sm:truncate">{{ $row['text'] }}</span>
                    </span>
                    <span class="material-symbols-outlined text-outline transition group-hover:translate-x-0.5 group-hover:text-on-surface" aria-hidden="true">chevron_right</span>
                </a>
            @empty
                <x-admin.empty-state icon="task_alt" title="All caught up" message="No payments to verify, pending bookings or low stock right now." class="py-10" />
            @endforelse
        </div>
    @endunless

    {{-- Today --}}
    <x-admin.section-label class="mt-12" title="Today" hint="Bookings and visits" />
    <div class="grid gap-6 xl:grid-cols-3">
        <x-admin.card title="Today's appointments" class="xl:col-span-2" :padding="false">
            <x-slot:actions><x-admin.button :href="route('appointments.index', ['date' => now()->toDateString()])" variant="text" size="sm">View all</x-admin.button></x-slot:actions>
            @forelse ($stats['today'] as $a)
                <a href="{{ route('appointments.show', $a) }}" class="flex items-center gap-3 border-b border-outline-variant px-5 py-3.5 last:border-0 hover:bg-surface-container/70 sm:gap-4 sm:px-6">
                    <div class="w-12 shrink-0 text-center sm:w-16">
                        <p class="text-sm font-semibold">{{ \Carbon\Carbon::parse($a->start_time)->format('g:i') }}</p>
                        <p class="text-xs text-on-surface-variant">{{ \Carbon\Carbon::parse($a->start_time)->format('A') }}</p>
                    </div>
                    <div class="min-w-0 flex-1">
                        <p class="truncate font-medium">{{ $a->customer->name }}</p>
                        <p class="truncate text-sm text-on-surface-variant">{{ $a->items->map(fn ($item) => $item->service_name.($item->staff?->user?->name ? ' · '.$item->staff->user->name : ''))->join(', ') }}</p>
                    </div>
                    <x-admin.status-badge :status="$a->status" />
                </a>
            @empty
                <x-admin.empty-state icon="event_available" title="No appointments today" message="New bookings will appear here as customers book." />
            @endforelse
        </x-admin.card>

        <x-admin.card title="Popular services" subtitle="Last 30 days">
            @forelse ($stats['popular'] as $i => $s)
                @php $max = max(1, $stats['popular']->max('bookings')); @endphp
                <div class="mb-4 last:mb-0">
                    <div class="mb-1.5 flex justify-between text-sm"><span class="font-medium">{{ $s->service_name }}</span><span class="text-on-surface-variant">{{ $s->bookings }}</span></div>
                    <div class="h-2 overflow-hidden rounded-full bg-surface-container-high" role="presentation">
                        <div class="h-full rounded-full bg-gold" style="width: {{ round($s->bookings / $max * 100) }}%"></div>
                    </div>
                </div>
            @empty
                <x-admin.empty-state icon="content_cut" title="No bookings yet" class="py-6" />
            @endforelse
        </x-admin.card>

        @unless ($isStaff)
            <x-admin.card title="Completed walk-ins today" class="xl:col-span-2" :padding="false">
                <x-slot:actions><x-admin.button :href="route('walk-ins.index')" variant="text" size="sm">View all walk-ins</x-admin.button></x-slot:actions>
                @forelse ($stats['completed_walk_ins_today'] as $visit)
                    <a href="{{ route('walk-ins.show', $visit) }}" class="flex items-center gap-3 border-b border-outline-variant px-5 py-3.5 last:border-0 hover:bg-surface-container/70 sm:gap-4 sm:px-6">
                        <div class="w-12 shrink-0 text-center sm:w-16">
                            <p class="text-sm font-semibold">{{ $visit->completed_at->format('g:i') }}</p>
                            <p class="text-xs text-on-surface-variant">{{ $visit->completed_at->format('A') }}</p>
                        </div>
                        <div class="min-w-0 flex-1">
                            <p class="truncate font-medium">{{ $visit->guest_name ?: 'Walk-in guest' }}</p>
                            <p class="truncate text-sm text-on-surface-variant">{{ $visit->items->map(fn ($item) => $item->service_name.($item->staff?->user?->name ? ' · '.$item->staff->user->name : ''))->join(', ') }}</p>
                        </div>
                        <span class="shrink-0 text-sm font-semibold">₱{{ number_format($visit->total_amount, 2) }}</span>
                    </a>
                @empty
                    <x-admin.empty-state icon="content_cut" title="No completed walk-ins today" message="Walk-in visits appear here after their services are marked complete." class="py-6" />
                @endforelse
            </x-admin.card>
        @endunless

        <x-admin.card title="Recent bookings" :padding="false" :class="$isStaff ? 'xl:col-span-3' : ''">
            @forelse ($stats['recent'] as $a)
                <a href="{{ route('appointments.show', $a) }}" class="block border-b border-outline-variant px-5 py-3.5 last:border-0 hover:bg-surface-container/70 sm:px-6">
                    <div class="flex items-center justify-between gap-2">
                        <p class="truncate text-sm font-medium">{{ $a->customer->name }}</p>
                        <x-admin.status-badge :status="$a->status" />
                    </div>
                    <p class="mt-0.5 truncate text-xs text-on-surface-variant">{{ $a->appointment_number }} · {{ $a->startsAt()->format('M j, g:i A') }} · booked {{ $a->created_at->diffForHumans() }}</p>
                </a>
            @empty
                <x-admin.empty-state icon="inbox" title="No bookings yet" class="py-6" />
            @endforelse
        </x-admin.card>
    </div>
</x-admin.layout>
