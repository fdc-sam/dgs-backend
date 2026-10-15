<?php

namespace App\Support;

use App\Models\Favorite;
use Illuminate\Database\Eloquent\Model;

/** Request-scoped map of the current user's favorites, so resources can flag is_favorite without N+1 queries. */
class FavoriteLookup
{
    /** @var array<string, string>|null "type:id" => favorite id */
    private ?array $map = null;

    private ?string $userId = null;

    public function idFor(Model $model): ?string
    {
        $user = auth('sanctum')->user();
        if (! $user) {
            return null;
        }

        if ($this->userId !== $user->id) {
            $this->map = null;
            $this->userId = $user->id;
        }

        $this->map ??= Favorite::where('user_id', $user->id)->get(['id', 'favoritable_type', 'favoritable_id'])
            ->mapWithKeys(fn (Favorite $f) => [$f->favoritable_type.':'.$f->favoritable_id => $f->id])
            ->all();

        return $this->map[$model->getMorphClass().':'.$model->getKey()] ?? null;
    }

    /** @return array{is_favorite: bool, favorite_id: ?string} */
    public function fields(Model $model): array
    {
        $id = $this->idFor($model);

        return ['is_favorite' => $id !== null, 'favorite_id' => $id];
    }

    public function forget(): void
    {
        $this->map = null;
    }
}
