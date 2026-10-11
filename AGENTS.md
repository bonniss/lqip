# AGENTS.md

Guidance for AI coding agents working in this repository.

## What this is

`bonniss26/lqip` — a framework-agnostic PHP library that generates LQIP
(low-quality image placeholders): a value usable directly inside a CSS
`background-image` list, computed from a real image, so something renders
while the real photo is still loading.

This package is the **core only** — no caching, no settings system, no
framework glue, no runtime dependencies. Callers own persistence and
configuration and pass options per call. Framework integration belongs in a
separate adapter package; `bonniss26/wn-imagery-plugin` (sibling checkout at
`../wn-imagery-plugin`) is the WinterCMS one (Twig filters, settings, DB
caching, queued generation, console commands).

Composer vendor is `bonniss26`; the GitHub account is `bonniss`
(`github.com/bonniss/lqip`). `bonniss26` does not exist on GitHub, so links
must not point there.

## Status

Done:

- Four modes, one class each under `src/Strategies/`, behind the
  `Strategies\LqipStrategy` interface. `PlaceholderGenerator::extend()` lets
  callers register a new mode or override a built-in one.
- **Engines behind `Drivers\Driver`** (`GdDriver`, `ImagickDriver`). A driver
  only decodes, shrinks to a 256px working copy, samples, and encodes; every
  strategy works on `Support\Raster` (a small int array) in plain PHP. That
  boundary is the point: it is why both engines agree, why a vips driver
  would be one class, and why strategies are testable without an engine.
  `Support\GaussianBlur` is GD's convolution reproduced bit for bit (tested
  against `imagefilter`), so blur measurements made on GD still hold.
- EXIF orientation via `Support\ExifOrientation` (pure PHP, no `ext-exif`),
  applied to the tiny sample in `Raster::orient()`.
- PHP 7.4–8.5. No constructor promotion, union types or `mixed`, and
  `imagedestroy()` only on PHP 7 (8.5
  deprecates it, and a deprecation is a diagnostic frameworks turn into a
  failed request). CI covers every minor version with GD and with GD+Imagick.
- `LqipResult` value object: strategies return a tagged value (`css` or
  `css_var`) rather than a bare string, because `css_only` produces something
  that is *not* a `background-image` value. `forFile()`/`forBytes()` return the
  CSS string and throw `NotCssRepresentable` for a non-CSS mode;
  `resultForFile()`/`resultForBytes()` are the richer siblings. That throw sits
  deliberately outside `resultForBytes()`'s `catch (Throwable)`, which exists
  only to make a broken *image* fail softly as null.
- `css_only` — single-integer placeholder after Lean Rada's format. The codec
  is GD-free and lives in `Support\LqipInteger` + `Support\Oklab`, so the bit
  layout is testable without images; verified against the published reference
  value `192900`.
- `Support\DominantColor` — shared by `dominant_color` and `css_only`. Its own
  quantizer (median cut → k-means → merge near clusters → count), not GD's,
  because GD's differs between builds and Imagick's is a third algorithm. The
  shape of it is justified with measurements in its docblock — read that
  before "simplifying" it.
- Numeric options clamped (`quality` 0–100, `long_edge` ≤ 64) and `is_file()`
  guarded, because both were paths to crashing the caller. See the commit
  "Stop bad input and bad options from crashing the caller" — the reasoning
  there matters more than the diff. An unknown mode or driver returns null
  before any decoding.
- PHPUnit suite — synthetic GD-generated images in-test, no binary fixtures.
  `tests/Drivers/DriverContractTest` runs against every driver and skips one
  whose engine is missing; run it under Docker (`php:*-cli` +
  `install-php-extensions gd imagick`) to exercise Imagick locally.
- `docs/render-examples.php` — renders the README's mode comparison picture
  (`docs/images/modes.jpg`) from CC0 photos in `docs/images/source/`. Rerun it
  when a mode's output changes. The README is user-facing first; its "For
  developers" section carries a short copy of the measurements below, so keep
  the two in step.
- `bench/compare-drivers.php <folder> [mode]` — timings and cross-driver
  agreement on real photos.
- MIT license, `composer.json`, README, CHANGELOG, CI.

### Measurements behind the defaults

40 photographs, 16px long edge, characters of the value actually sent over the
wire. RMSE is against the source on a 16×16 grid, 0–255 scale, lower is closer.

| mode / variant | chars | RMSE |
|---|---|---|
| `dominant_color` | 32 | 56.3 |
| `gradient` | 256 | (samples only 2×2) |
| `blur` webp q25 *(default)* | 131 | 28.3 |
| `blur` webp q50 | 138 | 27.3 |
| `blur` **jpg** q50 | 998 | — |
| `blur` avif q50 | 478 | — |
| `css_only` | 13 | — |

