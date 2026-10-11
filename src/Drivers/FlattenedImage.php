<?php namespace Bonniss26\Lqip\Drivers;

use Bonniss26\Lqip\Support\Raster;

/**
 * An Image whose samples are composited onto a solid colour - the `background`
 * option.
 *
 * A decorator rather than a driver setting so that it works with any driver,
 * custom ones included, and so that no strategy needs to know it exists: every
 * sample simply arrives opaque.
 */
final class FlattenedImage implements Image
{
    /** @var Image */
    private $image;

    /** @var int */
    private $background;

    /**
     * @param int $background 0xRRGGBB.
     */
    public function __construct(Image $image, int $background)
    {
        $this->image = $image;
        $this->background = $background;
    }

    public function width(): int
    {
        return $this->image->width();
    }

    public function height(): int
    {
        return $this->image->height();
    }

    public function sample(int $width, int $height): Raster
    {
        return $this->image->sample($width, $height)->flatten($this->background);
    }

    public function release(): void
    {
        $this->image->release();
    }
}
