<?php

/**
 * Renders the README's mode comparison as an HTML page.
 *
 *     php docs/render-examples.php > modes.html
 *     chrome --headless --hide-scrollbars --screenshot=modes.png \
 *            --window-size=1064,760 modes.html
 *     magick modes.png -strip -sampling-factor 4:4:4 -quality 90 docs/images/modes.jpg
 *
 * JPEG rather than PNG because the screenshot is mostly smooth gradients:
 * 136 KB against 444 KB, with no visible difference.
 *
 * Every placeholder cell uses the exact value this library returns as its CSS
 * background-image, so the screenshot is what a browser actually draws - not a
 * mock-up. The one exception is css_only: its decode stylesheet is supplied by
 * the consuming site, not this package, so that column draws the six decoded
 * cells over the base colour with gradients of its own. It shows what the
 * integer carries, approximately, and is labelled as such.
 */

require __DIR__ . '/../vendor/autoload.php';

use Bonniss26\Lqip\PlaceholderGenerator;
use Bonniss26\Lqip\Support\LqipInteger;

const CELL_WIDTH = 200;
const CELL_HEIGHT = 133;

$photos = glob(__DIR__ . '/images/source/*.jpg');
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

$columns = [
    'original' => 'Original',
    'dominant_color' => 'dominant_color',
    'gradient' => 'gradient',
    'blur' => 'blur',
    'css_only' => 'css_only*',
];

$rows = [];
foreach ($photos as $path) {
    $bytes = file_get_contents($path);
    $cells = ['original' => ["url('data:image/jpeg;base64," . base64_encode($bytes) . "')", '']];

    foreach (['dominant_color', 'gradient', 'blur', 'css_only'] as $mode) {
        $result = PlaceholderGenerator::resultForBytes($bytes, ['mode' => $mode]);
        if ($result === null) {
            fwrite(STDERR, "no {$mode} placeholder for {$path}\n");
            exit(1);
        }
        $value = $result->value();
        $css = $mode === 'css_only' ? approximateCssOnly((int) $value) : $value;
        $label = $mode === 'css_only' ? "--lqip:{$value}" : strlen($value) . ' chars';
        $cells[$mode] = [$css, $label];
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
  .grid { display: grid; grid-template-columns: repeat(5, <?= $width ?>px); gap: 12px 8px; }
  .head { font: 600 13px ui-monospace, Consolas, monospace; text-align: center; }
  .cell { width: <?= $width ?>px; height: <?= $height ?>px; border-radius: 6px;
          background-size: cover; background-position: center; }
  .label { margin-top: 4px; text-align: center; color: #57606a;
           font: 12px ui-monospace, Consolas, monospace; }
  .note { margin-top: 14px; color: #57606a; font-size: 12px; }
</style>
<div class="grid">
<?php foreach ($columns as $title): ?>
  <div class="head"><?= htmlspecialchars($title) ?></div>
<?php endforeach ?>
<?php foreach ($rows as $cells): ?>
<?php foreach ($cells as [$css, $label]): ?>
  <div><div class="cell" style="background-image:<?= htmlspecialchars($css) ?>"></div><div class="label"><?= htmlspecialchars($label) ?>&nbsp;</div></div>
<?php endforeach ?>
<?php endforeach ?>
</div>
<div class="note">Each placeholder is the library's real output rendered by the browser at the photo's size, using the default options.
* css_only needs a decode stylesheet supplied by the site; this column is an approximate rendering of what the integer carries.</div>
