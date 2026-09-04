# bonniss26/lqip

Framework-agnostic PHP library that generates a low-quality image placeholder
(LQIP) for a single image - a small value you can drop straight into a CSS
`background-image`, shown while the real photo is still loading.

No framework, no database, no config system: you call a static method with
raw bytes or a file path, you get a string back. Caching, settings UI, and
framework glue (e.g. a Twig filter for WinterCMS) belong in a separate
adapter package, not here.

## Install

```
composer require bonniss26/lqip
```

## Usage

```php
use Bonniss26\Lqip\PlaceholderGenerator;

$placeholder = PlaceholderGenerator::forFile('/path/to/photo.jpg');
// "url('data:image/webp;base64,...')"

$placeholder = PlaceholderGenerator::forBytes($binaryImageData, [
    'mode' => 'dominant_color', // dominant_color | gradient | blur
]);
// "linear-gradient(#7c6a54,#7c6a54)"
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

### Return values

`forFile()`/`forBytes()` return that CSS string, or `null` if the image can't
be read or decoded.

The experimental `css_only` mode is different: it produces a bare integer for
a CSS custom property, not a `background-image` value. Asking the methods
above for it throws `NotCssRepresentable`. Use the `resultFor*()` siblings,
which return an `LqipResult` that also tells you what kind of value it is:

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

### Extending with a custom strategy

Each mode is a class implementing `Bonniss26\Lqip\Strategies\LqipStrategy`.
Register your own (or override a built-in one) with `extend()`:

```php
use Bonniss26\Lqip\PlaceholderGenerator;
use Bonniss26\Lqip\Strategies\LqipStrategy;

PlaceholderGenerator::extend('thumbhash', new MyThumbHashStrategy());

$placeholder = PlaceholderGenerator::forFile('/path/to/photo.jpg', ['mode' => 'thumbhash']);
```

### Options

| Option      | Default          | Applies to | Meaning                                   |
|-------------|------------------|------------|--------------------------------------------|
| `mode`      | `dominant_color` | all        | `dominant_color` \| `gradient` \| `blur` \| `css_only` |
| `long_edge` | `16`             | `blur`     | Longest edge, in px, of the resized source |
| `quality`   | `25`             | `blur`     | Lossy encode quality (0-100)               |
| `blur`      | `true`           | `blur`     | Apply a Gaussian blur before encoding      |
| `format`    | `webp`           | `blur`     | `webp` \| `jpg` (falls back to jpg if GD lacks WebP support) |

`long_edge`, `quality`, `blur` and `format` only affect the `blur` mode.

## The modes

Sizes below are medians measured over 40 photos, counted as characters of the
value actually sent over the wire.

| Mode | Size | Needs | Notes |
|---|---|---|---|
| `dominant_color` | 32 | — | Default |
| `gradient` | 256 | — | Only worth it without WebP |
| `blur` | 138 | `img-src data:` | Best fidelity |
| `css_only` | 13 | A stylesheet, 2024+ browsers | Experimental |

- **`dominant_color`** (default) - a single flat tone, quantized down to a
  handful of colors and picking the most-used one (not a naive average, which
  tends to muddy distinct colors into grey/brown). Cheapest by far, no sense
  of shape.
- **`gradient`** - four CSS `radial-gradient()`s sampled from a 2x2 grid.
  Where WebP is available this is dominated by `blur`: it is nearly twice the
  size (256 characters against 138) and samples only four points, so it
  carries strictly less information than a 16px thumbnail. Its real niche is
  hosts whose GD lacks WebP, where `blur` gets expensive - see below.
- **`blur`** - a blurred raster miniature, base64-encoded as a data URI. Most
  realistic, and at the default 16px/quality-25 still only ~138 characters.
  **Without WebP support in GD this falls back to JPEG, which costs roughly 9x
  more** - about 725 bytes raw, near 1KB once base64-encoded, against 80 bytes
  for WebP. At 16px a JPEG is almost entirely header. On such a host prefer
  `gradient` or `dominant_color`; check with `function_exists('imagewebp')`.
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

- PHP 8.0+
- `ext-gd` (required). No Imagick support yet.

## Non-goals (for this package)

- Caching / persistence - the caller decides what "cached by path" means for
  their storage.
- Settings/config UI - pass options per call.
- Video posters, animated GIFs, PDF/print - out of scope.
- BlurHash/ThumbHash encodings - not implemented here; candidates for a future
  strategy, and both need a client-side JS decoder, which none of the current
  modes do.
- Shipping the `css_only` decode stylesheet - the mode generates the value,
  the consuming site supplies the CSS.

## Origin

Extracted from a WinterCMS site plugin (`bonniss26/mediaplaceholder`) where
the same algorithm ran cached-by-path behind a Twig filter. This package is
the framework-agnostic core; a WinterCMS adapter (Twig filter, Settings
model, caching, console commands) is planned as a separate package
(`bonniss26/wn-lqip-plugin`) that depends on this one.
