<?php namespace Bonniss26\Lqip\Support;

/**
 * Picks a single representative colour out of an image.
 *
 * Quantizes down to a handful of colours and takes the most-used one rather
 * than averaging every pixel - averaging tends to muddy distinct colours into
 * grey/brown, which is exactly the failure a placeholder makes most visible.
 *
 * The quantizer is written here rather than borrowed from the engine. GD's
 * imagetruecolortopalette() is not one algorithm: bundled libgd uses the IJG
 * quantizer, a system libgd linked against libimagequant uses that instead,
 * and Imagick's quantizeImage() is a third. Each picks a different colour from
 * the same pixels, so two servers sharing one cache could disagree about the
 * same image. Owning the algorithm makes the answer a function of the pixels
 * alone. On 64 pixels it costs nothing.
 *
 * Median cut seeds a palette, a few rounds of k-means reassign every pixel to
 * its nearest palette colour, near-identical clusters are merged, and only then
 * is anything counted. Each step answers a failure measured on 37 real
 * photographs, scored against GD's quantizer as the reference:
 *
 * - counting median-cut boxes directly rewards flat regions over textured
 *   ones, because a box with a wide spread keeps getting halved - a large,
 *   busy dark background lost to a smaller flat highlight (median distance
 *   from GD 49.5, 14 of 37 off by more than 60 in RGB);
 * - reassigning to the nearest colour, as GD's quantizer also does, fixes most
 *   of that (14.2, 8 of 37);
 * - but a five-colour budget still cuts one large region into "black" and
 *   "very dark grey", each then losing the count - merging clusters closer
 *   than MERGE_DISTANCE before counting brings it to 7.8, 4 of 37.
 *
 * Those last four are images where two colour groups each cover around a
 * third of the 8x8 sample; there the winner is a coin toss for any quantizer,
 * GD's included. Every other variant tried (other split rules, no k-means,
 * other merge distances) scored worse.
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

    /** k-means refinement rounds after the median cut. Converges in 2-3 on 64 pixels. */
    const REFINE_ROUNDS = 4;

    /**
     * Clusters whose colours are closer than this (Euclidean, 0-255 RGB) count
     * as one colour. About 9% of the RGB cube's diagonal.
     */
    const MERGE_DISTANCE = 40;

    /**
     * A pixel at least this opaque counts towards the colour; anything fainter
     * is background showing through. Half-way, so the anti-aliased rim of a
     * logo neither dominates nor disappears.
     */
    const MIN_ALPHA = 128;

    /**
     * @param Raster $sample Typically SAMPLE_SIZE square; any size works.
     * @return array{0:int,1:int,2:int}|null [r, g, b], the mean of the pixels
     *         nearest the most-used palette colour; null when nothing in the
     *         sample is opaque enough to have a colour.
     */
    public static function fromRaster(Raster $sample): ?array
    {
        // Column-major (x outer, y inner) on purpose: a cluster's tie-break key is
        // the scan position of its first pixel, so this order decides which of
        // two equally used colours wins - the leftmost one.
        //
        // Keyed by scan position, which is what every step below treats as a
        // pixel's identity - so skipping transparent pixels leaves the positions
        // of the rest, and an opaque image goes through exactly as before.
        $pixels = [];
        $position = 0;
        for ($x = 0; $x < $sample->width(); $x++) {
            for ($y = 0; $y < $sample->height(); $y++) {
                if ($sample->alphaAt($x, $y) >= self::MIN_ALPHA) {
                    $pixels[$position] = $sample->channelsAt($x, $y);
                }
                $position++;
            }
        }

        if (!$pixels) {
            return null;
        }

        $palette = array_map([self::class, 'mean'], self::medianCut($pixels));

        $clusters = [];
        for ($round = 0; $round <= self::REFINE_ROUNDS; $round++) {
            $clusters = self::assign($pixels, $palette);
            foreach ($clusters as $index => $members) {
                // An emptied cluster keeps its colour; it simply wins nothing.
                if ($members) {
                    $palette[$index] = self::mean(array_intersect_key($pixels, array_flip($members)));
                }
            }
        }

        [$palette, $clusters] = self::mergeNearClusters($palette, $clusters);

        // Most pixels wins; on a tie, the cluster whose first pixel came earliest.
        $best = null;
        foreach ($clusters as $index => $members) {
            if ($members && ($best === null
                || count($members) > count($clusters[$best])
                || (count($members) === count($clusters[$best]) && $members[0] < $clusters[$best][0]))
            ) {
                $best = $index;
            }
        }

        return array_map('intval', array_map('round', $palette[$best]));
    }

    /**
     * Splits the pixels into up to PALETTE_SIZE boxes, each time halving the
     * most populous box that still spans more than one value. Population first,
     * as libgd's quantizer does for its early cuts: it spends the palette where
     * the pixels are.
     *
     * @param array[] $pixels [r, g, b] each, keyed by scan position.
     * @return array[] Boxes of pixels, keys preserved.
     */
    private static function medianCut(array $pixels): array
    {
        $boxes = [$pixels];

        while (count($boxes) < self::PALETTE_SIZE) {
            $target = null;
            foreach ($boxes as $index => $box) {
                if (self::widestChannel($box)[1] > 0 && ($target === null || count($box) > count($boxes[$target]))) {
                    $target = $index;
                }
            }

            if ($target === null) {
                break; // every box is a single colour; nothing left to split
            }

            array_splice($boxes, $target, 1, self::split($boxes[$target]));
        }

        return $boxes;
    }

    /**
     * Splits at the median of the widest channel, nudged to the nearest change
     * of value so that one colour is never divided between two boxes.
     *
     * @return array{0:array,1:array}
     */
    private static function split(array $box): array
    {
        [$channel] = self::widestChannel($box);

        // Sort on the channel, then on scan position (the key): sorting is not
        // stable before PHP 8.0, so the tie-break has to be explicit.
        $values = $box;
        uksort($box, function (int $a, int $b) use ($values, $channel) {
            return [$values[$a][$channel], $a] <=> [$values[$b][$channel], $b];
        });
        $keys = array_keys($box);
        $count = count($keys);
        $median = intdiv($count, 2);

        $cut = max(1, $median);
        for ($distance = 0; $distance < $count; $distance++) {
            foreach ([$median - $distance, $median + $distance] as $candidate) {
                if ($candidate > 0 && $candidate < $count
                    && $box[$keys[$candidate - 1]][$channel] !== $box[$keys[$candidate]][$channel]
                ) {
                    $cut = $candidate;
                    break 2;
                }
            }
        }

        return [
            array_intersect_key($box, array_flip(array_slice($keys, 0, $cut))),
            array_intersect_key($box, array_flip(array_slice($keys, $cut))),
        ];
    }

    /**
     * Merges clusters whose colours are too close to tell apart, closest pair
     * first, until every remaining pair is at least MERGE_DISTANCE apart.
     *
     * Five clusters is a fixed budget, and k-means spends it wherever the
     * variance is - so a large region of near-black is often cut into "black"
     * and "very dark grey", each then losing the count to a smaller but
     * distinct colour. Counting only after merging what a viewer would call
     * the same colour is what makes "most-used" mean what it says.
     *
     * @return array{0:array,1:array} [palette, clusters], same shapes as given.
     */
    private static function mergeNearClusters(array $palette, array $clusters): array
    {
        while (true) {
            $pair = null;
            $closest = self::MERGE_DISTANCE ** 2;
            $indices = array_keys(array_filter($clusters));

            foreach ($indices as $i => $a) {
                foreach (array_slice($indices, $i + 1) as $b) {
                    $distance = ($palette[$a][0] - $palette[$b][0]) ** 2
                        + ($palette[$a][1] - $palette[$b][1]) ** 2
                        + ($palette[$a][2] - $palette[$b][2]) ** 2;
                    if ($distance < $closest) {
                        $closest = $distance;
                        $pair = [$a, $b];
                    }
                }
            }

            if ($pair === null) {
                return [$palette, $clusters];
            }

            [$a, $b] = $pair;
            $na = count($clusters[$a]);
            $nb = count($clusters[$b]);
            for ($channel = 0; $channel < 3; $channel++) {
                $palette[$a][$channel] = ($palette[$a][$channel] * $na + $palette[$b][$channel] * $nb) / ($na + $nb);
            }
            $clusters[$a] = array_merge($clusters[$a], $clusters[$b]);
            sort($clusters[$a]);
            $clusters[$b] = [];
        }
    }

    /**
     * Each pixel to its nearest palette colour (squared RGB distance; a tie
     * goes to the lower palette index).
     *
     * @return array<int,int[]> palette index => scan positions, ascending.
     */
    private static function assign(array $pixels, array $palette): array
    {
        $clusters = array_fill_keys(array_keys($palette), []);

        foreach ($pixels as $position => [$r, $g, $b]) {
            $nearest = null;
            $nearestDistance = PHP_INT_MAX;
            foreach ($palette as $index => [$pr, $pg, $pb]) {
                $distance = ($r - $pr) ** 2 + ($g - $pg) ** 2 + ($b - $pb) ** 2;
                if ($distance < $nearestDistance) {
                    $nearest = $index;
                    $nearestDistance = $distance;
                }
            }
            $clusters[$nearest][] = $position;
        }

        return $clusters;
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

    /** @return array{0:float,1:float,2:float} */
    private static function mean(array $pixels): array
    {
        $count = count($pixels);

        return [
            array_sum(array_column($pixels, 0)) / $count,
            array_sum(array_column($pixels, 1)) / $count,
            array_sum(array_column($pixels, 2)) / $count,
        ];
    }
}
