<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Validation\Rule;

/**
 * The number printed on a salon loyalty card ("CARD NO. LC-0000353"): stored as its digits ("0000353"), unique to one
 * customer, and shown with the card's prefix. Length and prefix come from config salon.loyalty.
 */
final class LoyaltyNumber
{
    /** "LC-0000353", "lc 0000353", "0000-353" → "0000353"; anything that is not a string stays as it is for validation to reject. */
    public static function normalize(mixed $value): mixed
    {
        if (! is_string($value)) {
            return $value;
        }
        $compact = preg_replace('/[\s\-]/', '', $value);
        $letters = preg_replace('/[\s\-]/', '', (string) config('salon.loyalty.prefix'));

        return $letters !== '' && stripos($compact, $letters) === 0 ? substr($compact, strlen($letters)) : $compact;
    }

    /** "0000353" → "LC-0000353", the way it is printed on the card. */
    public static function format(?string $number): ?string
    {
        return $number === null ? null : config('salon.loyalty.prefix').$number;
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
        $min = (int) config('salon.loyalty.min_digits');
        $max = (int) config('salon.loyalty.max_digits');
        $digits = $min === $max ? "{$min}-digit" : "{$min} to {$max} digit";

        return [
            "{$field}.regex" => "Enter the {$digits} card number printed on the loyalty card, e.g. ".self::format(str_pad('353', $max, '0', STR_PAD_LEFT)).'.',
            "{$field}.unique" => 'This loyalty card is already linked to another account. Please check the number.',
        ];
    }
}
