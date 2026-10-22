<?php

namespace App\Support;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

/** Formatting helpers shared by API resources and the admin views. */
final class Fmt
{
    public static function money(mixed $value): ?float
    {
        return $value === null ? null : round((float) $value, 2);
    }

    /** "09:00:00" → "09:00" */
    public static function time(?string $value): ?string
    {
        return $value === null ? null : substr($value, 0, 5);
    }

    public static function iso(?DateTimeInterface $value): ?string
    {
        return $value?->format(DATE_ATOM);
    }

    /**
     * A file in public/ with its modification time appended ("/favicon.png?v=1792…"), so browsers fetch a replaced
     * logo or icon right away instead of showing the copy they cached under the same name.
     */
    public static function versionedAsset(string $path): string
    {
        $modified = @filemtime(public_path($path));

        return asset($path).($modified ? '?v='.$modified : '');
    }

    public static function url(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        return str_starts_with($path, 'http') ? $path : Storage::disk(\App\Services\FileUploadService::publicDisk())->url($path);
    }

    /** Read a selected/aggregate column without tripping preventAccessingMissingAttributes. */
    public static function attr(Model $model, string $key, mixed $default = null): mixed
    {
        return array_key_exists($key, $model->getAttributes()) ? $model->getAttributes()[$key] : $default;
    }

    public static function peso(mixed $value): string
    {
        return ((float) $value < 0 ? '-' : '').'₱'.number_format(abs((float) $value), 2);
    }

    /** "2.50" → "2.5", "30.00" → "30" */
    public static function quantity(mixed $value): string
    {
        return rtrim(rtrim(number_format((float) $value, 2, '.', ','), '0'), '.');
    }
}
