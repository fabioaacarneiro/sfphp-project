<?php

namespace SfphpProject\src\Pwa;

use InvalidArgumentException;

/**
 * Generates PWA icons from a source image.
 *
 * Creates multiple icon sizes required by PWA:
 * - 192x192 (Android home screen)
 * - 512x512 (Android splash screen)
 * - 180x180 (Apple touch icon)
 */
final class IconGenerator
{
    private const REQUIRED_SIZES = [192, 512];
    private const APPLE_TOUCH_SIZE = 180;

    public function __construct(private string $sourcePath) {
        if (!is_file($sourcePath)) {
            throw new InvalidArgumentException("Source image not found: {$sourcePath}");
        }
    }

    public function generate(string $outputDir): array
    {
        if (!is_dir($outputDir)) {
            mkdir($outputDir, 0755, true);
        }

        $generated = [];

        foreach (self::REQUIRED_SIZES as $size) {
            $filename = "icon-{$size}x{$size}.png";
            $path = $outputDir . '/' . $filename;
            $this->resize($size, $size, $path);
            $generated[] = $path;
        }

        // Apple touch icon
        $applePath = $outputDir . '/apple-touch-icon.png';
        $this->resize(self::APPLE_TOUCH_SIZE, self::APPLE_TOUCH_SIZE, $applePath);
        $generated[] = $applePath;

        return $generated;
    }

    private function resize(int $width, int $height, string $outputPath): void
    {
        if (!extension_loaded('gd')) {
            throw new InvalidArgumentException('GD extension is required to generate icons');
        }

        $sourceImage = $this->loadImage($this->sourcePath);
        $resized = imagecreatetruecolor($width, $height);

        if (!$resized) {
            throw new InvalidArgumentException('Failed to create image resource');
        }

        // Enable alpha transparency
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        $transparent = imagecolorallocatealpha($resized, 255, 255, 255, 127);
        imagefilledrectangle($resized, 0, 0, $width, $height, $transparent);

        imagecopyresampled(
            $resized,
            $sourceImage,
            0, 0, 0, 0,
            $width, $height,
            imagesx($sourceImage), imagesy($sourceImage)
        );

        imagepng($resized, $outputPath, 9);
        imagedestroy($resized);
        imagedestroy($sourceImage);
    }

    private function loadImage(string $path): \GdImage
    {
        $mimeType = mime_content_type($path);

        return match ($mimeType) {
            'image/png' => imagecreatefrompng($path),
            'image/jpeg' => imagecreatefromjpeg($path),
            'image/gif' => imagecreatefromgif($path),
            'image/webp' => imagecreatefromwebp($path),
            default => throw new InvalidArgumentException("Unsupported image format: {$mimeType}"),
        };
    }
}
