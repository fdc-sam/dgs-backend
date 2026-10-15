<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Service;
use App\Models\StaffProfile;
use App\Models\WalkInVisit;
use App\Models\WalkInVisitItem;
use App\Services\AuditLogger;
use App\Services\InventoryService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class WalkInVisitController extends Controller
{
    public function index(Request $request): View
    {
        $filters = $request->validate(['search' => ['nullable', 'string', 'max:100']]);
        $visits = WalkInVisit::query()
            ->with(['branch', 'staff.user', 'items.staff.user'])
            ->when($filters['search'] ?? null, function ($query, $term) {
                $like = '%'.addcslashes($term, '%_\\').'%';
                $query->where(fn ($q) => $q
                    ->whereLike('guest_name', $like)
                    ->orWhereLike('phone', $like)
                    ->orWhereHas('items', fn ($items) => $items->whereLike('service_name', $like)));
            })
            ->latest('visited_at')
            ->paginate(20)
            ->withQueryString();

        return view('admin.appointments.walk-ins', compact('visits'));
    }

    public function create(): View
    {
        return view('admin.appointments.walk-in-form', [
            'branches' => Branch::active()->orderBy('name')->get(),
            'services' => Service::active()->ordered()->with([
                'category',
                'branches' => fn ($query) => $query->active()->wherePivot('is_available', true),
            ])->get(),
            'staff' => StaffProfile::bookable()->with([
                'user',
                'branches' => fn ($query) => $query->active(),
                'services',
            ])->get()->sortBy(fn ($profile) => $profile->user?->name),
        ]);
    }

    public function show(WalkInVisit $walkInVisit): View
    {
        $walkInVisit->load(['branch', 'staff.user', 'creator', 'items.staff.user']);
        $services = Service::query()->active()->availableAtBranch($walkInVisit->branch_id)
            ->with(['branches' => fn ($query) => $query->where('branches.id', $walkInVisit->branch_id)])
            ->orderBy('name')->get();
        $staff = StaffProfile::bookable()->atBranch($walkInVisit->branch_id)
            ->with(['user', 'services'])->get()->sortBy(fn ($profile) => $profile->user?->name);
        $history = AuditLog::with('user')
            ->where('entity_type', $walkInVisit->getMorphClass())
            ->where('entity_id', $walkInVisit->id)
            ->latest('created_at')
            ->get();

        return view('admin.appointments.walk-in-show', compact('walkInVisit', 'history', 'services', 'staff'));
    }

    public function updateItem(Request $request, WalkInVisit $walkInVisit, WalkInVisitItem $item, AuditLogger $audit, InventoryService $inventory): RedirectResponse
    {
        $item = $walkInVisit->items()->whereKey($item->id)->firstOrFail();
        $data = $request->validate([
            'service_id' => ['required', 'string', 'exists:services,id'],
            'staff_profile_id' => ['nullable', 'string', 'exists:staff_profiles,id'],
        ]);

        $service = Service::query()->active()->availableAtBranch($walkInVisit->branch_id)
            ->with(['branches' => fn ($query) => $query->where('branches.id', $walkInVisit->branch_id)])
            ->find($data['service_id']);
        if (! $service) {
            return back()->withInput()->withErrors(['service_id' => 'Choose a service available at this branch.']);
        }

        $staff = null;
        if (! empty($data['staff_profile_id'])) {
            $staff = StaffProfile::bookable()->atBranch($walkInVisit->branch_id)
                ->offersService($service->id)->find($data['staff_profile_id']);
            if (! $staff) {
                return back()->withInput()->withErrors(['staff_profile_id' => 'Choose a stylist who offers this service at this branch.']);
            }
        }

        $branchService = $service->branches->firstWhere('id', $walkInVisit->branch_id);
        $price = number_format((float) ($branchService?->pivot?->custom_price ?? $service->regular_price), 2, '.', '');
        $old = [
            'service_id' => $item->service_id,
            'service_name' => $item->service_name,
            'staff_profile_id' => $item->staff_profile_id,
            'unit_price' => $item->unit_price,
            'subtotal' => $item->subtotal,
        ];

        DB::transaction(function () use ($walkInVisit, $item, $service, $staff, $price, $inventory) {
            WalkInVisit::query()->whereKey($walkInVisit->id)->lockForUpdate()->first();
            $completed = $walkInVisit->status === 'completed';
            if ($completed) {
                $inventory->releaseUsage($item); // the old service's products go back on the shelf
            }
            $item->update([
                'service_id' => $service->id,
                'service_name' => $service->name,
                'staff_profile_id' => $staff?->id,
                'unit_price' => $price,
                'subtotal' => number_format((float) $price * $item->quantity, 2, '.', ''),
            ]);
            // Already completed: record the cost and stock use of the new service instead.
            if ($completed) {
                $inventory->recordUsage($item->newCollection([$item->fresh()]));
            }
            $this->refreshVisitTotals($walkInVisit);
        });

        $audit->log('walk_in_visit_item.updated', $walkInVisit, $old, [
            'service_id' => $item->service_id,
            'service_name' => $item->service_name,
            'staff_profile_id' => $item->staff_profile_id,
            'unit_price' => $item->unit_price,
            'subtotal' => $item->subtotal,
            'total_amount' => $walkInVisit->fresh()->total_amount,
        ]);

        return redirect()->route('walk-ins.show', $walkInVisit)->with('success', 'Walk-in service updated.');
    }

    public function destroyItem(WalkInVisit $walkInVisit, WalkInVisitItem $item, AuditLogger $audit, InventoryService $inventory): RedirectResponse
    {
        $item = $walkInVisit->items()->whereKey($item->id)->firstOrFail();
        $old = [
            'service_id' => $item->service_id,
            'service_name' => $item->service_name,
            'staff_profile_id' => $item->staff_profile_id,
            'unit_price' => $item->unit_price,
            'subtotal' => $item->subtotal,
        ];

        DB::transaction(function () use ($walkInVisit, $item, $inventory) {
            WalkInVisit::query()->whereKey($walkInVisit->id)->lockForUpdate()->first();
            if ($walkInVisit->items()->count() <= 1) {
                throw ValidationException::withMessages(['service' => 'A walk-in must have at least one service.']);
            }
            if ($walkInVisit->status === 'completed') {
                $inventory->releaseUsage($item); // a service that was not done does not use stock
            }
            $item->delete();
            $this->refreshVisitTotals($walkInVisit);
        });

        $audit->log('walk_in_visit_item.deleted', $walkInVisit, $old, [
            'total_amount' => $walkInVisit->fresh()->total_amount,
        ]);

        return redirect()->route('walk-ins.show', $walkInVisit)->with('success', 'Walk-in service removed.');
    }

    private function refreshVisitTotals(WalkInVisit $visit): void
    {
        $items = $visit->items()->get(['staff_profile_id', 'subtotal']);
        $staffIds = $items->pluck('staff_profile_id')->filter()->unique();
        $visit->update([
            'total_amount' => number_format((float) $items->sum(fn ($item) => (float) $item->subtotal), 2, '.', ''),
            'staff_profile_id' => $staffIds->count() === 1 ? $staffIds->first() : null,
        ]);
    }

    public function complete(WalkInVisit $walkInVisit, AuditLogger $audit, InventoryService $inventory): RedirectResponse
    {
        if ($walkInVisit->status === 'completed') {
            return back()->with('success', 'This walk-in service is already complete.');
        }

        $old = ['status' => $walkInVisit->status, 'completed_at' => $walkInVisit->completed_at?->toIso8601String()];
        DB::transaction(function () use ($walkInVisit, $inventory) {
            $walkInVisit->update(['status' => 'completed', 'completed_at' => now()]);
            $inventory->recordUsage($walkInVisit->items);
        });
        $audit->log('walk_in_visit.completed', $walkInVisit, $old, [
            'status' => $walkInVisit->status,
            'completed_at' => $walkInVisit->completed_at?->toIso8601String(),
        ]);

        return redirect()->route('walk-ins.show', $walkInVisit)->with('success', 'Walk-in services marked complete and added to the dashboard totals.');
    }

    public function store(Request $request, AuditLogger $audit): RedirectResponse
    {
        $data = $request->validate([
            'guest_name' => ['nullable', 'string', 'max:120'],
            'phone' => ['nullable', 'string', 'max:30'],
            'branch_id' => ['required', 'string', Rule::exists('branches', 'id')->where('status', 'active')],
            'staff_by_service' => ['nullable', 'array'],
            'staff_by_service.*' => ['nullable', 'string', 'exists:staff_profiles,id'],
            'services' => ['required', 'array', 'min:1', 'max:12'],
            'services.*' => ['required', 'string', 'distinct', 'exists:services,id'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $branch = Branch::active()->findOrFail($data['branch_id']);
        $serviceIds = array_values(array_unique($data['services']));
        $services = Service::query()
            ->active()
            ->whereIn('id', $serviceIds)
            ->availableAtBranch($branch->id)
            ->with(['branches' => fn ($query) => $query->where('branches.id', $branch->id)])
            ->get();

        if ($services->count() !== count($serviceIds)) {
            return back()->withInput()->withErrors(['services' => 'Choose services available at the selected branch.']);
        }

        $staffIdsByService = $data['staff_by_service'] ?? [];
        if (array_diff(array_keys($staffIdsByService), $serviceIds)) {
            return back()->withInput()->withErrors(['staff_by_service' => 'Choose a stylist only for a selected service.']);
        }

        $staffByService = [];
        foreach ($services as $service) {
            $staffId = $staffIdsByService[$service->id] ?? null;
            if (! $staffId) {
                continue;
            }

            $staff = StaffProfile::bookable()->atBranch($branch->id)->offersService($service->id)->find($staffId);
            if (! $staff) {
                return back()->withInput()->withErrors([
                    "staff_by_service.{$service->id}" => "Choose a stylist who offers {$service->name} at this branch.",
                ]);
            }
            $staffByService[$service->id] = $staff;
        }

        $pricedServices = $services->map(function (Service $service) use ($branch, $staffByService) {
            $branchService = $service->branches->firstWhere('id', $branch->id);
            $price = $branchService?->pivot?->custom_price ?? $service->regular_price;

            return [$service, number_format((float) $price, 2, '.', ''), $staffByService[$service->id] ?? null];
        });
        $totalCents = $pricedServices->sum(fn ($entry) => (int) round((float) $entry[1] * 100));
        $assignedStaffIds = $staffByService ? collect($staffByService)->pluck('id')->unique()->values() : collect();
        $visitStaffId = $assignedStaffIds->count() === 1 ? $assignedStaffIds->first() : null;

        $visit = DB::transaction(function () use ($data, $request, $branch, $visitStaffId, $pricedServices, $totalCents) {
            $visit = WalkInVisit::create([
                'guest_name' => filled($data['guest_name'] ?? null) ? trim($data['guest_name']) : null,
                'phone' => filled($data['phone'] ?? null) ? trim($data['phone']) : null,
                'branch_id' => $branch->id,
                'staff_profile_id' => $visitStaffId,
                'created_by' => $request->user()->id,
                'visited_at' => now(),
                'status' => 'in_progress',
                'total_amount' => number_format($totalCents / 100, 2, '.', ''),
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($pricedServices as [$service, $price, $staff]) {
                $visit->items()->create([
                    'service_id' => $service->id,
                    'staff_profile_id' => $staff?->id,
                    'service_name' => $service->name,
                    'quantity' => 1,
                    'unit_price' => $price,
                    'subtotal' => $price,
                ]);
            }

            return $visit;
        });

        $audit->log('walk_in_visit.created', $visit, null, [
            'guest_name' => $visit->guest_name,
            'branch_id' => $visit->branch_id,
            'service_names' => $pricedServices->map(fn ($entry) => $entry[0]->name)->values()->all(),
            'staff_by_service' => $pricedServices->mapWithKeys(fn ($entry) => [$entry[0]->id => $entry[2]?->id])->all(),
            'total_amount' => $visit->total_amount,
        ]);

        return redirect()->route('walk-ins.index')->with('success', 'Walk-in service visit recorded. No customer account or appointment was created.');
    }
}
