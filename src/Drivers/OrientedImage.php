<?php namespace Bonniss26\Lqip\Drivers;

use Bonniss26\Lqip\Support\Raster;

/**
 * Shared by the built-in drivers: holds a working copy exactly as stored and
 * applies the EXIF orientation to each sample, in PHP, after the engine has
 * shrunk it.
 *
 * Orienting the tiny sample rather than the decoded source keeps rotation off
 * the expensive path, and means every engine rotates the same way.
 */
abstract class OrientedImage implements Image
{
    /** @var int */
    private $width;

    /** @var int */
    private $height;

    /** @var int */
    private $orientation;

    /**
     * @param int $storedWidth Source width as stored in the file.
     * @param int $storedHeight Source height as stored in the file.
     * @param int $orientation EXIF orientation, 1-8.
     */
    protected function __construct(int $storedWidth, int $storedHeight, int $orientation)
    {
        $swaps = $orientation >= 5 && $orientation <= 8;

        $this->width = $swaps ? $storedHeight : $storedWidth;
        $this->height = $swaps ? $storedWidth : $storedHeight;
        $this->orientation = $orientation;
    }

    public function width(): int
    {
        return $this->width;
    }

    public function height(): int
    {
        return $this->height;
    }

    public function sample(int $width, int $height): Raster
    {
        $swaps = $this->orientation >= 5 && $this->orientation <= 8;

        return $this->sampleStored($swaps ? $height : $width, $swaps ? $width : $height)
            ->orient($this->orientation);
    }

    /** The working copy, unrotated, resampled to exactly this size. */
    abstract protected function sampleStored(int $width, int $height): Raster;
}
