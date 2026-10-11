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
     * @param \GdImage|resource $working Opaque truecolour working copy, owned from here on.
     */
    public function __construct($working, int $storedWidth, int $storedHeight, int $orientation)
    {
        parent::__construct($storedWidth, $storedHeight, $orientation);
        $this->working = $working;
    }

    protected function sampleStored(int $width, int $height): Raster
    {
        $sample = imagecreatetruecolor($width, $height);
        imagecopyresampled(
            $sample,
            $this->working,
            0, 0, 0, 0,
            $width, $height,
            imagesx($this->working), imagesy($this->working)
        );

        $pixels = [];
        for ($y = 0; $y < $height; $y++) {
            for ($x = 0; $x < $width; $x++) {
                // A truecolour pixel carries alpha in bits 24-30; the canvas is
                // opaque, but masking keeps the contract explicit.
                $pixels[] = imagecolorat($sample, $x, $y) & 0xFFFFFF;
            }
        }
        GdDriver::destroy($sample);

        return new Raster($width, $height, $pixels);
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
