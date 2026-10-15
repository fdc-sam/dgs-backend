<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Services\ReportService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request, ReportService $reports): View
    {
        $user = $request->user();
        // Managers can look at one branch; unknown values just fall back to all branches / today.
        $branches = $user->isStaff() ? collect() : Branch::query()->orderBy('name')->pluck('name', 'id');
        $requested = $request->query('branch_id');
        $branchId = is_string($requested) && $branches->has($requested) ? $requested : null;

        return view('admin.dashboard', [
            'stats' => $reports->dashboard($user, (string) $request->query('period', 'today'), $branchId),
            'branches' => $branches,
        ]);
    }
}
