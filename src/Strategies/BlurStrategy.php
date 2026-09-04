<?php namespace Bonniss26\Lqip\Strategies;

use Bonniss26\Lqip\LqipResult;

/**
 * Blurred miniature, encoded as a data URI - the most realistic option, and
 * also the most expensive one.
 */
class BlurStrategy implements LqipStrategy
{
    public function generate($source, int $origWidth, int $origHeight, array $options): ?LqipResult
    {
        $targetLongEdge = (int) $options['long_edge'];
        $quality = (int) $options['quality'];
        $blurEnabled = (bool) $options['blur'];
        $format = $options['format'];

        $longEdge = max($origWidth, $origHeight);
        $scale = $targetLongEdge / $longEdge;
        $targetWidth = max(1, (int) round($origWidth * $scale));
        $targetHeight = max(1, (int) round($origHeight * $scale));

        $resized = imagecreatetruecolor($targetWidth, $targetHeight);
        imagecopyresampled($resized, $source, 0, 0, 0, 0, $targetWidth, $targetHeight, $origWidth, $origHeight);

        if ($blurEnabled) {
            // Baked into the pixels rather than left to a CSS `filter: blur()` on the
            // <img> - that would apply to the whole element, including the real photo
            // once it's loaded on top of this background, permanently blurring it too.
            imagefilter($resized, IMG_FILTER_GAUSSIAN_BLUR);
            imagefilter($resized, IMG_FILTER_GAUSSIAN_BLUR);
        }

        ob_start();
        if ($format === 'webp' && function_exists('imagewebp')) {
            imagewebp($resized, null, $quality);
            $mime = 'image/webp';
        } else {
            imagejpeg($resized, null, $quality);
            $mime = 'image/jpeg';
        }
        $binary = ob_get_clean();
        imagedestroy($resized);

        if (!$binary) {
            return null;
        }

        $dataUri = "data:{$mime};base64," . base64_encode($binary);

        return LqipResult::css("url('{$dataUri}')");
    }
}
