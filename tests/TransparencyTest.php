<?php namespace Bonniss26\Lqip\Tests;

use Bonniss26\Lqip\PlaceholderGenerator;
use PHPUnit\Framework\TestCase;

/**
 * What each mode makes of an image with transparency - a logo, a cut-out
 * product shot. These used to be composited onto black, so a red logo on a
 * white page got a black placeholder. Run against every driver, because the
 * alpha has to survive decoding and resampling in each engine.
 */
class TransparencyTest extends TestCase
{
    public static function drivers(): array
    {
        return ['gd' => ['gd'], 'imagick' => ['imagick']];
    }

    /** @dataProvider drivers */
    public function testDominantColorIsTheLogoColourAtItsCoverage(string $driver): void
    {
        $this->requireDriver($driver);

        // Half the box is painted, so half opacity: #ff0000 + 0x80.
        $this->assertSame(
            'linear-gradient(#ff000080,#ff000080)',
            PlaceholderGenerator::forBytes(Fixtures::logoPng(0xFF0000), ['driver' => $driver])
        );
    }

    /** @dataProvider drivers */
    public function testAFullyTransparentImageHasNoDominantColour(string $driver): void
    {
        $this->requireDriver($driver);

        $this->assertNull(PlaceholderGenerator::forBytes(Fixtures::transparentPng(16, 16), ['driver' => $driver]));
    }

    /** @dataProvider drivers */
    public function testGradientCarriesEachCornersAlphaAndDropsTheSolidLayer(string $driver): void
    {
        $this->requireDriver($driver);

        $css = PlaceholderGenerator::forBytes(Fixtures::logoPng(0xFF0000), ['mode' => 'gradient', 'driver' => $driver]);

        $this->assertStringContainsString('at 25% 25%,#ff0000ff', $css);
        $this->assertMatchesRegularExpression('/at 75% 25%,#[0-9a-f]{6}00 /', $css);
        $this->assertStringNotContainsString('linear-gradient', $css);
    }

    /** @dataProvider drivers */
    public function testBlurKeepsTheShapeInAWebp(string $driver): void
    {
        $this->requireDriver($driver);
        if (!PlaceholderGenerator::resolveDriver($driver)->supportsFormat('webp') || !function_exists('imagecreatefromwebp')) {
            $this->markTestSkipped('needs WebP to encode and to decode the result');
        }

        $css = PlaceholderGenerator::forBytes(Fixtures::logoPng(0xFF0000, 32, 32), ['mode' => 'blur', 'driver' => $driver]);
        $mini = $this->decodeDataUri($css);

        // Opaque red on the left edge, transparent on the right edge, a blurred
        // seam between - and no blue anywhere.
        $left = imagecolorat($mini, 0, 8);
        $right = imagecolorat($mini, 15, 8);
        $this->assertLessThan(10, ($left >> 24) & 0x7F, 'left edge opaque');
        $this->assertGreaterThan(0xE0, ($left >> 16) & 0xFF, 'left edge red');
        $this->assertGreaterThan(117, ($right >> 24) & 0x7F, 'right edge transparent');
        $this->assertLessThan(0x20, imagecolorat($mini, 4, 8) & 0xFF, 'no blue bleeding into the red');
    }

    /** @dataProvider drivers */
    public function testBlurAsJpegIsFlattenedOntoWhite(string $driver): void
    {
        $this->requireDriver($driver);

        $css = PlaceholderGenerator::forBytes(
            Fixtures::logoPng(0xFF0000, 32, 32),
            ['mode' => 'blur', 'format' => 'jpg', 'quality' => 100, 'driver' => $driver]
        );
        $right = imagecolorat($this->decodeDataUri($css), 15, 8);

        $this->assertGreaterThan(0xE8, ($right >> 16) & 0xFF);
        $this->assertGreaterThan(0xE8, ($right >> 8) & 0xFF);
        $this->assertGreaterThan(0xE8, $right & 0xFF);
    }

    /**
     * The css_only format has no transparency to give, so - like the reference
     * implementation - it makes no placeholder rather than an opaque box.
     *
     * @dataProvider drivers
     */
    public function testCssOnlySkipsAnImageWithTransparency(string $driver): void
    {
        $this->requireDriver($driver);

        $this->assertNull(
            PlaceholderGenerator::resultForBytes(Fixtures::logoPng(0xFF0000), ['mode' => 'css_only', 'driver' => $driver])
        );
    }

    /** @dataProvider drivers */
    public function testBackgroundFlattensFirstSoEveryModeSeesAnOpaqueImage(string $driver): void
    {
        $this->requireDriver($driver);
        $logo = Fixtures::logoPng(0xFF0000, 32, 32);

        // Half red, half white: a tie, which goes to the leftmost colour.
        $this->assertSame(
            'linear-gradient(#ff0000,#ff0000)',
            PlaceholderGenerator::forBytes($logo, ['background' => '#fff', 'driver' => $driver])
        );
        $this->assertStringContainsString(
            'at 75% 25%,#ffffff 0px',
            PlaceholderGenerator::forBytes($logo, ['mode' => 'gradient', 'background' => 'ffffff', 'driver' => $driver])
        );
        $this->assertNotNull(
            PlaceholderGenerator::resultForBytes($logo, ['mode' => 'css_only', 'background' => 0xFFFFFF, 'driver' => $driver])
        );
    }

    public function testBackgroundDoesNotChangeAnOpaqueImage(): void
    {
        $photo = Fixtures::splitJpeg(0x3366AA, 0xAA6633, 32, 32);

        foreach (['dominant_color', 'gradient', 'blur'] as $mode) {
            $this->assertSame(
                PlaceholderGenerator::forBytes($photo, ['mode' => $mode]),
                PlaceholderGenerator::forBytes($photo, ['mode' => $mode, 'background' => '#123456']),
                $mode
            );
        }
    }

    public function testAnInvalidBackgroundIsAConfigurationErrorNotAGuess(): void
    {
        foreach (['white', '#12345', '#gggggg', -1, 0x1000000, [1]] as $background) {
            $this->assertNull(
                PlaceholderGenerator::resultForBytes(Fixtures::logoPng(0xFF0000), ['background' => $background]),
                var_export($background, true)
            );
        }
    }

    /** @return \GdImage|resource */
    private function decodeDataUri(string $css)
    {
        $this->assertSame(1, preg_match("~^url\\('data:image/[a-z]+;base64,([^']+)'\\)$~", $css, $match), $css);

        return imagecreatefromstring(base64_decode($match[1]));
    }

    private function requireDriver(string $driver): void
    {
        if (PlaceholderGenerator::resolveDriver($driver) === null) {
            $this->markTestSkipped("{$driver} is not available in this PHP");
        }
    }
}
