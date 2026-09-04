<?php namespace Bonniss26\Lqip;

use Bonniss26\Lqip\Strategies\BlurStrategy;
use Bonniss26\Lqip\Strategies\DominantColorStrategy;
use Bonniss26\Lqip\Strategies\GradientStrategy;
use Bonniss26\Lqip\Strategies\LqipStrategy;
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
 *
 * Decoding, size-capping, and dispatch to a mode's LqipStrategy live here;
 * the actual pixel-crunching per mode lives in Strategies\*.
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
        'mode' => 'dominant_color', // dominant_color | gradient | blur | custom (see ::extend())
        'long_edge' => 16,
        'quality' => 50,
        'blur' => true,
        'format' => 'webp', // webp | jpg
    ];

    /**
     * Strategies registered via ::extend(), keyed by mode name. Checked before
     * the built-in ones, so a custom registration can also override a
     * built-in mode name.
     */
    protected static array $customStrategies = [];

    /**
     * Register (or override) a strategy for a mode name, e.g. a future
     * `thumbhash` or `css_only` implementation living outside this package.
     */
    public static function extend(string $mode, LqipStrategy $strategy): void
    {
        static::$customStrategies[$mode] = $strategy;
    }

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

            $strategy = static::resolveStrategy($options['mode']);
            $style = $strategy->generate($source, $origWidth, $origHeight, $options);

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

    protected static function resolveStrategy(string $mode): LqipStrategy
    {
        return static::$customStrategies[$mode]
            ?? static::builtInStrategies()[$mode]
            // Unknown mode names fall back to `blur` - the most broadly
            // acceptable default when misconfigured, same as before this
            // was a match() with a `default` arm.
            ?? static::builtInStrategies()['blur'];
    }

    protected static function builtInStrategies(): array
    {
        static $strategies = null;

        return $strategies ??= [
            'dominant_color' => new DominantColorStrategy(),
            'gradient' => new GradientStrategy(),
            'blur' => new BlurStrategy(),
        ];
    }
}
