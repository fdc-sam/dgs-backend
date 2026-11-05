<?php

namespace App\Services;

use App\Models\User;
use Endroid\QrCode\Bacon\MatrixFactory;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use GdImage;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use ReflectionClass;

/**
 * Draws a customer's QR code: their unique code (see App\Support\CustomerCode) as a QR with the salon logo in the
 * middle and the code printed underneath (for typing it in if a scan fails).
 *
 * Styled so the logo sits in the code instead of being pasted over it: the centre modules are cleared on whole-module
 * lines (no half-cut dots), the logo is a rounded tile with a white gap around it, modules round off their free
 * corners and the three finder "eyes" are rounded squares. The cleared centre is about 6 % of the modules, well inside
 * the 30 % that level-H error correction can recover. The image is deterministic: the same customer always gets the
 * same picture.
 */
class CustomerQrService
{
    public const DEFAULT_SIZE = 600;

    public const MIN_SIZE = 240;

    public const MAX_SIZE = 1200;

    /** Bumped whenever the drawing changes, so cached images (ETag) are replaced. */
    private const RENDER_VERSION = 'rounded-1';

    /** Cleared centre as a share of the modules per side (rounded to an odd count, so it is centred on the grid). */
    private const LOGO_HOLE_SHARE = 0.24;

    /** Logo tile corner radius as a share of its width (an app-icon-like rounded square). */
    private const LOGO_CORNER_SHARE = 0.24;

    /** Free module corners are rounded by this share of a module. */
    private const MODULE_CORNER_SHARE = 0.45;

    private const DARK = [28, 27, 31];

    public function __construct(private readonly SettingsService $settings) {}

    public function png(User $customer, int $size = self::DEFAULT_SIZE): string
    {
        return $this->render($this->codeFor($customer), $size, $this->logoPath());
    }

    /** Renders $code as the styled PNG with $logoPath in the middle. Pure: no database or storage access. */
    public function render(string $code, int $size, string $logoPath): string
    {
        $size = max(self::MIN_SIZE, min(self::MAX_SIZE, $size));
        $margin = (int) round($size * 0.05);
        $outer = $size + 2 * $margin;

        $matrix = (new MatrixFactory)->create(new QrCode(data: $code, errorCorrectionLevel: ErrorCorrectionLevel::High));
        $count = $matrix->getBlockCount();
        $dark = fn (int $row, int $col): bool => $row >= 0 && $col >= 0 && $row < $count && $col < $count
            && $matrix->getBlockValue($row, $col) === 1;

        // Drawn at 2-4x and scaled down, which smooths the curves (GD shapes are not anti-aliased).
        $scale = max(2, min(4, intdiv(3000, $outer)));
        $canvas = imagecreatetruecolor($outer * $scale, $outer * $scale);
        $white = imagecolorallocate($canvas, 255, 255, 255);
        $ink = imagecolorallocate($canvas, ...self::DARK);
        imagefill($canvas, 0, 0, $white);

        $module = $size * $scale / $count;
        $at = fn (float $cells): int => (int) round($margin * $scale + $cells * $module);

        $hole = max(5, (int) round($count * self::LOGO_HOLE_SHARE));
        $hole += $hole % 2 === 0 ? 1 : 0;
        $holeStart = intdiv($count - $hole, 2);
        $inHole = fn (int $row, int $col): bool => $row >= $holeStart && $row < $holeStart + $hole
            && $col >= $holeStart && $col < $holeStart + $hole;
        $finders = [[0, 0], [0, $count - 7], [$count - 7, 0]];
        $inFinder = function (int $row, int $col) use ($finders): bool {
            foreach ($finders as [$top, $left]) {
                if ($row >= $top && $row < $top + 7 && $col >= $left && $col < $left + 7) {
                    return true;
                }
            }

            return false;
        };
        $filled = fn (int $row, int $col): bool => $dark($row, $col) && ! $inHole($row, $col);

        $radius = (int) round($module * self::MODULE_CORNER_SHARE);
        for ($row = 0; $row < $count; $row++) {
            for ($col = 0; $col < $count; $col++) {
                if (! $filled($row, $col) || $inFinder($row, $col)) {
                    continue;
                }
                $this->module($canvas, $at($col), $at($row), $at($col + 1) - 1, $at($row + 1) - 1, $radius, $ink, $white, [
                    'tl' => ! $filled($row - 1, $col) && ! $filled($row, $col - 1),
                    'tr' => ! $filled($row - 1, $col) && ! $filled($row, $col + 1),
                    'bl' => ! $filled($row + 1, $col) && ! $filled($row, $col - 1),
                    'br' => ! $filled($row + 1, $col) && ! $filled($row, $col + 1),
                ]);
            }
        }

        foreach ($finders as [$top, $left]) {
            $this->roundedRect($canvas, $at($left), $at($top), $at($left + 7) - 1, $at($top + 7) - 1, (int) round($module * 2.0), $ink);
            $this->roundedRect($canvas, $at($left + 1), $at($top + 1), $at($left + 6) - 1, $at($top + 6) - 1, (int) round($module * 1.3), $white);
            $this->roundedRect($canvas, $at($left + 2), $at($top + 2), $at($left + 5) - 1, $at($top + 5) - 1, (int) round($module * 0.9), $ink);
        }

        $pad = $module * 0.5;
        $this->logo(
            $canvas,
            $logoPath,
            (int) round($at($holeStart) + $pad),
            (int) round($at($holeStart + $hole) - $pad),
            $white,
        );

        $labelHeight = (int) round($size * 0.11);
        $image = imagecreatetruecolor($outer, $outer + $labelHeight);
        imagefill($image, 0, 0, imagecolorallocate($image, 255, 255, 255));
        imagecopyresampled($image, $canvas, 0, 0, 0, 0, $outer, $outer, $outer * $scale, $outer * $scale);
        imagedestroy($canvas);
        $this->label($image, $code, $outer, $labelHeight, $size);

        ob_start();
        imagepng($image);
        imagedestroy($image);

        return (string) ob_get_clean();
    }

