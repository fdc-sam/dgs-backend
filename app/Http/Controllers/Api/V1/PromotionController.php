<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PromotionStatus;
use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\PromotionResource;
use App\Models\Promotion;
use App\Services\PromotionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PromotionController extends ApiController
{
    public function __construct(private readonly PromotionService $promotions) {}

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'branch_id' => ['nullable', 'string', 'size:26'],
            'service_id' => ['nullable', 'string', 'size:26'],
        ]);

        return $this->ok(PromotionResource::collection(
            $this->promotions->activeQuery($request->input('branch_id'), $request->input('service_id'))->paginate($this->perPage())
        ));
    }

    public function active(Request $request): JsonResponse
    {
        $request->validate(['branch_id' => ['nullable', 'string', 'size:26']]);

        return $this->ok(PromotionResource::collection(
            $this->promotions->activeQuery($request->input('branch_id'))->limit(20)->get()
        ));
    }

    public function show(Promotion $promotion): JsonResponse
    {
        abort_if($promotion->status === PromotionStatus::Draft, 404);

        return $this->ok(new PromotionResource($promotion->load(['services', 'branches'])));
    }
}
