<?php namespace Bonniss26\Lqip;

use Bonniss26\Lqip\Drivers\Driver;
use Bonniss26\Lqip\Drivers\FlattenedImage;
use Bonniss26\Lqip\Drivers\GdDriver;
use Bonniss26\Lqip\Drivers\ImagickDriver;
use Bonniss26\Lqip\Strategies\BlurStrategy;
use Bonniss26\Lqip\Strategies\CssOnlyStrategy;
use Bonniss26\Lqip\Strategies\DominantColorStrategy;
use Bonniss26\Lqip\Strategies\GradientStrategy;
use Bonniss26\Lqip\Strategies\LqipStrategy;
use Bonniss26\Lqip\Strategies\ThumbHashStrategy;
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
 *   for a CSS custom property, and `thumbhash`, whose output is a hash for a
 *   client-side decoder - neither is a background-image value. Asking the
 *   CSS-returning methods for such a mode throws NotCssRepresentable.
 *
 * Framework-agnostic: no caching, no settings model, no media-library
 * abstraction. Callers own persistence and configuration; pass options
 * explicitly per call.
 *
 * Decoding is a Drivers\Driver's job (GD or Imagick); the pixel work per mode
 * lives in Strategies\*; this class validates, dispatches, and turns every
 * failure into null.
 */
class PlaceholderGenerator
{
    // Raised for the duration of a GD decode only - see GdDriver::load().
    const TEMP_MEMORY_LIMIT = '512M';

    // A source past this many pixels is skipped rather than risking a fatal
    // (uncatchable) memory exhaustion. A safety backstop, not a quality knob.
    const MAX_SOURCE_PIXELS = 60000000;

