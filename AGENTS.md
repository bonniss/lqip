# AGENTS.md

Guidance for AI coding agents working in this repository.

## What this is

`bonniss26/lqip` — a framework-agnostic PHP library that generates LQIP
(low-quality image placeholders): a value usable directly inside a CSS
`background-image` list, computed from a real image, so something renders
while the real photo is still loading.

Extracted from `plugins/bonniss26/mediaplaceholder`, a plugin in the WinterCMS
project `ateliersgam` (`C:\laragon\www\ateliersgam`, a separate git repo on
this machine), where the same algorithm ran cached-by-path behind a Twig
filter. This package is the framework-agnostic **core only** — no caching, no
settings/config system, no framework glue. A separate adapter package
(`bonniss26/wn-lqip-plugin`, not yet created) is planned to wrap this for
WinterCMS: Twig filter, Settings model, DB caching, console commands.

**Never modify `ateliersgam` from work done in this repo.** It's a separate
git repository and must keep working exactly as-is — still using the
original `plugins/bonniss26/mediaplaceholder` plugin — until the adapter
package exists and is proven equivalent, at which point migrating
`ateliersgam` over is its own separate task, not a side effect of anything
done here. If a task ever seems to require touching `ateliersgam`, stop and
confirm with the user first.

## Status

Done:
- Core extracted, 3 modes ported with unchanged pixel behavior:
  `dominant_color`, `gradient`, `blur`.
- `Strategies\LqipStrategy` interface, one class per mode
  (`src/Strategies/*.php`); `PlaceholderGenerator::extend()` lets callers
  register a new mode or override a built-in one.
- PHPUnit test suite (`vendor/bin/phpunit`) — synthetic GD-generated images
  in-test, no binary fixtures.
- MIT license, `composer.json`, README with usage + options table.

Not done (roadmap, in rough priority order):
- `thumbhash` strategy — wrap `srwiez/thumbhash` (pure PHP, GD/Imagick both
  work), aimed at headless/API consumers who already run client JS.
- `css_only` strategy — leanrada-style single-integer CSS-decoded placeholder
  (`calc()/round()/mod()/oklab()`, zero JS, smallest payload). Label clearly
  as experimental/modern-browsers-only.
- Static/manual override mechanism — a way for a caller to supply their own
  fixed placeholder value and skip generation entirely (useful for logos/
  icons where auto-generation looks bad, or brand-color consistency).
  Precedent found in the `ateliersgam` theme: `pc.bg_color`/`pc.text_color`
  are admin-editable JSON fields that override an auto-computed value —
  follow that "explicit value wins over computed one" shape. This belongs in
  the *adapter* (or as a caller-side check before calling this package), not
  as a new core strategy.
- Imagick as an optional, auto-detected driver (GD stays the required
  baseline for hosting compatibility).
- CI (GitHub Actions, PHP 8.0–8.3 matrix), semver tagging, CHANGELOG.
- GitHub remote + push + Packagist registration — intentionally not done yet,
  local-only so far by explicit decision.
- `bonniss26/wn-lqip-plugin` adapter package: Twig filter, Settings model,
  DB caching keyed by path, console commands (`lqip:regenerate`/`lqip:purge`
  with `--only`/`--only-missing`/`--ids`), config-driven strategy
  registration, optional queued generation, `LqipGenerated`/`LqipFailed`
  events, model trait `HasLqip`.

Known bug in the *original* `ateliersgam` plugin (not in this repo, but
relevant when designing the adapter's caching layer): its cache invalidation
keys only on `placeholder_mode`, not the full settings (format/quality/
long_edge/blur) — changing those without changing mode silently serves stale
cache.

Full original design rationale (naming decisions, universal use-case survey
across e-commerce/gallery/headless-CMS/email, why SQIP was rejected outright,
why BlurHash needed a JS decoder and was skipped for the theme but is
reasonable for this package, PHP requirement reasoning) was written during
planning in a Claude Code plan file on the machine this was built on:
`C:\Users\Trung\.claude\plans\plugins-bonniss26-mediaplaceholder-l-pl-gentle-sun.md`.
If continuing from a different machine or after that file is gone, ask the
user rather than re-deriving the reasoning from scratch.

## Working practices

- **Run `vendor/bin/phpunit` after every change**, before considering it
  done — don't eyeball the diff and assume it's correct. All tests must stay
  green; a refactor that changes public behavior needs updated tests that
  explain why, not just green output.
- **Root cause over workaround.** If something doesn't work as expected
  (a GD quirk, a quantization edge case, a missing extension function),
  understand why before patching around it — don't band-aid with fragile
  special-casing.
- **Extract shared/reusable code proactively**, don't wait to be asked —
  this is why the Strategy interface exists instead of a raw `match()` over
  private methods.
- **Verify, don't assume.** Before assuming code can be copied verbatim
  between contexts, actually read it: the original plan assumed this class
  could be copied as-is from the Winter plugin, but it turned out to hard-
  depend on 3 Winter-only classes (`PlaceholderCache`, `Settings`,
  `MediaLibrary`) — the "framework-agnostic core" had to be adapted, not
  literally copied.
- **This repo is separate from `ateliersgam`.** Any task that could
  plausibly touch either one should check `git status` in *both* repos,
  before and after.
- One commit per logical change; commit body explains *why*, not just what.
- Communication with the user: terse, direct, Vietnamese for chat, English
  for code/docs/commit messages. Prefer taking action over lengthy
  explanation once direction is clear; ask when genuinely blocked on a
  decision only the user can make (naming, scope, sequencing), not to
  double-check plumbing.
