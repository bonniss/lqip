# Changelog

All notable changes to this project are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0] - 2026-10-11

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
    defaults. Falls back to JPEG without WebP support, at roughly 9× the size.
  - `css_only` — **experimental**. The whole placeholder as one integer for a
    CSS custom property, ~13 characters, decoded entirely by a stylesheet the
    consuming site supplies. After Lean Rada's format.
- Two image engines behind `Drivers\Driver`: `gd` and `imagick`, chosen with the
  `driver` option (`auto` prefers Imagick when loaded). Imagick decodes JPEG at
  reduced size and measured ~3.5× faster on 20 MP photographs (5.5 against
  19.5 ms per megapixel). `useDriver()` swaps in a custom engine;
  `availableDrivers()` and `resolveDriver()` report what is usable.
- Blurring, colour quantization and cell averaging run in plain PHP on a small
  `Support\Raster`, so both engines produce the same placeholder within
  resampling differences. The Gaussian blur reproduces GD's bit for bit.
- EXIF orientation is applied, read from the bytes without `ext-exif`, so a
  portrait phone photo gets a portrait placeholder.
- `LqipResult`, so a caller can tell a `background-image` value apart from a
  custom-property value. Deliberately has no `__toString()`.
- `NotCssRepresentable`, thrown when the CSS-returning API is asked for a mode
  that cannot produce CSS. This is the only exception the package throws; every
  other failure is `null`, and no PHP warning or deprecation is emitted.
- `PlaceholderGenerator::extend()` to register a new mode or override a
  built-in one, and `modes()` to list them.
- Safety backstops: a 60-megapixel source ceiling, a `memory_limit` raise for
  the duration of a GD decode only, `quality` clamped to 0–100 and `long_edge`
  capped at 64. An unknown `mode` or `driver` returns `null` without decoding.

### Notes

- Requires PHP 7.4–8.5 and `ext-gd` or `ext-imagick`. WebP support in the
  engine is optional but strongly recommended — without it `blur` costs
  roughly 9× more per image.
- The Imagick driver only accepts JPEG, PNG, GIF, WebP, BMP and AVIF, sniffed by
  PHP before ImageMagick sees the bytes, so ImageMagick's external delegates
  (PostScript, SVG, MSL) are never reachable.
- Sites with a strict Content-Security-Policy need `img-src data:` for the
  `blur` mode, which emits a `data:` URI. The other three modes do not.

[Unreleased]: https://github.com/bonniss/lqip/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/bonniss/lqip/releases/tag/v0.1.0
