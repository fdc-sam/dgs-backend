<?php

namespace App\Services;

use GdImage;

/**
 * The picture a shared invite link shows in Messenger, Viber, WhatsApp… (its Open Graph image, 1200×630): the salon
 * badge on the left, "Maria S. invited you" with the membership offer and the invite code on the right, in the badge's
 * black and gold. Sharing only the link makes this card the whole message; tapping it opens /i/{code}. JPEG.
 *
 * Pure drawing (no database): the controller passes the inviter's short name, the code and the price line.
 */
class InviteCardService
{
    public const WIDTH = 1200;

    public const HEIGHT = 630;

    /** Bumped whenever the drawing changes, so cached cards (ETag) are replaced. */
    public const RENDER_VERSION = 'card-2';

    private const GOLD = [242, 193, 78];

    private const WHITE = [255, 255, 255];

    private const GREY = [196, 192, 184];

    private const INK = [24, 20, 12];

    /**
     * @param  string|null  $inviterName  "Maria S.", or null for an unknown code (a generic invitation)
     * @param  string|null  $code  the inviter's code, printed so it can be typed in
     * @param  string|null  $offer  "₱200.00 · member prices for a whole year", or null while joining in the app is off
     */
    public function jpeg(?string $inviterName, ?string $code, ?string $offer): string
    {
        $image = imagecreatetruecolor(self::WIDTH, self::HEIGHT);
        imagealphablending($image, true);
        imagefill($image, 0, 0, imagecolorallocate($image, 0, 0, 0));
        $this->glow($image, 330, 315, 330);
        $this->badge($image, 40, 25, 580);

        $left = 650;
        $right = self::WIDTH - 60;
        $width = $right - $left;
        $y = 120;

        $y = $this->text($image, 'YOU\'RE INVITED', $this->bold(), 20, self::GOLD, $left, $y, $width, spacing: 5) + 26;
        $headline = $inviterName ? $inviterName.' invited you to become a member' : 'Become a member';
        $y = $this->text($image, $headline, $this->bold(), $this->fit($headline, 44, $width, 3), self::WHITE, $left, $y, $width, lines: 3) + 22;

        imagefilledrectangle($image, $left, $y, $left + 80, $y + 4, $this->color($image, self::GOLD));
        $y += 32;

        if ($offer) {
            $y = $this->text($image, $offer, $this->regular(), 24, self::GREY, $left, $y, $width, lines: 2) + 34;
        }

        $y = $this->pill($image, 'Tap to join', $left, max($y, 400)) + 30;
        if ($code) {
            $this->text($image, 'Invite code  '.$code, $this->regular(), 20, self::GREY, $left, $y, $width);
        }

        // JPEG, not PNG: the badge is a photo-like picture, ~100 KB instead of ~550 KB. iPhone Messages gives up on
        // slow or heavy preview pictures and then shows the card without one.
        ob_start();
        imagejpeg($image, null, 85);
        $jpeg = (string) ob_get_clean();
        imagedestroy($image);

        return $jpeg;
    }

    /** A soft gold halo behind the badge (stacked translucent discs). */
    private function glow(GdImage $image, int $cx, int $cy, int $radius): void
    {
        for ($r = $radius; $r > $radius * 0.6; $r -= 6) {
            $alpha = (int) round(127 - 10 * (1 - ($r - $radius * 0.6) / ($radius * 0.4)));
            $color = imagecolorallocatealpha($image, 120, 84, 10, max(110, min(127, $alpha)));
            imagefilledellipse($image, $cx, $cy, (int) ($r * 2), (int) ($r * 2), $color);
        }
    }

    /** The round salon badge, cut from the site's share image (black around it, so it blends in). */
    private function badge(GdImage $image, int $x, int $y, int $size): void
    {
        $source = @imagecreatefrompng(public_path('images/og-share.png'));
        if (! $source) {
            return;
        }
        $side = min(imagesx($source), imagesy($source));
        $sx = intdiv(imagesx($source) - $side, 2);
        $sy = intdiv(imagesy($source) - $side, 2);
        imagecopyresampled($image, $source, $x, $y, $sx, $sy, $size, $size, $side, $side);
        imagedestroy($source);
    }

