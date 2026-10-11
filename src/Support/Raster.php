<?php namespace Bonniss26\Lqip\Support;

/**
 * A small, opaque RGB image held as plain PHP integers.
 *
 * This is the boundary between an image engine and the pixel work. Drivers
 * decode and shrink with whatever engine they wrap, then hand over a Raster;
 * everything after that - blurring, colour quantization, cell averaging - is
 * pure PHP, so it produces the same result whichever engine did the decoding.
 *
 * Only ever placeholder-sized (at most PlaceholderGenerator::MAX_LONG_EDGE on a
 * side), so an array of ints is cheap. Alpha is already gone: drivers composite
 * transparent sources onto black before building one.
 */
final class Raster
{
    /** @var int */
    private $width;

    /** @var int */
    private $height;

    /** @var int[] 0xRRGGBB per pixel, row-major. */
    private $pixels;

    /**
     * @param int[] $pixels 0xRRGGBB per pixel, row-major, exactly $width * $height of them.
     */
    public function __construct(int $width, int $height, array $pixels)
    {
        if ($width < 1 || $height < 1 || count($pixels) !== $width * $height) {
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
        $pixels = [];
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
            }
        }

        return new self($outWidth, $outHeight, $pixels);
    }
}
