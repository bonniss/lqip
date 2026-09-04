<?php namespace Bonniss26\Lqip\Exceptions;

use LogicException;

/**
 * Thrown when the CSS-returning API is asked for a mode that does not produce a
 * CSS value.
 *
 * A LogicException rather than a RuntimeException on purpose: nothing about the
 * input image caused this, the caller reached for the wrong method. The rest of
 * the package converts genuine runtime failures (undecodable bytes, unreadable
 * file, oversized source) into null instead.
 */
class NotCssRepresentable extends LogicException
{
    public static function forResult(string $mode, string $kind): self
    {
        return new self(sprintf(
            "Mode '%s' produces a '%s' value, which is not valid inside a CSS "
                . 'background-image list. Use PlaceholderGenerator::resultForBytes() or '
                . 'resultForFile() and read ->value() and ->cssProperty() instead.',
            $mode,
            $kind
        ));
    }
}
