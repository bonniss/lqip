<?php namespace Bonniss26\Lqip\Drivers;

use Bonniss26\Lqip\PlaceholderGenerator;
use Bonniss26\Lqip\Support\ExifOrientation;
use Bonniss26\Lqip\Support\Raster;

/**
 * The baseline driver. GD ships with nearly every PHP build, including the
 * shared hosting this package has to keep working on.
 *
 * Its weakness is decoding: GD always decodes the full bitmap before it can
 * shrink it - there is no reduced-resolution decode path like libjpeg's
 * scaling, which ImagickDriver uses - so cost and memory are linear in source
 * megapixels.
 */
final class GdDriver implements Driver
{
    public function name(): string
    {
        return 'gd';
    }

    public function isAvailable(): bool
    {
        return function_exists('imagecreatefromstring');
    }

    public function load(string $bytes): ?Image
    {
        if (!$this->isAvailable()) {
            return null;
        }

        // A 6000x4000 photo needs ~90MB for the bitmap alone, on top of whatever
        // the caller has already allocated - routinely more than PHP's default
        // memory_limit. Raised only for the decode: the working copy below is
        // tiny, so the limit goes back down before any strategy runs.
        $previousLimit = ini_get('memory_limit');
        ini_set('memory_limit', PlaceholderGenerator::TEMP_MEMORY_LIMIT);
        $source = null;

        try {
            $size = @getimagesizefromstring($bytes);
            // Checked before decoding: past this a decode risks a fatal - and
            // uncatchable - memory_limit exhaustion even against the raised ceiling.
            if (!$size || $size[0] < 1 || $size[1] < 1 || $size[0] * $size[1] > PlaceholderGenerator::MAX_SOURCE_PIXELS) {
                return null;
            }

            $source = @imagecreatefromstring($bytes);
            if (!$source) {
                return null;
            }

            $width = imagesx($source);
            $height = imagesy($source);

            return new GdSource(
                self::workingCopy($source, $width, $height),
                $width,
                $height,
                ExifOrientation::fromBytes($bytes)
            );
        } finally {
            if ($source) {
                self::destroy($source);
            }

            // Suppressed: lowering back below the peak usage this call just reached
            // triggers a warning, not a failure - the limit simply stays raised for
            // the rest of this request.
            @ini_set('memory_limit', $previousLimit);
        }
    }

    public function supportsFormat(string $format): bool
    {
        if ($format === 'webp') {
            return function_exists('imagewebp');
        }

        return $format === 'jpg' && function_exists('imagejpeg');
    }

    public function encode(Raster $raster, string $format, int $quality): ?array
    {
        if (!$this->supportsFormat($format)) {
            return null;
        }

        $image = imagecreatetruecolor($raster->width(), $raster->height());
        foreach ($raster->pixels() as $i => $pixel) {
            imagesetpixel($image, $i % $raster->width(), intdiv($i, $raster->width()), $pixel);
        }

        ob_start();
        try {
            $ok = $format === 'webp'
                ? imagewebp($image, null, $quality)
                : imagejpeg($image, null, $quality);
        } finally {
            $bytes = ob_get_clean();
            self::destroy($image);
        }

        if (!$ok || !$bytes) {
            return null;
        }

        return ['mime' => $format === 'webp' ? 'image/webp' : 'image/jpeg', 'bytes' => $bytes];
    }

    /**
     * Frees a GD image on PHP 7, where it is a resource that leaks until freed.
     *
     * From PHP 8.0 a GD image is an object freed by refcounting and
     * imagedestroy() is a no-op; PHP 8.5 deprecates it outright. A deprecation is
     * a diagnostic, and a framework that promotes diagnostics to exceptions would
     * turn it into a failed request - so on PHP 8 it is simply not called.
     *
     * @param \GdImage|resource $image
     * @internal
     */
    public static function destroy($image): void
    {
        if (PHP_VERSION_ID < 80000 && is_resource($image)) {
            imagedestroy($image);
        }
    }

    /**
     * Shrinks the decoded source to at most WORKING_LONG_EDGE so that every
     * later sample - a strategy may take several - resamples a few thousand
     * pixels instead of the full bitmap, and so the full bitmap can be freed
     * immediately. Composites alpha onto black along the way, because the
     * destination is an opaque truecolour canvas.
     *
     * @param \GdImage|resource $source
     * @return \GdImage|resource
     */
    private static function workingCopy($source, int $width, int $height)
    {
        $scale = min(1, PlaceholderGenerator::WORKING_LONG_EDGE / max($width, $height));
        $targetWidth = max(1, (int) round($width * $scale));
        $targetHeight = max(1, (int) round($height * $scale));

        $copy = imagecreatetruecolor($targetWidth, $targetHeight);
        imagecopyresampled($copy, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $width, $height);

        return $copy;
    }
}
