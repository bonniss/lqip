<?php namespace Bonniss26\Lqip\Tests;

use Bonniss26\Lqip\Drivers\Driver;
use Bonniss26\Lqip\Drivers\Image;
use Bonniss26\Lqip\Exceptions\NotCssRepresentable;
use Bonniss26\Lqip\LqipResult;
use Bonniss26\Lqip\PlaceholderGenerator;
use Bonniss26\Lqip\Strategies\LqipStrategy;
use Bonniss26\Lqip\Support\LqipInteger;
use PHPUnit\Framework\TestCase;

class PlaceholderGeneratorTest extends TestCase
{
    public function testDominantColorReturnsFlatColorForSolidImage(): void
    {
        $bytes = $this->makeSolidImagePng(0xE0, 0x40, 0x20);

        $result = PlaceholderGenerator::forBytes($bytes, ['mode' => 'dominant_color']);

        $this->assertMatchesRegularExpression(
            '/^linear-gradient\(#[0-9a-f]{6},#[0-9a-f]{6}\)$/',
            $result
        );

        preg_match('/^linear-gradient\((#[0-9a-f]{6}),(#[0-9a-f]{6})\)$/', $result, $matches);
        $this->assertSame($matches[1], $matches[2], 'both gradient stops must be the same color');
        $this->assertHexCloseTo('#e04020', $matches[1]);
    }

    /**
     * Exact-output pin, deliberately stricter than assertHexCloseTo above.
     * The dominant-colour computation is shared with other strategies, so a
     * refactor of it must not shift these bytes at all - a tolerance-based
     * assertion is too loose to notice.
     */
    public function testDominantColorProducesExactKnownOutput(): void
    {
        $cases = [
            [0xE0, 0x40, 0x20, 'linear-gradient(#e04020,#e04020)'],
            [0x00, 0xAA, 0xFF, 'linear-gradient(#00aaff,#00aaff)'],
            [0x10, 0x20, 0x30, 'linear-gradient(#102030,#102030)'],
        ];

        foreach ($cases as [$r, $g, $b, $expected]) {
            $this->assertSame(
                $expected,
                PlaceholderGenerator::forBytes($this->makeSolidImagePng($r, $g, $b), ['mode' => 'dominant_color']),
                sprintf('solid #%02x%02x%02x', $r, $g, $b)
            );
        }
    }

    /**
     * When two colours cover exactly the same number of pixels, the tie goes to
     * whichever is met first in DominantColor's column-major scan - i.e. the
     * leftmost colour wins. Flipping the scan order during a refactor would
     * silently flip the result here.
     */
    public function testDominantColorResolvesTiesByScanOrder(): void
    {
        $this->assertSame(
            'linear-gradient(#ff0000,#ff0000)',
            PlaceholderGenerator::forBytes(
                $this->makeVerticalSplitPng([0xFF, 0x00, 0x00], [0x00, 0x00, 0xFF]),
                ['mode' => 'dominant_color']
            ),
            'red on the left must win the 32/32 tie'
        );

        $this->assertSame(
            'linear-gradient(#0000ff,#0000ff)',
            PlaceholderGenerator::forBytes(
                $this->makeVerticalSplitPng([0x00, 0x00, 0xFF], [0xFF, 0x00, 0x00]),
                ['mode' => 'dominant_color']
            ),
            'blue on the left must win the same tie once the halves swap'
        );
    }

    public function testGradientReturnsFourRadialGradientsSampledFromCorners(): void
    {
        $bytes = $this->makeQuadrantImagePng();

        $result = PlaceholderGenerator::forBytes($bytes, ['mode' => 'gradient']);

        $pattern = '/^'
            . 'radial-gradient\(at 25% 25%,(#[0-9a-f]{6}) 0px,transparent 60%\),'
            . 'radial-gradient\(at 75% 25%,(#[0-9a-f]{6}) 0px,transparent 60%\),'
            . 'radial-gradient\(at 25% 75%,(#[0-9a-f]{6}) 0px,transparent 60%\),'
            . 'radial-gradient\(at 75% 75%,(#[0-9a-f]{6}) 0px,transparent 60%\),'
            . 'linear-gradient\((#[0-9a-f]{6}),\1\)'
            . '$/';

        $this->assertMatchesRegularExpression($pattern, $result);

        preg_match($pattern, $result, $matches);
        [, $topLeft, $topRight, $bottomLeft, $bottomRight] = $matches;

        $this->assertHexCloseTo('#ff0000', $topLeft, 40);
        $this->assertHexCloseTo('#00ff00', $topRight, 40);
        $this->assertHexCloseTo('#0000ff', $bottomLeft, 40);
        $this->assertHexCloseTo('#ffff00', $bottomRight, 40);
    }

