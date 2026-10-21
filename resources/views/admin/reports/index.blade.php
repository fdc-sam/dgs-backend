@php
    $links = [
        'sales' => ['Sales', 'payments', 'Gross and net revenue, discounts and product cost by day.'],
        'appointments' => ['Appointments', 'event_note', 'Bookings by status, cancellation and no-show rates.'],
        'services' => ['Services', 'content_cut', 'Most booked and highest earning services.'],
        'staff' => ['Staff', 'badge', 'Appointments, service hours, revenue and ratings per staff member.'],
        'branches' => ['Branches', 'storefront', 'Performance by location.'],
    ];
@endphp
<x-admin.layout title="Reports">
    <x-admin.page-header title="Reports" subtitle="Gross revenue = completed appointments (after discounts) and completed walk-ins. Net revenue = gross minus product cost from Inventory." />
    @include('admin.reports._range', ['action' => route('reports.index')])

    <div class="mb-6 grid grid-cols-2 gap-3 sm:gap-4 xl:grid-cols-4">
        @foreach ($sales->summary as $label => $value)<x-admin.stat :label="$label" :value="$value" icon="payments" tone="gold" />@endforeach
        @foreach ($appointments->summary as $label => $value)<x-admin.stat :label="$label" :value="$value" icon="event_note" tone="info" />@endforeach
    </div>

    <x-admin.card title="Revenue" :subtitle="$range->label()" class="mb-6">
        <div class="h-72"><canvas data-chart='@json($sales->chart)' role="img" aria-label="Revenue chart"></canvas></div>
    </x-admin.card>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-5">
        @foreach ($links as $key => [$label, $icon, $desc])
            <a href="{{ route('reports.show', ['report' => $key, ...request()->only('range', 'from', 'to')]) }}" class="group rounded-card border border-outline-variant/70 bg-white p-5 shadow-e1 transition hover:shadow-e2">
                <span class="mb-3 flex h-11 w-11 items-center justify-center rounded-2xl bg-primary-container text-on-primary-container"><span class="material-symbols-outlined" aria-hidden="true">{{ $icon }}</span></span>
                <p class="font-semibold group-hover:text-primary">{{ $label }} report</p>
                <p class="mt-1 text-sm text-on-surface-variant">{{ $desc }}</p>
            </a>
        @endforeach
    </div>
</x-admin.layout>
