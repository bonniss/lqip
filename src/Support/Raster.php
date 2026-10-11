<?php namespace Bonniss26\Lqip\Support;

/**
 * A small RGB image, with optional alpha, held as plain PHP integers.
 *
 * This is the boundary between an image engine and the pixel work. Drivers
 * decode and shrink with whatever engine they wrap, then hand over a Raster;
 * everything after that - blurring, colour quantization, cell averaging - is
 * pure PHP, so it produces the same result whichever engine did the decoding.
 *
 * Only ever placeholder-sized (at most PlaceholderGenerator::MAX_LONG_EDGE on a
 * side), so an array of ints is cheap.
 *
 * Colour and alpha are kept apart: pixels() is always 0xRRGGBB, as straight
 * (not premultiplied) colour, and alpha lives in its own plane, which is null
 * for an opaque image. Most images are opaque, and keeping them on exactly the
 * old representation is what keeps every opaque result byte-identical. The
 * colour of a fully transparent pixel means nothing; anything that averages
 * colours must weight them by alpha.
 */
final class Raster
{
    /** @var int */
    private $width;

    /** @var int */
    private $height;

    /** @var int[] 0xRRGGBB per pixel, row-major. */
    private $pixels;

    /** @var int[]|null 0 (transparent) to 255 (opaque) per pixel; null when all are opaque. */
    private $alphas;

    /**
     * @param int[] $pixels 0xRRGGBB per pixel, row-major, exactly $width * $height of them.
     * @param int[]|null $alphas 0-255 per pixel in the same order; null (or all 255) for opaque.
     */
    public function __construct(int $width, int $height, array $pixels, ?array $alphas = null)
    {
        if ($width < 1 || $height < 1 || count($pixels) !== $width * $height
            || ($alphas !== null && count($alphas) !== $width * $height)
        ) {
            throw new \InvalidArgumentException(sprintf(
                'A %dx%d raster needs %d pixels, got %d.',
                $width,
                $height,
                max(0, $width * $height),
                count($pixels)
            ));
        }

        $this->width = $width;
        $this->height = $height;
        $this->pixels = array_values($pixels);
        // Normalised, so that "opaque" has exactly one representation and every
        // transparency check is a null test.
        $this->alphas = ($alphas === null || min($alphas) >= 255) ? null : array_values($alphas);
    }

    /**
     * @param int[] $bytes Flat R, G, B, R, G, B... channel values, row-major -
     *                     the shape Imagick::exportImagePixels() returns.
     */
    public static function fromRgbBytes(int $width, int $height, array $bytes): self
    {
        $pixels = [];
        for ($i = 0, $n = count($bytes); $i + 2 < $n; $i += 3) {
            $pixels[] = (($bytes[$i] & 0xFF) << 16) | (($bytes[$i + 1] & 0xFF) << 8) | ($bytes[$i + 2] & 0xFF);
        }

        return new self($width, $height, $pixels);
    }

    /**
     * @param int[] $bytes Flat R, G, B, A, R, G, B, A... channel values, row-major,
     *                     alpha 255 = opaque - Imagick's 'RGBA' export.
     */
    public static function fromRgbaBytes(int $width, int $height, array $bytes): self
    {
        $pixels = $alphas = [];
        for ($i = 0, $n = count($bytes); $i + 3 < $n; $i += 4) {
            $pixels[] = (($bytes[$i] & 0xFF) << 16) | (($bytes[$i + 1] & 0xFF) << 8) | ($bytes[$i + 2] & 0xFF);
            $alphas[] = $bytes[$i + 3] & 0xFF;
        }

        return new self($width, $height, $pixels, $alphas);
    }

    public function width(): int
    {
        return $this->width;
    }

    public function height(): int
    {
        return $this->height;
    }

    /** Packed 0xRRGGBB. */
    public function rgbAt(int $x, int $y): int
    {
        return $this->pixels[$y * $this->width + $x];
    }

    /** @return array{0:int,1:int,2:int} */
    public function channelsAt(int $x, int $y): array
    {
        $pixel = $this->pixels[$y * $this->width + $x];

        return [($pixel >> 16) & 0xFF, ($pixel >> 8) & 0xFF, $pixel & 0xFF];
    }

    /** @return int[] 0xRRGGBB per pixel, row-major. */
    public function pixels(): array
    {
        return $this->pixels;
    }

    /** 0 (transparent) to 255 (opaque). */
    public function alphaAt(int $x, int $y): int
    {
        return $this->alphas === null ? 255 : $this->alphas[$y * $this->width + $x];
    }