- **AVIF loses badly at this size**: 4× larger than WebP and 280× slower
  (27ms vs 0.1ms per image), because container overhead dominates. Ruled out.
- **The JPEG fallback costs ~9× WebP.** That is `gradient`'s only real niche.
- **Gaussian passes matter more than `quality`**: 0/1/2/3 passes give
  125/93/84/79 bytes. Two passes stays the default — a multi-scale check showed
  blur preserves overall layout (2×2 grid RMSE 3.2 vs 3.0 unblurred) and only
  discards the high-frequency detail that would look blocky upscaled anyway.
- **`css_only` degenerates on evenly-lit photography**: about half of 25 test
  images produced six identical cells, i.e. a flat colour. Inherent to the
  format — a cell only changes bucket when its Oklab L differs from the base by
  more than 1/3. Worth knowing before choosing it over `dominant_color`.
- **Generation cost is linear in source megapixels**, ~21 ms/MP with GD on an
  idle machine, because decoding dominates rather than the pixel work. A 10 MP
  source is ~226 ms. Callers rendering many images at once need a cache and
  should not generate lazily without a per-request budget.
- **Imagick is ~3.5× faster**: 37 photos, median 20 MP, PHP 8.3 in Docker —
  5.5 ms/MP against GD's 19.5, across all four modes. The win is libjpeg's
  reduced-size decode (`jpeg:size`), so it is a JPEG win; that is why `auto`
  prefers Imagick.
- **Engines agree except where the image is ambiguous.** `gradient`: max RGB
  distance 1.7 between GD and Imagick. `dominant_color`: median 1.4, but on
  the ~10% of photos where two colour groups each cover about a third of the
  8×8 sample, a tiny resampling difference flips the winner. That is inherent
  to "most-used colour", not a driver bug — and why the WinterCMS plugin does
  not put the driver in its cache key.

Not done (roadmap, in rough priority order):

- Ship a `css_only` decode stylesheet. **Licence blocker**: the reference
  implementation's repository has no licence file, so its stylesheet is
  all-rights-reserved and must not be vendored into this MIT package. Any
  stylesheet here has to be written independently from the bit layout, which is
  a data format rather than expressive code. It is also the one artifact
  PHPUnit cannot verify — it needs manual browser checking.
- `thumbhash` strategy — wrap `srwiez/thumbhash` (pure PHP), aimed at
  headless/API consumers who already run client JS.
- `on_transparency` option (`ignore`|`skip`). Every mode currently composites
  alpha onto black; the `css_only` reference instead skips transparent images.
- A vips driver (`jcupitt/vips`, needs FFI) — only worth it if measured faster
  than Imagick; FFI is off on most shared hosting.
- GD's working copy is one `imagecopyresampled()` over the full bitmap, which
  is most of GD's cost after decoding. A cheaper pre-shrink (e.g. a
  nearest-neighbour pass to ~4× the working size first) would need measuring
  for both speed and output drift before adopting.


## Working practices

- **Run `vendor/bin/phpunit` after every change**, before considering it done —
  don't eyeball the diff and assume it's correct. All tests must stay green; a
  refactor that changes public behaviour needs updated tests that explain why,
  not just green output.
- **Root cause over workaround.** If something doesn't work as expected (a GD
  quirk, a quantization edge case, a missing extension function), understand
  why before patching around it — don't band-aid with fragile special-casing.
  Two real examples live in this repo's history: a warning that only became
  fatal under a framework's error handler, and an out-of-range option that
  raised an uncatchable `E_ERROR`. Both looked like "just add a try/catch".
- **Extract shared/reusable code proactively**, don't wait to be asked — this
  is why the Strategy interface exists instead of a raw `match()` over private
  methods, and why `Support\DominantColor` is its own class.
- **Verify, don't assume.** Measure before claiming a performance or size
  property, and read an API's source before relying on its behaviour. Several
  of the numbers above overturned a plausible-sounding assumption.
- **This library must never throw except `NotCssRepresentable`.** Every other
  failure is `null`. Consumers render it inside templates where an exception
  takes down a whole page, and a PHP warning counts — frameworks routinely
  promote warnings to exceptions.
- One commit per logical change; commit body explains *why*, not just what.
- Communication with the user: terse, direct, Vietnamese for chat, English for
  code, docs and commit messages. Prefer taking action over lengthy explanation
  once direction is clear; ask when genuinely blocked on a decision only the
  user can make (naming, scope, sequencing), not to double-check plumbing.
