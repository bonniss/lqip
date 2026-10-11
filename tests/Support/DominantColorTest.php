<?php namespace Bonniss26\Lqip\Tests\Support;

use Bonniss26\Lqip\Support\DominantColor;
use Bonniss26\Lqip\Support\Raster;
use PHPUnit\Framework\TestCase;

/**
 * Pure PHP - no image engine involved, which is the point of owning the
 * quantizer: the answer depends on the pixels alone.
 */
class DominantColorTest extends TestCase
{
    public function testSolidImageYieldsExactlyThatColor(): void
    {
        // Exact, where GD's palette quantizer used to shift it (#e04020 came back
        // as #e44224) by an amount that varied between libgd builds.
        $this->assertSame([0xE0, 0x40, 0x20], DominantColor::fromRaster($this->solid(0xE04020)));
    }

    public function testMajorityColorWinsOverMinorityColor(): void
    {
        // 48 red pixels vs 16 blue - no tie, so scan order is irrelevant here.
        $raster = $this->build(8, 8, function (int $x, int $y) {
            return $y < 2 ? 0x0000FF : 0xFF0000;
        });

        $this->assertSame([0xFF, 0x00, 0x00], DominantColor::fromRaster($raster));
    }

    /**
     * Two colours covering exactly 32 pixels each tie on count; the tie goes to
     * the one met first in the column-major scan, i.e. the leftmost. Flipping
     * the scan to row-major would make the top colour win instead, which this
     * catches together with the vertical case below.
     */
    public function testTiesGoToTheLeftmostColour(): void
    {
        $leftRed = $this->build(8, 8, function (int $x) {
            return $x < 4 ? 0xFF0000 : 0x0000FF;
        });
        $leftBlue = $this->build(8, 8, function (int $x) {
            return $x < 4 ? 0x0000FF : 0xFF0000;
        });

        $this->assertSame([0xFF, 0x00, 0x00], DominantColor::fromRaster($leftRed));
        $this->assertSame([0x00, 0x00, 0xFF], DominantColor::fromRaster($leftBlue));
    }

    public function testATieBetweenTopAndBottomGoesToTheTopColour(): void
    {
        $raster = $this->build(8, 8, function (int $x, int $y) {
            return $y < 4 ? 0x00FF00 : 0xFF00FF;
        });

        $this->assertSame([0x00, 0xFF, 0x00], DominantColor::fromRaster($raster));
    }

    /**
     * The point of quantizing rather than averaging: a half-red, half-green
     * image averages to a muddy olive that appears nowhere in it.
     */
    public function testPicksARealColourInsteadOfTheAverage(): void
    {
        $raster = $this->build(8, 8, function (int $x, int $y) {
            return ($x + $y) % 3 === 0 ? 0x00C000 : 0xC00000;
        });

        $this->assertSame([0xC0, 0x00, 0x00], DominantColor::fromRaster($raster));
    }

    /**
     * A gradient has no single colour; the result must still be a colour from
     * within the image's range rather than something invented.
     */
    public function testANoisyImageYieldsAColourWithinItsRange(): void
    {
        mt_srand(42);
        $raster = $this->build(8, 8, function () {
            return (mt_rand(100, 140) << 16) | (mt_rand(60, 80) << 8) | mt_rand(20, 40);
        });

        [$r, $g, $b] = DominantColor::fromRaster($raster);

        $this->assertGreaterThanOrEqual(100, $r);
        $this->assertLessThanOrEqual(140, $r);
        $this->assertGreaterThanOrEqual(60, $g);
        $this->assertLessThanOrEqual(80, $g);
        $this->assertGreaterThanOrEqual(20, $b);
        $this->assertLessThanOrEqual(40, $b);
    }

    public function testIsDeterministic(): void
    {
        mt_srand(7);
        $raster = $this->build(8, 8, function () {
            return mt_rand(0, 0xFFFFFF);
        });

        $this->assertSame(DominantColor::fromRaster($raster), DominantColor::fromRaster($raster));
    }

    private function solid(int $rgb): Raster
    {
        return new Raster(8, 8, array_fill(0, 64, $rgb));
    }

    private function build(int $w, int $h, callable $pixel): Raster
    {
        $pixels = [];
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $pixels[] = $pixel($x, $y);
            }
        }

        return new Raster($w, $h, $pixels);
    }
}
