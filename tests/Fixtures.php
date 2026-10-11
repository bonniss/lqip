<?php namespace Bonniss26\Lqip\Tests;

use Bonniss26\Lqip\Tests\Support\ExifOrientationTest;

/**
 * Synthetic images, generated with GD at test time - no binary fixtures in the
 * repository. GD is only the fixture factory here; the bytes it produces are
 * plain PNG/JPEG that every driver under test decodes on its own.
 */
final class Fixtures
{
    public static function solidPng(int $rgb, int $w = 8, int $h = 8): string
    {
        $image = imagecreatetruecolor($w, $h);
        imagefilledrectangle($image, 0, 0, $w - 1, $h - 1, $rgb);

        return self::png($image);
    }

    /** Four solid quadrants: red, green / blue, yellow. */
    public static function quadrantPng(int $cellSize = 1): string
    {
        $image = imagecreatetruecolor($cellSize * 2, $cellSize * 2);
        $last = $cellSize * 2 - 1;

        imagefilledrectangle($image, 0, 0, $cellSize - 1, $cellSize - 1, 0xFF0000);
        imagefilledrectangle($image, $cellSize, 0, $last, $cellSize - 1, 0x00FF00);
        imagefilledrectangle($image, 0, $cellSize, $cellSize - 1, $last, 0x0000FF);
        imagefilledrectangle($image, $cellSize, $cellSize, $last, $last, 0xFFFF00);

        return self::png($image);
    }

    /** Fully transparent white. */
    public static function transparentPng(int $w, int $h): string
    {
        $image = imagecreatetruecolor($w, $h);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefilledrectangle($image, 0, 0, $w - 1, $h - 1, imagecolorallocatealpha($image, 0xFF, 0xFF, 0xFF, 127));

        return self::png($image);
    }

    /**
     * A stand-in for a logo: left half opaque $rgb, right half fully
     * transparent - with blue as its (meaningless) colour, so a result that
     * lets transparent colour bleed in shows it.
     */
    public static function logoPng(int $rgb, int $w = 16, int $h = 16): string
    {
        $image = imagecreatetruecolor($w, $h);
        imagealphablending($image, false);
        imagesavealpha($image, true);
        imagefilledrectangle($image, 0, 0, intdiv($w, 2) - 1, $h - 1, $rgb);
        imagefilledrectangle($image, intdiv($w, 2), 0, $w - 1, $h - 1, imagecolorallocatealpha($image, 0, 0, 0xFF, 127));

        return self::png($image);
    }

    /** Left half one colour, right half another. */
    public static function splitJpeg(int $left, int $right, int $w, int $h): string
    {
        $image = imagecreatetruecolor($w, $h);
        imagefilledrectangle($image, 0, 0, intdiv($w, 2) - 1, $h - 1, $left);
        imagefilledrectangle($image, intdiv($w, 2), 0, $w - 1, $h - 1, $right);

        ob_start();
        imagejpeg($image, null, 95);

        return ob_get_clean();
    }

    /** Inserts an Exif APP1 segment carrying $orientation straight after SOI. */
    public static function withExifOrientation(string $jpeg, int $orientation): string
    {
        $segment = substr(ExifOrientationTest::jpegWithOrientation($orientation, 'MM'), 2, -2);

        return substr($jpeg, 0, 2) . $segment . substr($jpeg, 2);
    }

    /**
     * A PNG signature and IHDR claiming the given size, with no image data -
     * enough for getimagesizefromstring(), useless to a decoder.
     */
    public static function pngHeaderOnly(int $w, int $h): string
    {
        $ihdr = 'IHDR' . pack('NNCCCCC', $w, $h, 8, 2, 0, 0, 0);

        return "\x89PNG\r\n\x1A\n" . pack('N', 13) . $ihdr . pack('N', crc32($ihdr));
    }

    /** @param \GdImage|resource $image */
    private static function png($image): string
    {
        ob_start();
        imagepng($image);

        return ob_get_clean();
    }
}
