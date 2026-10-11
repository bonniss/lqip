<?php namespace Bonniss26\Lqip\Tests;

use Bonniss26\Lqip\Drivers\Driver;
use Bonniss26\Lqip\Drivers\GdDriver;
use Bonniss26\Lqip\Drivers\Image;
use Bonniss26\Lqip\PlaceholderGenerator;
use Bonniss26\Lqip\Support\Raster;
use PHPUnit\Framework\TestCase;

class DriverSelectionTest extends TestCase
{
    protected function tearDown(): void
    {
        PlaceholderGenerator::useDriver(null);
    }

    public function testAutoPrefersImagickAndFallsBackToGd(): void
    {
        $expected = extension_loaded('imagick') ? 'imagick' : 'gd';

        $this->assertSame($expected, PlaceholderGenerator::resolveDriver('auto')->name());
        $this->assertSame($expected, PlaceholderGenerator::availableDrivers()[0]);
    }

    public function testAnExplicitDriverIsUsed(): void
    {
        $this->assertSame('gd', PlaceholderGenerator::resolveDriver('gd')->name());

        $result = PlaceholderGenerator::forBytes(Fixtures::solidPng(0x102030), ['driver' => 'gd']);
        $this->assertSame('linear-gradient(#102030,#102030)', $result);
    }

    /**
     * Fail cheap: a driver name nothing answers to is a configuration problem,
     * and returns null without decoding anything.
     */
    public function testAnUnknownOrMissingDriverYieldsNull(): void
    {
        $this->assertNull(PlaceholderGenerator::resolveDriver('vips'));
        $this->assertNull(PlaceholderGenerator::forBytes(Fixtures::solidPng(0x102030), ['driver' => 'vips']));

        if (!extension_loaded('imagick')) {
            $this->assertNull(PlaceholderGenerator::forBytes(Fixtures::solidPng(0x102030), ['driver' => 'imagick']));
        }
    }

    /**
     * Used to fall back to `blur` - the most expensive mode - so a typo in a
     * settings file silently cost the most. Now it costs nothing.
     */
    public function testAnUnknownModeYieldsNullWithoutDecoding(): void
    {
        $driver = new CountingDriver();

        $this->assertNull(PlaceholderGenerator::forBytes(Fixtures::solidPng(0x102030), ['mode' => 'blurr', 'driver' => $driver]));
        $this->assertSame(0, $driver->loads, 'an unknown mode must not reach the decoder');
    }

    public function testADriverInstanceCanBePassedPerCall(): void
    {
        $driver = new CountingDriver();

        $result = PlaceholderGenerator::forBytes('anything', ['mode' => 'gradient', 'driver' => $driver]);

        $this->assertSame(1, $driver->loads);
        $this->assertStringContainsString('#abcdef', $result);
    }

    public function testUseDriverReplacesAuto(): void
    {
        $driver = new CountingDriver();
        PlaceholderGenerator::useDriver($driver);

        $this->assertSame($driver, PlaceholderGenerator::resolveDriver('auto'));
        PlaceholderGenerator::forBytes('anything', ['mode' => 'dominant_color']);
        $this->assertSame(1, $driver->loads);

        // A named driver still means that driver.
        $this->assertInstanceOf(GdDriver::class, PlaceholderGenerator::resolveDriver('gd'));
    }

    public function testModesListsBuiltInAndRegisteredModes(): void
    {
        PlaceholderGenerator::extend('test_listed_mode', new \Bonniss26\Lqip\Strategies\GradientStrategy());

        $modes = PlaceholderGenerator::modes();

        foreach (['dominant_color', 'gradient', 'blur', 'css_only', 'test_listed_mode'] as $mode) {
            $this->assertContains($mode, $modes);
        }
        $this->assertSame(count($modes), count(array_unique($modes)));
    }
}

/**
 * A driver with no engine behind it: every image is solid #abcdef. Counts
 * decodes so tests can prove one did or did not happen.
 */
final class CountingDriver implements Driver
{
    /** @var int */
    public $loads = 0;

    public function name(): string
    {
        return 'counting';
    }

    public function isAvailable(): bool
    {
        return true;
    }

    public function load(string $bytes): ?Image
    {
        $this->loads++;

        return new class implements Image {
            public function width(): int
            {
                return 10;
            }

            public function height(): int
            {
                return 10;
            }

            public function sample(int $width, int $height): Raster
            {
                return new Raster($width, $height, array_fill(0, $width * $height, 0xABCDEF));
            }

            public function release(): void
            {
            }
        };
    }

    public function supportsFormat(string $format): bool
    {
        return false;
    }

    public function encode(Raster $raster, string $format, int $quality): ?array
    {
        return null;
    }
}
