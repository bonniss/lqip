<?php namespace Bonniss26\Lqip\Strategies;

use Bonniss26\Lqip\Drivers\Driver;
use Bonniss26\Lqip\Drivers\Image;
use Bonniss26\Lqip\LqipResult;
use Bonniss26\Lqip\Support\GaussianBlur;

/**
 * Blurred miniature, encoded as a data URI - the most realistic option, and
 * also the most expensive one.
 */
class BlurStrategy implements LqipStrategy
{
    /**
     * Two passes, measured: 0/1/2/3 passes give 125/93/84/79 bytes at 16px, and
     * two keeps the overall layout (2x2 grid RMSE 3.2 against 3.0 unblurred)
     * while dropping the high-frequency detail that would look blocky upscaled.
     */
    const BLUR_PASSES = 2;

    /** What a transparent image is flattened onto when it has to be a JPEG. */
    const JPEG_BACKGROUND = 0xFFFFFF;

    public function generate(Image $image, Driver $driver, array $options): ?LqipResult
    {
        $targetLongEdge = (int) $options['long_edge'];
        $quality = (int) $options['quality'];
        $format = $options['format'] === 'webp' ? 'webp' : 'jpg';

        $scale = $targetLongEdge / max($image->width(), $image->height());
        $raster = $image->sample(
            max(1, (int) round($image->width() * $scale)),
            max(1, (int) round($image->height() * $scale))
        );

        if ($options['blur']) {
            // Baked into the pixels rather than left to a CSS `filter: blur()` on the
            // <img> - that would apply to the whole element, including the real photo
            // once it's loaded on top of this background, permanently blurring it too.
            $raster = GaussianBlur::apply($raster, self::BLUR_PASSES);
        }

        // Without WebP the JPEG fallback costs roughly 9x the bytes, but a
        // placeholder at that price still beats none.
        if (!$driver->supportsFormat($format)) {
            $format = 'jpg';
        }

        // WebP keeps transparency, so a logo's miniature is a blurred logo. JPEG
        // has no alpha: white is the likeliest page behind it, and a caller who
        // knows better passes `background`, which flattens before this point.
        if ($format === 'jpg') {
            $raster = $raster->flatten(self::JPEG_BACKGROUND);
        }

        $encoded = $driver->encode($raster, $format, $quality);
        if ($encoded === null) {
            return null;
        }

        $dataUri = "data:{$encoded['mime']};base64," . base64_encode($encoded['bytes']);

        return LqipResult::css("url('{$dataUri}')");
    }
}
