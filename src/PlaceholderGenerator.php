<?php namespace Bonniss26\Lqip;

use Throwable;

/**
 * Returns a single value usable inside a CSS `background-image` list (e.g.
 * `url('data:...')` or `radial-gradient(...),radial-gradient(...)`) for a
 * blurred/gradient/flat-color placeholder. Every mode - including
 * dominant_color, via a same-color-both-stops `linear-gradient()` - returns
 * something composable this way, so callers can either use it alone
 * (`background-image:{{placeholder}}`) or layer it behind a real photo that's
 * also a CSS background (`background-image:url(realPhoto), {{placeholder}}`)
 * without caring which mode is active.
 *
 * Framework-agnostic: no caching, no settings model, no media-library
 * abstraction. Callers own persistence and configuration; pass options
 * explicitly per call.
 */
class PlaceholderGenerator
{
    // GD fully decodes an image to an in-memory bitmap before it can downsample it -
    // there's no fast reduced-resolution decode path like libjpeg-turbo/ImageMagick have.
    // A 6000x4000 photo alone needs ~90MB just for the bitmap, on top of whatever the
    // caller has already allocated by request time - routinely more than PHP's default
    // memory_limit (128M locally). We temporarily raise the limit just for this call
    // rather than failing on perfectly ordinary large photos.
    const TEMP_MEMORY_LIMIT = '512M';

    // Still cap it - a source past this many pixels gets skipped rather than risking a
    // fatal (uncatchable) memory_limit exhaustion even against the raised ceiling above.
    // Not exposed as an option - it's a safety backstop, not a quality/cost knob.
    const MAX_SOURCE_PIXELS = 60_000_000;

    const DEFAULT_OPTIONS = [
        'mode' => 'dominant_color', // dominant_color | gradient | blur
        'long_edge' => 16,
        'quality' => 50,
        'blur' => true,
        'format' => 'webp', // webp | jpg
    ];

    /**
     * @param string $path Local filesystem path to a readable image file.
     * @param array $options See self::DEFAULT_OPTIONS.
     */
    public static function forFile(string $path, array $options = []): ?string
    {
        if (!$path || !is_readable($path)) {
            return null;
        }

        $bytes = file_get_contents($path);
        if ($bytes === false) {
            return null;
        }

        return static::forBytes($bytes, $options);
    }

    /**
     * @param string $bytes Raw image bytes.
     * @param array $options See self::DEFAULT_OPTIONS.
     */
    public static function forBytes(string $bytes, array $options = []): ?string
    {
        if (!$bytes) {
            return null;
        }

        $options = $options + static::DEFAULT_OPTIONS;

        $previousLimit = ini_get('memory_limit');
        ini_set('memory_limit', static::TEMP_MEMORY_LIMIT);

        try {
            $size = @getimagesizefromstring($bytes);
            if (!$size || $size[0] * $size[1] > static::MAX_SOURCE_PIXELS) {
                return null;
            }

            $source = @imagecreatefromstring($bytes);
            if (!$source) {
                return null;
            }

            [$origWidth, $origHeight] = $size;

            $style = match ($options['mode']) {
                'dominant_color' => static::dominantColorStyle($source, $origWidth, $origHeight),
                'gradient' => static::gradientStyle($source, $origWidth, $origHeight),
                default => static::blurStyle($source, $origWidth, $origHeight, $options),
            };

            imagedestroy($source);

            return $style;
        } catch (Throwable $e) {
            return null;
        } finally {
            // Suppressed: lowering back below the peak usage this call just reached
            // triggers a warning, not a failure - harmless, the limit simply stays raised
            // for the rest of this request.
            @ini_set('memory_limit', $previousLimit);
        }
    }

