<?php namespace Bonniss26\Lqip\Tests\Support;

use Bonniss26\Lqip\Support\Raster;
use Bonniss26\Lqip\Support\ThumbHash;
use PHPUnit\Framework\TestCase;

/**
 * Checked against Evan Wallace's JS reference (js/thumbhash.js), the
 * implementation every other decoder follows. The expected values below are
 * its output for exactly these rasters, produced with Node.
 *
 * Not against another PHP port: srwiez/thumbhash rounds with PHP's round(),
 * which pre-rounds to 15 digits and disagrees with Math.round - agreeing with
 * it hid exactly the bug ThumbHash::jsRound() fixes.
 */
class ThumbHashTest extends TestCase
{
    /**
     * name => [hash from the JS reference, decoded width, decoded height,
     *          [[x, y, [r, g, b, a]] from the JS reference's decoder, ...]]
     */
    const REFERENCE = [
        'landscape' => ['3/cJPJqAh4dwd3eHh3dwgAj4eA==', 32, 18, [[0, 0, [2, 0, 140, 255]], [31, 0, [251, 2, 135, 255]], [0, 17, [1, 255, 148, 255]], [31, 17, [240, 235, 96, 255]], [16, 9, [124, 133, 123, 255]]]],
        'portrait' => ['HwgKPBxwB4d3d4aHeHeAgAj3eA==', 18, 32, [[0, 0, [0, 0, 120, 255]], [17, 0, [248, 0, 111, 255]], [0, 31, [0, 255, 123, 255]], [17, 31, [241, 247, 84, 255]], [9, 16, [150, 122, 132, 255]]]],
        'square' => ['HwgKPxqAd3dwd3d3d3eHd4d3BxiYgH8H', 32, 32, [[0, 0, [0, 0, 88, 255]], [31, 0, [255, 3, 113, 255]], [0, 31, [1, 251, 117, 255]], [31, 31, [235, 240, 78, 255]], [16, 16, [126, 122, 132, 255]]]],
        'with alpha' => ['XKeFHI43Q3dAd3d3JwOFQ7/3iIiIeId4Bw==', 32, 26, [[0, 0, [18, 39, 127, 254]], [31, 0, [93, 95, 128, 37]], [0, 25, [0, 226, 130, 200]], [31, 25, [86, 163, 119, 10]], [16, 13, [115, 125, 121, 122]]]],
        'portrait with alpha' => ['W6eFGw43M0B3Z4dDQDnze4+Ih4h3eIg=', 19, 32, [[0, 0, [13, 43, 119, 253]], [18, 0, [84, 95, 115, 10]], [0, 31, [0, 236, 119, 226]], [18, 31, [81, 167, 118, 64]], [9, 16, [117, 118, 128, 138]]]],
        'solid' => ['WjsDBwBImT6IeHeId0d3iHiPg/V4T4YO', 32, 32, [[0, 0, [220, 62, 32, 255]], [31, 0, [220, 62, 32, 255]], [0, 31, [220, 62, 32, 255]], [31, 31, [220, 62, 32, 255]], [16, 16, [220, 62, 32, 255]]]],
        'fully transparent' => ['AAiCBQAAAAAAAAAAAAAAAAAAAAAAAAAAAA==', 32, 32, [[0, 0, [3, 0, 0, 0]], [31, 0, [3, 0, 0, 0]], [0, 31, [3, 0, 0, 0]], [31, 31, [3, 0, 0, 0]], [16, 16, [3, 0, 0, 0]]]],
        'reference maximum' => ['3wcKNZpwd3dweId3d3eHd4CACPh3', 32, 23, [[0, 0, [0, 0, 105, 255]], [31, 0, [255, 0, 125, 255]], [0, 22, [0, 228, 104, 255]], [31, 22, [223, 227, 96, 255]], [16, 11, [131, 121, 135, 255]]]],
    ];

