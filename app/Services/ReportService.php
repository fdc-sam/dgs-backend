<?php

namespace App\Services;

use App\Enums\AppointmentStatus;
use App\Enums\ReviewStatus;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\StaffProfile;
use App\Models\User;
use App\Models\WalkInVisit;
use App\Support\Reports\DateRange;
use App\Support\Reports\ReportTable;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Business reporting. Sales revenue includes completed appointments and walk-in visits, dated by the
 * appointment date or walk-in completion date respectively. Booking counts include every status unless stated otherwise.
 */
class ReportService
{
    private const COMPLETED = 'completed';

    /** Dashboard periods: value => label on the toggle. */
    public const PERIODS = ['today' => 'Today', 'week' => 'Week', 'month' => 'Month'];

    /**
     * Dashboard figures for a period (today / this week / this month), optionally for one branch.
     * Staff see only their own appointments (no walk-ins, product cost or branch filter).
     */
    public function dashboard(User $viewer, string $period = 'today', ?string $branchId = null): array
    {
        $period = array_key_exists($period, self::PERIODS) ? $period : 'today';
        [$from, $to] = $this->periodRange($period);
        $isStaff = $viewer->isStaff();
        $branchId = $isStaff ? null : $branchId;
        $today = now()->toDateString();

        $scope = fn (Builder $q) => $this->scopeViewer($q, $viewer)->when($branchId, fn ($q) => $q->where('branch_id', $branchId));
        $appointments = fn () => Appointment::query()->tap($scope)->whereBetween('appointment_date', [$from->toDateString(), $to->toDateString()]);
        $completedWalkIns = fn () => WalkInVisit::query()->where('status', self::COMPLETED)->when($branchId, fn ($q) => $q->where('branch_id', $branchId));
        $walkInsInPeriod = fn () => $completedWalkIns()->whereBetween('completed_at', [$from, $to]);

        $completed = $appointments()->where('status', self::COMPLETED)->count() + ($isStaff ? 0 : $walkInsInPeriod()->count());
        $revenue = (float) $appointments()->where('status', self::COMPLETED)->sum('total_amount')
            + ($isStaff ? 0 : (float) $walkInsInPeriod()->sum('total_amount'));
        // Gross minus product cost = net revenue. Managers only: staff see just their own bookings' revenue.
        $productCost = $isStaff ? 0.0 : (float) $this->productCostByDay($from, $to, $branchId)->sum();

        return [
            'period' => $period,
            'period_label' => match ($period) {
                'week' => 'This week',
                'month' => 'This month',
                default => 'Today',
            },
            'period_hint' => match ($period) {
                'week' => $from->format('M j').' – '.$to->format('M j'),
                'month' => $from->format('F Y'),
                default => $from->format('M j, Y'),
            },
            'from' => $from,
            'to' => $to,
            'branch_id' => $branchId,
            'appointments_count' => $appointments()->whereNotIn('status', ['cancelled'])->count(),
            'pending_count' => Appointment::query()->tap($scope)->where('status', 'pending')->where('starts_at', '>=', now()->startOfDay())->count(),
            'completed_count' => $completed,
            'revenue' => $revenue,
            'product_cost' => $productCost,
            'average_ticket' => $completed ? round($revenue / $completed, 2) : 0.0,
            'customers' => User::customers()->count(),
            'new_customers' => User::customers()->whereBetween('created_at', [$from, $to])->count(),
            'today' => Appointment::query()->tap($scope)->whereDate('appointment_date', $today)
                ->with(['customer', 'staff.user', 'items.staff.user', 'branch'])->orderBy('start_time')->get(),
            'recent' => Appointment::query()->tap($scope)->with(['customer', 'items', 'branch'])->latest()->limit(8)->get(),
            'completed_walk_ins_today' => $isStaff ? collect() : $completedWalkIns()->whereDate('completed_at', $today)
                ->with(['branch', 'staff.user', 'items.staff.user'])->latest('completed_at')->limit(8)->get(),
            'popular' => $this->popularServices(now()->subDays(30)->startOfDay(), now(), 5, $branchId),
            'revenue_chart' => $this->revenueSeries(now()->subDays(13)->startOfDay(), now()->startOfDay(), $viewer, $branchId),
        ];
    }

