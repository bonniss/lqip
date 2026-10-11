<?php

/**
 * Renders the README's mode comparison as an HTML page.
 *
 *     php docs/render-examples.php > modes.html
 *     chrome --headless --hide-scrollbars --screenshot=modes.png \
 *            --window-size=1150,760 modes.html
 *     magick modes.png -strip -sampling-factor 4:4:4 -quality 90 docs/images/modes.jpg
 *
 * JPEG rather than PNG because the screenshot is mostly smooth gradients:
 * about a third of the size, with no visible difference.
 *
 * Every placeholder cell uses the exact value this library returns, applied
 * the way LqipResult::toStyleAttribute() applies it, so the screenshot is what
 * a browser actually draws - not a mock-up. Two columns need a stand-in for
 * the decoder that would run on a real site:
 *
 * - css_only: its decode stylesheet is supplied by the consuming site, not
 *   this package, so the column draws the six decoded cells over the base
 *   colour with gradients of its own - approximately what the integer carries.
 * - thumbhash: decoded here with Support\ThumbHash::decode(), the reference
 *   decoder's arithmetic, into a PNG - what a client-side decoder would show.
 */

require __DIR__ . '/../vendor/autoload.php';

use Bonniss26\Lqip\PlaceholderGenerator;
use Bonniss26\Lqip\Support\LqipInteger;
use Bonniss26\Lqip\Support\ThumbHash;

const CELL_WIDTH = 150;
const CELL_HEIGHT = 100;

$photos = glob(__DIR__ . '/images/source/*.{jpg,png}', GLOB_BRACE);
sort($photos);

/** A CSS background approximating what a css_only integer decodes to. */
function approximateCssOnly(int $lqip): string
{
    $bits = LqipInteger::unpack($lqip);
    [$baseL, $a, $b] = LqipInteger::bitsToOklab($bits['ll'], $bits['aaa'], $bits['bbb']);

    $layers = [];
    foreach ($bits['cells'] as $i => $cell) {
        // Inverse of the encoder: a cell stores 0.5 + cellL - baseL in quarters.
        $lightness = max(0.0, min(1.0, $baseL + $cell / 3 - 0.5));
        $x = 100 / 6 * (2 * ($i % 3) + 1);
        $y = $i < 3 ? 25 : 75;
        $layers[] = sprintf(
            'radial-gradient(at %.1f%% %d%%,oklab(%.3f %.3f %.3f) 0,transparent 55%%)',
            $x,
            $y,
            $lightness,
            $a,
            $b
        );
    }
    $layers[] = sprintf('linear-gradient(oklab(%.3f %.3f %.3f),oklab(%.3f %.3f %.3f))', $baseL, $a, $b, $baseL, $a, $b);

    return implode(',', $layers);
}

/** A ThumbHash rendered to a PNG data URI, as a client-side decoder would. */
function decodedThumbHash(string $base64): string
{
    $raster = ThumbHash::decode(base64_decode($base64));
    $image = imagecreatetruecolor($raster->width(), $raster->height());
    imagealphablending($image, false);
    imagesavealpha($image, true);
    for ($y = 0; $y < $raster->height(); $y++) {
        for ($x = 0; $x < $raster->width(); $x++) {
            $alpha = 127 - ($raster->alphaAt($x, $y) >> 1);
            imagesetpixel($image, $x, $y, ($alpha << 24) | $raster->rgbAt($x, $y));
        }
    }
    ob_start();
    imagepng($image);

    return "url('data:image/png;base64," . base64_encode(ob_get_clean()) . "')";
}

$columns = [
    ['Original', null],
    ['dominant_color', ['mode' => 'dominant_color']],
    ['gradient', ['mode' => 'gradient']],
    ['blur', ['mode' => 'blur']],
    ['blur + smooth', ['mode' => 'blur', 'smooth' => true]],
    ['css_only*', ['mode' => 'css_only']],
    ['thumbhash*', ['mode' => 'thumbhash']],
];

$rows = [];
foreach ($photos as $path) {
    $bytes = file_get_contents($path);
    $mime = substr($path, -4) === '.png' ? 'image/png' : 'image/jpeg';
    $cells = [];

    foreach ($columns as [$title, $options]) {
        if ($options === null) {
            $cells[] = ["url('data:{$mime};base64," . base64_encode($bytes) . "')", ''];
            continue;
        }

        $result = PlaceholderGenerator::resultForBytes($bytes, $options);
        if ($result === null) {
            $cells[] = [null, 'no placeholder'];
            continue;
        }

        $value = $result->value();
        if ($options['mode'] === 'css_only') {
            $cells[] = [approximateCssOnly((int) $value), "--lqip:{$value}"];
        } elseif ($options['mode'] === 'thumbhash') {
            $cells[] = [decodedThumbHash($value), strlen($value) . ' chars'];
        } else {
            $cells[] = [$value, strlen($value) . ' chars'];
        }
    }
    $rows[] = $cells;
}

$width = CELL_WIDTH;
$height = CELL_HEIGHT;
?>
<!doctype html>
<meta charset="utf-8">
<style>
  body { margin: 0; padding: 16px; background: #fff; color: #24292f;
         font: 13px/1.4 -apple-system, "Segoe UI", Helvetica, Arial, sans-serif; }
  .grid { display: grid; grid-template-columns: repeat(<?= count($columns) ?>, <?= $width ?>px); gap: 12px 8px; }
  .head { font: 600 12px ui-monospace, Consolas, monospace; text-align: center; }
  .cell { width: <?= $width ?>px; height: <?= $height ?>px; border-radius: 6px;
          background-size: cover; background-position: center; }
  .empty { border: 1px dashed #d0d7de; box-sizing: border-box; }
  .label { margin-top: 4px; text-align: center; color: #57606a;
           font: 11px ui-monospace, Consolas, monospace; }
  .note { margin-top: 14px; color: #57606a; font-size: 12px; }
</style>
<div class="grid">
<?php foreach ($columns as [$title]): ?>
  <div class="head"><?= htmlspecialchars($title) ?></div>
<?php endforeach ?>
<?php foreach ($rows as $cells): ?>
<?php foreach ($cells as [$css, $label]): ?>
<?php if ($css === null): ?>
  <div><div class="cell empty"></div><div class="label"><?= htmlspecialchars($label) ?></div></div>
<?php else: ?>
  <div><div class="cell" style="background-image:<?= htmlspecialchars($css) ?>"></div><div class="label"><?= htmlspecialchars($label) ?>&nbsp;</div></div>
<?php endif ?>
<?php endforeach ?>
<?php endforeach ?>
</div>
<div class="note">Each placeholder is the library's real output at default options, drawn by the browser at the photo's size.
* Needs a decoder on the site: css_only shows an approximate rendering of what the integer carries, thumbhash shows what a ThumbHash decoder draws.</div>
