<?php namespace Bonniss26\Lqip\Strategies;

use Bonniss26\Lqip\Drivers\Driver;
use Bonniss26\Lqip\Drivers\Image;
use Bonniss26\Lqip\LqipResult;
use Bonniss26\Lqip\Support\DominantColor;
use Bonniss26\Lqip\Support\Raster;

/**
 * A single flat tone - cheapest by far, no sense of shape. The colour itself is
 * computed by Support\DominantColor, which quantizes rather than averages.
 *
 * On an image with transparency - a logo, a cut-out product shot - the colour
 * comes from the painted pixels only, and is made as translucent as the image
 * is empty: a red logo covering a third of its box gives red at a third
 * opacity, rather than the solid black block that compositing onto black used
 * to produce.
 */
class DominantColorStrategy implements LqipStrategy
{
    public function generate(Image $image, Driver $driver, array $options): ?LqipResult
    {
        $sample = $image->sample(DominantColor::SAMPLE_SIZE, DominantColor::SAMPLE_SIZE);

        $rgb = DominantColor::fromRaster($sample);
        if ($rgb === null) {
            return null; // nothing painted: no colour worth showing
        }

        $hex = self::hex($rgb, $sample);

        // Same-color-both-stops linear-gradient renders identically to a flat
        // background-color, but (unlike background-color) can be layered into a
        // background-image comma list.
        return LqipResult::css("linear-gradient({$hex},{$hex})");
    }

    /**
     * #rrggbb, or #rrggbbaa when the image is partly transparent.
     *
     * @param array{0:int,1:int,2:int} $rgb
     */
    private static function hex(array $rgb, Raster $sample): string
    {
        $hex = sprintf('#%02x%02x%02x', ...$rgb);

        if ($sample->hasTransparency()) {
            $hex .= sprintf('%02x', (int) round($sample->coverage() * 255));
        }

        return $hex;
    }
}
