<?php namespace Bonniss26\Lqip\Strategies;

use Bonniss26\Lqip\LqipResult;

/**
 * Turns a decoded GD image into a placeholder value. Implementations receive an
 * already-decoded, already-validated source image - decoding, size-capping and
 * the temporary memory_limit bump live in PlaceholderGenerator, not here.
 */
interface LqipStrategy
{
    /**
     * @param \GdImage|resource $source Decoded source image. Caller owns its
     *                                  lifecycle - do not imagedestroy() it here,
     *                                  and do not modify it in place either: GD
     *                                  functions like imagegammacorrect() and
     *                                  imagefilter() mutate their argument, so
     *                                  work on a copy.
     * @param int $origWidth
     * @param int $origHeight
     * @param array $options See PlaceholderGenerator::DEFAULT_OPTIONS.
     * @return LqipResult|null The generated placeholder, tagged with what kind of
     *                          value it is, or null if generation isn't possible.
     */
    public function generate($source, int $origWidth, int $origHeight, array $options): ?LqipResult;
}
