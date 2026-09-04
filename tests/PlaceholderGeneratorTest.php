<?php namespace Bonniss26\Lqip\Tests;

use Bonniss26\Lqip\PlaceholderGenerator;
use Bonniss26\Lqip\Strategies\LqipStrategy;
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

    public function testExtendRegistersACustomStrategyForANewMode(): void
    {
        $strategy = new class implements LqipStrategy {
            public function generate($source, int $origWidth, int $origHeight, array $options): ?string
            {
                return 'custom-placeholder-value';
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
            public function generate($source, int $origWidth, int $origHeight, array $options): ?string
            {
                return 'overridden-gradient';
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
