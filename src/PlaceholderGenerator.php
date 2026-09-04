<?php namespace Bonniss26\Lqip;

use Bonniss26\Lqip\Strategies\BlurStrategy;
use Bonniss26\Lqip\Strategies\CssOnlyStrategy;
use Bonniss26\Lqip\Strategies\DominantColorStrategy;
use Bonniss26\Lqip\Strategies\GradientStrategy;
use Bonniss26\Lqip\Strategies\LqipStrategy;
use Throwable;

/**
 * Computes a low-quality image placeholder from a real image.
 *
 * Two entry points, and which one you want depends on the mode:
 *
 * - forFile()/forBytes() return a single value usable inside a CSS
 *   `background-image` list (e.g. `url('data:...')` or
 *   `radial-gradient(...),radial-gradient(...)`). `dominant_color`, `gradient`
 *   and `blur` all return something composable this way - including
 *   dominant_color, via a same-color-both-stops `linear-gradient()` - so
 *   callers can use it alone (`background-image:{{placeholder}}`) or layer it
 *   behind a real photo that's also a CSS background
 *   (`background-image:url(realPhoto), {{placeholder}}`) without caring which
 *   of those three is active.
 *
 * - resultForFile()/resultForBytes() return an LqipResult, which also says what
 *   kind of value it is. Needed for `css_only`, whose output is a bare integer
 *   for a CSS custom property rather than a background-image value. Asking the
 *   CSS-returning methods for such a mode throws NotCssRepresentable.
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

    // Ranges the numeric options are forced into. Backstops, not quality knobs.
    //
    // quality: imagewebp() raises an uncatchable E_ERROR ("gd-webp encoding failed")
    // on a negative value, so this bound is what keeps a bad option from killing the
    // process outright rather than returning null like every other failure here.
    //
    // long_edge: a placeholder is meant to be tiny. At 64 the worst case measured
    // was ~5KB of base64; at 5000 it is ~70KB per image, which is no longer a
    // placeholder and overflows a MySQL TEXT column if the caller caches it.
    const MIN_QUALITY = 0;
    const MAX_QUALITY = 100;
    const MIN_LONG_EDGE = 1;
    const MAX_LONG_EDGE = 64;

    const DEFAULT_OPTIONS = [
        'mode' => 'dominant_color', // dominant_color | gradient | blur | css_only | custom (see ::extend())
        'long_edge' => 16,
        // Deliberately low. At a 16px long edge with the default two blur passes,
        // most of what a higher quality preserves is high-frequency detail the blur
        // just removed - measured across 40 photos, q50 costs 15% more bytes than
        // q25 for a 3.5% better reconstruction. lqip-modern, the most-cited
        // reference implementation of this technique, ships quality 20.
        'quality' => 25,
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
     * @throws Exceptions\NotCssRepresentable If the mode doesn't produce a CSS value.
     */
    public static function forFile(string $path, array $options = []): ?string
    {
        return static::toCss(static::resultForFile($path, $options), $options);
    }

    /**
     * @param string $bytes Raw image bytes.
     * @param array $options See self::DEFAULT_OPTIONS.
     * @throws Exceptions\NotCssRepresentable If the mode doesn't produce a CSS value.
     */
    public static function forBytes(string $bytes, array $options = []): ?string
    {
        return static::toCss(static::resultForBytes($bytes, $options), $options);
    }

    /**
     * @param string $path Local filesystem path to a readable image file.
     * @param array $options See self::DEFAULT_OPTIONS.
     */
    public static function resultForFile(string $path, array $options = []): ?LqipResult
    {
        // is_file() as well as is_readable(): a directory is "readable", so without
        // this the call falls through to file_get_contents(), which emits a PHP
        // warning. That warning is raised before the try block below can catch
        // anything - and a framework that promotes warnings to exceptions (Laravel
        // and anything built on it does) then turns it into a fatal request error,
        // breaking this class's promise that every failure is just null.
        if (!$path || !is_file($path) || !is_readable($path)) {
            return null;
        }

        $bytes = file_get_contents($path);
        if ($bytes === false) {
            return null;
        }

        return static::resultForBytes($bytes, $options);
    }

    /**
     * @param string $bytes Raw image bytes.
     * @param array $options See self::DEFAULT_OPTIONS.
     */
    public static function resultForBytes(string $bytes, array $options = []): ?LqipResult
    {
        if (!$bytes) {
            return null;
        }

        $options = static::normalizeOptions($options + static::DEFAULT_OPTIONS);

        $previousLimit = ini_get('memory_limit');
        ini_set('memory_limit', static::TEMP_MEMORY_LIMIT);
        $source = null;

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

            return $strategy->generate($source, $origWidth, $origHeight, $options);
        } catch (Throwable $e) {
            return null;
        } finally {
            // In finally rather than after the generate() call: a strategy that throws
            // would otherwise leak the decoded bitmap, which is the largest allocation
            // this class makes.
            if ($source) {
                imagedestroy($source);
            }

            // Suppressed: lowering back below the peak usage this call just reached
            // triggers a warning, not a failure - harmless, the limit simply stays raised
            // for the rest of this request.
            @ini_set('memory_limit', $previousLimit);
        }
    }

    /**
     * Deliberately outside resultFor*()'s try/catch. That catch exists to make a
     * broken *image* fail softly as null; asking for CSS from a mode that cannot
     * produce it is a caller mistake, and swallowing it into an
     * indistinguishable null would be undiagnosable. Keeping the conversion out
     * here means no future exception on this path can be swallowed either,
     * without anyone having to remember to special-case it.
     *
     * @throws Exceptions\NotCssRepresentable
     */
    protected static function toCss(?LqipResult $result, array $options): ?string
    {
        if ($result === null) {
            return null;
        }

        $css = $result->toCss();
        if ($css === null) {
            throw Exceptions\NotCssRepresentable::forResult(
                (string) ($options['mode'] ?? static::DEFAULT_OPTIONS['mode']),
                $result->kind()
            );
        }

        return $css;
    }

    /**
     * Forces the numeric options into ranges GD will actually accept.
     *
     * Not defensive tidying - imagewebp() with a negative quality raises E_ERROR,
     * which no try/catch can intercept, so a single out-of-range value takes the
     * whole process down and defeats every other safeguard in this class. A caller
     * driving these from a settings form or a config file cannot be assumed to
     * have validated them.
     */
    protected static function normalizeOptions(array $options): array
    {
        $options['quality'] = max(self::MIN_QUALITY, min(self::MAX_QUALITY, (int) $options['quality']));
        $options['long_edge'] = max(self::MIN_LONG_EDGE, min(self::MAX_LONG_EDGE, (int) $options['long_edge']));

        return $options;
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
            'css_only' => new CssOnlyStrategy(),
        ];
    }
}
