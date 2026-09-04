<?php namespace Bonniss26\Lqip\Support;

/**
 * Picks a single representative colour out of an image.
 *
 * Quantizes down to a handful of colours and takes the most-used one rather
 * than averaging every pixel - averaging tends to muddy distinct colours into
 * grey/brown, which is exactly the failure a placeholder makes most visible.
 *
 * Lives here rather than inside a strategy because more than one mode needs
 * it: `dominant_color` renders it directly, `css_only` uses it as the base
 * colour it encodes the six luminance cells relative to.
 */
final class DominantColor
{
    /** Side of the square the source is reduced to before quantizing. */
    const SAMPLE_SIZE = 8;

    /** How many colours the sample is quantized down to. */
    const PALETTE_SIZE = 5;

    /**
     * @param \GdImage|resource $source Decoded source image. Not modified.
     * @return array{0:int,1:int,2:int}|null [r, g, b], or null if GD could not
     *                                       build a palette for the sample.
     */
    public static function forImage($source, int $origWidth, int $origHeight): ?array
    {
        $size = self::SAMPLE_SIZE;

        $tiny = imagecreatetruecolor($size, $size);
        imagecopyresampled($tiny, $source, 0, 0, 0, 0, $size, $size, $origWidth, $origHeight);

        // Some GD builds can fail to allocate the palette. Without this check the
        // loop below would count indices from a still-truecolor image, where
        // imagecolorat() returns packed RGB rather than a palette index, and
        // imagecolorsforindex() would then read a colour that isn't there.
        if (imagetruecolortopalette($tiny, false, self::PALETTE_SIZE) === false) {
            imagedestroy($tiny);

            return null;
        }

        // Scan order matters. arsort() is stable as of PHP 8.0, so colours tied on
        // pixel count fall back to insertion order - which is this x-then-y walk.
        // Flipping the nesting silently changes which colour wins a tie.
        $counts = [];
        for ($x = 0; $x < $size; $x++) {
            for ($y = 0; $y < $size; $y++) {
                $index = imagecolorat($tiny, $x, $y);
                $counts[$index] = ($counts[$index] ?? 0) + 1;
            }
        }
        arsort($counts);

        $rgb = imagecolorsforindex($tiny, array_key_first($counts));
        imagedestroy($tiny);

        return [$rgb['red'], $rgb['green'], $rgb['blue']];
    }
}
