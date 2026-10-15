@php $days = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday']; $byDay = $profile->schedules->keyBy('day_of_week'); @endphp
<x-admin.layout title="My schedule">
    <x-admin.page-header title="My schedule" subtitle="Your weekly hours and assigned services. Ask an admin to make changes." />
    <div class="grid gap-6 lg:grid-cols-3">
        <x-admin.card title="Weekly hours" class="lg:col-span-2" :padding="false">
            <x-admin.table :headers="['Day', 'Branch', 'Hours', 'Break']">
                @foreach ($days as $i => $day)
                    @php $s = $byDay->get($i); @endphp
                    <tr>
                        <td class="font-medium">{{ $day }}</td>
                        @if ($s && $s->is_working)
                            <td>{{ $s->branch?->name }}</td>
                            <td>{{ \Carbon\Carbon::parse($s->start_time)->format('g:i A') }} – {{ \Carbon\Carbon::parse($s->end_time)->format('g:i A') }}</td>
                            <td>{{ $s->break_start ? \Carbon\Carbon::parse($s->break_start)->format('g:i A').' – '.\Carbon\Carbon::parse($s->break_end)->format('g:i A') : '—' }}</td>
                        @else
                            <td colspan="3" class="text-on-surface-variant">Day off</td>
                        @endif
                    </tr>
                @endforeach
            </x-admin.table>
        </x-admin.card>
        <div class="space-y-6">
            <x-admin.card title="Upcoming days off">
                @forelse ($profile->daysOff as $off)
                    <p class="py-1 text-sm"><strong>{{ $off->date->format('D, M j') }}</strong> · {{ $off->branch?->name ?? 'All branches' }}</p>
                @empty
                    <p class="text-sm text-on-surface-variant">None scheduled.</p>
                @endforelse
            </x-admin.card>
            <x-admin.card title="Services I perform">
                <div class="flex flex-wrap gap-2">@forelse ($profile->services as $s)<x-admin.badge tone="primary">{{ $s->name }}</x-admin.badge>@empty<p class="text-sm text-on-surface-variant">None assigned.</p>@endforelse</div>
            </x-admin.card>
        </div>
    </div>
</x-admin.layout>
