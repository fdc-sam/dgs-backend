<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Http\Resources\AchievementResource;
use App\Models\Achievement;
use Illuminate\Http\JsonResponse;

class AchievementController extends ApiController
{
    public function __invoke(): JsonResponse
    {
        return $this->ok(AchievementResource::collection(Achievement::query()->latest()->get()));
    }
}
