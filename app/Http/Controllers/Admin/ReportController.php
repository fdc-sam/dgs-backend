<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Services\ReportExporter;
use App\Services\ReportService;
use App\Support\Reports\DateRange;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class ReportController extends Controller
{
    public function __construct(private readonly ReportService $reports) {}

    public function index(Request $request): View
    {
        $range = DateRange::fromRequest($request);

        return view('admin.reports.index', [
            'range' => $range,
            'sales' => $this->reports->sales($range),
            'appointments' => $this->reports->appointments($range),
        ]);
    }

    public function show(Request $request, string $report, ReportExporter $exporter): View|Response
    {
        $request->validate([
            'range' => ['nullable', 'in:'.implode(',', array_keys(DateRange::PRESETS))],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
            'branch_id' => ['nullable', 'string'],
            'export' => ['nullable', 'in:csv,xlsx,pdf'],
        ]);

        $range = DateRange::fromRequest($request);
        $branchId = $request->query('branch_id');

        $table = match ($report) {
            'sales' => $this->reports->sales($range, $branchId),
            'appointments' => $this->reports->appointments($range, $branchId),
            'services' => $this->reports->services($range, $branchId),
            'staff' => $this->reports->staff($range, $branchId),
            'branches' => $this->reports->branches($range),
        };

        if ($format = $request->query('export')) {
            return $exporter->export($table, $range, $format, "dgs-{$report}-report-{$range->from->toDateString()}-to-{$range->to->toDateString()}");
        }

        return view('admin.reports.show', [
            'report' => $report,
            'table' => $table,
            'range' => $range,
            'branches' => $report === 'branches' ? collect() : Branch::orderBy('name')->pluck('name', 'id'),
        ]);
    }
}
