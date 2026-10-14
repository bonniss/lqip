# Changelog

All notable changes to this project are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.3.0] - 2026-10-12

Transparent images keep their transparency, results carry the image size, and
two new ways to render: `smooth` blur and `thumbhash`.

### Changed

- **Images with transparency keep it.** Every mode used to composite alpha
  onto black, so a logo or a cut-out product shot got a black placeholder.
  Now `dominant_color` takes the colour of the painted pixels only, at the
  image's coverage (`#ff000080` for a red logo filling half its box);
  `gradient` gives each corner its alpha and drops the solid bottom layer;
  `blur` keeps the shape in a transparent WebP (a JPEG is flattened onto
  white); `css_only` returns `null`, as the reference implementation does,
  because its format has no way to express transparency. Opaque images
  produce byte-identical output to 0.2.0 on both drivers.

### Added

- `background` option: composite onto a colour (`#ffffff`, `#fff` or
  `0xFFFFFF`) before anything else, for when the page colour is known. An
  invalid colour returns `null`, like an unknown mode.
- `LqipResult::width()`, `height()` and `aspectRatio()`: the source image's
  size as displayed, after EXIF orientation, which `getimagesize()` gets the
  wrong way round for portrait phone photos. Attached to every result,
  custom strategies' included. `toStyleAttribute(['aspect_ratio' => true])`
  also reserves the shape (`aspect-ratio:1600/900`).
- `smooth` option for `blur`: wraps the miniature in an SVG with a Gaussian
  blur filter, the technique `next/image` uses, so the browser's enlargement
  of a 16 px image no longer shows soft blocks. Costs ~530 characters against
  ~130, so it is off by default.
- `thumbhash` mode: a [ThumbHash](https://evanw.github.io/thumbhash/) as
  base64, ~28 characters (~36 with transparency), for APIs and apps that decode it client-side. Keeps
  transparency and the approximate aspect ratio. A port of the MIT reference
  implementation, checked against it, with no new dependency. Returns a
  result of the new kind `LqipResult::KIND_HASH`, which is not CSS:
  `forFile()`/`forBytes()` throw `NotCssRepresentable` for it, and
  `toStyleAttribute()` gives `''`. `Support\ThumbHash::decode()` renders a
  hash back to pixels.

### Fixed

- `LqipResult::toStyleAttribute()` now adds `background-size:cover`. Without
  it the `blur` miniature, a 16 px image, was tiled across the element at
  16 px instead of stretched over it. Gradients fill the box either way.

## [0.2.0] - 2026-10-11

Runs on Imagick as well as GD, on PHP 7.4 through 8.5.

### Added

- Two image engines behind `Drivers\Driver`: `gd` and `imagick`, chosen with
  the new `driver` option (`auto`, the default, prefers Imagick when loaded).
  Imagick decodes JPEG at reduced size and measured ~3.5× faster on 20 MP
  photographs (5.5 against 19.5 ms per megapixel).
  `PlaceholderGenerator::useDriver()` swaps in a custom engine;
  `availableDrivers()` and `resolveDriver()` report what is usable.
- `PlaceholderGenerator::modes()` lists built-in and registered modes.
- EXIF orientation is applied, read from the bytes without `ext-exif`, so a
  portrait phone photo gets a portrait placeholder.
- `bench/compare-drivers.php` to time the drivers on your own photos.

### Changed

- **Breaking for `extend()` users:** `LqipStrategy::generate()` now receives
  `(Drivers\Image $image, Drivers\Driver $driver, array $options)` instead of a
  GD image and its size. Strategies sample the image into a small
  `Support\Raster` and work in plain PHP, so they run on any engine.
- `dominant_color` uses its own quantizer (median cut, k-means, merging
  near-identical clusters) instead of GD's, whose result differed between
  libgd builds. Solid colours now come back exact (`#e04020` used to be
  `#e44224`); on photographs the choice agrees with GD's at a median of 7.8
  RGB units.
- An unknown `mode` or `driver` returns `null` without decoding. An unknown
  mode used to fall back to `blur`, the most expensive mode.
- Every mode now samples a 256px working copy made once per call, so the
  `memory_limit` raise lasts only for the decode and `css_only` no longer
  samples the full source twice.
- Requires PHP 7.4+ (was 8.0+). `ext-gd` is no longer required by Composer:
  either `ext-gd` or `ext-imagick` will do, and with neither every call
  returns `null`.

### Security

- The Imagick driver only hands ImageMagick JPEG, PNG, GIF, WebP, BMP or AVIF,
  sniffed by PHP first, so ImageMagick's external delegates (PostScript, SVG,
  MSL) are never reachable from an uploaded file.

## [0.1.0] - 2026-09-04

First release. Everything below is new, so this entry describes what the
package does rather than what changed.

### Added

- `PlaceholderGenerator::forFile()` / `forBytes()` return a value valid inside
  a CSS `background-image` list, or `null`. `resultForFile()` / `resultForBytes()`
  return an `LqipResult` that also reports what kind of value it is.
- Four modes:
  - `dominant_color` (default) — one flat tone, ~32 characters. Quantizes and
    takes the most-used colour rather than averaging, which muddies distinct
    colours into grey.
  - `gradient` — four `radial-gradient()`s from a 2×2 sample, ~256 characters.
  - `blur` — a blurred WebP miniature as a data URI, ~131 characters at the
    defaults. Falls back to JPEG when GD lacks WebP, at roughly 9× the size.
  - `css_only` — **experimental**. The whole placeholder as one integer for a
    CSS custom property, ~13 characters, decoded entirely by a stylesheet the
    consuming site supplies. After Lean Rada's format.
- `LqipResult`, so a caller can tell a `background-image` value apart from a
  custom-property value. Deliberately has no `__toString()`.
- `NotCssRepresentable`, thrown when the CSS-returning API is asked for a mode
  that cannot produce CSS. This is the only exception the package throws; every
  other failure is `null`.
- `PlaceholderGenerator::extend()` to register a new mode or override a
  built-in one.
- Safety backstops: a 60-megapixel source ceiling, a temporary `memory_limit`
  raise for the duration of a call, `quality` clamped to 0–100 and `long_edge`
  capped at 64.

### Notes

- Requires PHP 8.0+ and `ext-gd`. WebP support in GD is optional but strongly
  recommended — without it `blur` costs roughly 9× more per image.
- Sites with a strict Content-Security-Policy need `img-src data:` for the
  `blur` mode, which emits a `data:` URI. The other three modes do not.

[Unreleased]: https://github.com/bonniss/lqip/compare/v0.3.0...HEAD
[0.3.0]: https://github.com/bonniss/lqip/compare/v0.2.0...v0.3.0
[0.2.0]: https://github.com/bonniss/lqip/compare/v0.1.0...v0.2.0
[0.1.0]: https://github.com/bonniss/lqip/releases/tag/v0.1.0
