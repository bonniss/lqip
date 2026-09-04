<?php namespace Bonniss26\Lqip\Support;

/**
 * sRGB <-> Oklab conversion (Björn Ottosson's perceptual colour space).
 *
 * Pure math, no GD. Oklab is used rather than sRGB or HSL because the css_only
 * format spends only 8 bits on its base colour, so *where* the quantization
 * error lands matters far more than usual - Oklab keeps that error perceptually
 * even instead of crushing dark tones.
 *
 * toSrgb() is not needed to encode anything. It is here because it is the PHP
 * mirror of what the decode stylesheet does with `oklab()`, which makes the
 * format testable end to end and keeps the two halves next to each other.
 */
final class Oklab
{
    /** sRGB electro-optical transfer function: gamma-encoded channel -> linear. */
    public static function srgbChannelToLinear(float $c01): float
    {
        return $c01 >= 0.04045
            ? pow(($c01 + 0.055) / 1.055, 2.4)
            : $c01 / 12.92;
    }

    /** The inverse: linear channel -> gamma-encoded. */
    public static function linearChannelToSrgb(float $c01): float
    {
        return $c01 >= 0.0031308
            ? 1.055 * pow($c01, 1 / 2.4) - 0.055
            : 12.92 * $c01;
    }

    /**
     * @return array{0:float,1:float,2:float} [L, a, b]
     */
    public static function fromLinearRgb(float $r, float $g, float $b): array
    {
        $l = self::cbrt(0.4122214708 * $r + 0.5363325363 * $g + 0.0514459929 * $b);
        $m = self::cbrt(0.2119034982 * $r + 0.6806995451 * $g + 0.1073969566 * $b);
        $s = self::cbrt(0.0883024619 * $r + 0.2817188376 * $g + 0.6299787005 * $b);

        return [
            $l * 0.2104542553 + $m * 0.7936177850 + $s * -0.0040720468,
            $l * 1.9779984951 + $m * -2.4285922050 + $s * 0.4505937099,
            $l * 0.0259040371 + $m * 0.7827717662 + $s * -0.8086757660,
        ];
    }

    /**
     * @param int $r 0-255
     * @param int $g 0-255
     * @param int $b 0-255
     * @return array{0:float,1:float,2:float} [L, a, b]
     */
    public static function fromSrgb(int $r, int $g, int $b): array
    {
        return self::fromLinearRgb(
            self::srgbChannelToLinear($r / 255),
            self::srgbChannelToLinear($g / 255),
            self::srgbChannelToLinear($b / 255)
        );
    }

    /**
     * @return array{0:int,1:int,2:int} [r, g, b], each clamped to 0-255
     */
    public static function toSrgb(float $L, float $a, float $b): array
    {
        $l_ = $L + 0.3963377774 * $a + 0.2158037573 * $b;
        $m_ = $L - 0.1055613458 * $a - 0.0638541728 * $b;
        $s_ = $L - 0.0894841775 * $a - 1.2914855480 * $b;

        $l = $l_ ** 3;
        $m = $m_ ** 3;
        $s = $s_ ** 3;

        $linear = [
            4.0767416621 * $l - 3.3077115913 * $m + 0.2309699292 * $s,
            -1.2684380046 * $l + 2.6097574011 * $m - 0.3413193965 * $s,
            -0.0041960863 * $l - 0.7034186147 * $m + 1.7076147010 * $s,
        ];

        $out = [];
        foreach ($linear as $channel) {
            $out[] = max(0, min(255, (int) round(self::linearChannelToSrgb($channel) * 255)));
        }

        return $out;
    }

    /**
     * PHP has no cbrt(), and `(-8) ** (1/3)` is NAN rather than -2, so the sign
     * has to be handled explicitly. The LMS values fed in here can be negative
     * for saturated colours near the sRGB gamut edge.
     */
    private static function cbrt(float $x): float
    {
        return $x < 0 ? -pow(-$x, 1 / 3) : pow($x, 1 / 3);
    }
}
