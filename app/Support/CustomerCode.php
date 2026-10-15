<?php

namespace App\Support;

/**
 * The unique code printed in a customer's QR code, e.g. DGS-7K2M-9QXP-4T8W-HNCD-5RFB.
 *
 * It is random (100 bits, from a CSPRNG) and opaque: it carries no personal data, so the QR can be shown or photographed
 * without exposing anything beyond "this is customer code X". Letters and digits only, without the look-alikes 0/O and
 * 1/I, in groups of four so it can also be read out or typed. Dashes and uppercase letters are valid QR "alphanumeric"
 * characters, which keeps the QR small.
 */
final class CustomerCode
{
    public const PREFIX = 'DGS';

    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    private const GROUPS = 5;

    private const GROUP_SIZE = 4;

    public static function generate(): string
    {
        $groups = [];
        for ($g = 0; $g < self::GROUPS; $g++) {
            $chars = '';
            for ($i = 0; $i < self::GROUP_SIZE; $i++) {
                $chars .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
            }
            $groups[] = $chars;
        }

        return self::PREFIX.'-'.implode('-', $groups);
    }

    /** Accepts what a person might type or a scanner might send: any case, spaces or missing dashes. */
    public static function normalize(string $input): ?string
    {
        $raw = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $input));
        $length = self::GROUPS * self::GROUP_SIZE;
        if (! str_starts_with($raw, self::PREFIX) || strlen($raw) !== strlen(self::PREFIX) + $length) {
            return null;
        }
        $body = substr($raw, strlen(self::PREFIX));
        if (strspn($body, self::ALPHABET) !== $length) {
            return null;
        }

        return self::PREFIX.'-'.implode('-', str_split($body, self::GROUP_SIZE));
    }
}
