<?php

declare(strict_types=1);

namespace OpenWiki\Attachments;

use OpenWiki\Core\Database;
use OpenWiki\Core\Env;

final class AttachmentService
{
    private readonly string $storageRoot;

    public function __construct(
        private readonly Database $database,
        string $basePath
    ) {
        $this->storageRoot = rtrim($basePath, '/\\') . '/storage/attachments';
    }

    public function listForPage(int $pageId): array
    {
        return $this->database->fetchAll(
            'SELECT a.id, a.page_id, a.uploader_id, a.name, a.storage_key, a.mime_type, a.size_bytes,
                    a.current_version, a.created_at, a.updated_at,
                    av.sha256, u.username AS uploader_username
             FROM attachments a
             INNER JOIN attachment_versions av
                ON av.attachment_id = a.id AND av.version_number = a.current_version
             INNER JOIN users u ON u.id = a.uploader_id
             WHERE a.page_id = :page_id AND a.deleted_at IS NULL
             ORDER BY a.updated_at DESC, a.id DESC',
            ['page_id' => $pageId]
        );
    }

    public function find(int $attachmentId): ?array
    {
        return $this->database->fetchOne(
            'SELECT a.*, p.space_id, p.title AS page_title, p.slug AS page_slug, p.status AS page_status,
                    p.owner_id AS page_owner_id, p.author_id AS page_author_id,
                    s.space_key, s.name AS space_name, s.visibility AS space_visibility,
                    s.owner_id AS space_owner_id, s.status AS space_status, s.deleted_at AS space_deleted_at,
                    av.sha256
             FROM attachments a
             INNER JOIN pages p ON p.id = a.page_id AND p.deleted_at IS NULL
             INNER JOIN spaces s ON s.id = p.space_id
             INNER JOIN attachment_versions av
                ON av.attachment_id = a.id AND av.version_number = a.current_version
             WHERE a.id = :id AND a.deleted_at IS NULL
             LIMIT 1',
            ['id' => $attachmentId]
        );
    }

    public function versions(int $attachmentId): array
    {
        return $this->database->fetchAll(
            'SELECT av.id, av.version_number, av.storage_key, av.mime_type, av.size_bytes, av.sha256,
                    av.uploader_id, av.created_at, u.username AS uploader_username
             FROM attachment_versions av
             INNER JOIN users u ON u.id = av.uploader_id
             WHERE av.attachment_id = :attachment_id
             ORDER BY av.version_number DESC',
            ['attachment_id' => $attachmentId]
        );
    }

    public function upload(int $pageId, int $uploaderId, array $file): int
    {
        $source = $this->uploadedSource($file);

        return $this->createFromSource(
            $pageId,
            $uploaderId,
            $source['path'],
            $source['name'],
            true
        );
    }

    public function addVersion(int $attachmentId, int $uploaderId, array $file): int
    {
        $source = $this->uploadedSource($file);

        return $this->addVersionFromSource(
            $attachmentId,
            $uploaderId,
            $source['path'],
            $source['name'],
            true
        );
    }

    public function addVersionFromSource(
        int $attachmentId,
        int $uploaderId,
        string $sourcePath,
        string $originalName,
        bool $uploaded = false
    ): int {
        $attachment = $this->find($attachmentId);
        if ($attachment === null) {
            throw new \InvalidArgumentException('Attachment not found.');
        }

        $stored = $this->persist($sourcePath, $originalName, $uploaded);

        try {
            $version = (int) $attachment['current_version'] + 1;
            $this->database->transaction(function (Database $db) use ($attachmentId, $uploaderId, $version, $stored): void {
                $db->execute(
                    'INSERT INTO attachment_versions
                     (attachment_id, version_number, storage_key, mime_type, size_bytes, sha256, uploader_id, created_at)
                     VALUES
                     (:attachment_id, :version_number, :storage_key, :mime_type, :size_bytes, :sha256, :uploader_id, UTC_TIMESTAMP())',
                    [
                        'attachment_id' => $attachmentId,
                        'version_number' => $version,
                        'storage_key' => $stored['storage_key'],
                        'mime_type' => $stored['mime_type'],
                        'size_bytes' => $stored['size_bytes'],
                        'sha256' => $stored['sha256'],
                        'uploader_id' => $uploaderId,
                    ]
                );

                $db->execute(
                    'UPDATE attachments
                     SET storage_key = :storage_key, mime_type = :mime_type, size_bytes = :size_bytes,
                         current_version = :version, updated_at = UTC_TIMESTAMP()
                     WHERE id = :id AND deleted_at IS NULL',
                    [
                        'storage_key' => $stored['storage_key'],
                        'mime_type' => $stored['mime_type'],
                        'size_bytes' => $stored['size_bytes'],
                        'version' => $version,
                        'id' => $attachmentId,
                    ]
                );
            });

            return $version;
        } catch (\Throwable $exception) {
            @unlink($stored['path']);
            throw $exception;
        }
    }

