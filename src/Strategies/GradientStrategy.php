<?php namespace Bonniss26\Lqip\Strategies;

use Bonniss26\Lqip\LqipResult;

/**
 * A handful of CSS radial-gradients built from a 2x2 sample grid - no image
 * data at all, just literal colors, roughly approximating the photo's layout.
 */
class GradientStrategy implements LqipStrategy
{
    public function generate($source, int $origWidth, int $origHeight, array $options): ?LqipResult
    {
        $grid = imagecreatetruecolor(2, 2);
        imagecopyresampled($grid, $source, 0, 0, 0, 0, 2, 2, $origWidth, $origHeight);

        [$topLeft, $topRight, $bottomLeft, $bottomRight] = [
            $this->hexAt($grid, 0, 0),
            $this->hexAt($grid, 1, 0),
            $this->hexAt($grid, 0, 1),
            $this->hexAt($grid, 1, 1),
        ];
        imagedestroy($grid);

        // The solid linear-gradient as the last (bottom) layer stands in for a plain
        // background-color fallback - a background-color declaration can't be mixed
        // into a background-image comma list, but a same-color-both-stops gradient
        // renders identically and *can*.
        return LqipResult::css("radial-gradient(at 25% 25%,{$topLeft} 0px,transparent 60%),"
            . "radial-gradient(at 75% 25%,{$topRight} 0px,transparent 60%),"
            . "radial-gradient(at 25% 75%,{$bottomLeft} 0px,transparent 60%),"
            . "radial-gradient(at 75% 75%,{$bottomRight} 0px,transparent 60%),"
            . "linear-gradient({$topLeft},{$topLeft})");
    }

    protected function hexAt($image, int $x, int $y): string
    {
        $rgb = imagecolorsforindex($image, imagecolorat($image, $x, $y));

        return sprintf('#%02x%02x%02x', $rgb['red'], $rgb['green'], $rgb['blue']);
    }
}
