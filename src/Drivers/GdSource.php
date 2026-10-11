<?php namespace Bonniss26\Lqip\Drivers;

use Bonniss26\Lqip\Support\Raster;

/**
 * A source decoded by GdDriver, held as its small working copy.
 */
final class GdSource extends OrientedImage
{
    /** @var \GdImage|resource|null */
    private $working;

    /**
     * @param \GdImage|resource $working Truecolour working copy, alpha kept, owned from here on.
     */
    public function __construct($working, int $storedWidth, int $storedHeight, int $orientation)
    {
        parent::__construct($storedWidth, $storedHeight, $orientation);
        $this->working = $working;
    }

    protected function sampleStored(int $width, int $height): Raster
    {
        $sample = GdDriver::canvas($width, $height);
        imagecopyresampled(
            $sample,
            $this->working,
            0, 0, 0, 0,
            $width, $height,
            imagesx($this->working), imagesy($this->working)
        );

        $pixels = $alphas = [];
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                // A truecolour pixel carries GD's 7-bit alpha in bits 24-30,
                // 0 opaque to 127 transparent; Raster wants 0-255 the other way.
                $pixel = imagecolorat($sample, $x, $y);
                $pixels[] = $pixel & 0xFFFFFF;
                $alphas[] = (int) round((127 - (($pixel >> 24) & 0x7F)) * 255 / 127);
            }
        }
        GdDriver::destroy($sample);

        return new Raster($width, $height, $pixels, $alphas);
    }

    public function release(): void
    {
        if ($this->working) {
            GdDriver::destroy($this->working);
            $this->working = null;
        }
    }

    public function __destruct()
    {
        $this->release();
    }
}
