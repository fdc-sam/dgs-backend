<?php

namespace App\Http\Requests\Api;

class CreateReviewRequest extends ApiRequest
{
    public function rules(): array
    {
        return [
            'appointment_id' => ['required', 'string', 'size:26', 'exists:appointments,id'],
            'service_id' => ['nullable', 'string', 'size:26'],
            'rating' => ['required', 'integer', 'between:1,5'],
            'review' => ['nullable', 'string', 'max:2000'],
            'images' => ['nullable', 'array', 'max:3'],
            'images.*' => ['file', 'image', 'mimes:'.implode(',', config('salon.uploads.mimes')), 'max:'.config('salon.uploads.max_kilobytes')],
        ];
    }
}
