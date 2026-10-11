<?php namespace Bonniss26\Lqip\Tests\Support;

use Bonniss26\Lqip\Support\ExifOrientation;
use PHPUnit\Framework\TestCase;

class ExifOrientationTest extends TestCase
{
    public function testReadsTheTagInBothByteOrders(): void
    {
        foreach ([1, 3, 6, 8] as $orientation) {
            $this->assertSame($orientation, ExifOrientation::fromBytes(self::jpegWithOrientation($orientation, 'II')), "II {$orientation}");
            $this->assertSame($orientation, ExifOrientation::fromBytes(self::jpegWithOrientation($orientation, 'MM')), "MM {$orientation}");
        }
    }

    public function testFindsTheExifSegmentAfterOtherSegments(): void
    {
        $jfif = "\xFF\xE0" . pack('n', 16) . "JFIF\x00\x01\x01\x00\x00\x01\x00\x01\x00\x00";
        $exif = substr(self::jpegWithOrientation(6, 'II'), 2);

        $this->assertSame(6, ExifOrientation::fromBytes("\xFF\xD8" . $jfif . $exif));
    }

    public function testAnythingElseIsUpright(): void
    {
        $this->assertSame(1, ExifOrientation::fromBytes(''));
        $this->assertSame(1, ExifOrientation::fromBytes('not an image'));
        $this->assertSame(1, ExifOrientation::fromBytes("\x89PNG\r\n\x1A\n"));
        // A JPEG with no metadata at all.
        $this->assertSame(1, ExifOrientation::fromBytes("\xFF\xD8\xFF\xDA\x00\x02"));
        // An out-of-range tag value.
        $this->assertSame(1, ExifOrientation::fromBytes(self::jpegWithOrientation(9, 'II')));
    }

    public function testTruncatedInputDoesNotEmitAWarning(): void
    {
        $full = self::jpegWithOrientation(6, 'MM');

        $raised = null;
        set_error_handler(function ($level, $message) use (&$raised) {
            $raised = $message;

            return true;
        });

        try {
            for ($length = 0; $length < strlen($full); $length++) {
                ExifOrientation::fromBytes(substr($full, 0, $length));
            }
        } finally {
            restore_error_handler();
        }

        $this->assertNull($raised, "expected no PHP diagnostic, got: {$raised}");
    }

    /**
     * SOI followed by a single APP1 Exif segment holding one IFD entry. Enough
     * for the parser, which never looks at image data.
     */
    public static function jpegWithOrientation(int $orientation, string $order): string
    {
        $short = $order === 'II' ? 'v' : 'n';
        $long = $order === 'II' ? 'V' : 'N';

        $tiff = $order
            . pack($short, 42)
            . pack($long, 8)               // IFD0 straight after the header
            . pack($short, 1)              // one entry
            . pack($short, 0x0112)         // Orientation
            . pack($short, 3)              // SHORT
            . pack($long, 1)               // count
            . pack($short, $orientation) . "\x00\x00"
            . pack($long, 0);              // no next IFD

        $payload = "Exif\x00\x00" . $tiff;

        return "\xFF\xD8\xFF\xE1" . pack('n', strlen($payload) + 2) . $payload . "\xFF\xD9";
    }
}