    /** @return int[]|null 0-255 per pixel, row-major, or null when every pixel is opaque. */
    public function alphas(): ?array
    {
        return $this->alphas;
    }

    public function hasTransparency(): bool
    {
        return $this->alphas !== null;
    }

    /**
     * How much of the image is painted at all, 0.0 (nothing) to 1.0 (opaque):
     * the mean alpha. A logo on a transparent background might be 0.3.
     */
    public function coverage(): float
    {
        return $this->alphas === null ? 1.0 : array_sum($this->alphas) / (255 * count($this->alphas));
    }

    /**
     * Composites the image onto a solid colour, returning an opaque raster.
     *
     * @param int $background 0xRRGGBB.
     */
    public function flatten(int $background): self
    {
        if ($this->alphas === null) {
            return $this;
        }

        $bg = [($background >> 16) & 0xFF, ($background >> 8) & 0xFF, $background & 0xFF];
        $pixels = [];
        foreach ($this->pixels as $i => $pixel) {
            $a = $this->alphas[$i];
            $out = 0;
            foreach ([16, 8, 0] as $c => $shift) {
                $channel = (int) round(((($pixel >> $shift) & 0xFF) * $a + $bg[$c] * (255 - $a)) / 255);
                $out |= $channel << $shift;
            }
            $pixels[] = $out;
        }

        return new self($this->width, $this->height, $pixels);
    }

    /** @return int[] Flat R, G, B, R, G, B... - the inverse of fromRgbBytes(). */
    public function toRgbBytes(): array
    {
        $bytes = [];
        foreach ($this->pixels as $pixel) {
            $bytes[] = ($pixel >> 16) & 0xFF;
            $bytes[] = ($pixel >> 8) & 0xFF;
            $bytes[] = $pixel & 0xFF;
        }

        return $bytes;
    }

    /** @return int[] Flat R, G, B, A... - the inverse of fromRgbaBytes(). */
    public function toRgbaBytes(): array
    {
        $bytes = [];
        foreach ($this->pixels as $i => $pixel) {
            $bytes[] = ($pixel >> 16) & 0xFF;
            $bytes[] = ($pixel >> 8) & 0xFF;
            $bytes[] = $pixel & 0xFF;
            $bytes[] = $this->alphas === null ? 255 : $this->alphas[$i];
        }

        return $bytes;
    }

    /**
     * Applies an EXIF orientation, returning the image the way a browser
     * displays it.
     *
     * Done here rather than in each driver because a placeholder is tiny: a
     * per-pixel remap in PHP costs nothing, and one implementation means GD and
     * Imagick cannot disagree about which way is up. Orientations 5-8 swap the
     * axes, so the result is $height wide.
     *
     * @param int $orientation EXIF Orientation, 1-8. Anything else is treated as 1.
     */
    public function orient(int $orientation): self
    {
        if ($orientation < 2 || $orientation > 8) {
            return $this;
        }

        $w = $this->width;
        $h = $this->height;
        $swaps = $orientation >= 5;
        $outWidth = $swaps ? $h : $w;
        $outHeight = $swaps ? $w : $h;

        // For every output (display) pixel, which stored pixel lands there.
        $pixels = $alphas = [];
        for ($y = 0; $y < $outHeight; $y++) {
            for ($x = 0; $x < $outWidth; $x++) {
                switch ($orientation) {
                    case 2: [$sx, $sy] = [$w - 1 - $x, $y]; break;          // mirror horizontal
                    case 3: [$sx, $sy] = [$w - 1 - $x, $h - 1 - $y]; break; // rotate 180
                    case 4: [$sx, $sy] = [$x, $h - 1 - $y]; break;          // mirror vertical
                    case 5: [$sx, $sy] = [$y, $x]; break;                   // transpose
                    case 6: [$sx, $sy] = [$y, $h - 1 - $x]; break;          // rotate 90 CW
                    case 7: [$sx, $sy] = [$w - 1 - $y, $h - 1 - $x]; break; // transverse
                    default: [$sx, $sy] = [$w - 1 - $y, $x]; break;         // 8: rotate 90 CCW
                }
                $pixels[] = $this->pixels[$sy * $w + $sx];
                if ($this->alphas !== null) {
                    $alphas[] = $this->alphas[$sy * $w + $sx];
                }
            }
        }

        return new self($outWidth, $outHeight, $pixels, $this->alphas === null ? null : $alphas);
    }
}
