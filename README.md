# bonniss26/lqip

[![tests](https://github.com/bonniss/lqip/actions/workflows/tests.yaml/badge.svg)](https://github.com/bonniss/lqip/actions/workflows/tests.yaml)

Framework-agnostic PHP library that generates a low-quality image placeholder
(LQIP) for a single image - a small value you can drop straight into a CSS
`background-image`, shown while the real photo is still loading.

No framework, no database, no config system, no runtime dependencies: you
call a static method with raw bytes or a file path, you get a string back.
Runs on PHP 7.4 through 8.5, on GD or Imagick - whichever the server has.
Caching, settings UI and framework glue belong in an adapter; see
[Framework integration](#framework-integration).

## Install

```bash
composer require bonniss26/lqip
```

Until the package is listed on Packagist, point Composer at the GitHub
repository first:

```bash
composer config repositories.lqip vcs https://github.com/bonniss/lqip
composer require bonniss26/lqip:^0.2
```

Needs `ext-gd` or `ext-imagick`, or both - see [Drivers](#drivers).

## Usage

```php
use Bonniss26\Lqip\PlaceholderGenerator;

$placeholder = PlaceholderGenerator::forFile('/path/to/photo.jpg');
// "linear-gradient(#7c6a54,#7c6a54)"

$placeholder = PlaceholderGenerator::forBytes($binaryImageData, [
    'mode' => 'blur', // dominant_color | gradient | blur | css_only
]);
// "url('data:image/webp;base64,...')"
```

`dominant_color`, `gradient` and `blur` all return a value valid inside a
`background-image` list, so you can use it alone:

```css
background-image: linear-gradient(#7c6a54,#7c6a54);
```

or layer it behind the real photo once that's also a CSS background:

```css
background-image: url(realPhoto.jpg), linear-gradient(#7c6a54,#7c6a54);
```

Generation decodes the source image, so it costs real time - with GD roughly
21 ms per source megapixel. **Cache the result**; never generate it on every
request.

### Laravel

No service provider is needed. Call it like any helper and let Laravel's
cache hold the result:

```php
use Bonniss26\Lqip\PlaceholderGenerator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;

function lqip(string $path): string
{
    $disk = Storage::disk('public');

    // The modification time in the key regenerates it when the file is replaced.
    return Cache::rememberForever(
        'lqip:' . $path . ':' . $disk->lastModified($path),
        fn () => PlaceholderGenerator::forBytes($disk->get($path)) ?? ''
    );
}
```

```blade
<img src="{{ Storage::url($photo) }}" style="background-image: {{ lqip($photo) }}">
```

### Return values and failure

`forFile()`/`forBytes()` return that CSS string, or `null` if the image can't
be read or decoded, is larger than 60 megapixels, or no image engine is
available. **Nothing throws and nothing emits a PHP warning**, because the
usual caller is a template, where an exception takes down the whole page.

The one exception is a caller mistake, not an image problem: the experimental
`css_only` mode produces a bare integer for a CSS custom property, not a
`background-image` value, and asking the methods above for it throws
`NotCssRepresentable`. Use the `resultFor*()` siblings, which return an
`LqipResult` that also tells you what kind of value it is:

```php
$result = PlaceholderGenerator::resultForFile('/path/to/photo.jpg', ['mode' => 'css_only']);

$result->kind();             // "css_var"
$result->value();            // "192900"
$result->cssProperty();      // "--lqip"
$result->toCss();            // null - not usable as a background-image
$result->toStyleAttribute(); // "--lqip:192900"
```

`resultFor*()` works for every mode; for the three CSS ones `toCss()` returns
exactly what `forFile()`/`forBytes()` would have.

### Content-Security-Policy

`blur` emits a `data:` URI, so a site with a strict CSP needs `img-src data:`
(which covers CSS `background-image` too). Without it the placeholder silently
fails to render, with no console error pointing at this library.
`dominant_color`, `gradient` and `css_only` emit no `data:` URIs.

### Options

| Option      | Default          | Applies to | Meaning                                   |
|-------------|------------------|------------|--------------------------------------------|
| `mode`      | `dominant_color` | all        | `dominant_color` \| `gradient` \| `blur` \| `css_only`, or a mode added with `extend()` |
| `driver`    | `auto`           | all        | `auto` \| `gd` \| `imagick`, or a `Drivers\Driver` instance |
| `long_edge` | `16`             | `blur`     | Longest edge, in px, of the miniature (1-64) |
| `quality`   | `25`             | `blur`     | Lossy encode quality (0-100)               |
| `blur`      | `true`           | `blur`     | Apply a Gaussian blur before encoding      |
| `format`    | `webp`           | `blur`     | `webp` \| `jpg` (falls back to jpg without WebP support) |

Out-of-range numbers are clamped. An unknown `mode` or `driver` returns
`null` without decoding anything.

### Extending with a custom strategy

Each mode is a class implementing `Bonniss26\Lqip\Strategies\LqipStrategy`.
It receives the decoded image and samples it as a small array of pixels in
plain PHP, so it never touches GD or Imagick directly and works on both.
Register your own (or override a built-in one) with `extend()`:

```php
use Bonniss26\Lqip\Drivers\Driver;
use Bonniss26\Lqip\Drivers\Image;
use Bonniss26\Lqip\LqipResult;
use Bonniss26\Lqip\PlaceholderGenerator;
use Bonniss26\Lqip\Strategies\LqipStrategy;

final class AverageColour implements LqipStrategy
{
    public function generate(Image $image, Driver $driver, array $options): ?LqipResult
    {
        $hex = sprintf('#%06x', $image->sample(1, 1)->rgbAt(0, 0));

        return LqipResult::css("linear-gradient({$hex},{$hex})");
    }
}

PlaceholderGenerator::extend('average', new AverageColour());
PlaceholderGenerator::forFile('/path/to/photo.jpg', ['mode' => 'average']);
```

`PlaceholderGenerator::modes()` lists every mode, built-in and registered.

## Drivers

| Driver    | Needs         | Notes |
|-----------|---------------|-------|
| `gd`      | `ext-gd`      | Present on nearly every PHP host. Decodes the full bitmap, so cost and memory grow with source megapixels. |
| `imagick` | `ext-imagick` | Decodes JPEG directly at 1/2-1/8 scale, so large photos are much cheaper. Preferred by `auto` when loaded. |

The default `auto` takes Imagick when it is loaded and GD otherwise. Pin one
with the `driver` option, or process-wide with
`PlaceholderGenerator::useDriver()`, which also accepts your own
`Drivers\Driver` implementation. `PlaceholderGenerator::availableDrivers()`
reports which engines this PHP can use.

Both engines produce the same placeholder within resampling differences,
because only decoding, shrinking and encoding happen in the engine. Blurring,
colour quantization and cell averaging are done in PHP on a tiny pixel array.
EXIF orientation is applied either way, so a portrait phone photo gets a
portrait placeholder.

The Imagick driver only ever hands ImageMagick JPEG, PNG, GIF, WebP, BMP or
AVIF bytes, after PHP has sniffed the format itself: ImageMagick would
otherwise route SVG, PostScript and similar formats to external programs.

## The modes

Sizes below are medians measured over 40 photos, counted as characters of the
value actually sent over the wire.

| Mode | Size | Needs | Notes |
|---|---|---|---|
| `dominant_color` | 32 | — | Default |
| `gradient` | 256 | — | Only worth it without WebP |
| `blur` | 131 | `img-src data:` | Best fidelity |
| `css_only` | 13 | A stylesheet, 2024+ browsers | Experimental |

- **`dominant_color`** (default) - a single flat tone, quantized down to a
  handful of colours with a median cut and picking the most-used one (not a
  naive average, which tends to muddy distinct colours into grey/brown).
  Cheapest by far, no sense of shape.
- **`gradient`** - four CSS `radial-gradient()`s sampled from a 2x2 grid.
  Where WebP is available this is dominated by `blur`: it is nearly twice the
  size (256 characters against 131) and samples only four points, so it
  carries strictly less information than a 16px thumbnail. Its real niche is
  hosts without WebP, where `blur` gets expensive - see below.
- **`blur`** - a blurred raster miniature, base64-encoded as a data URI. Most
  realistic, and at the default 16px/quality-25 still only ~131 characters.
  **Without WebP support this falls back to JPEG, which costs roughly 9x
  more** - about 725 bytes raw, near 1KB once base64-encoded, against 73 bytes
  for WebP. At 16px a JPEG is almost entirely header. On such a host prefer
  `gradient` or `dominant_color`; check with
  `PlaceholderGenerator::resolveDriver()->supportsFormat('webp')`.
- **`css_only`** - **experimental.** The whole placeholder as one integer
  (`--lqip:192900`), expanded into gradients entirely by CSS. Around 13
  characters per image and no image bytes at all, but the trade is steep:
  - it needs a decode stylesheet the site ships once, which this package does
    not currently provide - see [the format's original write-up][lqip-note];
  - it needs CSS `round()`, `mod()`, `oklab()` and relative color syntax, so
    Chrome 125+, Firefox 118+, Safari 15.4-16.4 depending on the feature - in
    practice mid-2024 onward;
  - there is **no graceful fallback.** In an unsupporting browser, `var()`
    substitution of an unknown function invalidates the whole
    `background-image` declaration at computed-value time, so nothing renders
    at all - not even a solid color;
  - it is very coarse. A cell only changes bucket when its Oklab lightness
    differs from the base color by more than 1/3, so on evenly-lit
    photography it often degenerates to a flat tone. Measured on 25 interior
    and architecture photos, about half produced six identical cells. Compare
    against `dominant_color`, which costs 32 characters and works everywhere,
    before choosing it.

  It also deviates from the reference implementation in two documented ways:
  no sharpening pass (libvips' operator has no GD equivalent, and a 3x3
  convolution on a 3x2 image would be a different operation rather than an
  approximation), and images with transparency still get a placeholder
  instead of being skipped.

[lqip-note]: https://leanrada.com/notes/css-only-lqip/

## Requirements

- PHP 7.4 - 8.5
- `ext-gd` or `ext-imagick`. WebP support in the engine is optional but
  strongly recommended for the `blur` mode.

## Non-goals (for this package)

- Caching / persistence - the caller decides what "cached by path" means for
  their storage.
- Settings/config UI - pass options per call.
- Video posters, animated GIFs (the first frame is used), PDF/print.
- BlurHash/ThumbHash encodings - not implemented here; candidates for a future
  strategy, and both need a client-side JS decoder, which none of the current
  modes do.
- Shipping the `css_only` decode stylesheet - the mode generates the value,
  the consuming site supplies the CSS.

## Framework integration

This package is the core: give it an image, get a string back. Caching,
configuration and template helpers belong in an adapter for whatever framework
you use.

- **WinterCMS:** [`bonniss26/wn-imagery-plugin`](https://github.com/bonniss/wn-imagery-plugin)
  adds `|lqip` and `|lqipStyle` Twig filters, a backend settings page,
  database caching with failure backoff, queued generation and
  `imagery:warm` / `imagery:purge` commands.
- **Laravel:** see [the example above](#laravel).

## Credits

The `css_only` mode implements the placeholder format designed by Lean Rada,
described at [leanrada.com/notes/css-only-lqip](https://leanrada.com/notes/css-only-lqip/).
The colour space maths is Björn Ottosson's
[Oklab](https://bottosson.github.io/posts/oklab/).

## License

MIT.
