<?php namespace Bonniss26\Lqip\Support;

/**
 * Picks a single representative colour out of an image.
 *
 * Quantizes down to a handful of colours and takes the most-used one rather
 * than averaging every pixel - averaging tends to muddy distinct colours into
 * grey/brown, which is exactly the failure a placeholder makes most visible.
 *
 * The quantizer is a median cut written here rather than borrowed from the
 * engine. GD's imagetruecolortopalette() is not one algorithm: bundled libgd
 * uses the IJG quantizer, a system libgd linked against libimagequant uses
 * that instead, and Imagick's quantizeImage() is a third. Each picks a
 * different colour from the same pixels, so two servers sharing one cache could
 * disagree about the same image. Owning the algorithm makes the answer a
 * function of the pixels alone. On 64 pixels it costs nothing.
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
     * @param Raster $sample Typically SAMPLE_SIZE square; any size works.
     * @return array{0:int,1:int,2:int} [r, g, b], the mean of the most populous
     *                                  box after a median cut.
     */
    public static function fromRaster(Raster $sample): array
    {
        // Column-major (x outer, y inner) on purpose: a box's tie-break key is the
        // scan position of its first pixel, so this order decides which of two
        // equally large colours wins - the leftmost one.
        $pixels = [];
        for ($x = 0; $x < $sample->width(); $x++) {
            for ($y = 0; $y < $sample->height(); $y++) {
                $pixels[] = $sample->channelsAt($x, $y) + [3 => count($pixels)];
            }
        }

        $boxes = [$pixels];
        while (count($boxes) < self::PALETTE_SIZE) {
            $target = self::widestBox($boxes);
            if ($target === null) {
                break; // every box is a single colour; nothing left to split
            }

            array_splice($boxes, $target, 1, self::split($boxes[$target]));
        }

        return self::mean(self::largestBox($boxes));
    }

    /**
     * The box with the widest spread on any one channel, or null if no box spans
     * more than one value. Ties go to the earlier box, which keeps the cut
     * deterministic.
     */
    private static function widestBox(array $boxes): ?int
    {
        $best = null;
        $bestRange = 0;
        foreach ($boxes as $index => $box) {
            [, $range] = self::widestChannel($box);
            if ($range > $bestRange) {
                $best = $index;
                $bestRange = $range;
            }
        }

        return $best;
    }

    /** @return array{0:int,1:int} [channel index, max - min on that channel] */
    private static function widestChannel(array $box): array
    {
        $best = [0, -1];
        for ($channel = 0; $channel < 3; $channel++) {
            $values = array_column($box, $channel);
            $range = max($values) - min($values);
            if ($range > $best[1]) {
                $best = [$channel, $range];
            }
        }

        return $best;
    }

    /**
     * Splits at the median of the widest channel, nudged to the nearest change
     * of value so that one colour is never divided between two boxes - a solid
     * region staying whole is what lets it win on pixel count.
     *
     * @return array{0:array,1:array}
     */
    private static function split(array $box): array
    {
        [$channel] = self::widestChannel($box);

        // Sort on the channel, then on the scan position: usort() is not stable
        // before PHP 8.0, so the tie-break has to be explicit.
        usort($box, function (array $a, array $b) use ($channel) {
            return [$a[$channel], $a[3]] <=> [$b[$channel], $b[3]];
        });

        $count = count($box);
        $median = intdiv($count, 2);

        $cut = null;
        for ($distance = 0; $distance < $count; $distance++) {
            foreach ([$median - $distance, $median + $distance] as $candidate) {
                if ($candidate > 0 && $candidate < $count && $box[$candidate - 1][$channel] !== $box[$candidate][$channel]) {
                    $cut = $candidate;
                    break 2;
                }
            }
        }

        // Unreachable: widestBox() only offers a box whose channel spans a range.
        if ($cut === null) {
            $cut = max(1, $median);
        }

        return [array_slice($box, 0, $cut), array_slice($box, $cut)];
    }

    /**
     * Most pixels wins; on a tie, the box whose first pixel came earliest in the
     * column-major scan.
     */
    private static function largestBox(array $boxes): array
    {
        $best = null;
        foreach ($boxes as $box) {
            if ($best === null
                || count($box) > count($best)
                || (count($box) === count($best) && min(array_column($box, 3)) < min(array_column($best, 3)))
            ) {
                $best = $box;
            }
        }

        return $best;
    }

    /** @return array{0:int,1:int,2:int} */
    private static function mean(array $box): array
    {
        $count = count($box);

        return [
            (int) round(array_sum(array_column($box, 0)) / $count),
            (int) round(array_sum(array_column($box, 1)) / $count),
            (int) round(array_sum(array_column($box, 2)) / $count),
        ];
    }
}
