<?php

declare(strict_types=1);

namespace OpenWiki\Images;

use DOMDocument;
use DOMElement;
use OpenWiki\Attachments\AttachmentService;
use OpenWiki\Core\Database;
use OpenWiki\Core\Env;

final class InlineImageService
{
    private readonly AttachmentService $attachments;
    private readonly ImageService $images;
    private array $createdStorageKeys = [];

    public function __construct(
        private readonly Database $database,
        string $basePath
    ) {
        $this->attachments = new AttachmentService($database, $basePath);
        $this->images = new ImageService($this->attachments, $basePath);
    }

    public function materializeDataImages(string $html, int $pageId, int $userId): array
    {
        if (!str_contains($html, 'data:image/')) {
            return ['html' => $html, 'attachment_ids' => []];
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<!doctype html><html><body><div id="openwiki-inline-image-root">' . $html . '</div></body></html>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementById('openwiki-inline-image-root');
        if (!$root) {
            throw new \RuntimeException('Unable to parse page images.');
        }

        $images = [];
        foreach ($root->getElementsByTagName('img') as $image) {
            if ($image instanceof DOMElement && str_starts_with(trim($image->getAttribute('src')), 'data:image/')) {
                $images[] = $image;
            }
        }

        $maxImages = max(1, Env::int('INLINE_IMAGE_MAX_COUNT', 20));
        if (count($images) > $maxImages) {
            throw new \InvalidArgumentException('Page contains too many pasted images.');
        }

        $maxImageBytes = max(256 * 1024, Env::int('INLINE_IMAGE_MAX_BYTES', 10 * 1024 * 1024));
        $maxTotalBytes = max($maxImageBytes, Env::int('INLINE_IMAGE_MAX_TOTAL_BYTES', 25 * 1024 * 1024));
        $totalBytes = 0;
        $attachmentIds = [];

        foreach ($images as $index => $image) {
            $source = trim($image->getAttribute('src'));
            if (preg_match(
                '#^data:image/(png|jpeg|gif|webp);base64,([A-Za-z0-9+/=\r\n]+)$#i',
                $source,
                $match
            ) !== 1) {
                throw new \InvalidArgumentException('Pasted image data is invalid.');
            }

            $bytes = base64_decode(preg_replace('/\s+/', '', $match[2]) ?? '', true);
            if (!is_string($bytes)) {
                throw new \InvalidArgumentException('Pasted image cannot be decoded.');
            }

            $size = strlen($bytes);
            if ($size < 1 || $size > $maxImageBytes) {
                throw new \InvalidArgumentException('Pasted image exceeds the allowed size.');
            }
            $totalBytes += $size;
            if ($totalBytes > $maxTotalBytes) {
                throw new \InvalidArgumentException('Pasted images exceed the total allowed size.');
            }

            $extension = strtolower($match[1]) === 'jpeg' ? 'jpg' : strtolower($match[1]);
            $temp = tempnam(sys_get_temp_dir(), 'openwiki-image-');
            if ($temp === false) {
                throw new \RuntimeException('Unable to create temporary image file.');
            }
            $tempFile = $temp . '.' . $extension;
            @unlink($temp);

            try {
                if (file_put_contents($tempFile, $bytes, LOCK_EX) === false) {
                    throw new \RuntimeException('Unable to write temporary image.');
                }

                $imageInfo = $this->images->inspectPath($tempFile);
                $name = 'pasted-image-' . gmdate('Ymd-His') . '-' . ($index + 1) . '.' . $extension;
                $attachmentId = $this->attachments->createFromSource(
                    $pageId,
                    $userId,
                    $tempFile,
                    $name,
                    false
                );
                $attachment = $this->attachments->find($attachmentId);
                if ($attachment === null) {
                    throw new \RuntimeException('Stored image attachment cannot be resolved.');
                }

                $attachmentIds[] = $attachmentId;
                $this->createdStorageKeys[] = (string) $attachment['storage_key'];

                $requestedWidth = filter_var($image->getAttribute('width'), FILTER_VALIDATE_INT);
                $requestedWidth = $requestedWidth === false
                    ? min(1280, (int) $imageInfo['width'])
                    : max(64, min(1600, (int) $requestedWidth));

                $image->setAttribute(
                    'src',
                    '/attachments/' . $attachmentId . '/thumbnail?width=' . $requestedWidth
                );
                $image->setAttribute('data-attachment-id', (string) $attachmentId);
                $image->setAttribute('data-original-width', (string) $imageInfo['width']);
                $image->setAttribute('data-original-height', (string) $imageInfo['height']);
                $image->setAttribute('width', (string) min($requestedWidth, (int) $imageInfo['width']));
                $image->setAttribute('loading', 'lazy');
            } finally {
                @unlink($tempFile);
            }
        }

        $result = '';
        foreach ($root->childNodes as $child) {
            $result .= $document->saveHTML($child);
        }

        return ['html' => $result, 'attachment_ids' => $attachmentIds];
    }

    public function cleanupCreatedFiles(): void
    {
        if ($this->createdStorageKeys === []) {
            return;
        }

        $this->attachments->purgeStorageKeys($this->createdStorageKeys);
        $this->createdStorageKeys = [];
    }

    public function clearTracking(): void
    {
        $this->createdStorageKeys = [];
    }
}
