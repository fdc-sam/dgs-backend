@php
    use App\Http\Controllers\Admin\CalendarController as Cal;
    $hourPx = 64;
    $startHour = Cal::START_HOUR;
    $endHour = Cal::END_HOUR;
    $query = fn (array $over = []) => route('calendar', array_filter([...request()->only('branch_id', 'staff_id', 'cancelled'), 'view' => $view, 'date' => $date->toDateString(), ...$over]));
    $top = fn ($time) => max(0, (\Carbon\Carbon::parse($time)->hour - $startHour) * 60 + \Carbon\Carbon::parse($time)->minute) / 60 * $hourPx;
    $height = fn ($a) => max(22, $a->durationMinutes() / 60 * $hourPx - 2);
    $tone = fn ($a) => match ($a->status->color()) {
        'success' => 'border-success bg-success-container text-success',
        'info' => 'border-info bg-info-container text-info',
        'danger' => 'border-danger bg-danger-container/70 text-danger line-through',
        default => 'border-warning bg-warning-container text-warning',
    };
    $dot = fn ($a) => match ($a->status->color()) {
        'success' => 'bg-success', 'info' => 'bg-info', 'danger' => 'bg-danger', default => 'bg-warning',
    };
    $title = match ($view) {
        'day' => $date->format('l, F j, Y'),
        'week' => $from->format('M j').' – '.$to->format('M j, Y'),
        'month' => $date->format('F Y'),
    };
