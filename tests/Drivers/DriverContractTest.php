<?php namespace Bonniss26\Lqip\Tests\Drivers;

use Bonniss26\Lqip\Drivers\Driver;
use Bonniss26\Lqip\Drivers\GdDriver;
use Bonniss26\Lqip\Drivers\ImagickDriver;
use Bonniss26\Lqip\PlaceholderGenerator;
use Bonniss26\Lqip\Support\Raster;
use Bonniss26\Lqip\Tests\Fixtures;
use Bonniss26\Lqip\Tests\Support\ExifOrientationTest;
use PHPUnit\Framework\TestCase;

/**
 * What every driver has to do, run against each built-in one. A driver whose
 * engine is not loaded here is skipped rather than passed, so a green run on a
 * GD-only machine says nothing about Imagick - CI runs both.
 */
class DriverContractTest extends TestCase
{
    public static function drivers(): array
    {
        return [
            'gd' => [new GdDriver()],
            'imagick' => [new ImagickDriver()],
        ];
    }

    /** @dataProvider drivers */
    public function testReportsTheDisplayedSize(Driver $driver): void
    {
        $this->requireDriver($driver);

        $image = $driver->load(Fixtures::solidPng(0x336699, 40, 30));

        $this->assertNotNull($image);
        $this->assertSame(40, $image->width());
        $this->assertSame(30, $image->height());
        $image->release();
        $image->release(); // twice is safe
    }

    /** @dataProvider drivers */
    public function testSamplesASolidImageAsThatColour(Driver $driver): void
    {
        $this->requireDriver($driver);

        $image = $driver->load(Fixtures::solidPng(0xE04020, 500, 300));
        $sample = $image->sample(8, 8);

        $this->assertSame(8, $sample->width());
        $this->assertSame(8, $sample->height());
        foreach ($sample->pixels() as $pixel) {
            $this->assertSame(0xE04020, $pixel);
        }
    }

    /** @dataProvider drivers */
    public function testSampleKeepsQuadrantsApart(Driver $driver): void
    {
        $this->requireDriver($driver);

        $grid = $driver->load(Fixtures::quadrantPng(64))->sample(2, 2);

        $this->assertSame(0xFF0000, $grid->rgbAt(0, 0));
        $this->assertSame(0x00FF00, $grid->rgbAt(1, 0));
        $this->assertSame(0x0000FF, $grid->rgbAt(0, 1));
        $this->assertSame(0xFFFF00, $grid->rgbAt(1, 1));
    }

    /** @dataProvider drivers */
    public function testKeepsTransparency(Driver $driver): void
    {
        $this->requireDriver($driver);

        $sample = $driver->load(Fixtures::transparentPng(16, 16))->sample(4, 4);

        $this->assertTrue($sample->hasTransparency());
        $this->assertSame(0, $sample->alphaAt(0, 0));
    }

    /**
     * The colour of a transparent pixel is meaningless (here blue), and must not
     * leak into an average: half opaque red, half transparent blue, resampled
     * to one pixel, is red at half alpha - not purple, and not darkened.
     *
     * @dataProvider drivers
     */
    public function testResamplingWeightsColourByAlpha(Driver $driver): void
    {
        $this->requireDriver($driver);

        $sample = $driver->load(Fixtures::logoPng(0xFF0000, 16, 16))->sample(1, 1);

        $this->assertSame(0xFF0000, $sample->rgbAt(0, 0));
        $this->assertEqualsWithDelta(128, $sample->alphaAt(0, 0), 2);
    }

    /** @dataProvider drivers */
    public function testAnOpaqueSourceSamplesWithoutAnAlphaPlane(Driver $driver): void
    {
        $this->requireDriver($driver);

        $this->assertFalse($driver->load(Fixtures::solidPng(0x336699, 16, 16))->sample(4, 4)->hasTransparency());
        $this->assertFalse(
            $driver->load(Fixtures::splitJpeg(0xFF0000, 0x0000FF, 16, 16))->sample(4, 4)->hasTransparency()
        );
    }