    public function testBlurReturnsDataUri(): void
    {
        $bytes = $this->makeQuadrantImagePng(32);

        $result = PlaceholderGenerator::forBytes($bytes, ['mode' => 'blur']);

        $expectedMime = PlaceholderGenerator::resolveDriver('auto')->supportsFormat('webp') ? 'webp' : 'jpeg';
        $this->assertMatchesRegularExpression(
            "/^url\\('data:image\\/{$expectedMime};base64,[A-Za-z0-9+\\/=]+'\\)$/",
            $result
        );
    }

    public function testBlurRespectsExplicitJpegFormat(): void
    {
        $bytes = $this->makeSolidImagePng(0x10, 0x20, 0x30);

        $result = PlaceholderGenerator::forBytes($bytes, ['mode' => 'blur', 'format' => 'jpg']);

        $this->assertMatchesRegularExpression(
            "/^url\\('data:image\\/jpeg;base64,[A-Za-z0-9+\\/=]+'\\)$/",
            $result
        );
    }

    public function testBlurWithoutGaussianBlurStillProducesOutput(): void
    {
        $bytes = $this->makeSolidImagePng(0x10, 0x20, 0x30);

        $result = PlaceholderGenerator::forBytes($bytes, [
            'mode' => 'blur',
            'blur' => false,
            'format' => 'jpg',
        ]);

        $this->assertMatchesRegularExpression(
            "/^url\\('data:image\\/jpeg;base64,[A-Za-z0-9+\\/=]+'\\)$/",
            $result
        );
    }

    public function testDefaultQualityIsTunedForTinyPlaceholders(): void
    {
        $this->assertSame(25, PlaceholderGenerator::DEFAULT_OPTIONS['quality']);
    }

    public function testHigherQualityProducesALongerDataUri(): void
    {
        // Needs an image with real detail: on a solid or near-solid source there is
        // nothing for the encoder to spend the extra bits on and the two sizes
        // converge, which would make this flaky. Blur is off for the same reason.
        $bytes = $this->makeQuadrantImagePng(32);

        $low = PlaceholderGenerator::forBytes($bytes, ['mode' => 'blur', 'blur' => false, 'quality' => 25]);
        $high = PlaceholderGenerator::forBytes($bytes, ['mode' => 'blur', 'blur' => false, 'quality' => 90]);

        $this->assertLessThan(strlen($high), strlen($low));
    }

    public function testForFileReadsFromDisk(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'lqip') . '.png';
        file_put_contents($path, $this->makeSolidImagePng(0x00, 0xAA, 0xFF));

