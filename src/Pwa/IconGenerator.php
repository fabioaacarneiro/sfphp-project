<?php

namespace SfphpProject\src\Pwa;

use InvalidArgumentException;
use RuntimeException;

/**
 * Generates PWA icons from a source image.
 *
 * Creates the sizes the manifest, iOS and push notifications refer to:
 * - icon-192x192.png (Android home screen)
 * - icon-512x512.png (Android splash screen)
 * - apple-touch-icon.png, 180x180 (iOS home screen)
 * - badge-72x72.png (the small icon in a push notification's status bar)
 *
 * Needs ext-gd. The source may be PNG, JPEG, GIF or WebP (WebP only when GD
 * was built with it). A source that is not square is fitted inside the icon,
 * centred on a transparent background, rather than stretched.
 */
final class IconGenerator
{
    /** @var array<string, int> File name => edge in pixels */
    private const ICONS = [
        'icon-192x192.png' => 192,
        'icon-512x512.png' => 512,
        'apple-touch-icon.png' => 180,
        /*
         * Generated because the service worker's push handler defaults to it.
         * It was referenced there and never produced, so every notification
         * asked the server for a file that returned 404.
         */
        'badge-72x72.png' => 72,
    ];

    /**
     * @param string $sourcePath The source image
     * @throws InvalidArgumentException If the file does not exist
     */
    public function __construct(private string $sourcePath) {
        if (!is_file($sourcePath)) {
            throw new InvalidArgumentException("Source image not found: {$sourcePath}");
        }
    }

    /**
     * Write every icon into a directory.
     *
     * @param string $outputDir The directory, created when missing
     * @return list<string> The files written
     * @throws RuntimeException If GD is missing or the image cannot be read or written
     */
    public function generate(string $outputDir): array
    {
        /*
         * Checked up front, before anything is written, and as an exception:
         * a missing extension surfaced as an Error from deep inside a GD call,
         * which the command's catch did not see.
         */
        if (!extension_loaded('gd')) {
            throw new RuntimeException('The GD extension (ext-gd) is required to generate icons.');
        }

        if (!is_dir($outputDir) && !mkdir($outputDir, 0755, true) && !is_dir($outputDir)) {
            throw new RuntimeException("Could not create {$outputDir}");
        }

        $source = $this->loadImage($this->sourcePath);
        $generated = [];

        try {
            foreach (self::ICONS as $filename => $size) {
                $path = $outputDir . '/' . $filename;
                $this->resize($source, $size, $path);
                $generated[] = $path;
            }
        } finally {
            imagedestroy($source);
        }

        return $generated;
    }

    /**
     * Fit the source inside a square icon and write it as PNG.
     *
     * @param \GdImage $source The source image
     * @param int $size The icon's edge in pixels
     * @param string $outputPath Where to write it
     * @return void
     */
    private function resize(\GdImage $source, int $size, string $outputPath): void
    {
        $resized = imagecreatetruecolor($size, $size);

        if (!$resized) {
            throw new RuntimeException('Failed to create image resource');
        }

        // Enable alpha transparency
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        $transparent = imagecolorallocatealpha($resized, 255, 255, 255, 127);
        imagefilledrectangle($resized, 0, 0, $size, $size, $transparent);

        $sourceWidth = imagesx($source);
        $sourceHeight = imagesy($source);
        $scale = $size / max($sourceWidth, $sourceHeight);
        $width = max(1, (int) round($sourceWidth * $scale));
        $height = max(1, (int) round($sourceHeight * $scale));

        imagecopyresampled(
            $resized,
            $source,
            intdiv($size - $width, 2), intdiv($size - $height, 2), 0, 0,
            $width, $height,
            $sourceWidth, $sourceHeight
        );

        $written = imagepng($resized, $outputPath, 9);
        imagedestroy($resized);

        if (!$written) {
            throw new RuntimeException("Could not write {$outputPath}");
        }
    }

    /**
     * Open the source image.
     *
     * The type comes from getimagesize(), which reads the file's own header
     * and is part of PHP itself. mime_content_type() needs ext-fileinfo, and on
     * a PHP built without it the call was an Error the command never caught.
     *
     * @param string $path The file
     * @return \GdImage The image
     * @throws RuntimeException If the format is not supported or the file is not a readable image
     */
    private function loadImage(string $path): \GdImage
    {
        $info = @getimagesize($path);
        $type = is_array($info) ? $info[2] : null;

        $loader = match ($type) {
            IMAGETYPE_PNG => 'imagecreatefrompng',
            IMAGETYPE_JPEG => 'imagecreatefromjpeg',
            IMAGETYPE_GIF => 'imagecreatefromgif',
            IMAGETYPE_WEBP => 'imagecreatefromwebp',
            default => throw new RuntimeException(
                'Unsupported image format' . (is_array($info) ? ': ' . $info['mime'] : '')
                . '. Use a PNG, JPEG, GIF or WebP file.'
            ),
        };

        if (!function_exists($loader)) {
            throw new RuntimeException("This PHP's GD cannot read {$info['mime']} files ({$loader}() is missing).");
        }

        $image = @$loader($path);

        if (!$image instanceof \GdImage) {
            throw new RuntimeException("Could not read {$path} as an image.");
        }

        return $image;
    }
}