    /** @return array{0: CarbonImmutable, 1: CarbonImmutable} */
    private function periodRange(string $period): array
    {
        return match ($period) {
            'week' => [now()->startOfWeek(), now()->endOfWeek()],
            'month' => [now()->startOfMonth(), now()->endOfMonth()],
            default => [now()->startOfDay(), now()->endOfDay()],
        };
    }

    /**
     * Daily sales, with the same terms as the dashboard: gross revenue = what completed appointments (after
     * discounts) and completed walk-ins were charged; net revenue = gross minus the product cost recorded on them.
     */
    public function sales(DateRange $range, ?string $branchId = null): ReportTable
    {
        $rows = Appointment::query()
            ->where('status', self::COMPLETED)
            ->whereBetween('appointment_date', [$range->from->toDateString(), $range->to->toDateString()])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->selectRaw('DATE(appointment_date) as day, COUNT(*) as appointments, SUM(subtotal) as before_discounts, SUM(discount_amount) as discounts, SUM(total_amount) as gross')
            ->groupBy('day')
            ->toBase()
            ->get()
            ->keyBy(fn ($r) => (string) $r->day);

        $walkInRows = WalkInVisit::query()
            ->where('status', self::COMPLETED)
            ->whereBetween('completed_at', [$range->from->startOfDay(), $range->to->endOfDay()])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->selectRaw('DATE(completed_at) as day, COUNT(*) as walk_ins, SUM(total_amount) as revenue')
            ->groupBy('day')
            ->toBase()
            ->get()
            ->keyBy(fn ($r) => (string) $r->day);

        $costs = $this->productCostByDay($range->from, $range->to, $branchId);

        $table = collect($range->days())->map(function (string $day) use ($rows, $walkInRows, $costs) {
            $appointments = $rows[$day] ?? null;
            $walkIns = $walkInRows[$day] ?? null;
            $walkInRevenue = (float) ($walkIns->revenue ?? 0); // walk-ins have no discounts
            $gross = (float) ($appointments->gross ?? 0) + $walkInRevenue;
            $productCost = (float) ($costs[$day] ?? 0);

            return [
                'date' => CarbonImmutable::parse($day)->format('D, M j, Y'),
                'appointments' => (int) ($appointments->appointments ?? 0),
                'walk_ins' => (int) ($walkIns->walk_ins ?? 0),
                'before_discounts' => (float) ($appointments->before_discounts ?? 0) + $walkInRevenue,
                'discounts' => (float) ($appointments->discounts ?? 0),
                'gross' => $gross,
                'product_cost' => $productCost,
                'net' => round($gross - $productCost, 2),
            ];
        });

        $appointmentCount = $table->sum('appointments');
        $walkInCount = $table->sum('walk_ins');
        $count = $appointmentCount + $walkInCount;
        $gross = $table->sum('gross');
        $net = $table->sum('net');

        return new ReportTable(
            'Sales',
            [
                'date' => 'Date', 'appointments' => 'Completed appointments', 'walk_ins' => 'Completed walk-ins',
                'before_discounts' => '₱ Before discounts', 'discounts' => '₱ Discounts', 'gross' => '₱ Gross revenue',
                'product_cost' => '₱ Product cost', 'net' => '₱ Net revenue',
            ],
            $table->all(),
            [
                'date' => 'Total', 'appointments' => $appointmentCount, 'walk_ins' => $walkInCount,
                'before_discounts' => $table->sum('before_discounts'), 'discounts' => $table->sum('discounts'), 'gross' => $gross,
                'product_cost' => $table->sum('product_cost'), 'net' => $net,
            ],
            ['type' => 'line', 'money' => true, 'labels' => array_map(fn ($d) => CarbonImmutable::parse($d)->format('M j'), $range->days()), 'series' => [
                ['label' => 'Gross revenue', 'data' => $table->pluck('gross')->all()],
                ['label' => 'Net revenue', 'data' => $table->pluck('net')->all()],
            ]],
            [
                'Gross revenue' => '₱'.number_format($gross, 2),
                'Net revenue' => '₱'.number_format($net, 2),
                'Completed visits' => number_format($count),
                'Average per visit' => '₱'.number_format($count ? $gross / $count : 0, 2),
            ],
        );
    }

