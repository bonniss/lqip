<?php namespace Bonniss26\Lqip\Drivers;

use Bonniss26\Lqip\Support\Raster;

/**
 * The engine-specific half of placeholder generation: decoding a source,
 * shrinking it, and encoding a miniature back into image bytes.
 *
 * Deliberately narrow. Everything a strategy does to pixels happens in PHP on a
 * Raster, so a new engine only has to answer these few questions, and two
 * engines can only disagree where they genuinely must (resampling, encoding).
 *
 * Implementations must not throw and must not emit PHP warnings for bad input -
 * return null instead. PlaceholderGenerator catches Throwable as a last resort,
 * but it cannot catch a warning a framework has promoted to an exception
 * before it reaches that catch.
 */
interface Driver
{
    /** Short, stable identifier, e.g. "gd". Suitable for a cache key. */
    public function name(): string;

    /** Whether the engine this driver wraps is usable in this process. */
    public function isAvailable(): bool;

    /**
     * Decodes a source image.
     *
     * @return Image|null null if the bytes are not a decodable image, or if the
     *                    source is too large to decode safely.
     */
    public function load(string $bytes): ?Image;

    /** Whether encode() can produce this format ("webp", "jpg"). */
    public function supportsFormat(string $format): bool;

    /**
     * @param string $format "webp" or "jpg".
     * @param int $quality 0-100, already clamped by the caller.
     * @return array{mime:string,bytes:string}|null
     */
    public function encode(Raster $raster, string $format, int $quality): ?array;
}
