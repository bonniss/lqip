<?php namespace Bonniss26\Lqip\Drivers;

use Bonniss26\Lqip\Support\Raster;

/**
 * A decoded source image, as a driver holds it.
 *
 * Dimensions are as displayed: EXIF orientation is already applied, so a
 * portrait phone photo reports itself as portrait.
 */
interface Image
{
    /** Source width as displayed, in pixels. */
    public function width(): int;

    /** Source height as displayed, in pixels. */
    public function height(): int;

    /**
     * The whole image resampled to exactly $width x $height, ignoring aspect
     * ratio, with any transparency composited onto black.
     */
    public function sample(int $width, int $height): Raster;

    /** Frees whatever the engine allocated. Safe to call twice. */
    public function release(): void;
}
