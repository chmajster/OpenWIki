<?php

declare(strict_types=1);

namespace OpenWiki\Attachments;

use OpenWiki\Core\Database;
use OpenWiki\Images\ImageService;

final class DraftFileService
{
    private readonly AttachmentService $attachments;

    public function __construct(private readonly Database $database, private readonly string $basePath)
    {
        $this->attachments = new AttachmentService($database, $basePath);
    }

    public function upload(int $space, int $user, string $document, string $operation, array $file): array
    {
        if (!preg_match('/^[a-f0-9-]{36}$/D', $operation)) throw new \InvalidArgumentException('Invalid upload operation.');
        $staged = null;
        try {
            return $this->database->transaction(function () use ($space, $user, $document, $operation, $file, &$staged): array {
                $this->database->fetchOne('SELECT id FROM users WHERE id = :id FOR UPDATE', ['id' => $user]);
                $previous = $this->database->fetchOne('SELECT * FROM editor_draft_files WHERE user_id = :user AND operation_id = :operation', ['user' => $user, 'operation' => $operation]);
                if ($previous !== null) {
                    if ((int) $previous['space_id'] !== $space || $previous['document_key'] !== $document
                        || !is_file($file['tmp_name'] ?? '') || !hash_equals($previous['sha256'], (string) hash_file('sha256', $file['tmp_name']))) {
                        throw new \InvalidArgumentException('Upload identity was reused for a different file.');
                    }
                    return $previous;
                }
                $quota = $this->database->fetchOne('SELECT COUNT(*) AS total, COALESCE(SUM(size_bytes), 0) AS bytes FROM editor_draft_files WHERE user_id = :user AND attachment_id IS NULL', ['user' => $user]);
                if ((int) $quota['total'] >= 50 || (int) $quota['bytes'] + (int) ($file['size'] ?? 0) > 100 * 1024 * 1024) throw new \InvalidArgumentException('Private draft file quota reached (50 files / 100 MiB).');
                $staged = $this->attachments->stage($file);
                $image = null;
                if (str_starts_with($staged['mime_type'], 'image/')) $image = (new ImageService($this->attachments, $this->basePath))->inspectPath($staged['path']);
                $id = $this->database->insert('INSERT INTO editor_draft_files (space_id, user_id, document_key, operation_id, name, storage_key, mime_type, size_bytes, sha256, width, height, created_at, last_used_at)
                    VALUES (:space, :user, :document, :operation, :name, :key, :mime, :size, :sha, :width, :height, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
                    ['space' => $space, 'user' => $user, 'document' => $document, 'operation' => $operation, 'name' => $staged['name'], 'key' => $staged['storage_key'],
                        'mime' => $staged['mime_type'], 'size' => $staged['size_bytes'], 'sha' => $staged['sha256'], 'width' => $image['width'] ?? null, 'height' => $image['height'] ?? null]);
                return $this->database->fetchOne('SELECT * FROM editor_draft_files WHERE id = :id', ['id' => $id]);
            });
        } catch (\Throwable $exception) {
            if ($staged !== null) $this->attachments->purgeStorageKeys([$staged['storage_key']]);
            throw $exception;
        }
    }

    public function materialize(string $html, int $pageId, int $space, int $user, string $document): array
    {
        if (!str_contains($html, 'data-draft-file-id') && !str_contains($html, '/editor-files/')) return ['html' => $html, 'urls' => []];
        $dom = new \DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<meta charset="utf-8"><div id="draft-root">' . $html . '</div>');
        libxml_clear_errors(); libxml_use_internal_errors($previous);
        $root = $dom->getElementById('draft-root');
        $urls = [];
        foreach ((new \DOMXPath($dom))->query('//img | //a') as $element) {
            $attribute = $element->tagName === 'img' ? 'src' : 'href';
            $oldUrl = $element->getAttribute($attribute);
            if (!$element->hasAttribute('data-draft-file-id') && preg_match('~^/editor-files/([1-9][0-9]*)/(?:preview|thumbnail|download)(?:\?[^#]*)?$~D', $oldUrl, $match)) $element->setAttribute('data-draft-file-id', $match[1]);
            if (!$element->hasAttribute('data-draft-file-id')) continue;
            $id = filter_var($element->getAttribute('data-draft-file-id'), FILTER_VALIDATE_INT);
            $file = $id === false ? null : $this->database->fetchOne('SELECT * FROM editor_draft_files WHERE id = :id AND space_id = :space AND user_id = :user AND document_key = :document FOR UPDATE',
                ['id' => $id, 'space' => $space, 'user' => $user, 'document' => $document]);
            if ($file === null) throw new \DomainException('You cannot attach a file from another draft.');
            $attachmentId = $file['attachment_id'];
            if ($attachmentId === null) {
                $attachmentId = $this->attachments->adopt($pageId, $user, $file);
                $this->database->execute('UPDATE editor_draft_files SET attachment_id = :attachment, last_used_at = UTC_TIMESTAMP() WHERE id = :id', ['attachment' => $attachmentId, 'id' => $id]);
            } else {
                $attachment = $this->attachments->find((int) $attachmentId);
                if ($attachment === null || (int) $attachment['page_id'] !== $pageId) throw new \DomainException('Draft file belongs to another page.');
            }
            $element->removeAttribute('data-draft-file-id');
            $element->setAttribute('data-attachment-id', (string) $attachmentId);
            if ($element->tagName === 'img') {
                if ($file['width'] === null) throw new \InvalidArgumentException('This draft file is not an image.');
                $width = max(64, min(1600, (int) ($element->getAttribute('width') ?: 640)));
                $element->setAttribute('src', '/attachments/' . $attachmentId . '/thumbnail?width=' . $width);
            } elseif ($element->tagName === 'a') $element->setAttribute('href', '/attachments/' . $attachmentId . '/download');
            $urls[$oldUrl] = $element->getAttribute($attribute);
        }
        $result = '';
        foreach ($root->childNodes as $child) $result .= $dom->saveHTML($child);
        return ['html' => $result, 'urls' => $urls];
    }

    public function prune(): int
    {
        $rows = $this->database->fetchAll('SELECT id, storage_key, attachment_id FROM editor_draft_files WHERE last_used_at < UTC_TIMESTAMP() - INTERVAL 30 DAY LIMIT 500');
        foreach ($rows as $row) $this->database->transaction(function () use ($row): void {
            $fresh = $this->database->fetchOne('SELECT * FROM editor_draft_files WHERE id = :id AND last_used_at < UTC_TIMESTAMP() - INTERVAL 30 DAY FOR UPDATE', ['id' => $row['id']]);
            if ($fresh === null) return;
            // A published attachment owns its bytes independently of the draft record.
            $this->database->execute('DELETE FROM editor_draft_files WHERE id = :id', ['id' => $row['id']]);
            if ($fresh['attachment_id'] === null) $this->attachments->purgeStorageKeys([$fresh['storage_key']]);
        });
        return count($rows);
    }

    public function touch(string $html, int $space, int $user, string $document): void
    {
        preg_match_all('/data-draft-file-id="([0-9]+)"/', $html, $matches);
        preg_match_all('~/editor-files/([1-9][0-9]*)/(?:preview|thumbnail|download)~', $html, $urls);
        foreach (array_unique(array_merge($matches[1], $urls[1])) as $id) {
            $file = $this->database->fetchOne('SELECT id FROM editor_draft_files WHERE id = :id AND space_id = :space AND user_id = :user AND document_key = :document',
                ['id' => $id, 'space' => $space, 'user' => $user, 'document' => $document]);
            if ($file === null) throw new \DomainException('You cannot use a file from another draft.');
            $this->database->execute('UPDATE editor_draft_files SET last_used_at = UTC_TIMESTAMP() WHERE id = :id', ['id' => $id]);
        }
    }
}
