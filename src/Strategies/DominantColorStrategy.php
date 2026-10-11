<?php namespace Bonniss26\Lqip\Strategies;

use Bonniss26\Lqip\Drivers\Driver;
use Bonniss26\Lqip\Drivers\Image;
use Bonniss26\Lqip\LqipResult;
use Bonniss26\Lqip\Support\DominantColor;

/**
 * A single flat tone - cheapest by far, no sense of shape. The colour itself is
 * computed by Support\DominantColor, which quantizes rather than averages.
 */
class DominantColorStrategy implements LqipStrategy
{
    public function generate(Image $image, Driver $driver, array $options): ?LqipResult
    {
        $rgb = DominantColor::fromRaster(
            $image->sample(DominantColor::SAMPLE_SIZE, DominantColor::SAMPLE_SIZE)
        );

        $hex = sprintf('#%02x%02x%02x', ...$rgb);

        // Same-color-both-stops linear-gradient renders identically to a flat
        // background-color, but (unlike background-color) can be layered into a
        // background-image comma list.
        return LqipResult::css("linear-gradient({$hex},{$hex})");
    }
}
