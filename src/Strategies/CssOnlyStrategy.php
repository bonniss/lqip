<?php namespace Bonniss26\Lqip\Strategies;

use Bonniss26\Lqip\Drivers\Driver;
use Bonniss26\Lqip\Drivers\Image;
use Bonniss26\Lqip\LqipResult;
use Bonniss26\Lqip\Support\DominantColor;
use Bonniss26\Lqip\Support\LqipInteger;
use Bonniss26\Lqip\Support\Oklab;
use Bonniss26\Lqip\Support\Raster;

/**
 * The whole placeholder as a single integer, decoded entirely in CSS.
 *
 * Emits something like `192900`, meant for a custom property
 * (`style="--lqip:192900"`), which a stylesheet the site ships once expands
 * into a stack of gradients. Around 12-14 characters per image instead of the
 * ~138 a base64 WebP thumbnail costs, and no image bytes at all.
 *
 * Experimental, and the trade is real: it needs CSS round()/mod()/oklab() and
 * relative colour syntax, so browsers from roughly mid-2024 onward, with no
 * graceful degradation - an unsupporting browser shows nothing rather than a
 * worse placeholder. It is also very coarse; on evenly-lit photography the six
 * cells often land in the same bucket and the result renders as a flat colour.
 *
 * After Lean Rada's format: https://leanrada.com/notes/css-only-lqip/
 */
class CssOnlyStrategy implements LqipStrategy
{
    /**
     * Fixed, not an option: the decode stylesheet selects on
     * `[style*="--lqip:"]`, so a configurable name would only ever desynchronise
     * the encoder from its decoder.
     */
    const CSS_PROPERTY = '--lqip';

    /**
     * Intermediate sample, 8x8 source pixels per grid cell. Big enough that each
     * cell average is meaningful, small enough that the linear-light pass below
     * stays trivial (384 pixels).
     */
    const SAMPLE_WIDTH = 24;   // GRID_COLUMNS * 8
    const SAMPLE_HEIGHT = 16;  // GRID_ROWS * 8

    public function generate(Image $image, Driver $driver, array $options): ?LqipResult
    {
        $rgb = DominantColor::fromRaster(
            $image->sample(DominantColor::SAMPLE_SIZE, DominantColor::SAMPLE_SIZE)
        );

        [$ll, $aaa, $bbb] = LqipInteger::findOklabBits(...Oklab::fromSrgb(...$rgb));

        // The *quantized* base L, not the dominant colour's raw L. The decode CSS
        // can only reconstruct L from the two ll bits, so measuring the cells
        // against anything else desynchronises encoder and decoder.
        [$baseL] = LqipInteger::bitsToOklab($ll, $aaa, $bbb);

        $cells = $this->cells($image->sample(self::SAMPLE_WIDTH, self::SAMPLE_HEIGHT), $baseL);

        return LqipResult::cssVar(
            (string) LqipInteger::pack($cells, $ll, $aaa, $bbb),
            self::CSS_PROPERTY
        );
    }

    /**
     * Six 2-bit cells, each the cell's luminance relative to the base colour.
     *
     * The averaging happens in linear light. That matters: mean(gamma(x)) is not
     * gamma(mean(x)), and averaging gamma-encoded values biases every cell dark.
     * The reference implementation gets this from sharp's gamma-correct resizing;
     * the GD equivalent, imagegammacorrect(), is not usable here - it works on
     * 8-bit data, so `pow(c/255, 2) * 255` collapses every channel value at or
     * below 15 to zero, destroying shadow detail before it can be averaged. It
     * also mutates its argument.
     *
     * So: let the engine do the bulk reduction, then average each 8x8 block into
     * linear float here. The source -> 24x16 step is still averaged in gamma
     * space, which confines that error to 1/64 of each cell while the dominant
     * 64:1 average is linear.
     *
     * @param Raster $sample SAMPLE_WIDTH x SAMPLE_HEIGHT. Aspect ratio is
     *                       deliberately ignored - the grid maps onto the image
     *                       box, however it is shaped.
     * @return array Six ints 0-3, row-major.
     */
    private function cells(Raster $sample, float $baseL): array
    {
        $blockWidth = intdiv(self::SAMPLE_WIDTH, LqipInteger::GRID_COLUMNS);
        $blockHeight = intdiv(self::SAMPLE_HEIGHT, LqipInteger::GRID_ROWS);
        $pixelsPerBlock = $blockWidth * $blockHeight;

        $cells = [];
        for ($row = 0; $row < LqipInteger::GRID_ROWS; $row++) {
            for ($column = 0; $column < LqipInteger::GRID_COLUMNS; $column++) {
                $r = $g = $b = 0.0;

                for ($y = $row * $blockHeight; $y < ($row + 1) * $blockHeight; $y++) {
                    for ($x = $column * $blockWidth; $x < ($column + 1) * $blockWidth; $x++) {
                        $pixel = $sample->rgbAt($x, $y);
                        $r += Oklab::srgbChannelToLinear((($pixel >> 16) & 0xFF) / 255);
                        $g += Oklab::srgbChannelToLinear((($pixel >> 8) & 0xFF) / 255);
                        $b += Oklab::srgbChannelToLinear(($pixel & 0xFF) / 255);
                    }
                }

                [$cellL] = Oklab::fromLinearRgb(
                    $r / $pixelsPerBlock,
                    $g / $pixelsPerBlock,
                    $b / $pixelsPerBlock
                );

                // Centred on 0.5 so a cell matching the base colour sits mid-range and
                // can move either way. Clamped before scaling, which keeps the argument
                // non-negative - so PHP's round() (half away from zero) and the
                // reference's Math.round (half up) agree here. Don't reorder these.
                $value = max(0.0, min(1.0, 0.5 + $cellL - $baseL));
                $cells[] = (int) round($value * 3);
            }
        }

        return $cells;
    }
}
