# Changelog

All notable changes to this project are documented here.

The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [0.1.0]

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
  - `blur` — a blurred WebP miniature as a data URI, ~138 characters at the
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

[Unreleased]: https://github.com/bonniss26/lqip/compare/v0.1.0...HEAD
[0.1.0]: https://github.com/bonniss26/lqip/releases/tag/v0.1.0
