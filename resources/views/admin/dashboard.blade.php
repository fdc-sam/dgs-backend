@php
    $peso = fn ($v) => \App\Support\Fmt::peso($v);
    $isStaff = auth()->user()->isStaff();
    $branchName = $stats['branch_id'] ? $branches[$stats['branch_id']] : null;
    $range = array_filter(['from' => $stats['from']->toDateString(), 'to' => $stats['to']->toDateString(), 'branch_id' => $stats['branch_id']]);
@endphp
<x-admin.layout title="Dashboard">
    <x-admin.page-header :title="'Good '.(now()->hour < 12 ? 'morning' : (now()->hour < 18 ? 'afternoon' : 'evening')).', '.\Illuminate\Support\Str::before(auth()->user()->name, ' ')"
                         :subtitle="now()->format('l, F j, Y').($isStaff ? ' · your appointments' : ' · '.($branchName ?? 'All branches'))">
        <x-slot:actions>
            @unless ($isStaff)
                {{-- Branch: picking one reloads the dashboard for it. --}}
                <form method="GET" action="{{ route('dashboard') }}" class="relative w-60" x-data>
                    <input type="hidden" name="period" value="{{ $stats['period'] }}">
                    <span class="material-symbols-outlined pointer-events-none absolute top-1/2 left-3.5 z-10 -translate-y-1/2 text-xl text-outline" aria-hidden="true">storefront</span>
                    <select name="branch_id" aria-label="Branch" data-no-placeholder data-container-class="select2-with-icon" @change="$el.form.submit()">
                        <option value="">All branches</option>
                        @foreach ($branches as $id => $name)
                            <option value="{{ $id }}" @selected($stats['branch_id'] === $id)>{{ $name }}</option>
                        @endforeach
                    </select>
                </form>
            @endunless
            {{-- Period toggle --}}
            <nav class="inline-flex rounded-full bg-surface-container-high p-1" aria-label="Period">
                @foreach (\App\Services\ReportService::PERIODS as $key => $label)
                    @php $active = $stats['period'] === $key; @endphp
                    <a href="{{ route('dashboard', array_filter(['period' => $key, 'branch_id' => $stats['branch_id']])) }}" @if ($active) aria-current="true" @endif
                       @class(['inline-flex min-h-9 items-center gap-2 rounded-full px-5 text-sm font-medium transition',
                               'bg-white text-on-surface shadow-e1' => $active, 'text-on-surface-variant hover:text-on-surface' => ! $active])>
                        @if ($active)<span class="h-1.5 w-1.5 rounded-full bg-danger" aria-hidden="true"></span>@endif{{ $label }}
                    </a>
                @endforeach
            </nav>
        </x-slot:actions>
    </x-admin.page-header>

    @php $label = $stats['period_label']; $hint = $stats['period_hint']; @endphp
    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        <x-admin.stat :label="'Appointments · '.$label" :value="$stats['appointments_count']" icon="today" :hint="$hint" :href="route('appointments.index', $range)" />
        <x-admin.stat label="Pending confirmation" :value="$stats['pending_count']" icon="pending_actions" tone="warning" hint="Upcoming"
                      :href="route('appointments.index', array_filter(['status' => 'pending', 'branch_id' => $stats['branch_id']]))" />
        <x-admin.stat :label="'Completed · '.$label" :value="$stats['completed_count']" icon="task_alt" tone="success" :hint="$isStaff ? $hint : 'Appointments and walk-ins'" />
        @if ($isStaff)
            <x-admin.stat :label="'Revenue · '.$label" :value="$peso($stats['revenue'])" icon="payments" tone="gold" :hint="$hint" />
        @else
            <x-admin.stat label="Customers" :value="number_format($stats['customers'])" icon="groups" :hint="'+'.$stats['new_customers'].' new · '.strtolower($label)" :href="route('customers.index')" />
            {{-- Gross = what completed services were charged; net = gross minus the products they used (Inventory). --}}
            <x-admin.stat :label="'Gross revenue · '.$label" :value="$peso($stats['revenue'])" icon="payments" tone="gold" :hint="$hint" />
            <x-admin.stat :label="'Net revenue · '.$label" :value="$peso($stats['revenue'] - $stats['product_cost'])" icon="savings" tone="success"
                          :hint="'After '.$peso($stats['product_cost']).' in products'" :href="route('products.index')" />
            <x-admin.stat :label="'Product cost · '.$label" :value="$peso($stats['product_cost'])" icon="inventory_2" tone="info" hint="Inventory used" :href="route('products.index')" />
            <x-admin.stat label="Average per visit" :value="$peso($stats['average_ticket'])" icon="receipt_long" tone="primary" :hint="$label.' · gross'" />
        @endif
    </div>

    <div class="mt-6 grid gap-6 xl:grid-cols-3">
        <x-admin.card title="Today's appointments" class="xl:col-span-2" :padding="false">
            <x-slot:actions><x-admin.button :href="route('appointments.index', ['date' => now()->toDateString()])" variant="text" size="sm">View all</x-admin.button></x-slot:actions>
            @forelse ($stats['today'] as $a)
                <a href="{{ route('appointments.show', $a) }}" class="flex items-center gap-4 border-b border-outline-variant/50 px-5 py-3 last:border-0 hover:bg-surface-container/60">
                    <div class="w-16 shrink-0 text-center">
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
                    <div class="mb-1 flex justify-between text-sm"><span class="font-medium">{{ $s->service_name }}</span><span class="text-on-surface-variant">{{ $s->bookings }}</span></div>
                    <div class="h-2 overflow-hidden rounded-full bg-surface-container-high" role="presentation">
                        <div class="h-full rounded-full bg-gold" style="width: {{ round($s->bookings / $max * 100) }}%"></div>
                    </div>
                </div>
            @empty
                <x-admin.empty-state icon="content_cut" title="No bookings yet" class="py-6" />
            @endforelse
        </x-admin.card>

        <x-admin.card title="Revenue overview" :subtitle="$isStaff ? 'Completed services, last 14 days' : 'Completed services, last 14 days · net is after product cost'" class="xl:col-span-2">
            <div class="h-64"><canvas data-chart='@json($stats['revenue_chart'])' aria-label="Revenue chart for the last 14 days" role="img"></canvas></div>
        </x-admin.card>

        <x-admin.card title="Recent bookings" :padding="false">
            @forelse ($stats['recent'] as $a)
                <a href="{{ route('appointments.show', $a) }}" class="block border-b border-outline-variant/50 px-5 py-3 last:border-0 hover:bg-surface-container/60">
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

    @unless ($isStaff)
        <x-admin.card title="Completed walk-ins today" class="mt-6" :padding="false">
            <x-slot:actions><x-admin.button :href="route('walk-ins.index')" variant="text" size="sm">View all walk-ins</x-admin.button></x-slot:actions>
            @forelse ($stats['completed_walk_ins_today'] as $visit)
                <a href="{{ route('walk-ins.show', $visit) }}" class="flex items-center gap-4 border-b border-outline-variant/50 px-5 py-3 last:border-0 hover:bg-surface-container/60">
                    <div class="w-16 shrink-0 text-center">
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
</x-admin.layout>
