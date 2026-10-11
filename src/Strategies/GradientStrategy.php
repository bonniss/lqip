<?php namespace Bonniss26\Lqip\Strategies;

use Bonniss26\Lqip\Drivers\Driver;
use Bonniss26\Lqip\Drivers\Image;
use Bonniss26\Lqip\LqipResult;
use Bonniss26\Lqip\Support\Raster;

/**
 * A handful of CSS radial-gradients built from a 2x2 sample grid - no image
 * data at all, just literal colors, roughly approximating the photo's layout.
 *
 * On an image with transparency each patch carries its cell's alpha
 * (#rrggbbaa), and the solid bottom layer is left out, so empty corners stay
 * empty instead of being filled with the top-left colour.
 */
class GradientStrategy implements LqipStrategy
{
    public function generate(Image $image, Driver $driver, array $options): ?LqipResult
    {
        $grid = $image->sample(2, 2);

        [$topLeft, $topRight, $bottomLeft, $bottomRight] = [
            $this->hexAt($grid, 0, 0),
            $this->hexAt($grid, 1, 0),
            $this->hexAt($grid, 0, 1),
            $this->hexAt($grid, 1, 1),
        ];

        $css = "radial-gradient(at 25% 25%,{$topLeft} 0px,transparent 60%),"
            . "radial-gradient(at 75% 25%,{$topRight} 0px,transparent 60%),"
            . "radial-gradient(at 25% 75%,{$bottomLeft} 0px,transparent 60%),"
            . "radial-gradient(at 75% 75%,{$bottomRight} 0px,transparent 60%)";

        if ($grid->hasTransparency()) {
            return LqipResult::css($css);
        }

        // The solid linear-gradient as the last (bottom) layer stands in for a plain
        // background-color fallback - a background-color declaration can't be mixed
        // into a background-image comma list, but a same-color-both-stops gradient
        // renders identically and *can*.
        return LqipResult::css("{$css},linear-gradient({$topLeft},{$topLeft})");
    }

    protected function hexAt(Raster $grid, int $x, int $y): string
    {
        $hex = sprintf('#%06x', $grid->rgbAt($x, $y));

        return $grid->hasTransparency() ? $hex . sprintf('%02x', $grid->alphaAt($x, $y)) : $hex;
    }
}
