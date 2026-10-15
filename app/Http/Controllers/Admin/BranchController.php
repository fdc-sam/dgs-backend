<?php

namespace App\Http\Controllers\Admin;

use App\Enums\RecordStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\BranchRequest;
use App\Models\Branch;
use App\Models\BranchClosure;
use App\Services\AuditLogger;
use App\Services\StaffAdminService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class BranchController extends Controller
{
    public function __construct(private readonly StaffAdminService $service) {}

    public function index(): View
    {
        return view('admin.branches.index', [
            'branches' => Branch::withCount(['staff', 'services'])->with('businessHours')->orderBy('name')->get(),
        ]);
    }

    public function create(): View
    {
        $branch = new Branch(['status' => RecordStatus::Active]);

        return view('admin.branches.form', ['branch' => $branch, 'hours' => $this->hoursFor($branch)]);
    }

    public function store(BranchRequest $request): RedirectResponse
    {
        $branch = $this->service->saveBranch(null, $request->validated(), $this->slug($request, null));

        return redirect()->route('branches.edit', $branch)->with('success', 'Branch created. Assign staff and services to start taking bookings.');
    }

    public function edit(Branch $branch): View
    {
        $branch->load(['businessHours', 'closures' => fn ($q) => $q->where('date', '>=', now()->subDays(30))->orderBy('date')]);

        return view('admin.branches.form', ['branch' => $branch, 'hours' => $this->hoursFor($branch)]);
    }

    public function update(BranchRequest $request, Branch $branch): RedirectResponse
    {
        $this->service->saveBranch($branch, $request->validated(), $this->slug($request, $branch));

        return redirect()->route('branches.edit', $branch)->with('success', 'Branch updated.');
    }

    public function destroy(Branch $branch, AuditLogger $audit): RedirectResponse
    {
        if ($branch->appointments()->where('starts_at', '>=', now())->blocking()->exists()) {
            return back()->with('error', 'This branch has upcoming appointments. Set it to inactive instead, or reschedule them first.');
        }
        $audit->log('branch.deleted', $branch, ['name' => $branch->name]);
        $branch->update(['slug' => $branch->slug.'-deleted-'.now()->timestamp, 'status' => RecordStatus::Inactive]);
        $branch->delete();

        return redirect()->route('branches.index')->with('success', 'Branch deleted.');
    }

    public function storeClosure(Request $request, Branch $branch): RedirectResponse
    {
        $data = $request->validate([
            'date' => ['required', 'date_format:Y-m-d', 'after_or_equal:today'],
            'reason' => ['nullable', 'string', 'max:255'],
        ]);
        BranchClosure::updateOrCreate(['branch_id' => $branch->id, 'date' => $data['date']], ['reason' => $data['reason'] ?? null]);

        $conflicts = $branch->appointments()->whereDate('appointment_date', $data['date'])->blocking()->count();

        return back()->with($conflicts ? 'warning' : 'success', $conflicts
            ? "Closure added. {$conflicts} existing appointment(s) on that date need to be rescheduled or cancelled."
            : 'Closure added.');
    }

    public function destroyClosure(Branch $branch, BranchClosure $closure): RedirectResponse
    {
        abort_unless($closure->branch_id === $branch->id, 404);
        $closure->delete();

        return back()->with('success', 'Closure removed.');
    }

    private function hoursFor(Branch $branch): array
    {
        $existing = $branch->exists ? $branch->businessHours->keyBy('day_of_week') : collect();

        return collect(range(0, 6))->map(fn ($d) => [
            'is_open' => $existing->has($d) ? $existing[$d]->is_open : $d !== 0,
            'opening_time' => substr($existing[$d]->opening_time ?? '09:00', 0, 5),
            'closing_time' => substr($existing[$d]->closing_time ?? '18:00', 0, 5),
        ])->all();
    }

    private function slug(BranchRequest $request, ?Branch $branch): string
    {
        $base = Str::slug($request->input('slug') ?: $request->input('name'));
        $slug = $base;
        $i = 2;
        while (Branch::withTrashed()->where('slug', $slug)->when($branch, fn ($q) => $q->whereKeyNot($branch->id))->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
