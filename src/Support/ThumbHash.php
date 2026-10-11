<?php namespace Bonniss26\Lqip\Support;

/**
 * ThumbHash: a whole placeholder in ~25 bytes, decoded on the client.
 *
 * A PHP port of Evan Wallace's reference implementation
 * (https://github.com/evanw/thumbhash, MIT, Copyright (c) 2023 Evan Wallace),
 * kept arithmetic-for-arithmetic so that a hash from here is byte-identical to
 * one from the reference - decoders for JS, Swift, Kotlin and Rust then all
 * read it the same way. The tests check that against an independent PHP
 * port.
 *
 * The image is converted to an average colour plus luminance, two chroma
 * channels and (if present) alpha, each reduced to a few low-frequency DCT
 * coefficients quantized to 4 bits. It carries the approximate aspect ratio
 * and transparency, which BlurHash does not.
 *
 * Rounding goes through jsRound(), not PHP's round(): see there.
 *
 * The reference implementation's licence, which covers this port:
 *
 *   Copyright (c) 2023 Evan Wallace
 *
 *   Permission is hereby granted, free of charge, to any person obtaining a
 *   copy of this software and associated documentation files (the
 *   "Software"), to deal in the Software without restriction, including
 *   without limitation the rights to use, copy, modify, merge, publish,
 *   distribute, sublicense, and/or sell copies of the Software, and to permit
 *   persons to whom the Software is furnished to do so, subject to the
 *   following conditions:
 *
 *   The above copyright notice and this permission notice shall be included
 *   in all copies or substantial portions of the Software.
 *
 *   THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS
 *   OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
 *   MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN
 *   NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM,
 *   DAMAGES OR OTHER LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR
 *   OTHERWISE, ARISING FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE
 *   USE OR OTHER DEALINGS IN THE SOFTWARE.
 */
final class ThumbHash
{
    /** The reference rejects anything larger: slower, and no better. */
    const MAX_SIZE = 100;

