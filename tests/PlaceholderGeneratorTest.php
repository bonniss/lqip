<?php namespace Bonniss26\Lqip\Tests;

use Bonniss26\Lqip\Exceptions\NotCssRepresentable;
use Bonniss26\Lqip\LqipResult;
use Bonniss26\Lqip\PlaceholderGenerator;
use Bonniss26\Lqip\Strategies\CssOnlyStrategy;
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
            [0xE0, 0x40, 0x20, 'linear-gradient(#e44224,#e44224)'],
            [0x00, 0xAA, 0xFF, 'linear-gradient(#04aafc,#04aafc)'],
            [0x10, 0x20, 0x30, 'linear-gradient(#142234,#142234)'],
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
     * When two colours cover exactly the same number of pixels, arsort() is
     * stable (PHP 8.0+) so the tie falls back to insertion order, which is the
     * `for x { for y }` scan order - i.e. the leftmost colour wins. Flipping the
     * loop nesting during a refactor would silently flip the result here.
     */
    public function testDominantColorResolvesTiesByScanOrder(): void
    {
        $this->assertSame(
            'linear-gradient(#fc0204,#fc0204)',
            PlaceholderGenerator::forBytes(
                $this->makeVerticalSplitPng([0xFF, 0x00, 0x00], [0x00, 0x00, 0xFF]),
                ['mode' => 'dominant_color']
            ),
            'red on the left must win the 32/32 tie'
        );

        $this->assertSame(
            'linear-gradient(#0402fc,#0402fc)',
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

        $expectedMime = function_exists('imagewebp') ? 'webp' : 'jpeg';
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

    /**
     * The reference implementation emits no placeholder at all for images with
     * transparency. This package deliberately does not follow that: every other
     * mode already composites alpha onto black, and returning null here - in a
     * class where null also means "broken image" - would be undiagnosable.
     */
    public function testCssOnlyStillProducesAValueForAnImageWithAlpha(): void
    {
        $image = imagecreatetruecolor(24, 16);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0x20, 0x80, 0xC0, 64));

        $result = PlaceholderGenerator::resultForBytes(
            $this->encodePng($image),
            ['mode' => 'css_only']
        );

        $this->assertNotNull($result);
        $this->assertSame(LqipResult::KIND_CSS_VAR, $result->kind());
    }

    public function testCssOnlyDoesNotModifyTheDecodedSource(): void
    {
        $image = imagecreatetruecolor(24, 16);
        imagefilledrectangle($image, 0, 0, 23, 15, imagecolorallocate($image, 0x33, 0x66, 0x99));
        imagefilledrectangle($image, 0, 0, 11, 7, imagecolorallocate($image, 0xCC, 0x33, 0x11));
        $before = [imagecolorat($image, 0, 0), imagecolorat($image, 23, 15)];

        (new CssOnlyStrategy())->generate($image, 24, 16, PlaceholderGenerator::DEFAULT_OPTIONS);

        $this->assertSame(
            $before,
            [imagecolorat($image, 0, 0), imagecolorat($image, 23, 15)],
            'the strategy must not mutate the source image the generator still owns'
        );
        imagedestroy($image);
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

    public function testForBytesThrowsWhenAStrategyProducesANonCssValue(): void
    {
        $strategy = new class implements LqipStrategy {
            public function generate($source, int $origWidth, int $origHeight, array $options): ?LqipResult
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
            public function generate($source, int $origWidth, int $origHeight, array $options): ?LqipResult
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
            public function generate($source, int $origWidth, int $origHeight, array $options): ?LqipResult
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
            public function generate($source, int $origWidth, int $origHeight, array $options): ?LqipResult
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
        imagedestroy($image);

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
