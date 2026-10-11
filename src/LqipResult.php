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
     * An encoded placeholder (e.g. a base64 ThumbHash) for a client-side
     * decoder to turn into an image - not CSS at all. Meant for APIs and apps.
     */
    const KIND_HASH = 'hash';

    /**
     * These strings are part of the wire format: the planned WinterCMS adapter
     * caches generated placeholders in a database alongside their kind. When the
     * PHP floor moves to 8.1 these should become a backed `enum LqipKind: string`
     * keeping exactly these backing values, so already-cached rows stay readable.
     */
    /** @var string */
    private $kind;

    /** @var string */
    private $value;

    /** @var string|null */
    private $property;

    /** @var int|null */
    private $width;

    /** @var int|null */
    private $height;

    private function __construct(string $kind, string $value, ?string $property)
    {
        $this->kind = $kind;
        $this->value = $value;
        $this->property = $property;
    }

    /**
     * A copy carrying the source image's displayed size. PlaceholderGenerator
     * attaches it to every result, so a strategy - built-in or custom - never
     * has to.
     */
    public function withDimensions(int $width, int $height): self
    {
        $copy = clone $this;
        $copy->width = $width;
        $copy->height = $height;

        return $copy;
    }

    /**
     * Width of the source image as displayed, in pixels - after its EXIF
     * orientation, so a portrait phone photo stored sideways reports its
     * portrait width. getimagesize() reports the stored size and gets those
     * the wrong way round. Null only for a result built by hand.
     */
    public function width(): ?int
    {
        return $this->width;
    }

    /** Height of the source image as displayed; see width(). */
    public function height(): ?int
    {
        return $this->height;
    }

    /** Width divided by height, or null when the size is unknown. */
    public function aspectRatio(): ?float
    {
        return $this->width && $this->height ? $this->width / $this->height : null;
    }

    public static function css(string $value): self
    {
        return new self(self::KIND_CSS, $value, null);
    }

    public static function cssVar(string $value, string $property): self
    {
        return new self(self::KIND_CSS_VAR, $value, $property);
    }

    public static function hash(string $value): self
    {
        return new self(self::KIND_HASH, $value, null);
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
     * The declaration to put in a `style` attribute, for either CSS kind. Saves
     * every caller hand-concatenating it - which is where the custom property
     * name would otherwise get mistyped, silently producing a placeholder that
     * no stylesheet matches. A hash has no CSS form and gives '' (or only the
     * aspect-ratio, when asked), so a template using this cannot break.
     *
     * A CSS value comes with `background-size:cover`. A background image is
     * drawn at its intrinsic size and repeated by default, so the blur mode's
     * 16px miniature would otherwise tile across the element instead of
     * stretching over it. Gradients have no intrinsic size and fill the box
     * either way, so the declaration is harmless for every other mode.
     *
     * With ['aspect_ratio' => true] it also reserves the image's shape
     * (`aspect-ratio:1600/900`), so an element without fixed dimensions has
     * the right height before the photo arrives instead of jumping when it
     * does. Left out by default because it changes layout, which a caller
     * styling the element themselves may not want.
     *
     * @param array{aspect_ratio?:bool} $options
     */
    public function toStyleAttribute(array $options = []): string
    {
        if ($this->kind === self::KIND_HASH) {
            $style = ''; // a hash is for a client-side decoder; there is no CSS to give
        } elseif ($this->kind === self::KIND_CSS_VAR) {
            $style = "{$this->property}:{$this->value}";
        } else {
            $style = "background-image:{$this->value};background-size:cover";
        }

        if (!empty($options['aspect_ratio']) && $this->width && $this->height) {
            $style .= ($style === '' ? '' : ';') . "aspect-ratio:{$this->width}/{$this->height}";
        }

        return $style;
    }
}
