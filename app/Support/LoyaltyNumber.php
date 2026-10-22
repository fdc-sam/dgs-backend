<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Validation\Rule;

/** The number printed on a salon loyalty card: digits only, unique to one customer. */
final class LoyaltyNumber
{
    /** "1234 5678-9012" → "123456789012"; anything that is not a string stays as it is for validation to reject. */
    public static function normalize(mixed $value): mixed
    {
        return is_string($value) ? preg_replace('/[\s\-]/', '', $value) : $value;
    }

    /** "123456789012" → "1234 5678 9012", the way it is printed on the card. */
    public static function format(?string $number): ?string
    {
        return $number === null ? null : trim(chunk_split($number, 4, ' '));
    }

    /** @return list<mixed> */
    public static function rules(?User $owner = null): array
    {
        $min = (int) config('salon.loyalty.min_digits');
        $max = (int) config('salon.loyalty.max_digits');

        return ['required', 'string', "regex:/^\\d{{$min},{$max}}$/", Rule::unique('users', 'loyalty_number')->ignore($owner?->id)];
    }

    /** @return array<string, string> keyed for a field called $field */
    public static function messages(string $field = 'loyalty_number'): array
    {
        return [
            "{$field}.regex" => sprintf('Enter the %d to %d digit number printed on the loyalty card.',
                config('salon.loyalty.min_digits'), config('salon.loyalty.max_digits')),
            "{$field}.unique" => 'This loyalty card is already linked to another account. Please check the number.',
        ];
    }
}
