<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\ServiceResource;
use App\Http\Resources\StaffResource;
use App\Models\StaffProfile;
use App\Services\ServiceCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class StaffController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'branch_id' => ['nullable', 'string', 'size:26'],
            'service_id' => ['nullable', 'string', 'size:26'],
        ]);

        $staff = StaffProfile::query()->bookable()
            ->when($request->input('branch_id'), fn ($q, $id) => $q->atBranch($id))
            ->when($request->input('service_id'), fn ($q, $id) => $q->offersService($id))
            ->with(['user', 'branches', 'services'])
            ->get()
            ->sortBy(fn ($s) => $s->user?->name)->values();

        return $this->ok(StaffResource::collection($staff));
    }

    public function show(string $staff): JsonResponse
    {
        $profile = StaffProfile::query()->bookable()->with(['user', 'branches', 'services'])->findOrFail($staff);

        return $this->ok(new StaffResource($profile));
    }

    public function services(string $staff, ServiceCatalogService $catalog): JsonResponse
    {
        $profile = StaffProfile::query()->bookable()->findOrFail($staff);

        return $this->ok(ServiceResource::collection(
            $catalog->query()->whereHas('staff', fn ($q) => $q->whereKey($profile->id))->get()
        ));
    }
}
