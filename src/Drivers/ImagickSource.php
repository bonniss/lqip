<?php namespace Bonniss26\Lqip\Drivers;

use Bonniss26\Lqip\Support\Raster;

/**
 * A source decoded by ImagickDriver, held as its small working copy.
 */
final class ImagickSource extends OrientedImage
{
    /** @var \Imagick|null */
    private $working;

    public function __construct(\Imagick $working, int $storedWidth, int $storedHeight, int $orientation)
    {
        parent::__construct($storedWidth, $storedHeight, $orientation);
        $this->working = $working;
    }

    protected function sampleStored(int $width, int $height): Raster
    {
        $sample = clone $this->working;

        try {
            // Box rather than ImageMagick's default Lanczos: averaging every source
            // pixel that falls in a cell is what GD's imagecopyresampled() does, and
            // what a colour sample wants. Lanczos rings, which on a 2x2 or 8x8
            // target means invented colours.
            $sample->resizeImage($width, $height, \Imagick::FILTER_BOX, 1);

            if ($sample->getImageAlphaChannel()) {
                return Raster::fromRgbaBytes(
                    $width,
                    $height,
                    $sample->exportImagePixels(0, 0, $width, $height, 'RGBA', \Imagick::PIXEL_CHAR)
                );
            }

            return Raster::fromRgbBytes(
                $width,
                $height,
                $sample->exportImagePixels(0, 0, $width, $height, 'RGB', \Imagick::PIXEL_CHAR)
            );
        } finally {
            $sample->clear();
        }
    }

    public function release(): void
    {
        if ($this->working) {
            $this->working->clear();
            $this->working = null;
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