        try {
            $result = PlaceholderGenerator::forFile($path, ['mode' => 'dominant_color']);

            $this->assertMatchesRegularExpression('/^linear-gradient\(#[0-9a-f]{6},#[0-9a-f]{6}\)$/', $result);
        } finally {
            unlink($path);
        }
    }

    public function testForFileReturnsNullForMissingFile(): void
    {
        $this->assertNull(PlaceholderGenerator::forFile('/does/not/exist.png'));
    }

    public function testForFileReturnsNullForEmptyPath(): void
    {
        $this->assertNull(PlaceholderGenerator::forFile(''));
    }

    public function testForBytesReturnsNullForEmptyInput(): void
    {
        $this->assertNull(PlaceholderGenerator::forBytes(''));
    }

    public function testForBytesReturnsNullForGarbageInput(): void
    {
        $this->assertNull(PlaceholderGenerator::forBytes('not an image'));
    }

    /**
     * A directory passes is_readable(), so without an is_file() check this reaches
     * file_get_contents() and emits a PHP warning. Frameworks that promote warnings
     * to exceptions - Laravel and everything built on it - would then turn a merely
     * unusable path into a fatal request error, which is exactly what this class
     * promises never to do.
     */
    public function testForFileReturnsNullForADirectoryWithoutEmittingAWarning(): void
    {
        $raised = null;
        set_error_handler(function ($level, $message) use (&$raised) {
            $raised = $message;

            return true;
        });

        try {
            $result = PlaceholderGenerator::forFile(sys_get_temp_dir());
        } finally {
            restore_error_handler();
        }

        $this->assertNull($result);
        $this->assertNull($raised, "expected no PHP diagnostic, got: {$raised}");
    }

    /**
     * imagewebp() raises an uncatchable E_ERROR on a negative quality, so an
     * unvalidated settings value would take the whole process down rather than
     * returning null. Clamping is what keeps every failure recoverable.
     */
    public function testOutOfRangeQualityIsClampedRatherThanReachingGd(): void
    {
        $bytes = $this->makeQuadrantImagePng(32);

        foreach ([-5, -1, 101, 9999] as $quality) {
            $this->assertNotNull(
                PlaceholderGenerator::forBytes($bytes, ['mode' => 'blur', 'quality' => $quality]),
                "quality {$quality} should have been clamped into range"
            );
        }
    }

    public function testLongEdgeIsCappedSoAPlaceholderStaysSmall(): void
    {
        $bytes = $this->makeQuadrantImagePng(32);

        $capped = PlaceholderGenerator::forBytes($bytes, ['mode' => 'blur', 'long_edge' => 5000]);
        $atCap = PlaceholderGenerator::forBytes($bytes, ['mode' => 'blur', 'long_edge' => PlaceholderGenerator::MAX_LONG_EDGE]);

        $this->assertSame($atCap, $capped, 'an oversized long_edge must behave exactly like the cap');

        // Well under a MySQL TEXT column, which a caching caller is likely to use.
        $this->assertLessThan(16384, strlen($capped));
    }

    public function testNonPositiveLongEdgeStillProducesOutput(): void
    {
        $bytes = $this->makeSolidImagePng(0x10, 0x20, 0x30);

        foreach ([0, -3] as $longEdge) {
            $this->assertNotNull(
                PlaceholderGenerator::forBytes($bytes, ['mode' => 'blur', 'long_edge' => $longEdge]),
                "long_edge {$longEdge} should have been clamped up to the minimum"
            );
        }
    }

    public function testCssOnlyReturnsAnIntegerForACustomProperty(): void
    {
        $result = PlaceholderGenerator::resultForBytes(
            $this->makeQuadrantImagePng(32),
            ['mode' => 'css_only']
        );

        $this->assertNotNull($result);
        $this->assertSame(LqipResult::KIND_CSS_VAR, $result->kind());
        $this->assertMatchesRegularExpression('/^-?\d+$/', $result->value());
        $this->assertSame('--lqip', $result->cssProperty());
        $this->assertNull($result->toCss());

        $value = (int) $result->value();
        $this->assertGreaterThanOrEqual(LqipInteger::MIN, $value);
        $this->assertLessThanOrEqual(LqipInteger::MAX, $value);
    }

    public function testCssOnlyEncodesAUniformImageAsSixIdenticalCells(): void
    {
        $result = PlaceholderGenerator::resultForBytes(
            $this->makeSolidImagePng(0x7C, 0x6A, 0x54, 24, 16),
            ['mode' => 'css_only']
        );

        $cells = LqipInteger::unpack((int) $result->value())['cells'];

        // Every cell has the same luminance as every other, so whichever bucket
        // they land in they must all land in the same one. Asserting a specific
        // bucket would be asserting the base colour's quantization error instead.
        $this->assertCount(1, array_unique($cells), 'cells: ' . implode(',', $cells));
    }

    /**
     * The only test that proves the six cells are stored in row-major order
     * (top-left, top-middle, top-right, then the bottom row). A transposed or
     * reversed layout still round-trips through pack/unpack, so nothing in the
     * codec tests would notice.
     */
    public function testCssOnlyCellsFollowVerticalBrightness(): void
    {
        $image = imagecreatetruecolor(24, 16);
        imagefilledrectangle($image, 0, 0, 23, 7, imagecolorallocate($image, 0x00, 0x00, 0x00));
        imagefilledrectangle($image, 0, 8, 23, 15, imagecolorallocate($image, 0xFF, 0xFF, 0xFF));

        $result = PlaceholderGenerator::resultForBytes(
            $this->encodePng($image),
            ['mode' => 'css_only']
        );
        $cells = LqipInteger::unpack((int) $result->value())['cells'];

        $this->assertLessThan(
            min(array_slice($cells, 3, 3)),
            max(array_slice($cells, 0, 3)),
            'the dark top row must encode below the bright bottom row; cells: ' . implode(',', $cells)
        );
    }

    public function testForBytesThrowsForTheCssOnlyMode(): void
    {
        $this->expectException(NotCssRepresentable::class);
        $this->expectExceptionMessageMatches('/resultFor/');

        PlaceholderGenerator::forBytes($this->makeSolidImagePng(0x11, 0x22, 0x33), ['mode' => 'css_only']);
    }

    public function testForFileThrowsForTheCssOnlyMode(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'lqip') . '.png';
        file_put_contents($path, $this->makeSolidImagePng(0x11, 0x22, 0x33));

        try {
            $this->expectException(NotCssRepresentable::class);

            PlaceholderGenerator::forFile($path, ['mode' => 'css_only']);
        } finally {
            unlink($path);
        }
    }

    public function testResultForBytesTagsBuiltInModesAsCssAndAgreesWithForBytes(): void
    {
        $bytes = $this->makeSolidImagePng(0xE0, 0x40, 0x20);

        foreach (['dominant_color', 'gradient', 'blur'] as $mode) {
            $result = PlaceholderGenerator::resultForBytes($bytes, ['mode' => $mode]);

            $this->assertNotNull($result, $mode);
            $this->assertSame(LqipResult::KIND_CSS, $result->kind(), $mode);
            $this->assertSame(
                PlaceholderGenerator::forBytes($bytes, ['mode' => $mode]),
                $result->toCss(),
                "the two entry points must agree for mode {$mode}"
            );
        }
    }

    public function testResultForFileReadsFromDisk(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'lqip') . '.png';
        file_put_contents($path, $this->makeSolidImagePng(0x00, 0xAA, 0xFF));

        try {
            $result = PlaceholderGenerator::resultForFile($path, ['mode' => 'dominant_color']);

            $this->assertNotNull($result);
            $this->assertSame(LqipResult::KIND_CSS, $result->kind());
        } finally {
            unlink($path);
        }
    }

    public function testResultForFileReturnsNullForMissingFile(): void
    {
        $this->assertNull(PlaceholderGenerator::resultForFile('/does/not/exist.png'));
    }

    public function testResultForBytesReturnsNullForGarbageInput(): void
    {
        $this->assertNull(PlaceholderGenerator::resultForBytes('not an image'));
    }

    /**
     * Stored 60x40 with Orientation 6, displayed 40x60 - the size a template
     * needs for width/height or aspect-ratio, and the one getimagesize() gets
     * the wrong way round.
     */
    public function testResultsCarryTheDisplayedSize(): void
    {
        $jpeg = Fixtures::withExifOrientation(Fixtures::splitJpeg(0xFF0000, 0x0000FF, 60, 40), 6);

        foreach (['dominant_color', 'gradient', 'blur', 'css_only'] as $mode) {
            $result = PlaceholderGenerator::resultForBytes($jpeg, ['mode' => $mode]);

            $this->assertSame(40, $result->width(), $mode);
            $this->assertSame(60, $result->height(), $mode);
        }

        $this->assertSame([60, 40], array_slice(getimagesizefromstring($jpeg), 0, 2));
    }

    public function testACustomStrategysResultGetsTheSizeToo(): void
    {
        PlaceholderGenerator::extend(__FUNCTION__, new class implements LqipStrategy {
            public function generate(Image $image, Driver $driver, array $options): ?LqipResult
            {
                return LqipResult::css('none');
            }
        });

        $result = PlaceholderGenerator::resultForBytes(Fixtures::solidPng(0x336699, 30, 20), ['mode' => __FUNCTION__]);

        $this->assertSame([30, 20], [$result->width(), $result->height()]);
    }

    public function testForBytesThrowsWhenAStrategyProducesANonCssValue(): void
    {
        $strategy = new class implements LqipStrategy {
            public function generate(Image $image, Driver $driver, array $options): ?LqipResult
            {
                return LqipResult::cssVar('42', '--x');
            }
        };
        // Unique mode name: $customStrategies is static with no unregister, so a
        // shared name would leak into whatever test runs next.
        PlaceholderGenerator::extend(__FUNCTION__, $strategy);

        $this->expectException(NotCssRepresentable::class);
        $this->expectExceptionMessageMatches('/resultFor/');

        PlaceholderGenerator::forBytes($this->makeSolidImagePng(0x11, 0x22, 0x33), ['mode' => __FUNCTION__]);
    }

    /**
     * The two failure modes have a precedence, and it matters: a broken image is
     * a soft null even in a non-CSS mode, because the kind is never established.
     * Only a successfully generated non-CSS value throws. Regression guard for
     * moving the conversion back inside resultForBytes()'s catch (Throwable).
     */
    public function testUndecodableInputIsStillNullEvenInANonCssMode(): void
    {
        $strategy = new class implements LqipStrategy {
            public function generate(Image $image, Driver $driver, array $options): ?LqipResult
            {
                return LqipResult::cssVar('42', '--x');
            }
        };
        PlaceholderGenerator::extend(__FUNCTION__, $strategy);

        $this->assertNull(PlaceholderGenerator::forBytes('not an image', ['mode' => __FUNCTION__]));
    }

    public function testExtendRegistersACustomStrategyForANewMode(): void
    {
        $strategy = new class implements LqipStrategy {
            public function generate(Image $image, Driver $driver, array $options): ?LqipResult
            {
                return LqipResult::css('custom-placeholder-value');
            }
        };

        PlaceholderGenerator::extend('my_custom_mode', $strategy);

        $result = PlaceholderGenerator::forBytes(
            $this->makeSolidImagePng(0x11, 0x22, 0x33),
            ['mode' => 'my_custom_mode']
        );

        $this->assertSame('custom-placeholder-value', $result);
    }

    public function testExtendCanOverrideABuiltInModeName(): void
    {
        $strategy = new class implements LqipStrategy {
            public function generate(Image $image, Driver $driver, array $options): ?LqipResult
            {
                return LqipResult::css('overridden-gradient');
            }
        };

        PlaceholderGenerator::extend('gradient', $strategy);

        try {
            $result = PlaceholderGenerator::forBytes(
                $this->makeQuadrantImagePng(),
                ['mode' => 'gradient']
            );

            $this->assertSame('overridden-gradient', $result);
        } finally {
            // Restore the built-in so this override doesn't leak into other tests.
            PlaceholderGenerator::extend('gradient', new \Bonniss26\Lqip\Strategies\GradientStrategy());
        }
    }

    private function makeSolidImagePng(int $r, int $g, int $b, int $w = 8, int $h = 8): string
    {
        $image = imagecreatetruecolor($w, $h);
        imagefill($image, 0, 0, imagecolorallocate($image, $r, $g, $b));

        return $this->encodePng($image);
    }

    /**
     * An 8x8 image split into two solid halves of exactly 32 pixels each, so the
     * two colors tie on pixel count and only scan order can break it.
     *
     * @param array{0:int,1:int,2:int} $left
     * @param array{0:int,1:int,2:int} $right
     */
    private function makeVerticalSplitPng(array $left, array $right): string
    {
        $image = imagecreatetruecolor(8, 8);
        imagefilledrectangle($image, 0, 0, 3, 7, imagecolorallocate($image, ...$left));
        imagefilledrectangle($image, 4, 0, 7, 7, imagecolorallocate($image, ...$right));

        return $this->encodePng($image);
    }

    /**
     * A 2x2 image with a distinct solid color per quadrant, useful for testing
     * per-corner sampling (gradient mode) without any resampling blur to worry about.
     */
    private function makeQuadrantImagePng(int $cellSize = 1): string
    {
        $image = imagecreatetruecolor($cellSize * 2, $cellSize * 2);

        $red = imagecolorallocate($image, 0xFF, 0x00, 0x00);
        $green = imagecolorallocate($image, 0x00, 0xFF, 0x00);
        $blue = imagecolorallocate($image, 0x00, 0x00, 0xFF);
        $yellow = imagecolorallocate($image, 0xFF, 0xFF, 0x00);

        imagefilledrectangle($image, 0, 0, $cellSize - 1, $cellSize - 1, $red);
        imagefilledrectangle($image, $cellSize, 0, $cellSize * 2 - 1, $cellSize - 1, $green);
        imagefilledrectangle($image, 0, $cellSize, $cellSize - 1, $cellSize * 2 - 1, $blue);
        imagefilledrectangle($image, $cellSize, $cellSize, $cellSize * 2 - 1, $cellSize * 2 - 1, $yellow);

        return $this->encodePng($image);
    }

    private function encodePng($image): string
    {
        ob_start();
        imagepng($image);
        $bytes = ob_get_clean();

        return $bytes;
    }

    private function assertHexCloseTo(string $expected, string $actual, int $tolerance = 10): void
    {
        [$er, $eg, $eb] = sscanf(ltrim($expected, '#'), '%02x%02x%02x');
        [$ar, $ag, $ab] = sscanf(ltrim($actual, '#'), '%02x%02x%02x');

        $this->assertLessThanOrEqual($tolerance, abs($er - $ar), "red channel: expected {$expected}, got {$actual}");
        $this->assertLessThanOrEqual($tolerance, abs($eg - $ag), "green channel: expected {$expected}, got {$actual}");
        $this->assertLessThanOrEqual($tolerance, abs($eb - $ab), "blue channel: expected {$expected}, got {$actual}");
    }
}
