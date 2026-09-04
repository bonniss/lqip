# AGENTS.md

Guidance for AI coding agents working in this repository.

## What this is

`bonniss26/lqip` — a framework-agnostic PHP library that generates LQIP
(low-quality image placeholders): a value usable directly inside a CSS
`background-image` list, computed from a real image, so something renders
while the real photo is still loading.

This package is the **core only** — no caching, no settings system, no
framework glue. Callers own persistence and configuration and pass options
per call. Framework integration belongs in a separate adapter package;
`bonniss26/wn-imagery-plugin` is the planned WinterCMS one (Twig filters,
settings, DB caching, console commands).

## Status

Done:

- Four modes, one class each under `src/Strategies/`, behind the
  `Strategies\LqipStrategy` interface. `PlaceholderGenerator::extend()` lets
  callers register a new mode or override a built-in one.
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
- `Support\DominantColor` — shared by `dominant_color` and `css_only`.
- Numeric options clamped (`quality` 0–100, `long_edge` ≤ 64) and `is_file()`
  guarded, because both were paths to crashing the caller. See the commit
  "Stop bad input and bad options from crashing the caller" — the reasoning
  there matters more than the diff.
- PHPUnit suite — synthetic GD-generated images in-test, no binary fixtures.
- MIT license, `composer.json`, README, CHANGELOG, CI across PHP 8.0–8.3.

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
- **Generation cost is linear in source megapixels**, ~21 ms/MP on an idle
  machine, because JPEG decode dominates rather than the pixel work. A 10 MP
  source is ~226 ms. Callers rendering many images at once need a cache and
  should not generate lazily without a per-request budget.

Not done (roadmap, in rough priority order):

- Ship a `css_only` decode stylesheet. **Licence blocker**: the reference
  implementation's repository has no licence file, so its stylesheet is
  all-rights-reserved and must not be vendored into this MIT package. Any
  stylesheet here has to be written independently from the bit layout, which is
  a data format rather than expressive code. It is also the one artifact
  PHPUnit cannot verify — it needs manual browser checking.
- `css_only` samples the source **twice** (8×8 for the dominant colour, then
  24×16 for the cells), which is why it is ~50% slower than the other modes.
  One shared intermediate sample would fix it.
- `thumbhash` strategy — wrap `srwiez/thumbhash` (pure PHP), aimed at
  headless/API consumers who already run client JS.
- `on_transparency` option (`ignore`|`skip`). Every mode currently composites
  alpha onto black; the `css_only` reference instead skips transparent images.
- `resolveStrategy()` silently falls back to `blur` for an unknown mode, which
  is the *most expensive* branch. A typo should fail cheap, or loudly.
- Imagick as an optional, auto-detected driver (GD stays the required baseline
  for hosting compatibility).
- Semver tagging, GitHub remote, Packagist registration.

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
