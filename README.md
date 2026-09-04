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

Every mode returns a value valid inside a `background-image` list, so you can
use it alone:

```css
background-image: linear-gradient(#7c6a54,#7c6a54);
```

or layer it behind the real photo once that's also a CSS background:

```css
background-image: url(realPhoto.jpg), linear-gradient(#7c6a54,#7c6a54);
```

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
| `mode`      | `dominant_color` | all        | `dominant_color` \| `gradient` \| `blur`   |
| `long_edge` | `16`             | `blur`     | Longest edge, in px, of the resized source |
| `quality`   | `25`             | `blur`     | Lossy encode quality (0-100)               |
| `blur`      | `true`           | `blur`     | Apply a Gaussian blur before encoding      |
| `format`    | `webp`           | `blur`     | `webp` \| `jpg` (falls back to jpg if GD lacks WebP support) |

## The three modes

- **`dominant_color`** (default) - a single flat tone, quantized down to a
  handful of colors and picking the most-used one (not a naive average, which
  tends to muddy distinct colors into grey/brown). Cheapest by far, no sense
  of shape. ~30 bytes.
- **`gradient`** - four CSS `radial-gradient()`s sampled from a 2x2 grid,
  roughly approximating the photo's layout. Not meaningfully cheaper than
  `blur` at typical sizes; kept for the layout hint it gives when a flat
  color feels too plain.
- **`blur`** - a blurred raster miniature, base64-encoded as a data URI. Most
  realistic, most expensive (still on the order of a few hundred bytes at
  default settings).

## Requirements

- PHP 8.0+
- `ext-gd` (required). No Imagick support yet.

## Non-goals (for this package)

- Caching / persistence - the caller decides what "cached by path" means for
  their storage.
- Settings/config UI - pass options per call.
- Video posters, animated GIFs, PDF/print - out of scope.
- BlurHash/ThumbHash/CSS-only (leanrada-style) encodings - not implemented
  here; candidates for a future strategy-based major version, not this one.

## Origin

Extracted from a WinterCMS site plugin (`bonniss26/mediaplaceholder`) where
the same algorithm ran cached-by-path behind a Twig filter. This package is
the framework-agnostic core; a WinterCMS adapter (Twig filter, Settings
model, caching, console commands) is planned as a separate package
(`bonniss26/wn-lqip-plugin`) that depends on this one.
