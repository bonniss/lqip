<?php namespace Bonniss26\Lqip\Tests\Support;

use Bonniss26\Lqip\Support\Oklab;
use PHPUnit\Framework\TestCase;

class OklabTest extends TestCase
{
    public function testSrgbRoundTripsThroughOklab(): void
    {
        $colors = [
            [0, 0, 0], [255, 255, 255], [128, 128, 128],
            [255, 0, 0], [0, 255, 0], [0, 0, 255],
            [0xE0, 0x40, 0x20], [0x12, 0x34, 0x56], [0x7C, 0x6A, 0x54],
        ];

        foreach ($colors as $rgb) {
            $this->assertEqualsWithDelta(
                $rgb,
                Oklab::toSrgb(...Oklab::fromSrgb(...$rgb)),
                1,
                sprintf('#%02x%02x%02x did not round-trip', ...$rgb)
            );
        }
    }

    public function testWhiteAndBlackSitAtTheLightnessAnchors(): void
    {
        [$whiteL, $whiteA, $whiteB] = Oklab::fromSrgb(255, 255, 255);
        $this->assertEqualsWithDelta(1.0, $whiteL, 1e-6);
        $this->assertEqualsWithDelta(0.0, $whiteA, 1e-6);
        $this->assertEqualsWithDelta(0.0, $whiteB, 1e-6);

        $this->assertEqualsWithDelta([0.0, 0.0, 0.0], Oklab::fromSrgb(0, 0, 0), 1e-9);
    }

    /**
     * The transfer function is piecewise; if the branch condition drifts between
     * `>=` and `>`, or a constant is mistyped, the two halves stop meeting.
     */
    public function testTransferFunctionIsContinuousAtTheBreakpoint(): void
    {
        $below = Oklab::srgbChannelToLinear(0.04045 - 1e-9);
        $above = Oklab::srgbChannelToLinear(0.04045);

        $this->assertEqualsWithDelta($below, $above, 1e-6);
    }

    public function testTransferFunctionsAreInverses(): void
    {
        foreach ([0.0, 0.001, 0.04045, 0.2, 0.5, 1.0] as $value) {
            $this->assertEqualsWithDelta(
                $value,
                Oklab::linearChannelToSrgb(Oklab::srgbChannelToLinear($value)),
                1e-9
            );
        }
    }

    /**
     * PHP has no cbrt() and `(-8) ** (1/3)` is NAN. The LMS values feeding the
     * cube root go negative for saturated colours, so a naive `**` would poison
     * the whole conversion with NAN rather than failing visibly.
     */
    public function testHandlesNegativeLmsValuesWithoutProducingNan(): void
    {
        $oklab = Oklab::fromLinearRgb(-0.05, 0.4, 0.9);

        foreach ($oklab as $component) {
            $this->assertFalse(is_nan($component), 'Oklab conversion produced NAN');
        }
    }
}
