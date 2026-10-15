<?php

namespace App\Http\Requests\Api;

use Illuminate\Foundation\Http\FormRequest;

abstract class ApiRequest extends FormRequest
{
    public const PHONE_REGEX = '/^09\d{9}$/';

    public function authorize(): bool
    {
        return true;
    }

    /** Normalise PH mobile numbers: "+63 917-123-4567" → "09171234567". */
    protected function normalizePhone(?string $phone): ?string
    {
        if ($phone === null || trim($phone) === '') {
            return null;
        }
        $digits = preg_replace('/[\s\-().]/', '', $phone);

        return preg_match('/^\+?639\d{9}$/', $digits) ? '0'.substr($digits, -10) : $digits;
    }
}
