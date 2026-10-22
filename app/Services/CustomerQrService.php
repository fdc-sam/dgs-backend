<?php

namespace App\Services;

use App\Models\User;
use Endroid\QrCode\Builder\Builder;
use Endroid\QrCode\Color\Color;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\Writer\PngWriter;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Draws a customer's QR code: their unique code (see App\Support\CustomerCode) as a QR with the salon logo in the
 * middle and the code printed underneath (for typing it in if a scan fails).
 *
 * The logo covers part of the symbol, so the highest error-correction level is used (up to 30 % of the symbol can be
 * unreadable and it still decodes). The image is deterministic: the same customer always gets the same picture.
 */
class CustomerQrService
{
    public const DEFAULT_SIZE = 600;

    public const MIN_SIZE = 240;

    public const MAX_SIZE = 1200;

    /** Logo width as a share of the QR width - small enough for reliable scanning with level-H correction. */
    private const LOGO_SHARE = 0.22;

    public function __construct(private readonly SettingsService $settings) {}

    public function png(User $customer, int $size = self::DEFAULT_SIZE): string
    {
        $size = max(self::MIN_SIZE, min(self::MAX_SIZE, $size));
        $code = $this->codeFor($customer);

        return (new Builder(
            writer: new PngWriter,
            data: $code,
            errorCorrectionLevel: ErrorCorrectionLevel::High,
            size: $size,
            margin: (int) round($size * 0.05),
            foregroundColor: new Color(28, 27, 31),
            backgroundColor: new Color(255, 255, 255),
            labelText: $code,
            labelTextColor: new Color(28, 27, 31),
            logoPath: $this->logoPath(),
            logoResizeToWidth: (int) round($size * self::LOGO_SHARE),
            logoPunchoutBackground: true,
        ))->build()->getString();
    }

    /** The cacheable HTTP response for a customer's QR image (inline, or as a download). */
    public function response(User $customer, Request $request, int $size = self::DEFAULT_SIZE, bool $download = false): Response
    {
        $size = max(self::MIN_SIZE, min(self::MAX_SIZE, $size));
        $etag = '"'.md5($this->codeFor($customer).'|'.$size.'|'.$this->logoPath().'|'.@filemtime($this->logoPath())).'"';

        $response = response()->make('', 200, ['Content-Type' => 'image/png']);
        $response->setEtag(trim($etag, '"'));
        $response->headers->set('Cache-Control', 'private, max-age=3600, must-revalidate');
        if ($response->isNotModified($request)) {
            return $response;
        }

        $response->setContent($this->png($customer, $size));
        if ($download) {
            $response->headers->set('Content-Disposition', 'attachment; filename="dgs-qr-'.Str::slug($customer->name).'.png"');
        }

        return $response;
    }

    /** Customers always have a code from creation; this only covers an account whose role was changed to customer later. */
    private function codeFor(User $customer): string
    {
        if (blank($customer->qr_code)) {
            $customer->forceFill(['qr_code' => User::newUniqueQrCode()])->save();
        }

        return $customer->qr_code;
    }

    /**
     * The logo on a white backing: the QR PNG has no alpha channel, so a transparent logo's clear corners would turn
     * black. Flattened once per logo file and kept under storage/app/cache.
     */
    private function logoPath(): string
    {
        $source = $this->sourceLogoPath();
        $flat = storage_path('app/cache/qr-logo-white-'.sha1($source.'|'.@filemtime($source)).'.png');
        if (is_file($flat)) {
            return $flat;
        }

        $logo = @imagecreatefromstring((string) @file_get_contents($source));
        if ($logo === false) {
            return $source;
        }
        $canvas = imagecreatetruecolor(imagesx($logo), imagesy($logo));
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopy($canvas, $logo, 0, 0, 0, 0, imagesx($logo), imagesy($logo));
        @mkdir(dirname($flat), 0755, true);
        imagepng($canvas, $flat);

        return $flat;
    }

    /** The salon's configured logo (admin → Settings), or the bundled DGS logo. */
    private function sourceLogoPath(): string
    {
        $configured = $this->settings->get('salon_logo');
        $disk = Storage::disk(FileUploadService::publicDisk());

        if ($configured && $disk->exists($configured)) {
            if (config('filesystems.disks.'.FileUploadService::publicDisk().'.driver') === 'local') {
                return $disk->path($configured);
            }

            // Remote storage (Supabase): keep a local copy for the QR renderer, keyed by path (uploads are immutable).
            $cached = storage_path('app/cache/qr-logo-'.sha1($configured).'.'.pathinfo($configured, PATHINFO_EXTENSION));
            if (! is_file($cached)) {
                @mkdir(dirname($cached), 0755, true);
                file_put_contents($cached, $disk->get($configured));
            }

            return $cached;
        }

        return public_path('images/dgs-logo-512.png');
    }
}
