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
    $title = match ($view) {
        'day' => $date->format('l, F j, Y'),
        'week' => $from->format('M j').' – '.$to->format('M j, Y'),
        'month' => $date->format('F Y'),
    };
@endphp
<x-admin.layout title="Calendar">
    <x-admin.page-header title="Calendar" :subtitle="$title">
        <x-slot:actions>
            <div class="inline-flex rounded-full border border-outline-variant bg-white p-1" role="group" aria-label="Calendar view">
                @foreach (['day' => 'Day', 'week' => 'Week', 'month' => 'Month'] as $key => $label)
                    <a href="{{ $query(['view' => $key]) }}" @class(['min-h-9 rounded-full px-4 py-2 text-sm font-medium', 'bg-primary text-on-primary' => $view === $key, 'hover:bg-surface-container' => $view !== $key]) @if ($view === $key) aria-current="page" @endif>{{ $label }}</a>
                @endforeach
            </div>
            <div class="inline-flex items-center gap-1">
                <x-admin.button :href="$query(['date' => $prev->toDateString()])" variant="text" icon="chevron_left" aria-label="Previous" />
                <x-admin.button :href="$query(['date' => now()->toDateString()])" variant="outlined" size="sm">Today</x-admin.button>
                <x-admin.button :href="$query(['date' => $next->toDateString()])" variant="text" icon="chevron_right" aria-label="Next" />
            </div>
        </x-slot:actions>
    </x-admin.page-header>

    <x-admin.filter-bar :action="route('calendar')">
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
                @foreach (['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'] as $d)<div class="py-2">{{ $d }}</div>@endforeach
            </div>
            <div class="grid grid-cols-7">
                @for ($day = $from; $day->lte($to); $day = $day->addDay())
                    @php $items = $byDay[$day->toDateString()] ?? collect(); @endphp
                    <div @class(['min-h-28 border-b border-r border-outline-variant/60 p-1.5', 'bg-surface-dim/50 text-outline' => $day->month !== $date->month])>
                        <a href="{{ $query(['view' => 'day', 'date' => $day->toDateString()]) }}"
                           @class(['mb-1 inline-flex h-7 w-7 items-center justify-center rounded-full text-sm', 'bg-primary font-semibold text-on-primary' => $day->isToday(), 'hover:bg-surface-container-high' => ! $day->isToday()])>{{ $day->day }}</a>
                        @foreach ($items->take(3) as $a)
                            <a href="{{ route('appointments.show', $a) }}" class="mb-0.5 block truncate rounded-md border-l-4 px-1.5 py-0.5 text-xs {{ $tone($a) }}" title="{{ $a->customer->name }} — {{ $a->items->pluck('service_name')->join(', ') }}">
                                {{ $a->startsAt()->format('g:i') }} {{ $a->customer->name }}
                            </a>
                        @endforeach
                        @if ($items->count() > 3)
                            <a href="{{ $query(['view' => 'day', 'date' => $day->toDateString()]) }}" class="block px-1.5 text-xs font-medium text-primary">+{{ $items->count() - 3 }} more</a>
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
        <x-admin.card :padding="false" class="overflow-hidden">
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