    public function createFromSource(
        int $pageId,
        int $uploaderId,
        string $sourcePath,
        string $originalName,
        bool $uploaded = false
    ): int {
        $stored = $this->persist($sourcePath, $originalName, $uploaded);

        try {
            return $this->database->transaction(function (Database $db) use ($pageId, $uploaderId, $stored): int {
                $attachmentId = $db->insert(
                    'INSERT INTO attachments
                     (page_id, uploader_id, name, storage_key, mime_type, size_bytes, current_version, created_at, updated_at, deleted_at)
                     VALUES
                     (:page_id, :uploader_id, :name, :storage_key, :mime_type, :size_bytes, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP(), NULL)',
                    [
                        'page_id' => $pageId,
                        'uploader_id' => $uploaderId,
                        'name' => $stored['name'],
                        'storage_key' => $stored['storage_key'],
                        'mime_type' => $stored['mime_type'],
                        'size_bytes' => $stored['size_bytes'],
                    ]
                );

                $db->execute(
                    'INSERT INTO attachment_versions
                     (attachment_id, version_number, storage_key, mime_type, size_bytes, sha256, uploader_id, created_at)
                     VALUES
                     (:attachment_id, 1, :storage_key, :mime_type, :size_bytes, :sha256, :uploader_id, UTC_TIMESTAMP())',
                    [
                        'attachment_id' => $attachmentId,
                        'storage_key' => $stored['storage_key'],
                        'mime_type' => $stored['mime_type'],
                        'size_bytes' => $stored['size_bytes'],
                        'sha256' => $stored['sha256'],
                        'uploader_id' => $uploaderId,
                    ]
                );

                return $attachmentId;
            });
        } catch (\Throwable $exception) {
            @unlink($stored['path']);
            throw $exception;
        }
    }

    public function rename(int $attachmentId, string $name): bool
    {
        $name = $this->safeName($name);

        return $this->database->execute(
            'UPDATE attachments SET name = :name, updated_at = UTC_TIMESTAMP()
             WHERE id = :id AND deleted_at IS NULL',
            ['name' => $name, 'id' => $attachmentId]
        ) === 1;
    }

    public function delete(int $attachmentId): bool
    {
        return $this->database->execute(
            'UPDATE attachments SET deleted_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
             WHERE id = :id AND deleted_at IS NULL',
            ['id' => $attachmentId]
        ) === 1;
    }

    public function currentPath(array $attachment): string
    {
        return $this->safeStoredPath((string) $attachment['storage_key']);
    }

    public function versionPath(int $attachmentId, int $version): ?array
    {
        $row = $this->database->fetchOne(
            'SELECT av.storage_key, av.mime_type, av.size_bytes, av.sha256, a.name
             FROM attachment_versions av
             INNER JOIN attachments a ON a.id = av.attachment_id
             WHERE av.attachment_id = :attachment_id AND av.version_number = :version
             LIMIT 1',
            ['attachment_id' => $attachmentId, 'version' => $version]
        );
        if ($row === null) {
            return null;
        }

        $row['path'] = $this->safeStoredPath((string) $row['storage_key']);
        return $row;
    }

    public function storageKeysForPage(int $pageId): array
    {
        $rows = $this->database->fetchAll(
            'SELECT DISTINCT av.storage_key
             FROM attachment_versions av
             INNER JOIN attachments a ON a.id = av.attachment_id
             WHERE a.page_id = :page_id',
            ['page_id' => $pageId]
        );

        return array_values(array_map(
            static fn (array $row): string => (string) $row['storage_key'],
            $rows
        ));
    }

