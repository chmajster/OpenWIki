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

    public function editorDrafts(int $spaceId, int $userId, string $documentKey): array
    {
        $rows = $this->database->fetchAll(
            'SELECT editor_id, sequence, base_version, payload_json, updated_at FROM page_editor_drafts
             WHERE space_id = :space AND user_id = :user AND document_key = :document AND sequence > acknowledged_sequence ORDER BY updated_at DESC',
            ['space' => $spaceId, 'user' => $userId, 'document' => $documentKey]
        );
        return array_map(static fn (array $row): array => json_decode($row['payload_json'], true, 512, JSON_THROW_ON_ERROR)
            + ['editorId' => $row['editor_id'], 'sequence' => (int) $row['sequence'], 'baseVersion' => (int) $row['base_version'], 'savedAt' => $row['updated_at']], $rows);
    }

    public function saveEditorDraft(int $spaceId, int $userId, string $documentKey, string $editorId, int $sequence, int $baseVersion, array $payload): array
    {
        if (!preg_match('/^(?:page:[1-9][0-9]*|new:[a-f0-9-]{36})$/D', $documentKey)
            || !preg_match('/^[a-f0-9-]{36}$/D', $editorId) || $sequence < 1 || $baseVersion < 0) {
            throw new \InvalidArgumentException('Invalid draft identity or sequence.');
        }
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (strlen($encoded) > 12_000_000) throw new \InvalidArgumentException('Draft is too large.');
        return $this->database->transaction(function () use ($spaceId, $userId, $documentKey, $editorId, $sequence, $baseVersion, $encoded): array {
            $this->database->execute(
                'INSERT INTO page_editor_drafts (space_id, user_id, document_key, editor_id, sequence, base_version, payload_json, updated_at)
                 VALUES (:space, :user, :document, :editor, :sequence, :base, :payload, UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE
                 base_version = IF(VALUES(sequence) > sequence AND VALUES(sequence) > acknowledged_sequence, VALUES(base_version), base_version),
                 payload_json = IF(VALUES(sequence) > sequence AND VALUES(sequence) > acknowledged_sequence, VALUES(payload_json), payload_json),
                 updated_at = IF(VALUES(sequence) > sequence, UTC_TIMESTAMP(), updated_at),
                 sequence = GREATEST(sequence, VALUES(sequence))',
                ['space' => $spaceId, 'user' => $userId, 'document' => $documentKey, 'editor' => $editorId,
                    'sequence' => $sequence, 'base' => $baseVersion, 'payload' => $encoded]
            );
            $saved = $this->database->fetchOne(
                'SELECT sequence, updated_at FROM page_editor_drafts WHERE space_id = :space AND user_id = :user AND document_key = :document AND editor_id = :editor',
                ['space' => $spaceId, 'user' => $userId, 'document' => $documentKey, 'editor' => $editorId]
            );
            return ['sequence' => (int) $saved['sequence'], 'saved_at' => $saved['updated_at']];
        });
    }

    public function acknowledgeDraft(int $spaceId, int $userId, string $documentKey, string $editorId, int $sequence): void
    {
        $this->database->execute(
            'INSERT INTO page_editor_drafts (space_id, user_id, document_key, editor_id, sequence, base_version, payload_json, updated_at, acknowledged_sequence)
             VALUES (:space, :user, :document, :editor, 0, 0, "{}", UTC_TIMESTAMP(), :sequence)
             ON DUPLICATE KEY UPDATE
             payload_json = IF(sequence <= VALUES(acknowledged_sequence), "{}", payload_json),
             acknowledged_sequence = GREATEST(acknowledged_sequence, VALUES(acknowledged_sequence)),
             updated_at = UTC_TIMESTAMP()',
            ['space' => $spaceId, 'user' => $userId, 'document' => $documentKey, 'editor' => $editorId, 'sequence' => $sequence]
        );
    }
}
