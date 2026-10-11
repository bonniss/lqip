<?php namespace Bonniss26\Lqip\Support;

/**
 * A 3x3 Gaussian blur on a Raster - the same arithmetic as GD's
 * imagefilter(IMG_FILTER_GAUSSIAN_BLUR), reproduced exactly.
 *
 * Exactly, not approximately, because the blur defaults were tuned against
 * GD's output: the measurements behind two passes at quality 25 (see
 * AGENTS.md) only stay true if the pixels going into the encoder are the ones
 * that were measured. Doing it in PHP rather than in each engine is what lets
 * an Imagick host produce the same miniature as a GD one.
 *
 * libgd's gdImageConvolution() with the kernel [1 2 1; 2 4 2; 1 2 1] / 16:
 * neighbours past an edge are clamped to the edge pixel, the weighted sum is
 * divided by 16 and truncated toward zero. Every term is an integer and 16 is a
 * power of two, so libgd's float arithmetic is exact and integer division
 * reproduces it bit for bit.
 */
final class GaussianBlur
{
    const KERNEL = [
        [1, 2, 1],
        [2, 4, 2],
        [1, 2, 1],
    ];

    const DIVISOR = 16;

    public static function apply(Raster $raster, int $passes = 1): Raster
    {
        for ($i = 0; $i < $passes; $i++) {
            $raster = self::pass($raster);
        }

        return $raster;
    }

    private static function pass(Raster $raster): Raster
    {
        if ($raster->hasTransparency()) {
            return self::passWithAlpha($raster);
        }

        $w = $raster->width();
        $h = $raster->height();
        $source = $raster->pixels();

        $out = [];
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $r = $g = $b = 0;

                for ($j = 0; $j < 3; $j++) {
                    $sy = min(max($y - 1 + $j, 0), $h - 1);
                    for ($i = 0; $i < 3; $i++) {
                        $sx = min(max($x - 1 + $i, 0), $w - 1);
                        $pixel = $source[$sy * $w + $sx];
                        $weight = self::KERNEL[$j][$i];

                        $r += (($pixel >> 16) & 0xFF) * $weight;
                        $g += (($pixel >> 8) & 0xFF) * $weight;
                        $b += ($pixel & 0xFF) * $weight;
                    }
                }

                $out[] = (intdiv($r, self::DIVISOR) << 16) | (intdiv($g, self::DIVISOR) << 8) | intdiv($b, self::DIVISOR);
            }
        }

        return new Raster($w, $h, $out);
    }

    /**
     * The same kernel on an image with transparency. GD has nothing to match
     * here - imagefilter() blurs alpha and colour independently, so a
     * transparent pixel's meaningless colour (often black) bleeds into the
     * edge of whatever sits next to it. Instead each colour is weighted by its
     * alpha, which is what blurring premultiplied colour amounts to, and the
     * alpha is blurred with the plain kernel. Edges are clamped and results
     * truncated, as in the opaque pass.
     */
    private static function passWithAlpha(Raster $raster): Raster
    {
        $w = $raster->width();
        $h = $raster->height();
        $source = $raster->pixels();
        $sourceAlphas = $raster->alphas();

        $out = $alphas = [];
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $r = $g = $b = $weightSum = 0;

                for ($j = 0; $j < 3; $j++) {
                    $sy = min(max($y - 1 + $j, 0), $h - 1);
                    for ($i = 0; $i < 3; $i++) {
                        $sx = min(max($x - 1 + $i, 0), $w - 1);
                        $pixel = $source[$sy * $w + $sx];
                        $alpha = $sourceAlphas[$sy * $w + $sx];
                        $weight = self::KERNEL[$j][$i] * $alpha;

                        $r += (($pixel >> 16) & 0xFF) * $weight;
                        $g += (($pixel >> 8) & 0xFF) * $weight;
                        $b += ($pixel & 0xFF) * $weight;
                        $weightSum += $weight;
                    }
                }

                $out[] = $weightSum === 0
                    ? 0
                    : (intdiv($r, $weightSum) << 16) | (intdiv($g, $weightSum) << 8) | intdiv($b, $weightSum);
                // The sum of kernel x alpha, so also the blurred alpha x 16.
                $alphas[] = intdiv($weightSum, self::DIVISOR);
            }
        }

        return new Raster($w, $h, $out, $alphas);
    }
}
