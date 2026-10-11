<?php namespace Bonniss26\Lqip\Tests\Support;

use Bonniss26\Lqip\Support\Raster;
use PHPUnit\Framework\TestCase;

class RasterTest extends TestCase
{
    public function testRgbBytesRoundTrip(): void
    {
        $raster = Raster::fromRgbBytes(2, 1, [0x12, 0x34, 0x56, 0xAB, 0xCD, 0xEF]);

        $this->assertSame([0x123456, 0xABCDEF], $raster->pixels());
        $this->assertSame([0xAB, 0xCD, 0xEF], $raster->channelsAt(1, 0));
        $this->assertSame([0x12, 0x34, 0x56, 0xAB, 0xCD, 0xEF], $raster->toRgbBytes());
    }

    public function testRejectsAPixelCountThatDoesNotMatchTheSize(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Raster(2, 2, [0, 0, 0]);
    }

    /**
     * Each orientation checked against what it must display. The stored image is
     * 3x2 with distinct pixels:
     *
     *     a b c
     *     d e f
     *
     * and the expected arrays are the display image, row-major.
     */
    public function testOrientationsProduceTheDisplayedImage(): void
    {
        [$a, $b, $c, $d, $e, $f] = [1, 2, 3, 4, 5, 6];
        $stored = new Raster(3, 2, [$a, $b, $c, $d, $e, $f]);

        $cases = [
            1 => [3, 2, [$a, $b, $c, $d, $e, $f]],
            2 => [3, 2, [$c, $b, $a, $f, $e, $d]], // mirrored left-right
            3 => [3, 2, [$f, $e, $d, $c, $b, $a]], // upside down
            4 => [3, 2, [$d, $e, $f, $a, $b, $c]], // mirrored top-bottom
            5 => [2, 3, [$a, $d, $b, $e, $c, $f]], // transposed
            6 => [2, 3, [$d, $a, $e, $b, $f, $c]], // rotated 90 clockwise
            7 => [2, 3, [$f, $c, $e, $b, $d, $a]], // transversed
            8 => [2, 3, [$c, $f, $b, $e, $a, $d]], // rotated 90 counter-clockwise
        ];

        foreach ($cases as $orientation => [$width, $height, $pixels]) {
            $oriented = $stored->orient($orientation);

            $this->assertSame($width, $oriented->width(), "orientation {$orientation} width");
            $this->assertSame($height, $oriented->height(), "orientation {$orientation} height");
            $this->assertSame($pixels, $oriented->pixels(), "orientation {$orientation} pixels");
        }
    }

    public function testAnInvalidOrientationIsTreatedAsUpright(): void
    {
        $stored = new Raster(2, 1, [1, 2]);

        $this->assertSame([1, 2], $stored->orient(0)->pixels());
        $this->assertSame([1, 2], $stored->orient(9)->pixels());
    }

    public function testAnAllOpaqueAlphaPlaneIsDropped(): void
    {
        $raster = new Raster(2, 1, [1, 2], [255, 255]);

        $this->assertFalse($raster->hasTransparency());
        $this->assertNull($raster->alphas());
        $this->assertSame(255, $raster->alphaAt(1, 0));
        $this->assertSame(1.0, $raster->coverage());
    }

    public function testRgbaBytesRoundTrip(): void
    {
        $bytes = [0x12, 0x34, 0x56, 0xFF, 0xAB, 0xCD, 0xEF, 0x40];
        $raster = Raster::fromRgbaBytes(2, 1, $bytes);

        $this->assertSame([0x123456, 0xABCDEF], $raster->pixels());
        $this->assertSame([255, 0x40], $raster->alphas());
        $this->assertSame($bytes, $raster->toRgbaBytes());
        $this->assertEqualsWithDelta((255 + 0x40) / 510, $raster->coverage(), 1e-9);
    }

    public function testFlattenCompositesOntoTheBackground(): void
    {
        $raster = new Raster(3, 1, [0xFF0000, 0xFF0000, 0x0000FF], [255, 128, 0]);
        $flat = $raster->flatten(0xFFFFFF);

        $this->assertFalse($flat->hasTransparency());
        $this->assertSame([0xFF0000, 0xFF7F7F, 0xFFFFFF], $flat->pixels());
    }

    public function testOrientCarriesAlphaAlong(): void
    {
        $this->assertSame([10, 20], (new Raster(2, 1, [1, 2], [10, 20]))->orient(6)->alphas());
        $this->assertSame([20, 10], (new Raster(2, 1, [1, 2], [10, 20]))->orient(2)->alphas());
    }

    public function testRejectsAnAlphaPlaneOfTheWrongSize(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new Raster(2, 1, [1, 2], [255]);
    }
}