@endphp
<x-admin.layout title="Calendar" live="appointments">
    <x-admin.page-header title="Calendar" :subtitle="$title">
        <x-slot:actions>
            <div class="inline-flex rounded-full border border-outline-variant bg-white p-1 max-sm:flex-1" role="group" aria-label="Calendar view">
                @foreach (['day' => 'Day', 'week' => 'Week', 'month' => 'Month'] as $key => $label)
                    <a href="{{ $query(['view' => $key]) }}" @class(['min-h-9 rounded-full px-4 py-2 text-center text-sm font-medium max-sm:flex-1 max-sm:px-2', 'bg-primary text-on-primary' => $view === $key, 'hover:bg-surface-container' => $view !== $key]) @if ($view === $key) aria-current="page" @endif>{{ $label }}</a>
                @endforeach
            </div>
            <div class="inline-flex items-center gap-1 max-sm:gap-0">
                <x-admin.button :href="$query(['date' => $prev->toDateString()])" variant="text" icon="chevron_left" aria-label="Previous" class="max-sm:px-2.5" />
                <x-admin.button :href="$query(['date' => now()->toDateString()])" variant="outlined" size="sm">Today</x-admin.button>
                <x-admin.button :href="$query(['date' => $next->toDateString()])" variant="text" icon="chevron_right" aria-label="Next" class="max-sm:px-2.5" />
            </div>
        </x-slot:actions>
    </x-admin.page-header>

    <x-admin.filter-bar :action="route('calendar')" :except="['view', 'date']">
        <input type="hidden" name="view" value="{{ $view }}"><input type="hidden" name="date" value="{{ $date->toDateString() }}">
        <x-admin.select name="branch_id" label="Branch" :options="$branches" :value="request('branch_id')" placeholder="All branches" class="w-52" />
        @if ($staff->isNotEmpty())
            <x-admin.select name="staff_id" label="Staff" :options="$staff" :value="request('staff_id')" placeholder="All staff" class="w-52" />
        @endif
        <x-admin.checkbox name="cancelled" label="Show cancelled" :checked="request()->boolean('cancelled')" />
    </x-admin.filter-bar>

    @if ($view === 'month')
        <x-admin.card :padding="false" class="overflow-hidden">
            <div class="grid grid-cols-7 border-b border-outline-variant bg-surface-container text-center text-xs font-semibold uppercase tracking-wide text-on-surface-variant">
                @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $d)<div class="py-2"><span class="md:hidden">{{ mb_substr($d, 0, 1) }}</span><span class="max-md:hidden">{{ $d }}</span></div>@endforeach
            </div>
            <div class="grid grid-cols-7">
                @for ($day = $from; $day->lte($to); $day = $day->addDay())
                    @php $items = $byDay[$day->toDateString()] ?? collect(); @endphp
                    <div @class(['relative min-h-16 border-b border-r border-outline-variant/60 p-1 text-center md:min-h-28 md:p-1.5 md:text-left', 'bg-surface-dim/50 text-outline' => $day->month !== $date->month])>
                        {{-- Phones: the whole cell opens the day; bookings show as coloured dots. --}}
                        <a href="{{ $query(['view' => 'day', 'date' => $day->toDateString()]) }}" class="absolute inset-0 md:hidden" aria-label="{{ $day->format('l, F j') }}: {{ trans_choice(':count booking|:count bookings', $items->count()) }}"></a>
                        <a href="{{ $query(['view' => 'day', 'date' => $day->toDateString()]) }}"
                           @class(['mb-1 inline-flex h-7 w-7 items-center justify-center rounded-full text-sm', 'bg-primary font-semibold text-on-primary' => $day->isToday(), 'hover:bg-surface-container-high' => ! $day->isToday()])>{{ $day->day }}</a>
                        @if ($items->isNotEmpty())
                            <div class="flex flex-wrap justify-center gap-0.5 md:hidden" aria-hidden="true">
                                @foreach ($items->take(4) as $a)<span class="h-1.5 w-1.5 rounded-full {{ $dot($a) }}"></span>@endforeach
                            </div>
                            @if ($items->count() > 4)<p class="text-[0.625rem] leading-3 text-on-surface-variant md:hidden" aria-hidden="true">+{{ $items->count() - 4 }}</p>@endif
                        @endif
                        @foreach ($items->take(3) as $a)
                            <a href="{{ route('appointments.show', $a) }}" class="mb-0.5 block truncate rounded-md border-l-4 px-1.5 py-0.5 text-xs max-md:hidden {{ $tone($a) }}" title="{{ $a->customer->name }} — {{ $a->items->pluck('service_name')->join(', ') }}">
                                {{ $a->startsAt()->format('g:i') }} {{ $a->customer->name }}
                            </a>
                        @endforeach
                        @if ($items->count() > 3)
                            <a href="{{ $query(['view' => 'day', 'date' => $day->toDateString()]) }}" class="block px-1.5 text-xs font-medium text-primary max-md:hidden">+{{ $items->count() - 3 }} more</a>
                        @endif
                    </div>
                @endfor
            </div>
        </x-admin.card>
    @else
        @php
            $columns = $view === 'day'
                ? $staffColumns->map(fn ($s) => ['key' => $s->id, 'label' => $s->user?->name, 'sub' => $s->position, 'items' => ($byDay[$date->toDateString()] ?? collect())->filter(fn ($a) => $a->staff_profile_id === $s->id || $a->items->contains('staff_profile_id', $s->id))])
                : collect(range(0, 6))->map(fn ($i) => ['key' => $from->addDays($i)->toDateString(), 'label' => $from->addDays($i)->format('D j'), 'sub' => null, 'today' => $from->addDays($i)->isToday(), 'items' => $byDay[$from->addDays($i)->toDateString()] ?? collect()]);
            $laneMap = $view === 'day' ? [] : $lanes;
        @endphp
        {{-- Phones: an agenda list in place of the time grid. --}}
        <div class="space-y-5 md:hidden">
            @foreach ($view === 'day' ? [$date] : collect(range(0, 6))->map(fn ($i) => $from->addDays($i)) as $day)
                @php $dayItems = ($byDay[$day->toDateString()] ?? collect())->sortBy('start_time'); @endphp
                <section aria-labelledby="agenda-{{ $day->toDateString() }}">
                    <div class="mb-2 flex items-center gap-2 px-1">
                        <h2 id="agenda-{{ $day->toDateString() }}" @class(['text-sm font-semibold', 'text-on-surface-variant' => ! $day->isToday()])>{{ $day->format('l, M j') }}</h2>
                        @if ($day->isToday())<span class="rounded-full bg-gold px-2 py-0.5 text-[0.6875rem] font-semibold text-on-surface">Today</span>@endif
                        <span class="ml-auto text-xs text-outline">{{ trans_choice(':count booking|:count bookings', $dayItems->count()) }}</span>
                    </div>
                    <div class="overflow-hidden rounded-card border border-outline-variant bg-white">
                        @forelse ($dayItems as $a)
                            <a href="{{ route('appointments.show', $a) }}" class="flex items-center gap-3 border-b border-outline-variant px-4 py-3 last:border-0 active:bg-surface-container/70">
                                <div class="w-12 shrink-0">
                                    <p class="text-sm font-semibold">{{ $a->startsAt()->format('g:i') }}</p>
                                    <p class="text-xs text-on-surface-variant">{{ $a->startsAt()->format('A') }}</p>
                                </div>
                                <span class="w-1 self-stretch rounded-full {{ $dot($a) }}" aria-hidden="true"></span>
                                <div class="min-w-0 flex-1">
                                    <p @class(['truncate font-medium', 'line-through' => $a->status->color() === 'danger'])>{{ $a->customer->name }}</p>
                                    <p class="truncate text-sm text-on-surface-variant">{{ $a->items->pluck('service_name')->join(', ') }}</p>
                                    <p class="truncate text-xs text-outline">{{ $a->startsAt()->format('g:i') }}–{{ $a->endsAt()->format('g:i A') }}@if ($a->staff?->user?->name) · {{ $a->staff->user->name }}@endif</p>
                                </div>
                                <x-admin.status-badge :status="$a->status" />
                            </a>
                        @empty
                            <p class="px-4 py-3.5 text-sm text-outline">No bookings</p>
                        @endforelse
                    </div>
                </section>
            @endforeach
        </div>

        <x-admin.card :padding="false" class="overflow-hidden max-md:hidden">
            @if ($columns->isEmpty())
                <x-admin.empty-state icon="badge" title="No staff to show" message="Add staff to this branch to see their day." />
            @else
                <div class="overflow-x-auto">
                    <div class="grid min-w-[720px]" style="grid-template-columns: 4rem repeat({{ $columns->count() }}, minmax(8rem, 1fr));">
                        <div class="sticky top-0 border-b border-outline-variant bg-surface-container"></div>
                        @foreach ($columns as $col)
                            <div class="border-b border-l border-outline-variant bg-surface-container px-2 py-2 text-center">
                                <p @class(['text-sm font-semibold', 'text-primary' => $col['today'] ?? false])>{{ $col['label'] }}</p>
                                @if ($col['sub'])<p class="truncate text-xs text-on-surface-variant">{{ $col['sub'] }}</p>@endif
                            </div>
                        @endforeach

                        <div class="relative" style="height: {{ ($endHour - $startHour) * $hourPx }}px">
                            @for ($h = $startHour; $h < $endHour; $h++)
                                <div class="absolute right-2 -translate-y-2 text-xs text-outline" style="top: {{ ($h - $startHour) * $hourPx }}px">{{ \Carbon\Carbon::createFromTime($h)->format('g A') }}</div>
                            @endfor
                        </div>
                        @foreach ($columns as $col)
                            <div class="relative border-l border-outline-variant/70" style="height: {{ ($endHour - $startHour) * $hourPx }}px">
                                @for ($h = $startHour; $h < $endHour; $h++)
                                    <div class="absolute inset-x-0 border-t border-outline-variant/40" style="top: {{ ($h - $startHour) * $hourPx }}px"></div>
                                @endfor
                                @foreach ($col['items'] as $a)
                                    @php
                                        $lane = $laneMap[$col['key']][$a->id] ?? ['lane' => 0, 'lanes' => 1];
                                        $w = 100 / $lane['lanes'];
                                    @endphp
                                    <a href="{{ route('appointments.show', $a) }}"
                                       class="absolute overflow-hidden rounded-lg border-l-4 px-2 py-1 text-xs shadow-e1 transition hover:z-10 hover:shadow-e2 {{ $tone($a) }}"
                                       style="top: {{ $top($a->start_time) }}px; height: {{ $height($a) }}px; left: calc({{ $lane['lane'] * $w }}% + 2px); width: calc({{ $w }}% - 4px);"
                                       title="{{ $a->startsAt()->format('g:i A') }}–{{ $a->endsAt()->format('g:i A') }} · {{ $a->customer->name }} · {{ $a->items->pluck('service_name')->join(', ') }}">
                                        <p class="truncate font-semibold">{{ $a->startsAt()->format('g:i') }} {{ $a->customer->name }}</p>
                                        <p class="truncate opacity-80">{{ $a->items->pluck('service_name')->join(', ') }}</p>
                                        @if ($view === 'week')<p class="truncate opacity-70">{{ $a->staff?->user?->name }}</p>@endif
                                    </a>
                                @endforeach
                                @if (($col['today'] ?? ($view === 'day' && $date->isToday())) && now()->hour >= $startHour && now()->hour < $endHour)
                                    <div class="pointer-events-none absolute inset-x-0 z-20 border-t-2 border-danger" style="top: {{ $top(now()->format('H:i')) }}px" aria-hidden="true"></div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif
        </x-admin.card>
    @endif

    <div class="mt-4 flex flex-wrap gap-3 text-xs text-on-surface-variant">
        <span class="inline-flex items-center gap-1.5"><span class="h-3 w-3 rounded-sm bg-warning-container ring-2 ring-warning"></span> Pending / rescheduled</span>
        <span class="inline-flex items-center gap-1.5"><span class="h-3 w-3 rounded-sm bg-info-container ring-2 ring-info"></span> Confirmed / in progress</span>
        <span class="inline-flex items-center gap-1.5"><span class="h-3 w-3 rounded-sm bg-success-container ring-2 ring-success"></span> Completed</span>
        <span class="inline-flex items-center gap-1.5"><span class="h-3 w-3 rounded-sm bg-danger-container ring-2 ring-danger"></span> Cancelled / no-show</span>
    </div>
</x-admin.layout>
