<?php namespace Bonniss26\Lqip\Support;

/**
 * Reads the EXIF Orientation tag straight out of JPEG bytes.
 *
 * Browsers rotate a JPEG according to this tag; GD ignores it, and Imagick only
 * applies it when asked. Without honouring it, a portrait photo straight off a
 * phone - stored landscape with Orientation 6 - gets a landscape placeholder
 * under a portrait image.
 *
 * Hand-rolled rather than exif_read_data() because ext-exif is one more
 * extension a host may lack, and that function emits warnings on malformed
 * data, which frameworks routinely promote to exceptions. Only the handful of
 * bytes on the path to one tag are read; anything unexpected means 1.
 */
final class ExifOrientation
{
    const TAG_ORIENTATION = 0x0112;

    /**
     * @return int 1-8; 1 (as stored) for non-JPEG input, a missing tag, or
     *             anything that does not parse.
     */
    public static function fromBytes(string $bytes): int
    {
        $length = strlen($bytes);
        if ($length < 4 || $bytes[0] !== "\xFF" || $bytes[1] !== "\xD8") {
            return 1;
        }

        $offset = 2;
        while ($offset + 4 <= $length) {
            if ($bytes[$offset] !== "\xFF") {
                return 1;
            }

            $marker = ord($bytes[$offset + 1]);

            // Fill bytes before a marker.
            if ($marker === 0xFF) {
                $offset++;
                continue;
            }

            // Start of scan or end of image: metadata segments all come before these.
            if ($marker === 0xDA || $marker === 0xD9) {
                return 1;
            }

            $segmentLength = (ord($bytes[$offset + 2]) << 8) | ord($bytes[$offset + 3]);
            if ($segmentLength < 2 || $offset + 2 + $segmentLength > $length) {
                return 1;
            }

            if ($marker === 0xE1 && substr($bytes, $offset + 4, 6) === "Exif\x00\x00") {
                return self::fromTiff(substr($bytes, $offset + 10, $segmentLength - 8));
            }

            $offset += 2 + $segmentLength;
        }

        return 1;
    }

    private static function fromTiff(string $tiff): int
    {
        $length = strlen($tiff);
        if ($length < 8) {
            return 1;
        }

        $order = substr($tiff, 0, 2);
        if ($order === 'II') {
            $short = 'v';
            $long = 'V';
        } elseif ($order === 'MM') {
            $short = 'n';
            $long = 'N';
        } else {
            return 1;
        }

        $ifd = unpack($long, substr($tiff, 4, 4))[1];
        if ($ifd + 2 > $length) {
            return 1;
        }

        $entries = unpack($short, substr($tiff, $ifd, 2))[1];
        for ($i = 0; $i < $entries; $i++) {
            $entry = $ifd + 2 + $i * 12;
            if ($entry + 12 > $length) {
                return 1;
            }

            if (unpack($short, substr($tiff, $entry, 2))[1] !== self::TAG_ORIENTATION) {
                continue;
            }

            // Type SHORT, value held inline in the first two bytes of the value field.
            $value = unpack($short, substr($tiff, $entry + 8, 2))[1];

            return ($value >= 1 && $value <= 8) ? $value : 1;
        }

        return 1;
    }
}