    /**
     * @return string The hash, as raw bytes.
     */
    public static function encode(Raster $raster): string
    {
        $w = $raster->width();
        $h = $raster->height();
        if ($w > self::MAX_SIZE || $h > self::MAX_SIZE) {
            throw new \InvalidArgumentException("{$w}x{$h} doesn't fit in 100x100");
        }

        $count = $w * $h;
        $rgb = [];
        $alphas = [];
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $rgb[] = $raster->channelsAt($x, $y);
                $alphas[] = $raster->alphaAt($x, $y) / 255;
            }
        }

        // The average colour, weighted by alpha.
        $avgR = $avgG = $avgB = $avgA = 0.0;
        foreach ($rgb as $i => [$r, $g, $b]) {
            $alpha = $alphas[$i];
            $avgR += $alpha / 255 * $r;
            $avgG += $alpha / 255 * $g;
            $avgB += $alpha / 255 * $b;
            $avgA += $alpha;
        }
        if ($avgA > 0) {
            $avgR /= $avgA;
            $avgG /= $avgA;
            $avgB /= $avgA;
        }

        $hasAlpha = $avgA < $count;
        $lLimit = $hasAlpha ? 5 : 7; // fewer luminance bits when alpha needs room
        $lx = max(1, self::jsRound($lLimit * $w / max($w, $h)));
        $ly = max(1, self::jsRound($lLimit * $h / max($w, $h)));

        // RGBA to LPQA, composited atop the average colour.
        $l = $p = $q = $a = [];
        foreach ($rgb as $i => [$r8, $g8, $b8]) {
            $alpha = $alphas[$i];
            $r = $avgR * (1 - $alpha) + $alpha / 255 * $r8;
            $g = $avgG * (1 - $alpha) + $alpha / 255 * $g8;
            $b = $avgB * (1 - $alpha) + $alpha / 255 * $b8;
            $l[] = ($r + $g + $b) / 3;
            $p[] = ($r + $g) / 2 - $b;
            $q[] = $r - $g;
            $a[] = $alpha;
        }

        [$lDc, $lAc, $lScale] = self::encodeChannel($l, max(3, $lx), max(3, $ly), $w, $h);
        [$pDc, $pAc, $pScale] = self::encodeChannel($p, 3, 3, $w, $h);
        [$qDc, $qAc, $qScale] = self::encodeChannel($q, 3, 3, $w, $h);
        [$aDc, $aAc, $aScale] = $hasAlpha ? self::encodeChannel($a, 5, 5, $w, $h) : [0.0, [], 0.0];

        $isLandscape = $w > $h;
        $header24 = self::jsRound(63 * $lDc)
            | (self::jsRound(31.5 + 31.5 * $pDc) << 6)
            | (self::jsRound(31.5 + 31.5 * $qDc) << 12)
            | (self::jsRound(31 * $lScale) << 18)
            | ((int) $hasAlpha << 23);
        $header16 = ($isLandscape ? $ly : $lx)
            | (self::jsRound(63 * $pScale) << 3)
            | (self::jsRound(63 * $qScale) << 9)
            | ((int) $isLandscape << 15);

        $hash = [$header24 & 255, ($header24 >> 8) & 255, $header24 >> 16, $header16 & 255, $header16 >> 8];
        if ($hasAlpha) {
            $hash[] = self::jsRound(15 * $aDc) | (self::jsRound(15 * $aScale) << 4);
        }

        $acStart = $hasAlpha ? 6 : 5;
        $acIndex = 0;
        foreach ($hasAlpha ? [$lAc, $pAc, $qAc, $aAc] : [$lAc, $pAc, $qAc] as $ac) {
            foreach ($ac as $f) {
                $byte = $acStart + ($acIndex >> 1);
                $hash[$byte] = ($hash[$byte] ?? 0) | (self::jsRound(15 * $f) << (($acIndex & 1) << 2));
                $acIndex++;
            }
        }

        return pack('C*', ...$hash);
    }

    /**
     * Renders a hash back to a small image (32px on its long edge), as the
     * reference decoder does. For previews and tests; clients usually decode
     * with their own library.
     */
    public static function decode(string $hash): Raster
    {
        $bytes = array_values(unpack('C*', $hash));
        if (count($bytes) < 5) {
            throw new \InvalidArgumentException('A ThumbHash is at least 5 bytes.');
        }

        $header24 = $bytes[0] | ($bytes[1] << 8) | ($bytes[2] << 16);
        $header16 = $bytes[3] | ($bytes[4] << 8);
        $lDc = ($header24 & 63) / 63;
        $pDc = (($header24 >> 6) & 63) / 31.5 - 1;
        $qDc = (($header24 >> 12) & 63) / 31.5 - 1;
        $lScale = (($header24 >> 18) & 31) / 31;
        $hasAlpha = (bool) ($header24 >> 23);
        $pScale = (($header16 >> 3) & 63) / 63;
        $qScale = (($header16 >> 9) & 63) / 63;
        $isLandscape = (bool) ($header16 >> 15);
        $lx = max(3, $isLandscape ? ($hasAlpha ? 5 : 7) : $header16 & 7);
        $ly = max(3, $isLandscape ? $header16 & 7 : ($hasAlpha ? 5 : 7));
        $aDc = $hasAlpha ? (($bytes[5] ?? 0) & 15) / 15 : 1.0;
        $aScale = (($bytes[5] ?? 0) >> 4) / 15;

        // Saturation is boosted 1.25x to make up for quantization, as in the reference.
        $acIndex = 0;
        $acStart = $hasAlpha ? 6 : 5;
        $lAc = self::decodeChannel($bytes, $acStart, $acIndex, $lx, $ly, $lScale);
        $pAc = self::decodeChannel($bytes, $acStart, $acIndex, 3, 3, $pScale * 1.25);
        $qAc = self::decodeChannel($bytes, $acStart, $acIndex, 3, 3, $qScale * 1.25);
        $aAc = $hasAlpha ? self::decodeChannel($bytes, $acStart, $acIndex, 5, 5, $aScale) : [];

        $ratio = self::aspectRatio($hash);
        $w = self::jsRound($ratio > 1 ? 32 : 32 * $ratio);
        $h = self::jsRound($ratio > 1 ? 32 / $ratio : 32);

        $pixels = $alphas = [];
        $fx = $fy = [];
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $l = $lDc;
                $p = $pDc;
                $q = $qDc;
                $a = $aDc;

                for ($cx = 0, $n = max($lx, $hasAlpha ? 5 : 3); $cx < $n; $cx++) {
                    $fx[$cx] = cos(M_PI / $w * ($x + 0.5) * $cx);
                }
                for ($cy = 0, $n = max($ly, $hasAlpha ? 5 : 3); $cy < $n; $cy++) {
                    $fy[$cy] = cos(M_PI / $h * ($y + 0.5) * $cy);
                }

                for ($cy = 0, $j = 0; $cy < $ly; $cy++) {
                    for ($cx = $cy ? 0 : 1, $fy2 = $fy[$cy] * 2; $cx * $ly < $lx * ($ly - $cy); $cx++, $j++) {
                        $l += $lAc[$j] * $fx[$cx] * $fy2;
                    }
                }

                for ($cy = 0, $j = 0; $cy < 3; $cy++) {
                    for ($cx = $cy ? 0 : 1, $fy2 = $fy[$cy] * 2; $cx < 3 - $cy; $cx++, $j++) {
                        $f = $fx[$cx] * $fy2;
                        $p += $pAc[$j] * $f;
                        $q += $qAc[$j] * $f;
                    }
                }

                if ($hasAlpha) {
                    for ($cy = 0, $j = 0; $cy < 5; $cy++) {
                        for ($cx = $cy ? 0 : 1, $fy2 = $fy[$cy] * 2; $cx < 5 - $cy; $cx++, $j++) {
                            $a += $aAc[$j] * $fx[$cx] * $fy2;
                        }
                    }
                }

                $b = $l - 2 / 3 * $p;
                $r = (3 * $l - $b + $q) / 2;
                $g = $r - $q;

                // A Uint8Array store truncates, so (int) - every value is >= 0.
                $pixels[] = ((int) max(0, 255 * min(1, $r)) << 16)
                    | ((int) max(0, 255 * min(1, $g)) << 8)
                    | (int) max(0, 255 * min(1, $b));
                $alphas[] = (int) max(0, 255 * min(1, $a));
            }
        }

        return new Raster($w, $h, $pixels, $alphas);
    }

    /** The approximate width / height the hash carries. */
    public static function aspectRatio(string $hash): float
    {
        $header = ord($hash[3]);
        $hasAlpha = ord($hash[2]) & 0x80;
        $isLandscape = ord($hash[4]) & 0x80;
        $lx = $isLandscape ? ($hasAlpha ? 5 : 7) : $header & 7;
        $ly = $isLandscape ? $header & 7 : ($hasAlpha ? 5 : 7);

        return $lx / $ly;
    }

    /**
     * DCT of one channel into a DC term and AC terms normalised to 0-1.
     *
     * @param float[] $channel
     * @return array{0:float,1:float[],2:float} [dc, ac, scale]
     */
    private static function encodeChannel(array $channel, int $nx, int $ny, int $w, int $h): array
    {
        $dc = 0.0;
        $ac = [];
        $scale = 0.0;
        $fx = [];

        for ($cy = 0; $cy < $ny; $cy++) {
            for ($cx = 0; $cx * $ny < $nx * ($ny - $cy); $cx++) {
                $f = 0.0;
                for ($x = 0; $x < $w; $x++) {
                    $fx[$x] = cos(M_PI / $w * $cx * ($x + 0.5));
                }
                for ($y = 0; $y < $h; $y++) {
                    $fy = cos(M_PI / $h * $cy * ($y + 0.5));
                    for ($x = 0; $x < $w; $x++) {
                        $f += $channel[$x + $y * $w] * $fx[$x] * $fy;
                    }
                }
                $f /= $w * $h;

                if ($cx || $cy) {
                    $ac[] = $f;
                    $scale = max($scale, abs($f));
                } else {
                    $dc = $f;
                }
            }
        }

        if ($scale > 0) {
            foreach ($ac as $i => $value) {
                $ac[$i] = 0.5 + 0.5 / $scale * $value;
            }
        }

        return [$dc, $ac, $scale];
    }

    /**
     * JavaScript's Math.round(): the nearest integer, halves up.
     *
     * PHP's round() is not that. Before 8.4 it "pre-rounds" to 15 significant
     * digits, so round(7.499999999999999) is 8 where Math.round gives 7, and
     * one such value in a DCT coefficient flips a nibble of the hash. Found by
     * checking hashes against the JS reference itself; srwiez/thumbhash uses
     * round() and shares the discrepancy, so agreeing with it was not enough.
     * x - floor(x) is exact for doubles of this size, so this has no such edge.
     */
    private static function jsRound(float $x): int
    {
        $floor = floor($x);

        return (int) ($x - $floor >= 0.5 ? $floor + 1 : $floor);
    }

    /**
     * @param int[] $bytes
     * @return float[]
     */
    private static function decodeChannel(array $bytes, int $acStart, int &$acIndex, int $nx, int $ny, float $scale): array
    {
        $ac = [];
        for ($cy = 0; $cy < $ny; $cy++) {
            for ($cx = $cy ? 0 : 1; $cx * $ny < $nx * ($ny - $cy); $cx++) {
                $byte = $bytes[$acStart + ($acIndex >> 1)] ?? 0;
                $ac[] = ((($byte >> (($acIndex & 1) << 2)) & 15) / 7.5 - 1) * $scale;
                $acIndex++;
            }
        }

        return $ac;
    }
}