    public function purgeStorageKeys(array $storageKeys): void
    {
        foreach (array_unique(array_map('strval', $storageKeys)) as $storageKey) {
            try {
                $path = $this->safeStoredPath($storageKey);
                if (is_file($path) && !@unlink($path)) {
                    error_log('[OpenWiki attachment purge] Unable to remove ' . $storageKey);
                }
            } catch (\RuntimeException $exception) {
                error_log('[OpenWiki attachment purge] ' . $exception->getMessage());
            }
        }
    }

    public function mayPreview(string $mimeType): bool
    {
        return in_array(strtolower($mimeType), [
            'image/png',
            'image/jpeg',
            'image/gif',
            'image/webp',
            'application/pdf',
            'text/plain',
        ], true);
    }

    private function uploadedSource(array $file): array
    {
        $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($error !== UPLOAD_ERR_OK) {
            $message = match ($error) {
                UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'Uploaded file exceeds the configured size limit.',
                UPLOAD_ERR_PARTIAL => 'File upload was incomplete.',
                UPLOAD_ERR_NO_FILE => 'Choose a file to upload.',
                default => 'File upload failed.',
            };
            throw new \InvalidArgumentException($message);
        }

        $path = (string) ($file['tmp_name'] ?? '');
        if ($path === '' || !is_uploaded_file($path)) {
            throw new \InvalidArgumentException('Invalid uploaded file.');
        }

        return ['path' => $path, 'name' => (string) ($file['name'] ?? 'attachment')];
    }

    private function persist(string $sourcePath, string $originalName, bool $uploaded): array
    {
        if (!is_file($sourcePath) || !is_readable($sourcePath)) {
            throw new \InvalidArgumentException('Attachment source is not readable.');
        }

        $size = filesize($sourcePath);
        if ($size === false) {
            throw new \RuntimeException('Unable to determine attachment size.');
        }

        $maxBytes = Env::int('ATTACHMENT_MAX_BYTES', 50 * 1024 * 1024);
        if ($size > $maxBytes) {
            throw new \InvalidArgumentException('Attachment exceeds the maximum allowed size.');
        }

        $name = $this->safeName($originalName);
        $sha256 = hash_file('sha256', $sourcePath);
        if (!is_string($sha256)) {
            throw new \RuntimeException('Unable to hash attachment.');
        }

        $mimeType = 'application/octet-stream';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $detected = finfo_file($finfo, $sourcePath);
                finfo_close($finfo);
                if (is_string($detected) && $detected !== '') {
                    $mimeType = mb_substr($detected, 0, 191);
                }
            }
        }

        $random = bin2hex(random_bytes(32));
        $storageKey = substr($random, 0, 2) . '/' . substr($random, 2, 2) . '/' . $random . '.bin';
        $path = $this->storageRoot . '/' . $storageKey;
        $directory = dirname($path);

        if (!is_dir($directory) && !mkdir($directory, 0770, true) && !is_dir($directory)) {
            throw new \RuntimeException('Unable to create attachment storage directory.');
        }

        $stored = $uploaded
            ? move_uploaded_file($sourcePath, $path)
            : copy($sourcePath, $path);
        if (!$stored) {
            throw new \RuntimeException('Unable to persist attachment.');
        }

        @chmod($path, 0640);

        return [
            'name' => $name,
            'storage_key' => $storageKey,
            'path' => $path,
            'mime_type' => $mimeType,
            'size_bytes' => (int) $size,
            'sha256' => $sha256,
        ];
    }

    private function safeName(string $name): string
    {
        $name = str_replace(["\0", "\r", "\n", '\\'], ['', '', '', '/'], trim($name));
        $name = basename($name);
        if ($name === '' || $name === '.' || $name === '..') {
            throw new \InvalidArgumentException('Invalid attachment name.');
        }

        $name = mb_substr($name, 0, 255);
        return $name;
    }

    private function safeStoredPath(string $storageKey): string
    {
        if (!preg_match('#^[a-f0-9]{2}/[a-f0-9]{2}/[a-f0-9]{64}\.bin$#', $storageKey)) {
            throw new \RuntimeException('Invalid attachment storage key.');
        }

        $path = $this->storageRoot . '/' . $storageKey;
        $realPath = realpath($path);
        $realRoot = realpath($this->storageRoot);

        if ($realPath === false || $realRoot === false || !str_starts_with($realPath, $realRoot . DIRECTORY_SEPARATOR)) {
            throw new \RuntimeException('Attachment file is not available.');
        }

        return $realPath;
    }
}
