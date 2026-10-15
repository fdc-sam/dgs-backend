<?php

namespace App\Http\Controllers\Admin;

use App\Enums\RecordStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StaffRequest;
use App\Models\Branch;
use App\Models\Service;
use App\Models\StaffDayOff;
use App\Models\StaffProfile;
use App\Services\StaffAdminService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StaffController extends Controller
{
    public function __construct(private readonly StaffAdminService $staffService) {}

    public function index(Request $request): View
    {
        $staff = StaffProfile::query()
            ->with(['user', 'branches'])
            ->withCount(['services', 'appointments' => fn ($q) => $q->where('starts_at', '>=', now())->whereNotIn('status', ['cancelled'])])
            ->when($request->query('branch_id'), fn ($q, $id) => $q->atBranch($id))
            ->when($request->query('search'), fn ($q, $s) => $q->whereHas('user', fn ($u) => $u->whereLike('name', '%'.addcslashes($s, '%_\\').'%')))
            ->get()->sortBy('user.name');

        return view('admin.staff.index', ['staff' => $staff, 'branches' => Branch::orderBy('name')->pluck('name', 'id')]);
    }

    public function create(): View
    {
        return view('admin.staff.form', $this->formData(new StaffProfile(['status' => RecordStatus::Active])));
    }

    public function store(StaffRequest $request): RedirectResponse
    {
        $staff = $this->staffService->saveStaff(null, $request->validated(), $request->file('photo'));

        return redirect()->route('staff.edit', $staff)->with('success', 'Staff member created. They can sign in to the admin portal with their email.');
    }

    public function edit(StaffProfile $staff): View
    {
        return view('admin.staff.form', $this->formData($staff->load(['user', 'branches', 'services', 'schedules', 'daysOff' => fn ($q) => $q->where('date', '>=', now()->subDays(30))->orderBy('date')])));
    }

    public function update(StaffRequest $request, StaffProfile $staff): RedirectResponse
    {
        $this->staffService->saveStaff($staff, $request->validated(), $request->file('photo'));

        return redirect()->route('staff.edit', $staff)->with('success', 'Staff member updated.');
    }

    public function destroy(StaffProfile $staff): RedirectResponse
    {
        $this->staffService->deleteStaff($staff);

        return redirect()->route('staff.index')->with('success', 'Staff member removed and their sign-in disabled.');
    }

    public function storeDayOff(Request $request, StaffProfile $staff): RedirectResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'branch_id' => ['nullable', 'string', 'exists:branches,id'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);

        $conflicts = $staff->appointments()->whereDate('appointment_date', $data['date'])->blocking()
            ->when($data['branch_id'] ?? null, fn ($q, $b) => $q->where('branch_id', $b))->count();

        StaffDayOff::firstOrCreate(
            ['staff_profile_id' => $staff->id, 'branch_id' => $data['branch_id'] ?? null, 'date' => $data['date']],
            ['reason' => $data['reason'] ?? null],
        );

        $message = 'Day off added.';

        return back()->with($conflicts ? 'warning' : 'success', $conflicts
            ? "Day off added, but {$conflicts} existing appointment(s) on that date still need to be rescheduled."
            : $message);
    }

    public function destroyDayOff(StaffProfile $staff, StaffDayOff $dayOff): RedirectResponse
    {
        abort_unless($dayOff->staff_profile_id === $staff->id, 404);
        $dayOff->delete();

        return back()->with('success', 'Day off removed.');
    }

    private function formData(StaffProfile $staff): array
    {
        return [
            'staff' => $staff,
            'branches' => Branch::orderBy('name')->pluck('name', 'id'),
            'services' => Service::with('category')->orderBy('name')->get()->groupBy(fn ($s) => $s->category?->name ?? 'Other'),
            'days' => ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'],
        ];
    }
}
