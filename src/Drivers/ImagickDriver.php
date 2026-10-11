<?php namespace Bonniss26\Lqip\Drivers;

use Bonniss26\Lqip\PlaceholderGenerator;
use Bonniss26\Lqip\Support\ExifOrientation;
use Bonniss26\Lqip\Support\Raster;
use Throwable;

/**
 * ImageMagick, through ext-imagick.
 *
 * The reason to prefer it is decoding. A placeholder needs a few hundred pixels
 * out of a source with millions, and for JPEG - most photographs - ImageMagick
 * passes a size hint to libjpeg, which then decodes at 1/2, 1/4 or 1/8 scale
 * directly instead of building the full bitmap first. GD has no equivalent.
 */
final class ImagickDriver implements Driver
{
    /**
     * The only formats handed to ImageMagick, keyed by the IMAGETYPE_* constant
     * getimagesizefromstring() reports.
     *
     * An allowlist, not a convenience. ImageMagick also understands PostScript,
     * SVG, MSL and other formats that delegate to external programs, which is the
     * attack surface behind ImageTragick. The format is sniffed by PHP first and
     * then forced on the reader, so a crafted file cannot pick its own coder.
     */
    const FORMATS = [
        IMAGETYPE_JPEG => 'JPEG',
        IMAGETYPE_PNG => 'PNG',
        IMAGETYPE_GIF => 'GIF',
        IMAGETYPE_WEBP => 'WEBP',
        IMAGETYPE_BMP => 'BMP',
    ];

    /** @var array<string,bool> */
    private static $formatSupport = [];

    public function name(): string
    {
        return 'imagick';
    }

    public function isAvailable(): bool
    {
        return extension_loaded('imagick') && class_exists(\Imagick::class);
    }

    public function load(string $bytes): ?Image
    {
        if (!$this->isAvailable()) {
            return null;
        }

        $size = @getimagesizefromstring($bytes);
        if (!$size || $size[0] < 1 || $size[1] < 1 || $size[0] * $size[1] > PlaceholderGenerator::MAX_SOURCE_PIXELS) {
            return null;
        }

        $format = self::FORMATS[$size[2]] ?? (defined('IMAGETYPE_AVIF') && $size[2] === IMAGETYPE_AVIF ? 'AVIF' : null);
        if ($format === null) {
            return null;
        }

        $image = null;

        try {
            $image = new \Imagick();
            $image->setFormat($format);

            if ($format === 'JPEG') {
                // libjpeg decodes straight to the smallest 1/2^n scale that still
                // covers this size, so the working copy below is never upscaled.
                $edge = PlaceholderGenerator::WORKING_LONG_EDGE;
                $image->setOption('jpeg:size', "{$edge}x{$edge}");
            }

            $image->readImageBlob($bytes);

            // Animated GIF/WebP: keep only the first frame, as GD does.
            $image->setFirstIterator();
            $frame = $image->getImage();

            return new ImagickSource(
                self::workingCopy($frame),
                $size[0],
                $size[1],
                ExifOrientation::fromBytes($bytes)
            );
        } catch (Throwable $e) {
            return null;
        } finally {
            if ($image) {
                $image->clear();
            }
        }
    }

    public function supportsFormat(string $format): bool
    {
        if (!$this->isAvailable()) {
            return false;
        }

        $magick = $format === 'jpg' ? 'JPEG' : strtoupper($format);
        if (!in_array($magick, ['JPEG', 'WEBP'], true)) {
            return false;
        }

        if (!isset(self::$formatSupport[$magick])) {
            try {
                self::$formatSupport[$magick] = count(\Imagick::queryFormats($magick)) > 0;
            } catch (Throwable $e) {
                self::$formatSupport[$magick] = false;
            }
        }

        return self::$formatSupport[$magick];
    }

    public function encode(Raster $raster, string $format, int $quality): ?array
    {
        if (!$this->supportsFormat($format)) {
            return null;
        }

        $image = null;

        try {
            $image = new \Imagick();
            $image->newImage($raster->width(), $raster->height(), new \ImagickPixel('black'));
            $image->importImagePixels(
                0, 0,
                $raster->width(), $raster->height(),
                'RGB',
                \Imagick::PIXEL_CHAR,
                $raster->toRgbBytes()
            );

            $image->setImageFormat($format === 'webp' ? 'WEBP' : 'JPEG');
            // ImageMagick reads 0 as "use the default", not "lowest" as GD does.
            $image->setImageCompressionQuality(max(1, $quality));
            $image->stripImage();

            $bytes = $image->getImageBlob();
        } catch (Throwable $e) {
            return null;
        } finally {
            if ($image) {
                $image->clear();
            }
        }

        if (!$bytes) {
            return null;
        }

        return ['mime' => $format === 'webp' ? 'image/webp' : 'image/jpeg', 'bytes' => $bytes];
    }

    /**
     * Normalises a decoded frame into an opaque sRGB working copy no longer than
     * WORKING_LONG_EDGE, so every later sample is cheap.
     */
    private static function workingCopy(\Imagick $frame): \Imagick
    {
        if ($frame->getImageColorspace() === \Imagick::COLORSPACE_CMYK) {
            $frame->transformImageColorspace(\Imagick::COLORSPACE_SRGB);
        }

        // Composite transparency onto black, matching GD's opaque canvas.
        if ($frame->getImageAlphaChannel()) {
            $frame->setImageBackgroundColor(new \ImagickPixel('black'));
            $frame->setImageAlphaChannel(\Imagick::ALPHACHANNEL_REMOVE);
        }

        $width = $frame->getImageWidth();
        $height = $frame->getImageHeight();
        $scale = min(1, PlaceholderGenerator::WORKING_LONG_EDGE / max($width, $height));

        if ($scale < 1) {
            $frame->resizeImage(
                max(1, (int) round($width * $scale)),
                max(1, (int) round($height * $scale)),
                \Imagick::FILTER_BOX,
                1
            );
        }

        return $frame;
    }
}