    public function appointments(DateRange $range, ?string $branchId = null): ReportTable
    {
        $raw = Appointment::query()
            ->whereBetween('appointment_date', [$range->from->toDateString(), $range->to->toDateString()])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->selectRaw('DATE(appointment_date) as day, status, COUNT(*) as total')
            ->groupBy('day', 'status')
            ->toBase() // raw rows: keep status as a plain string key
            ->get();

        $statuses = AppointmentStatus::cases();
        $byDay = $raw->groupBy(fn ($r) => (string) $r->day);

        $rows = collect($range->days())->map(function (string $day) use ($byDay, $statuses) {
            $counts = ($byDay[$day] ?? collect())->pluck('total', 'status');
            $row = ['date' => CarbonImmutable::parse($day)->format('D, M j, Y')];
            foreach ($statuses as $status) {
                $row[$status->value] = (int) ($counts[$status->value] ?? 0);
            }
            $row['total'] = array_sum(array_slice($row, 1));

            return $row;
        });

        $totals = ['date' => 'Total'];
        foreach ($statuses as $status) {
            $totals[$status->value] = $rows->sum($status->value);
        }
        $totals['total'] = $rows->sum('total');
        $all = max(1, $totals['total']);

        $columns = ['date' => 'Date'];
        foreach ($statuses as $status) {
            $columns[$status->value] = $status->label();
        }
        $columns['total'] = 'Total';

        return new ReportTable(
            'Appointments',
            $columns,
            $rows->all(),
            $totals,
            ['type' => 'bar', 'labels' => array_map(fn ($d) => CarbonImmutable::parse($d)->format('M j'), $range->days()), 'series' => [
                ['label' => 'Completed', 'data' => $rows->pluck('completed')->all()],
                ['label' => 'Cancelled', 'data' => $rows->pluck('cancelled')->all()],
            ]],
            [
                'Total bookings' => number_format($totals['total']),
                'Completed' => number_format($totals['completed']),
                'Cancellation rate' => number_format($totals['cancelled'] / $all * 100, 1).'%',
                'No-show rate' => number_format($totals['no_show'] / $all * 100, 1).'%',
            ],
        );
    }

