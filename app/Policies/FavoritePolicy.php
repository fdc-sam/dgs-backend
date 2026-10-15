<?php

namespace App\Policies;

use App\Models\Favorite;
use App\Models\User;

class FavoritePolicy
{
    public function delete(User $user, Favorite $favorite): bool
    {
        return $favorite->user_id === $user->id;
    }
}