    /** @dataProvider drivers */
    public function testEncodesWebpWithAlpha(Driver $driver): void
    {
        $this->requireDriver($driver);
        if (!$driver->supportsFormat('webp') || !function_exists('imagecreatefromwebp')) {
            $this->markTestSkipped('no WebP support to encode or to decode the result');
        }

        $raster = new Raster(2, 1, [0xFF0000, 0x0000FF], [255, 0]);
        $encoded = $driver->encode($raster, 'webp', 100);
        $decoded = imagecreatefromstring($encoded['bytes']);

        $this->assertSame(0, (imagecolorat($decoded, 0, 0) >> 24) & 0x7F, 'left pixel opaque');
        $this->assertSame(127, (imagecolorat($decoded, 1, 0) >> 24) & 0x7F, 'right pixel transparent');
    }

    /**
     * A phone photo stored landscape with Orientation 6 must come out portrait,
     * the way a browser shows it - left edge of the stored image on top.
     *
     * @dataProvider drivers
     */
    public function testAppliesExifOrientation(Driver $driver): void
    {
        $this->requireDriver($driver);

        // Stored 60x40: left half red, right half blue.
        $jpeg = Fixtures::withExifOrientation(Fixtures::splitJpeg(0xFF0000, 0x0000FF, 60, 40), 6);
        $image = $driver->load($jpeg);

        $this->assertSame(40, $image->width());
        $this->assertSame(60, $image->height());

        // Rotated clockwise, the stored left (red) becomes the displayed top.
        $sample = $image->sample(1, 2);
        [$topRed, , $topBlue] = $sample->channelsAt(0, 0);
        [$bottomRed, , $bottomBlue] = $sample->channelsAt(0, 1);
        $this->assertGreaterThan($topBlue, $topRed, 'top should be red');
        $this->assertGreaterThan($bottomRed, $bottomBlue, 'bottom should be blue');
    }

    /** @dataProvider drivers */
    public function testRejectsWhatItCannotDecode(Driver $driver): void
    {
        $this->requireDriver($driver);

        $this->assertNull($driver->load('not an image'));
        $this->assertNull($driver->load(''));
        $this->assertNull($driver->load(substr(Fixtures::solidPng(0x123456, 8, 8), 0, 20)));
    }

    /**
     * ImageMagick would happily run an SVG or PostScript blob through an external
     * delegate. Only raster formats sniffed by PHP may reach it.
     *
     * @dataProvider drivers
     */
    public function testRefusesVectorAndScriptFormats(Driver $driver): void
    {
        $this->requireDriver($driver);

        $this->assertNull($driver->load('<svg xmlns="http://www.w3.org/2000/svg" width="10" height="10"><rect width="10" height="10" fill="red"/></svg>'));
        $this->assertNull($driver->load("%!PS-Adobe-3.0\n0 0 moveto showpage\n"));
    }

    /** @dataProvider drivers */
    public function testRefusesASourcePastThePixelCeiling(Driver $driver): void
    {
        $this->requireDriver($driver);

        // A real PNG header claiming 10000x10000; the check must happen before any
        // attempt to decode, so the missing pixel data never matters.
        $this->assertNull($driver->load(Fixtures::pngHeaderOnly(10000, 10000)));
        $this->assertGreaterThan(PlaceholderGenerator::MAX_SOURCE_PIXELS, 10000 * 10000);
    }

    /** @dataProvider drivers */
    public function testEncodesARasterInEachSupportedFormat(Driver $driver): void
    {
        $this->requireDriver($driver);

        $raster = new Raster(4, 2, [0xFF0000, 0x00FF00, 0x0000FF, 0xFFFFFF, 0, 0x808080, 0x123456, 0xFEDCBA]);

        foreach (['webp' => 'image/webp', 'jpg' => 'image/jpeg'] as $format => $mime) {
            if (!$driver->supportsFormat($format)) {
                continue;
            }

            $encoded = $driver->encode($raster, $format, 50);

            $this->assertNotNull($encoded, $format);
            $this->assertSame($mime, $encoded['mime']);
            $info = getimagesizefromstring($encoded['bytes']);
            $this->assertSame([4, 2], [$info[0], $info[1]], $format);
        }

        $this->assertNull($driver->encode($raster, 'bmp', 50));
    }

    private function requireDriver(Driver $driver): void
    {
        if (!$driver->isAvailable()) {
            $this->markTestSkipped("{$driver->name()} is not available in this PHP build");
        }
    }
}
