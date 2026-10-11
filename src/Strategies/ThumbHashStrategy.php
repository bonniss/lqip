<?php namespace Bonniss26\Lqip\Strategies;

use Bonniss26\Lqip\Drivers\Driver;
use Bonniss26\Lqip\Drivers\Image;
use Bonniss26\Lqip\LqipResult;
use Bonniss26\Lqip\Support\ThumbHash;

/**
 * A ThumbHash (https://evanw.github.io/thumbhash/), base64-encoded: ~25-35
 * characters that a client-side decoder - JS, Swift, Kotlin, Rust - renders
 * into a blurred image with the right colours, shape and transparency.
 *
 * Not CSS. It is for APIs, headless front ends and mobile apps that already
 * decode hashes; a server-rendered page wants `blur`, which needs no script.
 */
class ThumbHashStrategy implements LqipStrategy
{
    /**
     * The long edge sampled before encoding. A ThumbHash keeps at most 7x7
     * low-frequency coefficients, so beyond a few dozen pixels a bigger
     * sample only costs time. Measured on 41 photos against the reference
     * 100px: 30 identical hashes, the rest decoding within 2.1/255 on
     * average, at 1.4 ms per encode instead of 12.7.
     */
    const SAMPLE_LONG_EDGE = 32;

    public function generate(Image $image, Driver $driver, array $options): ?LqipResult
    {
        $scale = self::SAMPLE_LONG_EDGE / max($image->width(), $image->height());
        $raster = $image->sample(
            max(1, min(ThumbHash::MAX_SIZE, (int) round($image->width() * $scale))),
            max(1, min(ThumbHash::MAX_SIZE, (int) round($image->height() * $scale)))
        );

        return LqipResult::hash(base64_encode(ThumbHash::encode($raster)));
    }
}
