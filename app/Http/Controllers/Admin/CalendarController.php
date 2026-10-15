<?php

namespace App\Http\Controllers\Admin;

use App\Enums\AppointmentStatus;
use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\StaffProfile;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class CalendarController extends Controller
{
    public const START_HOUR = 7;

    public const END_HOUR = 22;

    public function __invoke(Request $request): View
    {
        $data = $request->validate([
            'view' => ['nullable', 'in:day,week,month'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'branch_id' => ['nullable', 'string'],
            'staff_id' => ['nullable', 'string'],
            'cancelled' => ['nullable', 'boolean'],
        ]);

        $user = $request->user();
        $view = $data['view'] ?? 'week';
        $date = CarbonImmutable::parse($data['date'] ?? now()->toDateString());
        $staffId = $user->isStaff() ? ($user->staffProfile?->id ?? '') : ($data['staff_id'] ?? null);
        $branchId = $data['branch_id'] ?? null;

        [$from, $to] = match ($view) {
            'day' => [$date, $date],
            'week' => [$date->startOfWeek(), $date->endOfWeek()->startOfDay()],
            'month' => [$date->startOfMonth()->startOfWeek(), $date->endOfMonth()->endOfWeek()->startOfDay()],
        };

        $appointments = Appointment::query()
            ->with(['customer', 'staff.user', 'items.staff.user', 'branch'])
            ->whereBetween('appointment_date', [$from->toDateString(), $to->toDateString()])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->when($staffId !== null, fn ($q) => $q->where(fn ($q) => $q->where('staff_profile_id', $staffId)
                ->orWhereHas('items', fn ($items) => $items->where('staff_profile_id', $staffId))))
            ->when(! ($data['cancelled'] ?? false), fn ($q) => $q->whereNotIn('status', [AppointmentStatus::Cancelled->value]))
            ->orderBy('start_time')
            ->get();

        $byDay = $appointments->groupBy(fn (Appointment $a) => $a->appointment_date->toDateString());

        $staffColumns = collect();
        if ($view === 'day') {
            $staffColumns = StaffProfile::query()->with('user')
                ->when($branchId, fn ($q) => $q->atBranch($branchId))
                ->when($staffId !== null, fn ($q) => $q->whereKey($staffId))
                ->where(fn ($q) => $q->bookable()->orWhereIn('id', $appointments->pluck('staff_profile_id')->filter()->merge($appointments->flatMap(fn ($a) => $a->items->pluck('staff_profile_id')))->filter()->unique()))
                ->get()->sortBy('user.name')->values();
        }

        return view('admin.calendar', [
            'view' => $view,
            'date' => $date,
            'from' => $from,
            'to' => $to,
            'byDay' => $byDay,
            'lanes' => $byDay->map(fn (Collection $day) => $this->lanes($day)),
            'staffColumns' => $staffColumns,
            'branches' => Branch::orderBy('name')->pluck('name', 'id'),
            'staff' => $user->isStaff() ? collect() : StaffProfile::with('user')->get()->sortBy('user.name')->mapWithKeys(fn ($s) => [$s->id => $s->user?->name]),
            'prev' => match ($view) {
                'day' => $date->subDay(), 'week' => $date->subWeek(), 'month' => $date->subMonthNoOverflow()
            },
            'next' => match ($view) {
                'day' => $date->addDay(), 'week' => $date->addWeek(), 'month' => $date->addMonthNoOverflow()
            },
        ]);
    }

    /**
     * Assign overlapping appointments to side-by-side lanes (greedy interval partitioning).
     *
     * @return array<string, array{lane: int, lanes: int}>
     */
    private function lanes(Collection $appointments): array
    {
        $laneEnds = [];
        $assigned = [];
        $cluster = [];
        $clusterEnd = '';
        $result = [];

        $flush = function () use (&$cluster, &$laneEnds, &$result) {
            $count = max(1, count($laneEnds));
            foreach ($cluster as $id => $lane) {
                $result[$id] = ['lane' => $lane, 'lanes' => $count];
            }
            $cluster = [];
            $laneEnds = [];
        };

        foreach ($appointments->sortBy('start_time') as $a) {
            if ($cluster && $a->start_time >= $clusterEnd) {
                $flush();
            }
            $lane = null;
            foreach ($laneEnds as $i => $end) {
                if ($end <= $a->start_time) {
                    $lane = $i;
                    break;
                }
            }
            $lane ??= count($laneEnds);
            $laneEnds[$lane] = $a->end_time;
            $cluster[$a->id] = $lane;
            $clusterEnd = max($clusterEnd, $a->end_time);
        }
        $flush();

        return $result;
    }
}