    // Drivers shrink the decoded source to this long edge before any strategy
    // samples it. Four times MAX_LONG_EDGE, so even the largest miniature is a
    // genuine reduction rather than an upscale of something already blurry.
    const WORKING_LONG_EDGE = 256;

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
        'mode' => 'dominant_color', // dominant_color | gradient | blur | css_only | thumbhash | custom (see ::extend())
        'long_edge' => 16,
        // Deliberately low. At a 16px long edge with the default two blur passes,
        // most of what a higher quality preserves is high-frequency detail the blur
        // just removed - measured across 40 photos, q50 costs 15% more bytes than
        // q25 for a 3.5% better reconstruction. lqip-modern, the most-cited
        // reference implementation of this technique, ships quality 20.
        'quality' => 25,
        'blur' => true,
        'format' => 'webp', // webp | jpg
        // blur only: wrap the miniature in an SVG that blurs it again at display
        // size, hiding the blocks a browser's enlargement of 16px leaves.
        'smooth' => false,
        // auto | gd | imagick, or a Drivers\Driver instance. `auto` takes Imagick
        // when it is loaded, for its reduced-size JPEG decoding, and GD otherwise.
        'driver' => 'auto',
        // null keeps transparency: a logo gets a placeholder in its own colour
        // with its own empty space. A colour ('#ffffff', '#fff' or 0xFFFFFF)
        // composites the image onto it first - for when you know the page behind.
        'background' => null,
    ];

    /**
     * Strategies registered via ::extend(), keyed by mode name. Checked before
     * the built-in ones, so a custom registration can also override a
     * built-in mode name.
     *
     * @var array<string,LqipStrategy>
     */
    protected static $customStrategies = [];

    /** @var Driver|null Set by ::useDriver(); replaces what `auto` resolves to. */
    protected static $defaultDriver = null;

    /**
     * Register (or override) a strategy for a mode name, e.g. a BlurHash
     * encoder living outside this package.
     */
    public static function extend(string $mode, LqipStrategy $strategy): void
    {
        static::$customStrategies[$mode] = $strategy;
    }

    /**
     * Every mode name this class will accept: the built-in ones plus anything
     * registered through ::extend().
     *
     * @return string[]
     */
    public static function modes(): array
    {
        return array_values(array_unique(array_merge(
            array_keys(static::builtInStrategies()),
            array_keys(static::$customStrategies)
        )));
    }

    /**
     * Makes $driver what the `auto` driver option resolves to - a custom engine,
     * or a built-in one pinned process-wide. Pass null to restore detection.
     */
    public static function useDriver(?Driver $driver): void
    {
        static::$defaultDriver = $driver;
    }

    /**
     * Names of the built-in drivers usable in this process, preferred first.
     *
     * @return string[]
     */
    public static function availableDrivers(): array
    {
        $names = [];
        foreach (static::builtInDrivers() as $name => $driver) {
            if ($driver->isAvailable()) {
                $names[] = $name;
            }
        }

        return $names;
    }

    /**
     * The driver a `driver` option value resolves to, or null if it names
     * nothing usable here. Public so a caller can ask the same driver that will
     * generate a placeholder what it supports - e.g. supportsFormat('webp') when
     * deciding a cache key.
     *
     * @param string|Driver|null $driver
     */
    public static function resolveDriver($driver = 'auto'): ?Driver
    {
        if ($driver instanceof Driver) {
            return $driver->isAvailable() ? $driver : null;
        }

        if ($driver === null || $driver === 'auto') {
            if (static::$defaultDriver !== null) {
                return static::$defaultDriver->isAvailable() ? static::$defaultDriver : null;
            }

            foreach (static::builtInDrivers() as $candidate) {
                if ($candidate->isAvailable()) {
                    return $candidate;
                }
            }

            return null;
        }

        $candidate = static::builtInDrivers()[$driver] ?? null;

        return ($candidate && $candidate->isAvailable()) ? $candidate : null;
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

        $bytes = @file_get_contents($path);
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
        if ($bytes === '') {
            return null;
        }

        $image = null;

        try {
            $options = static::normalizeOptions($options + static::DEFAULT_OPTIONS);

            // Resolved before decoding: an unknown mode or a missing engine is a
            // configuration problem, and should cost nothing rather than a full
            // decode followed by a null.
            $strategy = static::resolveStrategy((string) $options['mode']);
            $driver = static::resolveDriver($options['driver']);
            $background = static::parseColor($options['background']);
            if ($strategy === null || $driver === null || $background === false) {
                return null;
            }

            $image = $driver->load($bytes);
            if ($image === null) {
                return null;
            }

            if ($background !== null) {
                $image = new FlattenedImage($image, $background);
            }

            $result = $strategy->generate($image, $driver, $options);

            return $result ? $result->withDimensions($image->width(), $image->height()) : null;
        } catch (Throwable $e) {
            return null;
        } finally {
            // In finally rather than after generate(): a strategy that throws would
            // otherwise keep the decoded image alive until the next collection.
            if ($image) {
                $image->release();
            }
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
     * Forces the numeric options into ranges the engines will actually accept.
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
        $options['blur'] = (bool) $options['blur'];
        $options['smooth'] = (bool) $options['smooth'];

        return $options;
    }

    /**
     * The `background` option as 0xRRGGBB, null for none, or false when it is
     * not a colour - which, like an unknown mode, is a configuration error that
     * should cost nothing rather than silently producing something else.
     *
     * @param mixed $value '#rrggbb', '#rgb' (the # optional), an int, or null.
     * @return int|null|false
     */
    protected static function parseColor($value)
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $value >= 0 && $value <= 0xFFFFFF ? $value : false;
        }

        if (!is_string($value) || !preg_match('/^#?([0-9a-f]{3}|[0-9a-f]{6})$/i', trim($value), $match)) {
            return false;
        }

        $hex = strlen($match[1]) === 3
            ? $match[1][0] . $match[1][0] . $match[1][1] . $match[1][1] . $match[1][2] . $match[1][2]
            : $match[1];

        return (int) hexdec($hex);
    }

    /**
     * Null for a mode nobody registered. It used to fall back to `blur`, which
     * turned a typo into the most expensive branch, silently.
     */
    protected static function resolveStrategy(string $mode): ?LqipStrategy
    {
        return static::$customStrategies[$mode]
            ?? static::builtInStrategies()[$mode]
            ?? null;
    }

    /** @return array<string,LqipStrategy> */
    protected static function builtInStrategies(): array
    {
        static $strategies = null;

        return $strategies ?? $strategies = [
            'dominant_color' => new DominantColorStrategy(),
            'gradient' => new GradientStrategy(),
            'blur' => new BlurStrategy(),
            'css_only' => new CssOnlyStrategy(),
            'thumbhash' => new ThumbHashStrategy(),
        ];
    }

    /**
     * In `auto` preference order.
     *
     * @return array<string,Driver>
     */
    protected static function builtInDrivers(): array
    {
        static $drivers = null;

        return $drivers ?? $drivers = [
            'imagick' => new ImagickDriver(),
            'gd' => new GdDriver(),
        ];
    }
}
