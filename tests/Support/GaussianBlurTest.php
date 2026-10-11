<?php namespace Bonniss26\Lqip\Tests\Support;

use Bonniss26\Lqip\Support\GaussianBlur;
use Bonniss26\Lqip\Support\Raster;
use PHPUnit\Framework\TestCase;

class GaussianBlurTest extends TestCase
{
    /**
     * The claim GaussianBlur's docblock makes, checked: bit-for-bit what GD's
     * IMG_FILTER_GAUSSIAN_BLUR produces. The blur defaults were measured on GD
     * output, so a near miss would quietly invalidate those numbers.
     */
    public function testMatchesGdBitForBit(): void
    {
        if (!function_exists('imagefilter')) {
            $this->markTestSkipped('GD is needed as the reference');
        }

        mt_srand(1234);
        foreach ([[16, 9], [1, 1], [3, 2], [5, 17]] as [$w, $h]) {
            $pixels = [];
            for ($i = 0; $i < $w * $h; $i++) {
                $pixels[] = mt_rand(0, 0xFFFFFF);
            }
            $raster = new Raster($w, $h, $pixels);

            $gd = imagecreatetruecolor($w, $h);
            foreach ($pixels as $i => $pixel) {
                imagesetpixel($gd, $i % $w, intdiv($i, $w), $pixel);
            }

            for ($passes = 1; $passes <= 3; $passes++) {
                imagefilter($gd, IMG_FILTER_GAUSSIAN_BLUR);

                $expected = [];
                for ($y = 0; $y < $h; $y++) {
                    for ($x = 0; $x < $w; $x++) {
                        $expected[] = imagecolorat($gd, $x, $y) & 0xFFFFFF;
                    }
                }

                $this->assertSame(
                    $expected,
                    GaussianBlur::apply($raster, $passes)->pixels(),
                    "{$w}x{$h}, {$passes} pass(es)"
                );
            }
        }
    }

    public function testASolidImageIsUnchanged(): void
    {
        $raster = new Raster(4, 3, array_fill(0, 12, 0x336699));

        $this->assertSame($raster->pixels(), GaussianBlur::apply($raster, 2)->pixels());
    }

    public function testZeroPassesIsANoOp(): void
    {
        $raster = new Raster(2, 1, [0x000000, 0xFFFFFF]);

        $this->assertSame([0x000000, 0xFFFFFF], GaussianBlur::apply($raster, 0)->pixels());
    }
}
