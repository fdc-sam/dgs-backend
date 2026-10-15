<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\BranchResource;
use App\Http\Resources\ServiceResource;
use App\Http\Resources\StaffResource;
use App\Models\Branch;
use App\Models\StaffProfile;
use App\Services\ServiceCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class BranchController extends ApiController
{
    public function index(): JsonResponse
    {
        return $this->ok(BranchResource::collection(Branch::query()->active()->with('businessHours')->orderBy('name')->get()));
    }

    public function show(Branch $branch): JsonResponse
    {
        abort_unless($branch->isActive(), 404);

        return $this->ok(new BranchResource($branch->load('businessHours')));
    }

    public function services(Request $request, Branch $branch, ServiceCatalogService $catalog): JsonResponse
    {
        abort_unless($branch->isActive(), 404);
        $filters = $request->validate([
            'category_id' => ['nullable', 'string', 'size:26'],
            'search' => ['nullable', 'string', 'max:100'],
        ]);

        return $this->ok(ServiceResource::collection(
            $catalog->query([...$filters, 'branch_id' => $branch->id])->paginate($this->perPage())
        ));
    }

    public function staff(Request $request, Branch $branch): JsonResponse
    {
        abort_unless($branch->isActive(), 404);
        $request->validate(['service_id' => ['nullable', 'string', 'size:26']]);

        $staff = StaffProfile::query()->bookable()->atBranch($branch->id)
            ->when($request->input('service_id'), fn ($q, $id) => $q->offersService($id))
            ->with(['user', 'branches', 'services'])
            ->get()
            ->sortBy(fn ($s) => $s->user?->name)->values();

        return $this->ok(StaffResource::collection($staff));
    }
}
