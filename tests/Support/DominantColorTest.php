<?php namespace Bonniss26\Lqip\Tests\Support;

use Bonniss26\Lqip\Support\DominantColor;
use PHPUnit\Framework\TestCase;

class DominantColorTest extends TestCase
{
    public function testSolidImageYieldsThatColor(): void
    {
        $image = imagecreatetruecolor(8, 8);
        imagefill($image, 0, 0, imagecolorallocate($image, 0xE0, 0x40, 0x20));

        $rgb = DominantColor::forImage($image, 8, 8);
        imagedestroy($image);

        // Not exact: imagetruecolortopalette() shifts colours slightly, and how
        // much it shifts varies between libgd builds.
        $this->assertNotNull($rgb);
        $this->assertEqualsWithDelta(0xE0, $rgb[0], 8);
        $this->assertEqualsWithDelta(0x40, $rgb[1], 8);
        $this->assertEqualsWithDelta(0x20, $rgb[2], 8);
    }

    public function testMajorityColorWinsOverMinorityColor(): void
    {
        // 48 red pixels vs 16 blue - no tie, so scan order is irrelevant here.
        $image = imagecreatetruecolor(8, 8);
        imagefilledrectangle($image, 0, 0, 7, 7, imagecolorallocate($image, 0xFF, 0x00, 0x00));
        imagefilledrectangle($image, 0, 0, 7, 1, imagecolorallocate($image, 0x00, 0x00, 0xFF));

        $rgb = DominantColor::forImage($image, 8, 8);
        imagedestroy($image);

        $this->assertNotNull($rgb);
        $this->assertGreaterThan($rgb[2], $rgb[0], 'red channel should dominate the blue one');
    }

    public function testDoesNotModifyTheSourceImage(): void
    {
        $image = imagecreatetruecolor(8, 8);
        imagefilledrectangle($image, 0, 0, 7, 7, imagecolorallocate($image, 0x12, 0x34, 0x56));
        imagefilledrectangle($image, 0, 0, 3, 3, imagecolorallocate($image, 0xAB, 0xCD, 0xEF));
        $before = [imagecolorat($image, 0, 0), imagecolorat($image, 7, 7)];

        DominantColor::forImage($image, 8, 8);

        $this->assertSame(
            $before,
            [imagecolorat($image, 0, 0), imagecolorat($image, 7, 7)],
            'the caller owns the source image; it must come back untouched'
        );
        imagedestroy($image);
    }
}
