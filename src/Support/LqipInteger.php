<?php namespace Bonniss26\Lqip\Support;

/**
 * The 20-bit codec behind the css_only mode, after Lean Rada's CSS-only LQIP
 * format (https://leanrada.com/notes/css-only-lqip/).
 *
 * One signed integer carries a whole placeholder:
 *
 *     :98765432109876543210:
 *     :aaBBccDDeeFFllAAAbbb:
 *
 * - six 2-bit greyscale cells on a 3x2 grid, in row-major order
 *   (top-left, top-middle, top-right, bottom-left, bottom-middle, bottom-right)
 * - 2 bits of Oklab L, 3 of Oklab a, 3 of Oklab b for one shared base colour
 *
 * Extreme chroma subsampling, in other words: one colour for the whole image
 * plus a coarse luminance layout. Kept GD-free and separate from the strategy
 * so the bit layout and the colour search are testable without any image.
 */
final class LqipInteger
{
    /** All-zero payload: -(2**19). */
    const MIN = -524288;

    /** All-ones payload. */
    const MAX = 524287;

    /**
     * Browsers round CSS <integer> values beyond roughly +/-999999, which would
     * corrupt the payload. The 20-bit layout above cannot actually reach that far
     * - MIN/MAX are well inside it - so this is a documented invariant rather
     * than a runtime branch anything can trigger.
     */
    const CSS_INT_MIN = -999999;
    const CSS_INT_MAX = 999999;

    /** Number of luminance cells, and the grid they form. */
    const GRID_COLUMNS = 3;
    const GRID_ROWS = 2;

    /**
     * @param array $cells Six ints 0-3, row-major.
     */
    public static function pack(array $cells, int $ll, int $aaa, int $bbb): int
    {
        [$ca, $cb, $cc, $cd, $ce, $cf] = $cells;

        return self::MIN
            + (($ca & 0b11) << 18)
            + (($cb & 0b11) << 16)
            + (($cc & 0b11) << 14)
            + (($cd & 0b11) << 12)
            + (($ce & 0b11) << 10)
            + (($cf & 0b11) << 8)
            + (($ll & 0b11) << 6)
            + (($aaa & 0b111) << 3)
            + ($bbb & 0b111);
    }

    /**
     * @return array{cells:array,ll:int,aaa:int,bbb:int}
     */
    public static function unpack(int $lqip): array
    {
        $packed = $lqip - self::MIN;

        return [
            'cells' => [
                ($packed >> 18) & 0b11,
                ($packed >> 16) & 0b11,
                ($packed >> 14) & 0b11,
                ($packed >> 12) & 0b11,
                ($packed >> 10) & 0b11,
                ($packed >> 8) & 0b11,
            ],
            'll' => ($packed >> 6) & 0b11,
            'aaa' => ($packed >> 3) & 0b111,
            'bbb' => $packed & 0b111,
        ];
    }

    /**
     * The quantization grid the base colour snaps to. Mirrored exactly by the
     * decode stylesheet's oklab() call, so these constants are wire format.
     *
     * Note the asymmetric `+ 1` on b: the b axis is offset half a step relative
     * to a, which is not a typo and must not be "tidied up".
     *
     * @return array{0:float,1:float,2:float} [L, a, b]
     */
    public static function bitsToOklab(int $ll, int $aaa, int $bbb): array
    {
        return [
            $ll / 0b11 * 0.6 + 0.2,
            $aaa / 0b1000 * 0.7 - 0.35,
            ($bbb + 1) / 0b1000 * 0.7 - 0.35,
        ];
    }

    /**
     * Finds the representable base colour closest to an arbitrary Oklab colour,
     * by brute force over all 4*8*8 = 256 of them. Cheap, and it sidesteps having
     * to invert a non-uniform quantization.
     *
     * @return array{0:int,1:int,2:int} [ll, aaa, bbb]
     */
    public static function findOklabBits(float $L, float $a, float $b): array
    {
        $chroma = hypot($a, $b);
        $targetA = self::scaleComponentForDiff($a, $chroma);
        $targetB = self::scaleComponentForDiff($b, $chroma);

        $best = [0, 0, 0];
        $bestDistance = INF;

        // Ascending ll -> aaa -> bbb with a strict <, so ties settle on the lowest
        // triple. Loop order is part of the output for colours equidistant from
        // two candidates; reordering it changes results.
        for ($ll = 0; $ll < 4; $ll++) {
            for ($aaa = 0; $aaa < 8; $aaa++) {
                for ($bbb = 0; $bbb < 8; $bbb++) {
                    [$cL, $cA, $cB] = self::bitsToOklab($ll, $aaa, $bbb);
                    $candidateChroma = hypot($cA, $cB);

                    $dL = $L - $cL;
                    $dA = $targetA - self::scaleComponentForDiff($cA, $candidateChroma);
                    $dB = $targetB - self::scaleComponentForDiff($cB, $candidateChroma);

                    // hypot() only takes two arguments in PHP, so the 3-D distance is
                    // spelled out. Distance, not squared distance - the penalty below
                    // is calibrated against this scale.
                    $distance = sqrt($dL * $dL + $dA * $dA + $dB * $dB);

                    // Nudge away from the one achromatic combination. Grey is a very
                    // common average and makes for a drab placeholder, so it has to
                    // win by a margin rather than by a hair. Small enough that a
                    // genuinely grey image still picks it.
                    if ($aaa === 4 && $bbb === 3) {
                        $distance += 0.04;
                    }

                    if ($distance < $bestDistance) {
                        $bestDistance = $distance;
                        $best = [$ll, $aaa, $bbb];
                    }
                }
            }
        }

        return $best;
    }

    /**
     * Divides the a/b components by sqrt(chroma) before comparing them.
     *
     * Without this, distances near the achromatic centre are dominated by the L
     * term and hue is effectively ignored for muted colours - which is exactly
     * where a wrong hue is most visible across a whole flat placeholder.
     */
    private static function scaleComponentForDiff(float $x, float $chroma): float
    {
        return $x / (1e-6 + sqrt($chroma));
    }
}
