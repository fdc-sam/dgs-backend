<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\PromotionStatus;
use App\Http\Controllers\Api\ApiController;
use App\Http\Requests\Api\FavoriteRequest;
use App\Http\Resources\FavoriteResource;
use App\Models\Favorite;
use App\Models\Promotion;
use App\Models\Service;
use App\Models\StaffProfile;
use App\Support\FavoriteLookup;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class FavoriteController extends ApiController
{
    public function index(Request $request): JsonResponse
    {
        $request->validate(['type' => ['nullable', Rule::in(Favorite::TYPES)]]);

        $favorites = $request->user()->favorites()
            ->when($request->input('type'), fn ($q, $type) => $q->where('favoritable_type', $type))
            ->with(['favoritable' => fn (MorphTo $morph) => $morph->morphWith([
                Service::class => ['category', 'variants'],
                StaffProfile::class => ['user', 'branches', 'services'],
                Promotion::class => ['services', 'branches'],
            ])])
            ->latest()
            ->get()
            ->filter(fn (Favorite $f) => $f->favoritable !== null)
            ->values();

        return $this->ok(FavoriteResource::collection($favorites));
    }

    public function store(FavoriteRequest $request, FavoriteLookup $lookup): JsonResponse
    {
        $type = $request->input('type');
        $target = match ($type) {
            'service' => Service::query()->active()->findOrFail($request->input('id')),
            'staff' => StaffProfile::query()->bookable()->findOrFail($request->input('id')),
            'promotion' => Promotion::query()->where('status', '!=', PromotionStatus::Draft->value)->findOrFail($request->input('id')),
        };

        $favorite = Favorite::firstOrCreate([
            'user_id' => $request->user()->id,
            'favoritable_type' => $type,
            'favoritable_id' => $target->getKey(),
        ]);
        $lookup->forget();

        $response = new FavoriteResource($favorite->load('favoritable'));

        return $favorite->wasRecentlyCreated ? $this->created($response, 'Added to favorites.') : $this->ok($response);
    }

    public function destroy(Favorite $favorite, FavoriteLookup $lookup): JsonResponse
    {
        $this->authorize('delete', $favorite);
        $favorite->delete();
        $lookup->forget();

        return $this->ok(null, 'Removed from favorites.');
    }
}
