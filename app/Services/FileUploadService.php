<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * Stores validated images under salon/{directory} on the media disk (Supabase Storage in production).
 * Images are re-encoded with GD (strips EXIF/embedded payloads) and downscaled to a max dimension.
 */
class FileUploadService
{
    public const DIRECTORIES = ['logo', 'services', 'categories', 'promotions', 'achievements', 'staff', 'reviews', 'customers', 'branches', 'payments'];

    /** Disk for publicly viewable images (config filesystems.media_disk). */
    public static function publicDisk(): string
    {
        return (string) config('filesystems.media_disk', 'public');
    }

    /** Disk for files only staff (and the owner) may see, e.g. payment receipts. Served through authorised routes. */
    public static function privateDisk(): string
    {
        return (string) config('filesystems.private_media_disk', 'local');
    }

    /** @param  bool  $lossless  keep every pixel (QR codes must stay scannable) */
    public function storeImage(UploadedFile $file, string $directory, ?string $replacing = null, bool $lossless = false): string
    {
        if (! in_array($directory, self::DIRECTORIES, true)) {
            throw new InvalidArgumentException("Unknown upload directory [{$directory}].");
        }

        $contents = $this->optimize($file, $lossless);
        $path = $this->newPath("salon/{$directory}", $contents === null ? (string) $file->guessExtension() : 'webp');

        Storage::disk(self::publicDisk())->put($path, $contents ?? $file->getContent(), ['ContentType' => $contents === null ? $file->getMimeType() : 'image/webp', 'CacheControl' => 'public, max-age=31536000, immutable']);

        if ($replacing) {
            $this->delete($replacing);
        }

        return $path;
    }

    /** Same processing as [storeImage] but on the private disk under private/{directory}. */
    public function storePrivateImage(UploadedFile $file, string $directory): string
    {
        if (! in_array($directory, self::DIRECTORIES, true)) {
            throw new InvalidArgumentException("Unknown upload directory [{$directory}].");
        }

        $contents = $this->optimize($file);
        $path = $this->newPath("private/{$directory}", $contents === null ? (string) $file->guessExtension() : 'webp');

        Storage::disk(self::privateDisk())->put($path, $contents ?? $file->getContent(), ['ContentType' => $contents === null ? $file->getMimeType() : 'image/webp']);

        return $path;
    }

    public function delete(?string $path): void
    {
        if ($path && str_starts_with($path, 'salon/')) {
            Storage::disk(self::publicDisk())->delete($path);
        }
    }

    /**
     * Lower-case before writing: the path saved in the database must match the file name exactly on
     * case-sensitive filesystems (Linux servers), not only on macOS.
     */
    private function newPath(string $directory, string $extension): string
    {
        return strtolower("{$directory}/".Str::ulid()->toBase32().'.'.$extension);
    }

    /** Re-encode as WebP, downscaled; returns null when GD cannot process the image. */
    private function optimize(UploadedFile $file, bool $lossless = false): ?string
    {
        if (! function_exists('imagewebp')) {
            return null;
        }

        $image = @imagecreatefromstring($file->getContent());
        if ($image === false) {
            throw ValidationException::withMessages(['image' => 'The uploaded file is not a valid image.']);
        }

        $max = (int) config('salon.uploads.max_dimension', 1600);
        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1, $max / max($width, $height));

        if ($scale < 1) {
            $resized = imagescale($image, (int) round($width * $scale), (int) round($height * $scale));
            imagedestroy($image);
            $image = $resized;
        }

        imagepalettetotruecolor($image);
        imagealphablending($image, true);
        imagesavealpha($image, true);

        ob_start();
        imagewebp($image, null, $lossless ? IMG_WEBP_LOSSLESS : 82);
        $data = ob_get_clean();
        imagedestroy($image);

        return $data ?: null;
    }
}