    /** A gold rounded "button" with dark text; returns its bottom edge. */
    private function pill(GdImage $image, string $label, int $x, int $y): int
    {
        $size = 26;
        $box = imagettfbbox($size, 0, $this->bold(), $label);
        $textWidth = $box[2] - $box[0];
        $height = 70;
        $width = $textWidth + 2 * 44;
        $gold = $this->color($image, self::GOLD);
        $r = intdiv($height, 2);
        imagefilledrectangle($image, $x + $r, $y, $x + $width - $r, $y + $height, $gold);
        imagefilledellipse($image, $x + $r, $y + $r, $height, $height, $gold);
        imagefilledellipse($image, $x + $width - $r, $y + $r, $height, $height, $gold);
        $baseline = $y + intdiv($height + ($box[1] - $box[7]), 2) - $box[1];
        imagettftext($image, $size, 0, $x + 44 - $box[0], $baseline, $this->color($image, self::INK), $this->bold(), $label);

        return $y + $height;
    }

    /**
     * Word-wrapped text from the top-left corner ($x, $top), at most $lines lines (the last one ends in "…" if cut).
     * $spacing adds letter spacing (drawn glyph by glyph). Returns the bottom of the last line.
     */
    private function text(GdImage $image, string $text, string $font, float $size, array $rgb, int $x, int $top, int $width, int $lines = 1, int $spacing = 0): int
    {
        $color = $this->color($image, $rgb);
        $lineHeight = (int) round($size * 1.32);
        $ascent = (int) round($size * 1.02);
        foreach ($this->wrap($text, $font, $size, $width, $lines) as $i => $line) {
            $baseline = $top + $i * $lineHeight + $ascent;
            if ($spacing === 0) {
                imagettftext($image, $size, 0, $x, $baseline, $color, $font, $line);

                continue;
            }
            $cursor = $x;
            foreach (mb_str_split($line) as $char) {
                $box = imagettftext($image, $size, 0, $cursor, $baseline, $color, $font, $char);
                $cursor = $box[2] + $spacing;
            }
        }

        return $top + (count($this->wrap($text, $font, $size, $width, $lines)) - 1) * $lineHeight + (int) round($size * 1.3);
    }

    /** @return list<string> */
    private function wrap(string $text, string $font, float $size, int $width, int $lines): array
    {
        $out = [];
        $current = '';
        foreach (preg_split('/\s+/', trim($text)) ?: [] as $word) {
            $candidate = $current === '' ? $word : $current.' '.$word;
            if ($current !== '' && $this->measure($candidate, $font, $size) > $width) {
                $out[] = $current;
                $current = $word;
            } else {
                $current = $candidate;
            }
        }
        if ($current !== '') {
            $out[] = $current;
        }
        if (count($out) > $lines) {
            $out = array_slice($out, 0, $lines);
            $last = $out[$lines - 1];
            while ($last !== '' && $this->measure($last.'…', $font, $size) > $width) {
                $last = mb_substr($last, 0, -1);
            }
            $out[$lines - 1] = rtrim($last).'…';
        }

        return $out;
    }

    /** The largest size up to $max (down to 60 %) at which $text fits in $lines lines. */
    private function fit(string $text, float $max, int $width, int $lines): float
    {
        for ($size = $max; $size > $max * 0.6; $size -= 2) {
            $wrapped = $this->wrap($text, $this->bold(), $size, $width, PHP_INT_MAX);
            $widest = max(array_map(fn (string $line) => $this->measure($line, $this->bold(), $size), $wrapped ?: ['']));
            if (count($wrapped) <= $lines && $widest <= $width) {
                return $size;
            }
        }

        return $max * 0.6;
    }

    private function measure(string $text, string $font, float $size): int
    {
        $box = imagettfbbox($size, 0, $font, $text);

        return $box[2] - $box[0];
    }

    private function color(GdImage $image, array $rgb): int
    {
        return imagecolorallocate($image, ...$rgb);
    }

    /** DejaVu (shipped with dompdf): has the peso sign, which many fonts lack. */
    private function bold(): string
    {
        return base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans-Bold.ttf');
    }

    private function regular(): string
    {
        return base_path('vendor/dompdf/dompdf/lib/fonts/DejaVuSans.ttf');
    }
}
