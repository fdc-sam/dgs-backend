<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\CreateReviewRequest;
use App\Http\Resources\ReviewResource;
use App\Models\Review;
use App\Services\ReviewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class ReviewController extends ApiController
{
    private const RELATIONS = ['images', 'customer', 'service', 'appointment.items'];

    public function index(Request $request): JsonResponse
    {
        $request->validate([
            'service_id' => ['nullable', 'string', 'size:26'],
            'mine' => ['nullable', 'boolean'],
        ]);
        $user = auth('sanctum')->user();
        $mine = $request->boolean('mine');

        abort_if($mine && ! $user, 401);

        $reviews = Review::query()
            ->with(self::RELATIONS)
            ->when($mine, fn ($q) => $q->where('customer_id', $user->id), fn ($q) => $q->approved())
            ->when($request->input('service_id'), fn ($q, $id) => $q->where('service_id', $id))
            ->latest()
            ->paginate($this->perPage());

        return $this->ok(ReviewResource::collection($reviews));
    }

    public function store(CreateReviewRequest $request, ReviewService $reviews): JsonResponse
    {
        $this->authorize('create', Review::class);

        $review = $reviews->create($request->user(), $request->validated(), $request->file('images', []));

        return $this->created(new ReviewResource($review), 'Thank you for your review!');
    }

    public function show(Review $review): JsonResponse
    {
        abort_unless(Gate::forUser(auth('sanctum')->user())->allows('view', $review), 404);

        return $this->ok(new ReviewResource($review->load(self::RELATIONS)));
    }
}
