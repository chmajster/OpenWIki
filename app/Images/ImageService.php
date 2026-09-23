<?php

declare(strict_types=1);

namespace OpenWiki\Images;

use OpenWiki\Attachments\AttachmentService;
use OpenWiki\Core\Env;

final class ImageService
{
    private readonly string $cacheRoot;

    public function __construct(
        private readonly AttachmentService $attachments,
        string $basePath
    ) {
        $this->cacheRoot = rtrim($basePath, '/\\') . '/storage/cache/images';
    }

    public function inspectPath(string $path): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new \InvalidArgumentException('Image file is not readable.');
        }

        $info = @getimagesize($path);
        if (!is_array($info)) {
            throw new \InvalidArgumentException('File is not a supported raster image.');
        }

        $width = (int) ($info[0] ?? 0);
        $height = (int) ($info[1] ?? 0);
        $mime = strtolower((string) ($info['mime'] ?? ''));

        if ($width < 1 || $height < 1) {
            throw new \InvalidArgumentException('Image dimensions are invalid.');
        }
        if (!in_array($mime, ['image/png', 'image/jpeg', 'image/gif', 'image/webp'], true)) {
            throw new \InvalidArgumentException('Only PNG, JPEG, GIF and WebP images are supported.');
        }

        $maxPixels = max(1_000_000, Env::int('IMAGE_MAX_PIXELS', 40_000_000));
        if ($width * $height > $maxPixels) {
            throw new \InvalidArgumentException('Image dimensions exceed the configured pixel limit.');
        }

        return [
            'width' => $width,
            'height' => $height,
            'mime_type' => $mime,
        ];
    }

    public function inspectAttachment(array $attachment): array
    {
        return $this->inspectPath($this->attachments->currentPath($attachment));
    }

    public function thumbnail(array $attachment, int $requestedWidth): array
    {
        if (!extension_loaded('gd')) {
            throw new \RuntimeException('PHP GD extension is required for image thumbnails.');
        }

        $requestedWidth = max(64, min(1600, $requestedWidth));
        $sourcePath = $this->attachments->currentPath($attachment);
        $info = $this->inspectPath($sourcePath);
        $targetWidth = min($requestedWidth, (int) $info['width']);
        $targetHeight = max(
            1,
            (int) round(((int) $info['height'] / (int) $info['width']) * $targetWidth)
        );

        $sha = (string) ($attachment['sha256'] ?? hash_file('sha256', $sourcePath));
        if (!preg_match('/^[a-f0-9]{64}$/', $sha)) {
            throw new \RuntimeException('Image fingerprint is unavailable.');
        }

        $extension = function_exists('imagewebp') ? 'webp' : 'png';
        $mime = $extension === 'webp' ? 'image/webp' : 'image/png';
        $path = $this->cacheRoot . '/' . substr($sha, 0, 2) . '/'
            . $sha . '-' . $targetWidth . '.' . $extension;

        if (is_file($path) && is_readable($path)) {
            return [
                'path' => $path,
                'mime_type' => $mime,
                'width' => $targetWidth,
                'height' => $targetHeight,
            ];
        }

        $directory = dirname($path);
        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create image thumbnail cache directory.');
        }

        $bytes = file_get_contents($sourcePath);
        if (!is_string($bytes)) {
            throw new \RuntimeException('Unable to read image for thumbnail generation.');
        }

        $source = @imagecreatefromstring($bytes);
        if ($source === false) {
            throw new \InvalidArgumentException('Unable to decode image.');
        }

        $target = imagecreatetruecolor($targetWidth, $targetHeight);
        if ($target === false) {
            imagedestroy($source);
            throw new \RuntimeException('Unable to allocate image thumbnail.');
        }

        imagealphablending($target, false);
        imagesavealpha($target, true);
        $transparent = imagecolorallocatealpha($target, 0, 0, 0, 127);
        imagefill($target, 0, 0, $transparent);

        $resampled = imagecopyresampled(
            $target,
            $source,
            0,
            0,
            0,
            0,
            $targetWidth,
            $targetHeight,
            (int) $info['width'],
            (int) $info['height']
        );

        if (!$resampled) {
            imagedestroy($target);
            imagedestroy($source);
            throw new \RuntimeException('Unable to resize image.');
        }

        $temp = $path . '.tmp-' . bin2hex(random_bytes(6));
        $written = $extension === 'webp'
            ? imagewebp($target, $temp, 82)
            : imagepng($target, $temp, 6);

        imagedestroy($target);
        imagedestroy($source);

        if (!$written || !is_file($temp)) {
            @unlink($temp);
            throw new \RuntimeException('Unable to write image thumbnail.');
        }

        if (!@rename($temp, $path)) {
            @unlink($temp);
            throw new \RuntimeException('Unable to finalize image thumbnail.');
        }
        @chmod($path, 0640);

        return [
            'path' => $path,
            'mime_type' => $mime,
            'width' => $targetWidth,
            'height' => $targetHeight,
        ];
    }
}