    public function services(DateRange $range, ?string $branchId = null): ReportTable
    {
        $rows = DB::table('appointment_items')
            ->join('appointments', 'appointments.id', '=', 'appointment_items.appointment_id')
            ->whereBetween('appointments.appointment_date', [$range->from->toDateString(), $range->to->toDateString()])
            ->when($branchId, fn ($q) => $q->where('appointments.branch_id', $branchId))
            ->groupBy('appointment_items.service_id', 'appointment_items.service_name')
            ->selectRaw("appointment_items.service_id, appointment_items.service_name,
                COUNT(*) as bookings,
                SUM(CASE WHEN appointments.status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN appointments.status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
                SUM(CASE WHEN appointments.status = 'completed' THEN appointment_items.subtotal ELSE 0 END) as revenue")
            ->get();

        // Services done for walk-in guests, dated like the dashboard (when the visit was completed).
        $walkIns = DB::table('walk_in_visit_items')
            ->join('walk_in_visits', 'walk_in_visits.id', '=', 'walk_in_visit_items.walk_in_visit_id')
            ->where('walk_in_visits.status', self::COMPLETED)
            ->whereBetween('walk_in_visits.completed_at', [$range->from->startOfDay(), $range->to->endOfDay()])
            ->when($branchId, fn ($q) => $q->where('walk_in_visits.branch_id', $branchId))
            ->groupBy('walk_in_visit_items.service_id', 'walk_in_visit_items.service_name')
            ->selectRaw('walk_in_visit_items.service_id, walk_in_visit_items.service_name, COUNT(*) as walk_ins, SUM(walk_in_visit_items.subtotal) as revenue')
            ->get();

        $ratings = DB::table('reviews')->whereNull('deleted_at')->where('status', ReviewStatus::Approved->value)
            ->groupBy('service_id')->selectRaw('service_id, AVG(rating) as rating')->pluck('rating', 'service_id');

        $byService = [];
        foreach ($rows as $r) {
            $byService[$r->service_id ?? 'name:'.$r->service_name] = [
                'service_id' => $r->service_id, 'service' => $r->service_name, 'bookings' => (int) $r->bookings,
                'completed' => (int) $r->completed, 'walk_ins' => 0, 'cancelled' => (int) $r->cancelled, 'revenue' => (float) $r->revenue,
            ];
        }
        foreach ($walkIns as $r) {
            $key = $r->service_id ?? 'name:'.$r->service_name;
            $byService[$key] ??= ['service_id' => $r->service_id, 'service' => $r->service_name, 'bookings' => 0, 'completed' => 0, 'walk_ins' => 0, 'cancelled' => 0, 'revenue' => 0.0];
            $byService[$key]['walk_ins'] += (int) $r->walk_ins;
            $byService[$key]['revenue'] += (float) $r->revenue;
        }

        $table = collect($byService)->map(fn (array $r) => [
            'service' => $r['service'],
            'bookings' => $r['bookings'],
            'completed' => $r['completed'],
            'walk_ins' => $r['walk_ins'],
            'cancelled' => $r['cancelled'],
            'revenue' => round($r['revenue'], 2),
            'rating' => isset($ratings[$r['service_id']]) ? round((float) $ratings[$r['service_id']], 1) : null,
        ])->sortByDesc('revenue')->values();

        return new ReportTable(
            'Services',
            ['service' => 'Service', 'bookings' => 'Bookings', 'completed' => 'Completed appointments', 'walk_ins' => 'Completed walk-ins', 'cancelled' => 'Cancelled', 'revenue' => '₱ Revenue (before discounts)', 'rating' => 'Avg. rating'],
            $table->all(),
            ['service' => 'Total', 'bookings' => $table->sum('bookings'), 'completed' => $table->sum('completed'), 'walk_ins' => $table->sum('walk_ins'), 'cancelled' => $table->sum('cancelled'), 'revenue' => $table->sum('revenue'), 'rating' => null],
            ['type' => 'bar', 'money' => true, 'labels' => $table->take(10)->pluck('service')->all(), 'series' => [['label' => 'Revenue', 'data' => $table->take(10)->pluck('revenue')->all()]]],
            ['Services booked' => number_format($table->count()), 'Top service' => $table->first()['service'] ?? '—'],
        );
    }

    public function staff(DateRange $range, ?string $branchId = null): ReportTable
    {
        $rows = Appointment::query()
            ->whereNotNull('staff_profile_id')
            ->whereBetween('appointment_date', [$range->from->toDateString(), $range->to->toDateString()])
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->groupBy('staff_profile_id')
            ->selectRaw("staff_profile_id, COUNT(*) as total,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
                SUM(CASE WHEN status = 'no_show' THEN 1 ELSE 0 END) as no_show,
                SUM(CASE WHEN status = 'completed' THEN total_amount ELSE 0 END) as revenue,
                SUM(CASE WHEN status = 'completed' THEN {$this->minutesBetween('start_time', 'end_time')} ELSE 0 END) as minutes")
            ->toBase()
            ->get()
            ->keyBy('staff_profile_id');

        // Walk-in services are assigned per item; count the visits each stylist worked on and what their services earned.
        $walkIns = DB::table('walk_in_visit_items')
            ->join('walk_in_visits', 'walk_in_visits.id', '=', 'walk_in_visit_items.walk_in_visit_id')
            ->whereNotNull('walk_in_visit_items.staff_profile_id')
            ->where('walk_in_visits.status', self::COMPLETED)
            ->whereBetween('walk_in_visits.completed_at', [$range->from->startOfDay(), $range->to->endOfDay()])
            ->when($branchId, fn ($q) => $q->where('walk_in_visits.branch_id', $branchId))
            ->groupBy('walk_in_visit_items.staff_profile_id')
            ->selectRaw('walk_in_visit_items.staff_profile_id, COUNT(DISTINCT walk_in_visits.id) as walk_ins, SUM(walk_in_visit_items.subtotal) as revenue')
            ->get()
            ->keyBy('staff_profile_id');

        $ratings = DB::table('reviews')
            ->join('appointments', 'appointments.id', '=', 'reviews.appointment_id')
            ->whereNull('reviews.deleted_at')->where('reviews.status', ReviewStatus::Approved->value)
            ->groupBy('appointments.staff_profile_id')
            ->selectRaw('appointments.staff_profile_id, AVG(reviews.rating) as rating')
            ->pluck('rating', 'staff_profile_id');

        $ids = $rows->keys()->merge($walkIns->keys())->unique();
        $names = StaffProfile::withTrashed()->with('user')->whereKey($ids->all())->get()->mapWithKeys(fn ($p) => [$p->id => $p->user?->name]);

        $table = $ids->map(function ($id) use ($rows, $walkIns, $ratings, $names) {
            $r = $rows[$id] ?? null;
            $w = $walkIns[$id] ?? null;

            return [
                'staff' => $names[$id] ?? 'Former staff',
                'total' => (int) ($r->total ?? 0),
                'completed' => (int) ($r->completed ?? 0),
                'walk_ins' => (int) ($w->walk_ins ?? 0),
                'cancelled' => (int) ($r->cancelled ?? 0),
                'no_show' => (int) ($r->no_show ?? 0),
                'hours' => round(((int) ($r->minutes ?? 0)) / 60, 1),
                'revenue' => round((float) ($r->revenue ?? 0) + (float) ($w->revenue ?? 0), 2),
                'rating' => isset($ratings[$id]) ? round((float) $ratings[$id], 1) : null,
            ];
        })->sortByDesc('revenue')->values();

        return new ReportTable(
            'Staff performance',
            ['staff' => 'Staff', 'total' => 'Appointments', 'completed' => 'Completed', 'walk_ins' => 'Completed walk-ins', 'cancelled' => 'Cancelled', 'no_show' => 'No-shows', 'hours' => 'Service hours', 'revenue' => '₱ Gross revenue', 'rating' => 'Avg. rating'],
            $table->all(),
            ['staff' => 'Total', 'total' => $table->sum('total'), 'completed' => $table->sum('completed'), 'walk_ins' => $table->sum('walk_ins'), 'cancelled' => $table->sum('cancelled'), 'no_show' => $table->sum('no_show'), 'hours' => round($table->sum('hours'), 1), 'revenue' => $table->sum('revenue'), 'rating' => null],
            ['type' => 'bar', 'money' => true, 'labels' => $table->pluck('staff')->all(), 'series' => [['label' => 'Gross revenue', 'data' => $table->pluck('revenue')->all()]]],
        );
    }

    public function branches(DateRange $range): ReportTable
    {
        $rows = Appointment::query()
            ->whereBetween('appointment_date', [$range->from->toDateString(), $range->to->toDateString()])
            ->groupBy('branch_id')
            ->selectRaw("branch_id, COUNT(*) as total,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
                SUM(CASE WHEN status = 'no_show' THEN 1 ELSE 0 END) as no_show,
                SUM(CASE WHEN status = 'completed' THEN total_amount ELSE 0 END) as revenue,
                COUNT(DISTINCT customer_id) as customers")
            ->toBase()
            ->get()
            ->keyBy('branch_id');

        $walkIns = WalkInVisit::query()
            ->where('status', self::COMPLETED)
            ->whereBetween('completed_at', [$range->from->startOfDay(), $range->to->endOfDay()])
            ->groupBy('branch_id')
            ->selectRaw('branch_id, COUNT(*) as walk_ins, SUM(total_amount) as revenue')
            ->toBase()
            ->get()
            ->keyBy('branch_id');

        $ids = $rows->keys()->merge($walkIns->keys())->unique();
        $names = Branch::withTrashed()->whereKey($ids->all())->pluck('name', 'id');

        $table = $ids->map(function ($id) use ($rows, $walkIns, $names) {
            $r = $rows[$id] ?? null;
            $w = $walkIns[$id] ?? null;

            return [
                'branch' => $names[$id] ?? 'Deleted branch',
                'total' => (int) ($r->total ?? 0),
                'completed' => (int) ($r->completed ?? 0),
                'walk_ins' => (int) ($w->walk_ins ?? 0),
                'cancelled' => (int) ($r->cancelled ?? 0),
                'no_show' => (int) ($r->no_show ?? 0),
                'customers' => (int) ($r->customers ?? 0),
                'revenue' => round((float) ($r->revenue ?? 0) + (float) ($w->revenue ?? 0), 2),
            ];
        })->sortByDesc('revenue')->values();

        return new ReportTable(
            'Branches',
            ['branch' => 'Branch', 'total' => 'Appointments', 'completed' => 'Completed', 'walk_ins' => 'Completed walk-ins', 'cancelled' => 'Cancelled', 'no_show' => 'No-shows', 'customers' => 'Unique customers', 'revenue' => '₱ Gross revenue'],
            $table->all(),
            ['branch' => 'Total', 'total' => $table->sum('total'), 'completed' => $table->sum('completed'), 'walk_ins' => $table->sum('walk_ins'), 'cancelled' => $table->sum('cancelled'), 'no_show' => $table->sum('no_show'), 'customers' => null, 'revenue' => $table->sum('revenue')],
            ['type' => 'bar', 'money' => true, 'labels' => $table->pluck('branch')->all(), 'series' => [['label' => 'Gross revenue', 'data' => $table->pluck('revenue')->all()]]],
        );
    }

    /** @return Collection<int, object{service_name: string, bookings: int}> */
    public function popularServices(CarbonImmutable $from, CarbonImmutable $to, int $limit = 5, ?string $branchId = null)
    {
        return DB::table('appointment_items')
            ->join('appointments', 'appointments.id', '=', 'appointment_items.appointment_id')
            ->whereBetween('appointments.appointment_date', [$from->toDateString(), $to->toDateString()])
            ->when($branchId, fn ($q) => $q->where('appointments.branch_id', $branchId))
            ->whereNotIn('appointments.status', [AppointmentStatus::Cancelled->value])
            ->groupBy('appointment_items.service_name')
            ->selectRaw('appointment_items.service_name, COUNT(*) as bookings')
            ->orderByDesc('bookings')
            ->limit($limit)
            ->get();
    }

    /**
     * Product cost recorded on completed appointments and walk-ins, per day (same dating as revenue).
     *
     * @return Collection<string, float>
     */
    private function productCostByDay(CarbonImmutable $from, CarbonImmutable $to, ?string $branchId = null): Collection
    {
        $appointments = DB::table('appointment_items')
            ->join('appointments', 'appointments.id', '=', 'appointment_items.appointment_id')
            ->where('appointments.status', self::COMPLETED)
            ->when($branchId, fn ($q) => $q->where('appointments.branch_id', $branchId))
            ->whereBetween('appointments.appointment_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('DATE(appointments.appointment_date) as day, SUM(appointment_items.product_cost) as cost')
            ->groupBy('day')->pluck('cost', 'day');

        $walkIns = DB::table('walk_in_visit_items')
            ->join('walk_in_visits', 'walk_in_visits.id', '=', 'walk_in_visit_items.walk_in_visit_id')
            ->where('walk_in_visits.status', self::COMPLETED)
            ->when($branchId, fn ($q) => $q->where('walk_in_visits.branch_id', $branchId))
            ->whereBetween('walk_in_visits.completed_at', [$from->startOfDay(), $to->endOfDay()])
            ->selectRaw('DATE(walk_in_visits.completed_at) as day, SUM(walk_in_visit_items.product_cost) as cost')
            ->groupBy('day')->pluck('cost', 'day');

        return $appointments->keys()->merge($walkIns->keys())->unique()->mapWithKeys(fn ($day) => [
            (string) $day => (float) ($appointments[$day] ?? 0) + (float) ($walkIns[$day] ?? 0),
        ]);
    }

    private function revenueSeries(CarbonImmutable $from, CarbonImmutable $to, User $viewer, ?string $branchId = null): array
    {
        $range = new DateRange($from, $to, 'custom');
        $sums = Appointment::query()->tap(fn ($q) => $this->scopeViewer($q, $viewer))
            ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
            ->where('status', self::COMPLETED)
            ->whereBetween('appointment_date', [$from->toDateString(), $to->toDateString()])
            ->selectRaw('DATE(appointment_date) as day, SUM(total_amount) as net')
            ->groupBy('day')->pluck('net', 'day');

        if (! $viewer->isStaff()) {
            $walkInSums = WalkInVisit::query()->where('status', self::COMPLETED)
                ->when($branchId, fn ($q) => $q->where('branch_id', $branchId))
                ->whereBetween('completed_at', [$from->startOfDay(), $to->endOfDay()])
                ->selectRaw('DATE(completed_at) as day, SUM(total_amount) as net')
                ->groupBy('day')->pluck('net', 'day');

            foreach ($walkInSums as $day => $amount) {
                $sums[$day] = (float) ($sums[$day] ?? 0) + (float) $amount;
            }
        }

        $gross = array_map(fn ($d) => (float) ($sums[$d] ?? 0), $range->days());
        $series = [['label' => $viewer->isStaff() ? 'Revenue' : 'Gross revenue', 'data' => $gross]];
        if (! $viewer->isStaff()) {
            $costs = $this->productCostByDay($from, $to, $branchId);
            $series[] = ['label' => 'Net revenue', 'data' => array_map(fn ($d, $g) => round($g - ($costs[$d] ?? 0), 2), $range->days(), $gross)];
        }

        return [
            'type' => 'line',
            'money' => true,
            'labels' => array_map(fn ($d) => CarbonImmutable::parse($d)->format('M j'), $range->days()),
            'series' => $series,
        ];
    }

    /** Minutes between two TIME columns, per database driver. */
    private function minutesBetween(string $from, string $to): string
    {
        return DB::getDriverName() === 'pgsql'
            ? "EXTRACT(EPOCH FROM ({$to} - {$from})) / 60"
            : "TIMESTAMPDIFF(MINUTE, {$from}, {$to})";
    }

    private function scopeViewer(Builder $query, User $viewer): Builder
    {
        return $viewer->isStaff()
            ? $query->where(fn ($q) => $q->where('staff_profile_id', $viewer->staffProfile?->id ?? '')
                ->orWhereHas('items', fn ($items) => $items->where('staff_profile_id', $viewer->staffProfile?->id ?? '')))
            : $query;
    }
}
