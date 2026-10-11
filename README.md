# bonniss26/lqip

[![tests](https://github.com/bonniss/lqip/actions/workflows/tests.yaml/badge.svg)](https://github.com/bonniss/lqip/actions/workflows/tests.yaml)
[![Packagist](https://img.shields.io/packagist/v/bonniss26/lqip)](https://packagist.org/packages/bonniss26/lqip)

Show something in the right colours while a photo is still loading.

Give this library an image and it returns a tiny CSS value, a low-quality
image placeholder (LQIP), that you put in `background-image`. The visitor sees
a colour, a gradient or a blurred preview instead of an empty box.

![Every mode on four photos and a transparent cut-out](https://raw.githubusercontent.com/bonniss/lqip/main/docs/images/modes.jpg)

- **Plain PHP, any framework.** One static call, a string back. No config,
  no database, no runtime dependencies.
- **PHP 7.4 to 8.5**, on GD or Imagick, whichever your server has.
- **Never breaks your page.** A missing or broken image returns `null`. It
  never throws an exception or emits a PHP warning.

Using Winter CMS? Install
[`bonniss26/wn-imagery-plugin`](https://github.com/bonniss/wn-imagery-plugin)
instead. It wraps this library with Twig filters, caching and a settings page.

## Contents

- [Quick start](#quick-start)
- [Choosing a mode](#choosing-a-mode)
- [Usage](#usage): [Laravel](#laravel), [options](#options), [image size](#image-size), [transparent images](#transparent-images), [failures](#when-something-goes-wrong), [CSP](#content-security-policy)
- [GD or Imagick](#gd-or-imagick)
- [For developers](#for-developers): [how it works](#how-it-works), [measurements](#measurements), [extending](#extending), [contributing](#contributing)

## Quick start

```bash
composer require bonniss26/lqip
```

```php
use Bonniss26\Lqip\PlaceholderGenerator;

$placeholder = PlaceholderGenerator::forFile('/path/to/photo.jpg');
// "linear-gradient(#b5b2a5,#b5b2a5)"
```

```html
<img src="photo.jpg" style="background-image: linear-gradient(#b5b2a5,#b5b2a5); background-size: cover">
```

The placeholder shows behind the `<img>` until the photo has loaded and
covers it. For a CSS background photo, layer the two: the photo paints over
the placeholder once it arrives.

```css
background-image: url(photo.jpg), linear-gradient(#b5b2a5,#b5b2a5);
background-size: cover;
```

Always set `background-size: cover`. The `blur` placeholder is a 16 px image,
and without it the browser tiles it across the box at 16 px instead of
stretching it. `toStyleAttribute()` (below) includes it for you.

> **Cache the result.** Generating a placeholder decodes the whole photo, at
> about 20 ms per megapixel with GD. Do it once per image, when it is uploaded
> or on first use, and store the string. Never generate one on every page view.

## Choosing a mode

Pass `['mode' => ...]` as the second argument. The picture at the top shows
every mode on real images.

| Mode | Looks like | Size | Use it when |
|---|---|---|---|
| `dominant_color` *(default)* | One flat colour | ~32 chars | You want the cheapest thing that works everywhere |
| `blur` | A blurred thumbnail | ~130 chars | You want it to look like the photo. Best choice for most sites |
| `blur` + `smooth` | The same, without visible blocks | ~530 chars | You want the nicest look and can spare the bytes |
| `gradient` | Four soft colour patches | ~256 chars | Your PHP cannot encode WebP (see below) |
| `thumbhash` | A blurred thumbnail, drawn by the client | ~28 chars | An API or app that decodes ThumbHash itself. Not CSS |
| `css_only` | Colour plus coarse light/dark layout | ~13 chars | Experimental. Read the caveats first |

**`dominant_color`** picks the colour that covers the most of the image. It
does not average, so a blue sofa in a white room comes out white, not
grey-blue. It has no sense of shape. On a photo with a dark background, like
the parrot above, you get the background.

**`blur`** is a 16 px thumbnail, blurred and encoded as a WebP data URI. The
browser scales it up smoothly, so it reads as an out-of-focus version of the
photo. It costs about four times the characters of `dominant_color`, which is
still less than one line of CSS. If your GD or Imagick has no WebP support it
falls back to JPEG, which is about **9× larger** (~1 KB per image). On such a
server use `gradient` instead. Check with:

```php
PlaceholderGenerator::resolveDriver()->supportsFormat('webp'); // true / false
```

Enlarging a 16 px image leaves faint soft blocks (visible in the room photo
above). `'smooth' => true` wraps the thumbnail in an SVG that blurs it again at
display size, the way `next/image` does, at about four times the size.

**`gradient`** samples a 2×2 grid and paints four radial gradients. There is
no image data, only CSS colours. Where WebP works, `blur` is both smaller and
better, so `gradient` mainly exists for servers without WebP.

**`thumbhash`** returns a [ThumbHash](https://evanw.github.io/thumbhash/), a
base64 string of about 28 characters, for clients that decode it themselves:
a JSON API feeding a JavaScript front end or a mobile app (decoders exist for
JS, Swift, Kotlin and Rust). It is not CSS, so get it with `resultForFile()`
and read `->value()`. For a server-rendered page, `blur` needs no script.

**`css_only`** *(experimental)* packs the whole placeholder into one integer,
`--lqip:87524`, which a stylesheet expands into gradients. The format is
[Lean Rada's][lqip-note]. It is tiny, but:

- **You supply the decode stylesheet.** This package does not ship one yet.
  The column in the picture above is an approximate rendering.
- It needs mid-2024 browsers (CSS `round()`, `mod()`, `oklab()`). Older ones
  show **nothing at all**, not even a flat colour.
- It is very coarse. On evenly lit photos about half the time the layout
  vanishes and only one colour is left. Near-grey images can come out tinted,
  because the format deliberately avoids plain grey.

Unless you are excited about the format, `dominant_color` at 32 characters is
the safer small option.

[lqip-note]: https://leanrada.com/notes/css-only-lqip/

## Usage

```php
use Bonniss26\Lqip\PlaceholderGenerator;

PlaceholderGenerator::forFile('/path/to/photo.jpg');                 // from a file
PlaceholderGenerator::forBytes($imageBytes, ['mode' => 'blur']);     // from bytes, e.g. S3
// "url('data:image/webp;base64,UklGRkYAAABXRUJQVlA4IDoAAADwAQCd...')"
```

Both return a string that is valid inside a CSS `background-image` list, or
`null`.

### Laravel

No service provider is needed. Call it from a helper and let Laravel's cache
hold the result:

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
<img src="{{ Storage::url($photo) }}" style="background-image: {{ lqip($photo) }}; background-size: cover">
```

For many images per page, generate in a queued job after upload rather than
inside the request.

### Options

| Option       | Default          | Applies to | Meaning |
|--------------|------------------|------------|---------|
| `mode`       | `dominant_color` | all        | `dominant_color`, `gradient`, `blur`, `css_only`, `thumbhash`, or [your own](#extending) |
| `driver`     | `auto`           | all        | `auto`, `gd`, `imagick`. See [GD or Imagick](#gd-or-imagick) |
| `background` | `null`           | all        | A colour (`'#ffffff'`, `'#fff'`, `0xFFFFFF`) to put transparent images on first. See [transparent images](#transparent-images) |
| `long_edge`  | `16`             | `blur`     | Longest edge of the thumbnail in px (1-64). Bigger is sharper and longer |
| `quality`    | `25`             | `blur`     | Encode quality (0-100) |
| `blur`       | `true`           | `blur`     | Blur the thumbnail before encoding |
| `smooth`     | `false`          | `blur`     | Blur again at display size with an SVG filter. No visible blocks, ~4× the size |
| `format`     | `webp`           | `blur`     | `webp` or `jpg`. Falls back to `jpg` without WebP support |

Out-of-range numbers are clamped. An unknown `mode` or `driver`, or a
`background` that is not a colour, returns `null`.

### Image size

Every result also carries the image's size, so you can reserve its space and
stop the page jumping when the photo arrives:

```php
$result = PlaceholderGenerator::resultForFile('/path/to/photo.jpg');

$result->width();        // 1600
$result->height();       // 1067
$result->aspectRatio();  // 1.4995...
$result->toStyleAttribute(['aspect_ratio' => true]);
// "background-image:...;background-size:cover;aspect-ratio:1600/1067"
```

This is the size the photo is *displayed* at. A portrait phone photo is often
stored sideways with an EXIF flag to rotate it; `getimagesize()` reports it
the wrong way round, this does not.

### Transparent images

Logos and cut-out product shots keep their transparency:

- `dominant_color` uses only the painted pixels, made as see-through as the
  image is empty: a red logo filling half its box gives
  `linear-gradient(#ff000080,#ff000080)`.
- `gradient` gives each corner its own transparency.
- `blur` keeps the shape, as a transparent WebP. Transparency costs bytes:
  the cut-out above is ~330 characters against ~130 for a photo. A JPEG
  fallback has no transparency and is put on white.
- `thumbhash` keeps transparency too.
- `css_only` returns `null`: its format cannot express transparency.

If you know the page colour behind the image, pass it as `background`. The
image is put on that colour first and every mode treats it as opaque, which
also gives `css_only` something to encode.

### When something goes wrong

`forFile()` and `forBytes()` return `null` when:

- the image is missing, unreadable or broken;
- it is larger than 60 megapixels;
- neither GD nor Imagick is installed;
- the image is entirely transparent, or is transparent at all and the mode is
  `css_only`.

Nothing throws, and nothing emits a PHP warning. Frameworks often turn a
warning into an error page, and placeholders are usually rendered inside
templates.

The single exception is a programming mistake. `css_only` returns a CSS
custom property and `thumbhash` a hash, neither of which is a
`background-image` value, so asking `forFile()` for them throws
`NotCssRepresentable`. Use `resultForFile()` / `resultForBytes()`, which work
with every mode and tell you what you got:

```php
$result = PlaceholderGenerator::resultForFile('/path/to/photo.jpg', ['mode' => 'css_only']);

$result->kind();             // "css_var" ("css" for the CSS modes, "hash" for thumbhash)
$result->value();            // "87524"
$result->toCss();            // null: not usable as a background-image
$result->toStyleAttribute(); // "--lqip:87524"
```

For the CSS modes `toStyleAttribute()` gives
`background-image:linear-gradient(#b5b2a5,#b5b2a5);background-size:cover`,
and for `thumbhash` an empty string.

### Content-Security-Policy

`blur` produces a `data:` URI, so a site with a strict CSP needs
`img-src data:`. Without it the placeholder is silently not shown. The other
modes are plain CSS colours.

## GD or Imagick

You need one of `ext-gd` or `ext-imagick`. Almost every PHP host has GD.

| Driver | Speed on large JPEGs | Notes |
|---|---|---|
| `gd` | ~20 ms per megapixel | Everywhere |
| `imagick` | ~5.5 ms per megapixel, **~3.5× faster** | Decodes JPEGs at reduced size |

The default `auto` uses Imagick when it is installed and GD otherwise. Both
give the same placeholder, give or take a shade, so switching does not
invalidate anything you have cached. To pin one:

```php
use Bonniss26\Lqip\Drivers\GdDriver;

PlaceholderGenerator::forFile($path, ['driver' => 'gd']);   // per call
PlaceholderGenerator::useDriver(new GdDriver());            // process-wide
PlaceholderGenerator::availableDrivers();                   // ['imagick', 'gd']
```

Phone photos are rotated according to their EXIF orientation with either
driver, so a portrait photo gets a portrait placeholder.

## Requirements

- PHP 7.4 - 8.5
- `ext-gd` or `ext-imagick`, ideally with WebP support

---

## For developers

The rest of this page is for people who want to know how it works or want to
change it.

### How it works

```text
bytes ──► Driver: decode, shrink to a 256 px working copy
              │
              ▼
          Image::sample(w, h) ──► Raster (a few dozen pixels, PHP int arrays)
              │
              ▼
          Strategy (one per mode, plain PHP) ──► LqipResult
              │
              └─ blur only: Driver::encode(Raster) ──► WebP/JPEG bytes
```

- **Drivers** (`src/Drivers/`) do only what needs an image engine: decode,
  shrink, sample to a `Support\Raster`, and encode. `GdDriver` shrinks with
  one resample after a full decode. `ImagickDriver` asks libjpeg to decode at
  1/2-1/8 scale (`jpeg:size`), which is where its speed comes from. It also
  only accepts formats PHP has sniffed as JPEG, PNG, GIF, WebP, BMP or AVIF,
  so ImageMagick's external delegates (PostScript, SVG, MSL) are unreachable.
- **Strategies** (`src/Strategies/`, one class per mode) work on the
  `Raster` in plain PHP and never touch GD or Imagick. That is why both
  engines agree, why strategies are testable without an engine, and why a
  third engine would be one new class.
- **`Support\Raster`** keeps colour and alpha apart: colour as `0xRRGGBB`,
  alpha in its own array that is `null` for an opaque image. Opaque images
  therefore take exactly the path they always did. Anything that averages
  colour weights it by alpha, so the meaningless colour of a transparent
  pixel never bleeds in; both engines resample that way once GD's alpha
  blending is off. The `background` option is `Drivers\FlattenedImage`, a
  decorator that flattens every sample, so no strategy needs to know about it.
- **`Support\GaussianBlur`** reproduces GD's `IMG_FILTER_GAUSSIAN_BLUR` bit
  for bit on opaque images. The test suite checks it against `imagefilter()`.
  Images with alpha get an alpha-weighted variant, where GD has nothing to
  match.
- **`Support\DominantColor`** is its own quantizer: median cut, then k-means,
  then merging clusters closer than 40 RGB units, then counting pixels. GD's
  quantizer gives different results on different libgd builds and Imagick's
  is a third algorithm, so neither could be shared. The docblock records the
  measurements behind each step.
- **`Support\ExifOrientation`** reads the orientation tag from JPEG bytes
  without `ext-exif`. It is applied to the tiny sample, not the full image.
- **`Support\LqipInteger` / `Support\Oklab`** are the `css_only` codec,
  verified against the format's published reference value.
- **`Support\ThumbHash`** is a port of the MIT reference encoder and decoder,
  checked against the JS reference's own output. It rounds like JavaScript's
  `Math.round`, not PHP's `round()`, which pre-rounds before PHP 8.4 and
  flipped a bit in one hash in testing.
- **`LqipResult`** gets the image's displayed size attached by
  `PlaceholderGenerator` after the strategy runs, so custom strategies get it
  for free.

### Measurements

The defaults come from measurements, not guesses. 40 photographs, 16 px long
edge, length of the value actually sent; RMSE is against the source on a
16×16 grid (0-255, lower is closer):

| Mode / variant | Chars | RMSE |
|---|---|---|
| `dominant_color` | 32 | 56.3 |
| `blur` WebP q25 *(default)* | 131 | 28.3 |
| `blur` WebP q50 | 138 | 27.3 |
| `blur` JPEG q50 | 998 | — |
| `blur` AVIF q50 | 478 | — |
| `gradient` | 256 | — |
| `css_only` | 13 | — |
| `thumbhash` | 28 | — |

- `smooth` adds ~400 characters (four README photos: 525-541 against
  122-138). Its blur radius, 1 miniature pixel, was chosen from a rendered
  comparison of 0.5, 1 and 1.5.
- `thumbhash` samples 32 px rather than the reference's 100: on 41 photos,
  30 hashes came out identical and the rest decode within 2.1/255, at 1.4 ms
  per encode instead of 12.7.

- AVIF is 4× larger than WebP at this size and 280× slower to encode, so it
  is not offered.
- Blur passes matter more than `quality`: 0/1/2/3 passes give 125/93/84/79
  bytes. Two passes keep the overall layout (2×2 RMSE 3.2 against 3.0
  unblurred).
- Cost is linear in source megapixels because decoding dominates. Imagick
  against GD on 37 photos (median 20 MP, PHP 8.3): 5.5 against 19.5 ms/MP.
- GD and Imagick agree to within 1.7 RGB units on `gradient`. On
  `dominant_color` they agree to a median of 1.4. About 10% of photos have
  two colour groups of nearly equal size, and there the two engines can pick
  different winners.

Run the benchmark on your own photos:

```bash
php bench/compare-drivers.php /path/to/photos [mode]
```

### Extending

Each mode is a class implementing `Strategies\LqipStrategy`. Register your own,
or replace a built-in one, with `extend()`:

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

`PlaceholderGenerator::modes()` lists every mode. A custom engine implements
`Drivers\Driver` and `Drivers\Image` and is installed with `useDriver()`.

### Contributing

```bash
git clone https://github.com/bonniss/lqip && cd lqip
composer install
vendor/bin/phpunit
```

The test images are generated in code, so there are no binary fixtures.
Tests for a driver whose extension is missing are skipped. To run them all,
use Docker:

```bash
docker run --rm -v "$PWD":/app -w /app php:8.3-cli sh -c \
  "curl -sSL https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions -o /usr/local/bin/install-php-extensions \
   && chmod +x /usr/local/bin/install-php-extensions && install-php-extensions gd imagick \
   && vendor/bin/phpunit"
```

CI runs every PHP version from 7.4 to 8.5, with GD and with GD + Imagick.

Ground rules that pull requests are held to:

- **Never throw and never warn**, except `NotCssRepresentable`. A broken image
  is `null`. A call that can warn (decoding, `ini_set`, file access) gets an
  `@` *and* a checked return value; anything that can throw is caught.
- **PHP 7.4 syntax.** No constructor promotion, union types, `mixed`, `match`
  or named arguments.
- **No runtime dependencies**, and no new required extension.
- **Measure before claiming** a size, speed or quality improvement. Run
  `bench/compare-drivers.php`, and say what you measured in the pull request.
- Every change keeps `vendor/bin/phpunit` green. Behaviour changes come with
  tests that show why.
- One logical change per commit. The commit message says why, not only what.

If you change what a mode outputs, regenerate the picture at the top
(`docs/render-examples.php` describes how).

Ideas on the roadmap: a `css_only` decode stylesheet (it must be written
independently, because the reference one has no licence), and a vips driver
if it measures faster than Imagick. See [CHANGELOG.md](CHANGELOG.md) for what
changed between versions.

## Non-goals

- Caching or persistence. Your app knows its storage.
- A settings UI. Pass options per call, or use a framework plugin.
- Animated images (the first frame is used), video posters, PDFs.

## Credits

- The `css_only` format is Lean Rada's, from
  [leanrada.com/notes/css-only-lqip](https://leanrada.com/notes/css-only-lqip/).
- The colour maths is Björn Ottosson's [Oklab](https://bottosson.github.io/posts/oklab/).
- ThumbHash is Evan Wallace's ([evanw/thumbhash](https://github.com/evanw/thumbhash),
  MIT); `Support\ThumbHash` is a port and carries its licence.
- The example photos are CC0, from Wikimedia Commons. See
  [docs/images/source/SOURCES.md](docs/images/source/SOURCES.md).

## License

MIT.
