<?php namespace Bonniss26\Lqip\Strategies;

use Bonniss26\Lqip\LqipResult;
use Bonniss26\Lqip\Support\DominantColor;

/**
 * A single flat tone - cheapest by far, no sense of shape. The colour itself is
 * computed by Support\DominantColor, which quantizes rather than averages.
 */
class DominantColorStrategy implements LqipStrategy
{
    public function generate($source, int $origWidth, int $origHeight, array $options): ?LqipResult
    {
        $rgb = DominantColor::forImage($source, $origWidth, $origHeight);
        if ($rgb === null) {
            return null;
        }

        $hex = sprintf('#%02x%02x%02x', ...$rgb);

        // Same-color-both-stops linear-gradient renders identically to a flat
        // background-color, but (unlike background-color) can be layered into a
        // background-image comma list.
        return LqipResult::css("linear-gradient({$hex},{$hex})");
    }
}