    /**
     * Blurred miniature, encoded as a data URI - the most realistic option, and also
     * the most expensive one.
     */
    protected static function blurStyle($source, int $origWidth, int $origHeight, array $options): ?string
    {
        $targetLongEdge = (int) $options['long_edge'];
        $quality = (int) $options['quality'];
        $blurEnabled = (bool) $options['blur'];
        $format = $options['format'];

        $longEdge = max($origWidth, $origHeight);
        $scale = $targetLongEdge / $longEdge;
        $targetWidth = max(1, (int) round($origWidth * $scale));
        $targetHeight = max(1, (int) round($origHeight * $scale));

        $resized = imagecreatetruecolor($targetWidth, $targetHeight);
        imagecopyresampled($resized, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $origWidth, $origHeight);

        if ($blurEnabled) {
            // Baked into the pixels rather than left to a CSS `filter: blur()` on the
            // <img> - that would apply to the whole element, including the real photo
            // once it's loaded on top of this background, permanently blurring it too.
            imagefilter($resized, IMG_FILTER_GAUSSIAN_BLUR);
            imagefilter($resized, IMG_FILTER_GAUSSIAN_BLUR);
        }

        ob_start();
        if ($format === 'webp' && function_exists('imagewebp')) {
            imagewebp($resized, null, $quality);
            $mime = 'image/webp';
        } else {
            imagejpeg($resized, null, $quality);
            $mime = 'image/jpeg';
        }
        $binary = ob_get_clean();
        imagedestroy($resized);

        if (!$binary) {
            return null;
        }

        $dataUri = "data:{$mime};base64," . base64_encode($binary);

        return "url('{$dataUri}')";
    }

    /**
     * A handful of CSS radial-gradients built from a 2x2 sample grid - no image data
     * at all, just literal colors, roughly approximating the photo's layout.
     */
    protected static function gradientStyle($source, int $origWidth, int $origHeight): ?string
    {
        $grid = imagecreatetruecolor(2, 2);
        imagecopyresampled($grid, $source, 0, 0, 0, 0, 2, 2, $origWidth, $origHeight);

        [$topLeft, $topRight, $bottomLeft, $bottomRight] = [
            static::hexAt($grid, 0, 0),
            static::hexAt($grid, 1, 0),
            static::hexAt($grid, 0, 1),
            static::hexAt($grid, 1, 1),
        ];
        imagedestroy($grid);

        // The solid linear-gradient as the last (bottom) layer stands in for a plain
        // background-color fallback - a background-color declaration can't be mixed
        // into a background-image comma list, but a same-color-both-stops gradient
        // renders identically and *can*.
        return "radial-gradient(at 25% 25%,{$topLeft} 0px,transparent 60%),"
            . "radial-gradient(at 75% 25%,{$topRight} 0px,transparent 60%),"
            . "radial-gradient(at 25% 75%,{$bottomLeft} 0px,transparent 60%),"
            . "radial-gradient(at 75% 75%,{$bottomRight} 0px,transparent 60%),"
            . "linear-gradient({$topLeft},{$topLeft})";
    }

    /**
     * A single flat tone - cheapest by far, no sense of shape. Quantizes down to a
     * handful of colors and picks the most-used one rather than averaging every pixel,
     * which tends to muddy distinct colors into grey/brown.
     */
    protected static function dominantColorStyle($source, int $origWidth, int $origHeight): ?string
    {
        $tiny = imagecreatetruecolor(8, 8);
        imagecopyresampled($tiny, $source, 0, 0, 0, 0, 8, 8, $origWidth, $origHeight);
        imagetruecolortopalette($tiny, false, 5);

        $counts = [];
        for ($x = 0; $x < 8; $x++) {
            for ($y = 0; $y < 8; $y++) {
                $index = imagecolorat($tiny, $x, $y);
                $counts[$index] = ($counts[$index] ?? 0) + 1;
            }
        }
        arsort($counts);
        $topIndex = array_key_first($counts);
        $rgb = imagecolorsforindex($tiny, $topIndex);
        imagedestroy($tiny);

        $hex = sprintf('#%02x%02x%02x', $rgb['red'], $rgb['green'], $rgb['blue']);

        // Same-color-both-stops linear-gradient renders identically to a flat
        // background-color, but (unlike background-color) can be layered into a
        // background-image comma list.
        return "linear-gradient({$hex},{$hex})";
    }

    protected static function hexAt($image, int $x, int $y): string
    {
        $rgb = imagecolorsforindex($image, imagecolorat($image, $x, $y));

        return sprintf('#%02x%02x%02x', $rgb['red'], $rgb['green'], $rgb['blue']);
    }
}
