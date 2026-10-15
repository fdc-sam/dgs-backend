<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Read-only schedule for staff users (their assigned services, weekly hours and days off). */
class MyScheduleController extends Controller
{
    public function __invoke(Request $request): View
    {
        $profile = $request->user()->staffProfile?->load(['schedules.branch', 'services', 'branches', 'daysOff' => fn ($q) => $q->where('date', '>=', now()->startOfDay())->orderBy('date')->with('branch')]);
        abort_unless($profile, 404);

        return view('admin.my-schedule', ['profile' => $profile]);
    }
}
