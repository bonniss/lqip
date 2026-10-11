<?php namespace Bonniss26\Lqip\Strategies;

use Bonniss26\Lqip\Drivers\Driver;
use Bonniss26\Lqip\Drivers\Image;
use Bonniss26\Lqip\LqipResult;
use Bonniss26\Lqip\Support\GaussianBlur;
use Bonniss26\Lqip\Support\Raster;

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

    /** The `smooth` SVG blur, in miniature pixels. See smoothSvg(). */
    const SMOOTH_DEVIATION = 1;

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

        if (!empty($options['smooth'])) {
            return LqipResult::css("url('" . self::smoothSvg($dataUri, $raster) . "')");
        }

        return LqipResult::css("url('{$dataUri}')");
    }

    /**
     * Wraps the miniature in an SVG that blurs it again as it is drawn.
     *
     * The browser enlarges a 16px image with bilinear interpolation, which
     * leaves a visible grid of soft blocks and diamond-shaped highlights. An
     * SVG filter runs after the enlargement, at the displayed size, so it can
     * smooth those away - the technique next/image uses for its blur
     * placeholder. stdDeviation is in viewBox units, i.e. miniature pixels, so
     * the result looks the same at any display size.
     *
     * Blurring also pulls transparency in from outside the image, which would
     * leave a faded rim; for an opaque image the alpha is forced back to 1,
     * restoring the edge colours. A transparent image keeps its blurred alpha.
     *
     * Percent-encoded rather than base64'd: shorter, and the quotes are
     * encoded so the value is safe inside url('...') and inside a double-quoted
     * style attribute, even unescaped.
     */
    private static function smoothSvg(string $dataUri, Raster $raster): string
    {
        $w = $raster->width();
        $h = $raster->height();
        $opaque = $raster->hasTransparency()
            ? ''
            : "<feComponentTransfer><feFuncA type='discrete' tableValues='1 1'/></feComponentTransfer>";

        $svg = "<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 {$w} {$h}'>"
            . "<filter id='b' color-interpolation-filters='sRGB'>"
            . "<feGaussianBlur stdDeviation='" . self::SMOOTH_DEVIATION . "'/>{$opaque}</filter>"
            . "<image width='{$w}' height='{$h}' filter='url(#b)' href='{$dataUri}'/>"
            . '</svg>';

        return 'data:image/svg+xml,' . strtr($svg, [
            '%' => '%25',
            '<' => '%3C',
            '>' => '%3E',
            '#' => '%23',
            '"' => '%22',
            "'" => '%27',
        ]);
    }
}
