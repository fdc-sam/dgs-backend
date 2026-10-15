<?php

namespace App\Http\Requests\Api;

use App\Models\Favorite;
use Illuminate\Validation\Rule;

class FavoriteRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::in(Favorite::TYPES)],
            'id' => ['required', 'string', 'size:26'],
        ];
    }
}