    public static function rasters(): array
    {
        $gradient = function (int $w, int $h, bool $alpha): Raster {
            $pixels = $alphas = [];
            for ($y = 0; $y < $h; $y++) {
                for ($x = 0; $x < $w; $x++) {
                    $pixels[] = ((int) (255 * $x / max(1, $w - 1)) << 16)
                        | ((int) (255 * $y / max(1, $h - 1)) << 8)
                        | (($x * 37 + $y * 11) % 256);
                    $alphas[] = $alpha ? (int) (255 * (1 - $x / max(1, $w - 1))) : 255;
                }
            }

            return new Raster($w, $h, $pixels, $alphas);
        };

        $rasters = [
            'landscape' => $gradient(32, 20, false),
            'portrait' => $gradient(18, 32, false),
            'square' => $gradient(24, 24, false),
            'with alpha' => $gradient(32, 24, true),
            'portrait with alpha' => $gradient(20, 32, true),
            'solid' => new Raster(8, 8, array_fill(0, 64, 0xE04020)),
            'fully transparent' => new Raster(4, 4, array_fill(0, 16, 0), array_fill(0, 16, 0)),
            'reference maximum' => $gradient(100, 75, false),
        ];

        $cases = [];
        foreach ($rasters as $name => $raster) {
            $cases[$name] = [$raster, self::REFERENCE[$name]];
        }

        return $cases;
    }

    /**
     * Images whose hash depends on the last bit of cos(), so may differ
     * between V8 and a C library, or between two C libraries:
     *
     * - 'portrait with alpha' is symmetric enough to put a coefficient exactly
     *   on .5, where that last bit picks the rounding direction (one nibble,
     *   one step, on Windows);
     * - a flat image's AC terms are zero in exact arithmetic but ~1e-17 in
     *   floating point, and the reference normalises them by their own
     *   maximum - noise divided by noise. They decode to nothing either way,
     *   because the scale they are multiplied by quantizes to 0.
     *
     * Of 341 hashes of real photos and random images checked against the
     * reference while writing this, none was affected.
     */
    const PLATFORM_DEPENDENT = ['portrait with alpha', 'solid', 'fully transparent'];

    /** @dataProvider rasters */
    public function testEncodesAsTheReference(Raster $raster, array $reference): void
    {
        $actual = ThumbHash::encode($raster);
        $expected = base64_decode($reference[0]);

        if (!in_array($this->dataName(), self::PLATFORM_DEPENDENT, true)) {
            $this->assertSame(base64_encode($expected), base64_encode($actual));

            return;
        }

        // Then what has to hold is that both hashes show the same image, to
        // within one quantization step of a coefficient: a nibble is a
        // fifteenth of the channel's scale, which moved one decoded alpha
        // value by 13/255 here.
        $mine = ThumbHash::decode($actual)->toRgbaBytes();
        $theirs = ThumbHash::decode($expected)->toRgbaBytes();
        $this->assertCount(count($theirs), $mine);
        foreach ($theirs as $i => $value) {
            $this->assertEqualsWithDelta($value, $mine[$i], 16, "channel value {$i}");
        }
    }

    /** @dataProvider rasters */
    public function testDecodesAsTheReference(Raster $raster, array $reference): void
    {
        [$hash, $width, $height, $pixels] = $reference;
        $decoded = ThumbHash::decode(base64_decode($hash));

        $this->assertSame([$width, $height], [$decoded->width(), $decoded->height()]);
        foreach ($pixels as [$x, $y, $rgba]) {
            $actual = array_merge($decoded->channelsAt($x, $y), [$decoded->alphaAt($x, $y)]);
            foreach ($rgba as $channel => $value) {
                $this->assertEqualsWithDelta($value, $actual[$channel], 1, "pixel {$x},{$y} channel {$channel}");
            }
        }
    }

    public function testJsRoundingIsNotPhpRounding(): void
    {
        // round(7.499999999999999) is 8 in PHP < 8.4; Math.round gives 7.
        $method = new \ReflectionMethod(ThumbHash::class, 'jsRound');
        if (PHP_VERSION_ID < 80100) {
            $method->setAccessible(true); // needed before 8.1, deprecated in 8.5
        }

        $this->assertSame(7, $method->invoke(null, 7.499999999999999));
        $this->assertSame(8, $method->invoke(null, 7.5));
        $this->assertSame(0, $method->invoke(null, 0.49999999999999994));
    }

    public function testCarriesTheApproximateAspectRatio(): void
    {
        $landscape = ThumbHash::encode(new Raster(32, 16, array_fill(0, 512, 0x808080)));
        $portrait = ThumbHash::encode(new Raster(16, 32, array_fill(0, 512, 0x808080)));

        $this->assertGreaterThan(1.5, ThumbHash::aspectRatio($landscape));
        $this->assertLessThan(0.67, ThumbHash::aspectRatio($portrait));
    }

    public function testRejectsWhatTheReferenceRejects(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        ThumbHash::encode(new Raster(101, 1, array_fill(0, 101, 0)));
    }
}
