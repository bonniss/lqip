<?php namespace Bonniss26\Lqip\Tests;

use Bonniss26\Lqip\LqipResult;
use PHPUnit\Framework\TestCase;

class LqipResultTest extends TestCase
{
    public function testCssResultExposesItsValueAsCss(): void
    {
        $result = LqipResult::css('linear-gradient(#abc,#abc)');

        $this->assertSame(LqipResult::KIND_CSS, $result->kind());
        $this->assertSame('linear-gradient(#abc,#abc)', $result->value());
        $this->assertSame('linear-gradient(#abc,#abc)', $result->toCss());
        $this->assertTrue($result->isCssRepresentable());
        $this->assertNull($result->cssProperty());
        $this->assertSame('background-image:linear-gradient(#abc,#abc);background-size:cover', $result->toStyleAttribute());
    }

    public function testCssVarResultIsNotCssRepresentable(): void
    {
        $result = LqipResult::cssVar('192900', '--lqip');

        $this->assertSame(LqipResult::KIND_CSS_VAR, $result->kind());
        $this->assertSame('192900', $result->value());
        $this->assertNull($result->toCss(), 'a custom-property value is not a background-image value');
        $this->assertFalse($result->isCssRepresentable());
        $this->assertSame('--lqip', $result->cssProperty());
        $this->assertSame('--lqip:192900', $result->toStyleAttribute());
    }

    public function testDimensionsAreAttachedToACopy(): void
    {
        $bare = LqipResult::css('linear-gradient(#abc,#abc)');
        $sized = $bare->withDimensions(1600, 900);

        $this->assertNull($bare->width(), 'the original is not modified');
        $this->assertNull($bare->aspectRatio());
        $this->assertSame(1600, $sized->width());
        $this->assertSame(900, $sized->height());
        $this->assertEqualsWithDelta(16 / 9, $sized->aspectRatio(), 1e-9);
    }

    public function testStyleAttributeReservesTheShapeOnlyWhenAsked(): void
    {
        $css = LqipResult::css('linear-gradient(#abc,#abc)')->withDimensions(1600, 900);
        $var = LqipResult::cssVar('192900', '--lqip')->withDimensions(3, 4);

        $this->assertStringNotContainsString('aspect-ratio', $css->toStyleAttribute());
        $this->assertSame(
            'background-image:linear-gradient(#abc,#abc);background-size:cover;aspect-ratio:1600/900',
            $css->toStyleAttribute(['aspect_ratio' => true])
        );
        $this->assertSame('--lqip:192900;aspect-ratio:3/4', $var->toStyleAttribute(['aspect_ratio' => true]));
        $this->assertSame(
            '--lqip:192900',
            LqipResult::cssVar('192900', '--lqip')->toStyleAttribute(['aspect_ratio' => true]),
            'no size known, nothing to reserve'
        );
    }

    /**
     * Pins a deliberate omission. With __toString(), a css_var result would
     * silently interpolate into a background-image context and produce CSS that
     * parses but renders nothing - the exact failure this type exists to catch.
     */
    public function testHasNoStringCastToPreventSilentMisuse(): void
    {
        $this->assertFalse(method_exists(LqipResult::class, '__toString'));
    }
}
