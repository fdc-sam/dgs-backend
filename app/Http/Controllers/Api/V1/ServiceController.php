<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\ServiceResource;
use App\Models\Service;
use App\Services\ServiceCatalogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ServiceController extends ApiController
{
    public function __construct(private readonly ServiceCatalogService $catalog) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'category_id' => ['nullable', 'string', 'size:26'],
            'branch_id' => ['nullable', 'string', 'size:26'],
            'search' => ['nullable', 'string', 'max:100'],
            'featured' => ['nullable', 'boolean'],
        ]);

        return $this->ok(ServiceResource::collection($this->catalog->query($filters)->paginate($this->perPage())));
    }

    public function featured(Request $request): JsonResponse
    {
        $filters = $request->validate(['branch_id' => ['nullable', 'string', 'size:26']]);

        return $this->ok(ServiceResource::collection(
            $this->catalog->query([...$filters, 'featured' => true])->limit(10)->get()
        ));
    }

    public function show(Request $request, Service $service): JsonResponse
    {
        abort_unless($service->isActive(), 404);
        $request->validate(['branch_id' => ['nullable', 'string', 'size:26']]);

        return $this->ok(new ServiceResource($this->catalog->loadDetail($service, $request->input('branch_id'))));
    }
}
