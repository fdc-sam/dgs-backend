<?php

namespace App\Http\Requests\Admin;

use Illuminate\Validation\Rule;

class AchievementRequest extends AdminRequest
{
    public function rules(): array
    {
        $imageRules = $this->imageRules();

        return [
            'title' => ['required', 'string', 'max:160'],
            'description' => ['required', 'string', 'max:5000'],
            'image' => [Rule::requiredIf(! $this->route('achievement')), ...array_slice($imageRules, 1)],
        ];
    }
}
