<?php

declare(strict_types=1);

namespace OpenWiki\Wiki;

use OpenWiki\Core\Database;

final class EditSessionService
{
    public function __construct(private readonly Database $database)
    {
    }

    public function acquire(int $pageId, int $userId, int $ttlSeconds = 90): array
    {
        return $this->database->transaction(function (Database $database) use ($pageId, $userId, $ttlSeconds): array {
            $existing = $database->fetchOne(
                'SELECT l.page_id, l.user_id, l.lock_token, l.expires_at, u.username
                 FROM page_edit_locks l
                 INNER JOIN users u ON u.id = l.user_id
                 WHERE l.page_id = :page_id
                 FOR UPDATE',
                ['page_id' => $pageId]
            );

            $now = time();
            if (
                $existing !== null
                && (int) $existing['user_id'] !== $userId
                && strtotime((string) $existing['expires_at']) > $now
            ) {
                return [
                    'acquired' => false,
                    'holder' => $existing['username'],
                    'expires_at' => $existing['expires_at'],
                    'token' => null,
                ];
            }

            $token = $existing !== null && (int) $existing['user_id'] === $userId
                ? (string) $existing['lock_token']
                : bin2hex(random_bytes(32));

            $expiresAt = gmdate('Y-m-d H:i:s', $now + $ttlSeconds);

            $database->execute(
                'INSERT INTO page_edit_locks
                 (page_id, user_id, lock_token, acquired_at, expires_at)
                 VALUES (:page_id, :user_id, :lock_token, UTC_TIMESTAMP(), :expires_at)
                 ON DUPLICATE KEY UPDATE
                    user_id = VALUES(user_id),
                    lock_token = VALUES(lock_token),
                    acquired_at = UTC_TIMESTAMP(),
                    expires_at = VALUES(expires_at)',
                [
                    'page_id' => $pageId,
                    'user_id' => $userId,
                    'lock_token' => $token,
                    'expires_at' => $expiresAt,
                ]
            );

            return [
                'acquired' => true,
                'holder' => null,
                'expires_at' => $expiresAt,
                'token' => $token,
            ];
        });
    }

    public function release(int $pageId, int $userId, string $token): bool
    {
        if ($token === '') {
            return false;
        }

        return $this->database->execute(
            'DELETE FROM page_edit_locks
             WHERE page_id = :page_id AND user_id = :user_id AND lock_token = :lock_token',
            ['page_id' => $pageId, 'user_id' => $userId, 'lock_token' => $token]
        ) === 1;
    }

    public function saveDraft(
        int $pageId,
        int $userId,
        int $baseVersion,
        array $content
    ): array {
        $page = $this->database->fetchOne(
            'SELECT version FROM pages WHERE id = :page_id AND deleted_at IS NULL LIMIT 1',
            ['page_id' => $pageId]
        );

        if ($page === null) {
            throw new \RuntimeException('Page not found.');
        }

        $currentVersion = (int) $page['version'];
        $this->database->execute(
            'INSERT INTO draft_autosaves
             (page_id, user_id, content_html, content_markdown, content_format, base_version, updated_at)
             VALUES
             (:page_id, :user_id, :content_html, :content_markdown, :content_format, :base_version, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                content_html = VALUES(content_html),
                content_markdown = VALUES(content_markdown),
                content_format = VALUES(content_format),
                base_version = VALUES(base_version),
                updated_at = UTC_TIMESTAMP()',
            [
                'page_id' => $pageId,
                'user_id' => $userId,
                'content_html' => $content['html'],
                'content_markdown' => $content['markdown'],
                'content_format' => $content['format'],
                'base_version' => $baseVersion,
            ]
        );

        $saved = $this->draft($pageId, $userId);

        return [
            'saved_at' => $saved['updated_at'] ?? gmdate('Y-m-d H:i:s'),
            'stale' => $currentVersion !== $baseVersion,
            'current_version' => $currentVersion,
        ];
    }

    public function draft(int $pageId, int $userId): ?array
    {
        return $this->database->fetchOne(
            'SELECT page_id, user_id, content_html, content_markdown, content_format, base_version, updated_at
             FROM draft_autosaves
             WHERE page_id = :page_id AND user_id = :user_id
             LIMIT 1',
            ['page_id' => $pageId, 'user_id' => $userId]
        );
    }
}