    /**
     * One dark module; the corners flagged in $round (no dark neighbour on either side) become quarter circles.
     *
     * @param  array{tl: bool, tr: bool, bl: bool, br: bool}  $round
     */
    private function module(GdImage $img, int $x1, int $y1, int $x2, int $y2, int $r, int $ink, int $bg, array $round): void
    {
        imagefilledrectangle($img, $x1, $y1, $x2, $y2, $ink);
        $corners = [
            'tl' => [$x1, $y1, $x1 + $r, $y1 + $r],
            'tr' => [$x2 - $r, $y1, $x2 - $r, $y1 + $r],
            'bl' => [$x1, $y2 - $r, $x1 + $r, $y2 - $r],
            'br' => [$x2 - $r, $y2 - $r, $x2 - $r, $y2 - $r],
        ];
        foreach ($corners as $key => [$cx1, $cy1, $centreX, $centreY]) {
            if (! $round[$key]) {
                continue;
            }
            imagefilledrectangle($img, $cx1, $cy1, $cx1 + $r, $cy1 + $r, $bg);
            imagefilledellipse($img, $centreX, $centreY, 2 * $r, 2 * $r, $ink);
        }
    }

    private function roundedRect(GdImage $img, int $x1, int $y1, int $x2, int $y2, int $r, int $color): void
    {
        $r = min($r, intdiv($x2 - $x1, 2), intdiv($y2 - $y1, 2));
        imagefilledrectangle($img, $x1 + $r, $y1, $x2 - $r, $y2, $color);
        imagefilledrectangle($img, $x1, $y1 + $r, $x2, $y2 - $r, $color);
        foreach ([[$x1 + $r, $y1 + $r], [$x2 - $r, $y1 + $r], [$x1 + $r, $y2 - $r], [$x2 - $r, $y2 - $r]] as [$cx, $cy]) {
            imagefilledellipse($img, $cx, $cy, 2 * $r, 2 * $r, $color);
        }
    }

    /** The logo scaled into the square $from..$to (canvas pixels) with rounded corners cut back to the background. */
    private function logo(GdImage $canvas, string $path, int $from, int $to, int $bg): void
    {
        $logo = @imagecreatefromstring((string) @file_get_contents($path));
        if ($logo === false) {
            return;
        }
        $side = $to - $from;
        imagecopyresampled($canvas, $logo, $from, $from, 0, 0, $side, $side, imagesx($logo), imagesy($logo));
        imagedestroy($logo);

        $r = (int) round($side * self::LOGO_CORNER_SHARE);
        foreach ([[$from, $from, 1, 1], [$to - 1, $from, -1, 1], [$from, $to - 1, 1, -1], [$to - 1, $to - 1, -1, -1]] as [$cornerX, $cornerY, $dx, $dy]) {
            $centreX = $cornerX + $dx * $r;
            $centreY = $cornerY + $dy * $r;
            for ($i = 0; $i < $r; $i++) {
                for ($j = 0; $j < $r; $j++) {
                    $x = $cornerX + $dx * $i;
                    $y = $cornerY + $dy * $j;
                    if (($x - $centreX) ** 2 + ($y - $centreY) ** 2 > $r * $r) {
                        imagesetpixel($canvas, $x, $y, $bg);
                    }
                }
            }
        }
    }

    /** The code under the QR, centred, sized to the image (for typing it in if a scan fails). */
    private function label(GdImage $image, string $code, int $width, int $height, int $size): void
    {
        $font = dirname((string) (new ReflectionClass(QrCode::class))->getFileName()).'/../assets/open_sans.ttf';
        $points = $size * 0.026;
        $box = imagettfbbox($points, 0, $font, $code);
        $textWidth = $box[2] - $box[0];
        $textHeight = $box[1] - $box[7];
        $x = (int) round(($width - $textWidth) / 2 - $box[0]);
        $y = (int) round($width + ($height - $textHeight) / 2 - $box[7] - $height * 0.25);
        imagettftext($image, $points, 0, $x, $y, imagecolorallocate($image, ...self::DARK), $font, $code);
    }

    /** The cacheable HTTP response for a customer's QR image (inline, or as a download). */
    public function response(User $customer, Request $request, int $size = self::DEFAULT_SIZE, bool $download = false): Response
    {
        $size = max(self::MIN_SIZE, min(self::MAX_SIZE, $size));
        $etag = '"'.md5(self::RENDER_VERSION.'|'.$this->codeFor($customer).'|'.$size.'|'.$this->logoPath().'|'.@filemtime($this->logoPath())).'"';

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
