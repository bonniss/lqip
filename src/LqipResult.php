<?php namespace Bonniss26\Lqip;

/**
 * What a strategy produced, plus what kind of thing it is.
 *
 * Most modes return something you can drop straight into a CSS
 * `background-image` list. `css_only` cannot: it produces a bare integer meant
 * for a CSS custom property, decoded by a stylesheet the consuming site ships
 * once. Those two are not interchangeable, and a plain string cannot say which
 * one you are holding - hence this type.
 *
 * Deliberately has no __toString(). Stringifying a css_var value into a
 * background-image context is precisely the mistake this class exists to make
 * impossible; an explicit toCss() that can return null is the point.
 */
final class LqipResult
{
    /** Valid inside a CSS `background-image` list. */
    const KIND_CSS = 'css';

    /**
     * A bare value for a CSS custom property (e.g. `--lqip:192900`), useless
     * without the matching decode stylesheet.
     */
    const KIND_CSS_VAR = 'css_var';

    /**
     * These strings are part of the wire format: the planned WinterCMS adapter
     * caches generated placeholders in a database alongside their kind. When the
     * PHP floor moves to 8.1 these should become a backed `enum LqipKind: string`
     * keeping exactly these backing values, so already-cached rows stay readable.
     */
    private function __construct(
        private string $kind,
        private string $value,
        private ?string $property
    ) {
    }

    public static function css(string $value): self
    {
        return new self(self::KIND_CSS, $value, null);
    }

    public static function cssVar(string $value, string $property): self
    {
        return new self(self::KIND_CSS_VAR, $value, $property);
    }

    public function kind(): string
    {
        return $this->kind;
    }

    /** The raw generated value, whatever its kind. */
    public function value(): string
    {
        return $this->value;
    }

    /** The custom property this value belongs in, or null for KIND_CSS. */
    public function cssProperty(): ?string
    {
        return $this->property;
    }

    /** The value if it can go in a `background-image` list, otherwise null. */
    public function toCss(): ?string
    {
        return $this->kind === self::KIND_CSS ? $this->value : null;
    }

    public function isCssRepresentable(): bool
    {
        return $this->kind === self::KIND_CSS;
    }

    /**
     * The declaration to put in a `style` attribute, for either kind. Saves every
     * caller hand-concatenating it - which is where the custom property name
     * would otherwise get mistyped, silently producing a placeholder that no
     * stylesheet matches.
     */
    public function toStyleAttribute(): string
    {
        return $this->kind === self::KIND_CSS_VAR
            ? "{$this->property}:{$this->value}"
            : "background-image:{$this->value}";
    }
}
