<?php

namespace App\Http\Requests\Api;

class UpdateProfilePhotoRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'photo' => ['required', 'file', 'image', 'mimes:'.implode(',', config('salon.uploads.mimes')), 'max:'.config('salon.uploads.max_kilobytes'), 'dimensions:max_width=8000,max_height=8000'],
        ];
    }
}
