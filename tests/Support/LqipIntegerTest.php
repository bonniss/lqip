<?php namespace Bonniss26\Lqip\Tests\Support;

use Bonniss26\Lqip\Support\LqipInteger;
use Bonniss26\Lqip\Support\Oklab;
use PHPUnit\Framework\TestCase;

class LqipIntegerTest extends TestCase
{
    /**
     * Taken from a real page on leanrada.com, the format's origin. If this test
     * fails the bit layout has drifted away from the format every decode
     * stylesheet in the wild implements.
     */
    const REFERENCE_VALUE = 192900;
    const REFERENCE_CELLS = [2, 2, 3, 3, 0, 1];
    const REFERENCE_LL = 2;
    const REFERENCE_AAA = 0;
    const REFERENCE_BBB = 4;

    public function testUnpacksTheReferenceVector(): void
    {
        $unpacked = LqipInteger::unpack(self::REFERENCE_VALUE);

        $this->assertSame(self::REFERENCE_CELLS, $unpacked['cells']);
        $this->assertSame(self::REFERENCE_LL, $unpacked['ll']);
        $this->assertSame(self::REFERENCE_AAA, $unpacked['aaa']);
        $this->assertSame(self::REFERENCE_BBB, $unpacked['bbb']);
    }

    public function testPacksTheReferenceVector(): void
    {
        $this->assertSame(
            self::REFERENCE_VALUE,
            LqipInteger::pack(self::REFERENCE_CELLS, self::REFERENCE_LL, self::REFERENCE_AAA, self::REFERENCE_BBB)
        );
    }

    public function testReferenceVectorBaseColorDecodesToTheExpectedRgb(): void
    {
        $oklab = LqipInteger::bitsToOklab(self::REFERENCE_LL, self::REFERENCE_AAA, self::REFERENCE_BBB);

        $this->assertSame([0, 182, 69], Oklab::toSrgb(...$oklab));
    }

    public function testPacksTheFieldBoundaries(): void
    {
        $this->assertSame(LqipInteger::MIN, LqipInteger::pack([0, 0, 0, 0, 0, 0], 0, 0, 0));
        $this->assertSame(LqipInteger::MAX, LqipInteger::pack([3, 3, 3, 3, 3, 3], 3, 7, 7));
    }

    public function testPackAndUnpackRoundTripAcrossTheWholeRange(): void
    {
        // Prime stride, so the sample walks every field rather than aligning to
        // one of the power-of-two bit boundaries.
        for ($value = LqipInteger::MIN; $value <= LqipInteger::MAX; $value += 997) {
            $fields = LqipInteger::unpack($value);

            $this->assertSame(
                $value,
                LqipInteger::pack($fields['cells'], $fields['ll'], $fields['aaa'], $fields['bbb'])
            );
        }
    }

    /**
     * The layout cannot produce a value a browser would round, so this is an
     * invariant rather than a branch anything can hit - asserted here so that
     * widening the field count later fails loudly instead of silently corrupting
     * every placeholder.
     */
    public function testEveryPackedValueFitsTheCssIntegerLimit(): void
    {
        $this->assertGreaterThanOrEqual(LqipInteger::CSS_INT_MIN, LqipInteger::MIN);
        $this->assertLessThanOrEqual(LqipInteger::CSS_INT_MAX, LqipInteger::MAX);
    }

    public function testBitsToOklabMatchesTheReferenceQuantizationGrid(): void
    {
        // Pins the ranges, and specifically the asymmetric +1 on b: at bbb=0 the b
        // axis starts a half-step above where a does.
        $this->assertEqualsWithDelta([0.2, -0.35, -0.2625], LqipInteger::bitsToOklab(0, 0, 0), 1e-9);
        $this->assertEqualsWithDelta([0.8, 0.2625, 0.35], LqipInteger::bitsToOklab(3, 7, 7), 1e-9);
    }

    /**
     * Exercises the distance metric, the chroma scaling, the tie-breaking and the
     * grey penalty in one go: every colour the format can express must be its own
     * closest match, or encoding is lossy before quantization even starts.
     *
     * All 256 pass, including the achromatic (aaa=4, bbb=3) combination - the
     * 0.04 penalty is small enough that it only breaks near-ties and never
     * displaces an exact hit.
     */
    public function testEveryRepresentableColorIsItsOwnNearestMatch(): void
    {
        for ($ll = 0; $ll < 4; $ll++) {
            for ($aaa = 0; $aaa < 8; $aaa++) {
                for ($bbb = 0; $bbb < 8; $bbb++) {
                    $this->assertSame(
                        [$ll, $aaa, $bbb],
                        LqipInteger::findOklabBits(...LqipInteger::bitsToOklab($ll, $aaa, $bbb)),
                        "({$ll}, {$aaa}, {$bbb}) did not round-trip"
                    );
                }
            }
        }
    }
}
