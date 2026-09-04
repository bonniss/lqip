<?php namespace Bonniss26\Lqip\Strategies;

/**
 * A single flat tone - cheapest by far, no sense of shape. Quantizes down to a
 * handful of colors and picks the most-used one rather than averaging every
 * pixel, which tends to muddy distinct colors into grey/brown.
 */
class DominantColorStrategy implements LqipStrategy
{
    public function generate($source, int $origWidth, int $origHeight, array $options): ?string
    {
        $tiny = imagecreatetruecolor(8, 8);
        imagecopyresampled($tiny, $source, 0, 0, 0, 0, 8, 8, $origWidth, $origHeight);
        imagetruecolortopalette($tiny, false, 5);

        $counts = [];
        for ($x = 0; $x < 8; $x++) {
            for ($y = 0; $y < 8; $y++) {
                $index = imagecolorat($tiny, $x, $y);
                $counts[$index] = ($counts[$index] ?? 0) + 1;
            }
        }
        arsort($counts);
        $topIndex = array_key_first($counts);
        $rgb = imagecolorsforindex($tiny, $topIndex);
        imagedestroy($tiny);

        $hex = sprintf('#%02x%02x%02x', $rgb['red'], $rgb['green'], $rgb['blue']);

        // Same-color-both-stops linear-gradient renders identically to a flat
        // background-color, but (unlike background-color) can be layered into a
        // background-image comma list.
        return "linear-gradient({$hex},{$hex})";
    }
}
