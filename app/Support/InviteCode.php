<?php

namespace App\Support;

/**
 * A customer's invite code, the end of their invite link (/i/MARIA7K2M): up to five letters of their first name, then
 * four random characters, so friends recognise whose link it is and can type it if the link didn't carry it.
 * Letters and digits only, without the look-alikes 0/O and 1/I in the random part. Unlike the QR code (CustomerCode)
 * it is meant to be shared openly: it only says "invited by this customer".
 */
final class InviteCode
{
    private const ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    private const NAME_LETTERS = 5;

    private const RANDOM_CHARS = 4;

    public static function generate(string $name): string
    {
        $first = strtok(trim($name), ' ') ?: '';
        $letters = strtoupper(substr((string) preg_replace('/[^A-Za-z]/', '', (string) iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $first)), 0, self::NAME_LETTERS));
        $prefix = strlen($letters) >= 2 ? $letters : 'DGS';

        $random = '';
        for ($i = 0; $i < self::RANDOM_CHARS; $i++) {
            $random .= self::ALPHABET[random_int(0, strlen(self::ALPHABET) - 1)];
        }

        return $prefix.$random;
    }

    /**
     * What a person might type or a link might carry: any case, spaces or dashes, or the whole invite link pasted
     * (https://…/i/MARIA7K2M). Null when it can't be a code.
     */
    public static function normalize(?string $input): ?string
    {
        $input = (string) $input;
        if (preg_match('~/i/([A-Za-z0-9-]+)~', $input, $m)) {
            $input = $m[1];
        }
        $raw = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', $input));

        return preg_match('/^[A-Z0-9]{5,16}$/', $raw) ? $raw : null;
    }
}
