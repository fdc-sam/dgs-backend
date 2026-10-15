<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\RecordStatus;
use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\ServiceCategoryResource;
use App\Models\ServiceCategory;
use App\Services\ServiceCatalogService;
use Illuminate\Http\JsonResponse;

class CategoryController extends ApiController
{
    public function index(): JsonResponse
    {
        $categories = ServiceCategory::query()->active()->ordered()
            ->withCount(['services' => fn ($q) => $q->active()])
            ->get();

        return $this->ok(ServiceCategoryResource::collection($categories));
    }

    public function show(ServiceCategory $category, ServiceCatalogService $catalog): JsonResponse
    {
        abort_unless($category->status === RecordStatus::Active, 404);

        $category->setRelation('services', $catalog->query(['category_id' => $category->id])->get());
        $category->loadCount(['services' => fn ($q) => $q->active()]);

        return $this->ok(new ServiceCategoryResource($category));
    }
}
