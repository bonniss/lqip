<?php namespace Bonniss26\Lqip\Strategies;

use Bonniss26\Lqip\Drivers\Driver;
use Bonniss26\Lqip\Drivers\Image;
use Bonniss26\Lqip\LqipResult;

/**
 * Turns a decoded image into a placeholder value.
 *
 * Implementations work on pixels through $image->sample(), which hands back a
 * small Support\Raster in plain PHP, so a strategy never touches GD or Imagick
 * directly and works the same on either. Decoding, size-capping and freeing the
 * image live in PlaceholderGenerator, not here.
 */
interface LqipStrategy
{
    /**
     * @param Image $image The decoded source. Owned by the caller - do not release() it.
     * @param Driver $driver The driver that decoded it, for encoding a miniature back to bytes.
     * @param array $options See PlaceholderGenerator::DEFAULT_OPTIONS, already normalised.
     * @return LqipResult|null The generated placeholder, tagged with what kind of
     *                          value it is, or null if generation isn't possible.
     */
    public function generate(Image $image, Driver $driver, array $options): ?LqipResult;
}
