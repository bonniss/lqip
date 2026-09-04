<?php namespace Bonniss26\Lqip\Strategies;

/**
 * Turns a decoded GD image into a value usable inside a CSS `background-image`
 * list. Implementations receive an already-decoded, already-validated source
 * image - decoding, size-capping and the temporary memory_limit bump live in
 * PlaceholderGenerator, not here.
 */
interface LqipStrategy
{
    /**
     * @param \GdImage|resource $source Decoded source image. Caller owns its
     *                                  lifecycle - do not imagedestroy() it here.
     * @param int $origWidth
     * @param int $origHeight
     * @param array $options See PlaceholderGenerator::DEFAULT_OPTIONS.
     * @return string|null A CSS `background-image`-list-compatible value, or
     *                      null if generation isn't possible.
     */
    public function generate($source, int $origWidth, int $origHeight, array $options): ?string;
}
