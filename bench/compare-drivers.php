<?php

/**
 * Compares the built-in drivers on a folder of real images.
 *
 *     php bench/compare-drivers.php /path/to/photos [mode]
 *
 * For every driver available in this PHP: median and p90 generation time,
 * median output length, and - when more than one driver is available - how
 * far apart their outputs are (RGB distance between the dominant colours).
 * Each image is generated once per driver after one untimed warm-up call, so
 * the numbers are steady-state rather than first-call.
 *
 * Not part of the test suite: timings depend on the machine, and the photos
 * are yours. Nothing is written anywhere.
 */

require __DIR__ . '/../vendor/autoload.php';

use Bonniss26\Lqip\PlaceholderGenerator;

$dir = $argv[1] ?? null;
$mode = $argv[2] ?? 'dominant_color';
if (!$dir || !is_dir($dir)) {
    fwrite(STDERR, "usage: php bench/compare-drivers.php <image-folder> [mode]\n");
    exit(1);
}

ini_set('memory_limit', '1G');

$files = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (preg_match('/\.(jpe?g|png|webp|gif)$/i', $file->getFilename())) {
        $files[] = $file->getPathname();
    }
}
sort($files);

$drivers = PlaceholderGenerator::availableDrivers();
printf("PHP %s, drivers: %s, mode: %s, %d images\n\n", PHP_VERSION, implode(', ', $drivers), $mode, count($files));

$times = $lengths = $megapixels = [];
$outputs = [];

foreach ($files as $path) {
    $bytes = file_get_contents($path);
    $size = @getimagesizefromstring($bytes);
    if (!$size) {
        continue;
    }
    $megapixels[] = $size[0] * $size[1] / 1e6;

    foreach ($drivers as $driver) {
        $options = ['mode' => $mode, 'driver' => $driver];
        PlaceholderGenerator::resultForBytes($bytes, $options); // warm-up

        $start = hrtime(true);
        $result = PlaceholderGenerator::resultForBytes($bytes, $options);
        $times[$driver][] = (hrtime(true) - $start) / 1e6;

        $lengths[$driver][] = $result ? strlen($result->value()) : 0;
        $outputs[$path][$driver] = $result ? $result->value() : null;
    }
}

$median = function (array $values) {
    sort($values);

    return $values[intdiv(count($values), 2)];
};
$percentile = function (array $values, float $p) {
    sort($values);

    return $values[min(count($values) - 1, (int) floor(count($values) * $p))];
};

printf("source megapixels: median %.1f, max %.1f\n\n", $median($megapixels), max($megapixels));
printf("%-8s %12s %12s %12s %14s\n", 'driver', 'median ms', 'p90 ms', 'ms / MP', 'median chars');
foreach ($drivers as $driver) {
    $perMp = [];
    foreach ($times[$driver] as $i => $ms) {
        $perMp[] = $ms / max(0.01, $megapixels[$i]);
    }
    printf(
        "%-8s %12.1f %12.1f %12.1f %14d\n",
        $driver,
        $median($times[$driver]),
        $percentile($times[$driver], 0.9),
        $median($perMp),
        $median($lengths[$driver])
    );
}

if (count($drivers) > 1 && in_array($mode, ['dominant_color', 'gradient'], true)) {
    $distances = [];
    foreach ($outputs as $byDriver) {
        preg_match_all('/#([0-9a-f]{6})/', (string) $byDriver[$drivers[0]], $a);
        preg_match_all('/#([0-9a-f]{6})/', (string) $byDriver[$drivers[1]], $b);
        foreach ($a[1] as $i => $hex) {
            if (!isset($b[1][$i])) {
                continue;
            }
            [$r1, $g1, $b1] = sscanf($hex, '%02x%02x%02x');
            [$r2, $g2, $b2] = sscanf($b[1][$i], '%02x%02x%02x');
            $distances[] = sqrt(($r1 - $r2) ** 2 + ($g1 - $g2) ** 2 + ($b1 - $b2) ** 2);
        }
    }
    printf(
        "\n%s vs %s colour distance (RGB): median %.1f, p90 %.1f, max %.1f\n",
        $drivers[0],
        $drivers[1],
        $median($distances),
        $percentile($distances, 0.9),
        max($distances)
    );
}
