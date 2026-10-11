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
            'SELECT editor_id, sequence, draft_version, base_version, payload_json, updated_at FROM page_editor_drafts
             WHERE space_id = :space AND user_id = :user AND document_key = :document AND sequence > acknowledged_sequence ORDER BY updated_at DESC LIMIT 20',
            ['space' => $spaceId, 'user' => $userId, 'document' => $documentKey]
        );
        return array_map(static fn (array $row): array => json_decode($row['payload_json'], true, 512, JSON_THROW_ON_ERROR)
            + ['editorId' => $row['editor_id'], 'sequence' => (int) $row['sequence'], 'draftVersion' => (int) $row['draft_version'], 'baseVersion' => (int) $row['base_version'], 'savedAt' => $row['updated_at']], $rows);
    }

    public function saveEditorDraft(int $spaceId, int $userId, string $documentKey, string $editorId, int $sequence, int $baseVersion, array $payload,
        ?int $expectedVersion = null, ?string $operationId = null): array
    {
        if (!preg_match('/^(?:page:[1-9][0-9]*|new:[a-f0-9-]{36})$/D', $documentKey)
            || !preg_match('/^[a-f0-9-]{36}$/D', $editorId) || $sequence < 1 || $baseVersion < 0) {
            throw new \InvalidArgumentException('Invalid draft identity or sequence.');
        }
        if ($expectedVersion !== null && ($expectedVersion < 0 || !is_string($operationId) || !preg_match('/^[a-f0-9-]{36}$/D', $operationId))) {
            throw new \InvalidArgumentException('Invalid draft version or operation identity.');
        }
        $encoded = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        if (strlen($encoded) > 12_000_000) throw new \InvalidArgumentException('Draft is too large.');
        return $this->database->transaction(function () use ($spaceId, $userId, $documentKey, $editorId, $sequence, $baseVersion, $encoded, $expectedVersion, $operationId): array {
            $identity = ['space' => $spaceId, 'user' => $userId, 'document' => $documentKey, 'editor' => $editorId];
            $where = 'space_id = :space AND user_id = :user AND document_key = :document AND editor_id = :editor';
            // Lock the owner first: creation, retention and quota checks cannot race.
            $this->database->fetchOne('SELECT id FROM users WHERE id = :id FOR UPDATE', ['id' => $userId]);
            $hash = hash('sha256', $sequence . ':' . $baseVersion . ':' . $encoded);
            if ($operationId !== null) {
                $receipt = $this->database->fetchOne('SELECT * FROM editor_draft_operations WHERE ' . $where . ' AND operation_id = :operation', $identity + ['operation' => $operationId]);
                if ($receipt !== null) {
                    if (!hash_equals($receipt['request_hash'], $hash)) throw new \InvalidArgumentException('Operation identity was reused for different content.');
                    return json_decode($receipt['result_json'], true, 512, JSON_THROW_ON_ERROR);
                }
            }
            $existing = $this->database->fetchOne('SELECT * FROM page_editor_drafts WHERE ' . $where . ' FOR UPDATE', $identity);
            if ($expectedVersion !== null && ($expectedVersion !== (int) ($existing['draft_version'] ?? 0)
                || ($existing !== null && $sequence <= max((int) $existing['sequence'], (int) $existing['acknowledged_sequence'])))) {
                throw new DraftConflict($existing === null ? ['draftVersion' => 0] : json_decode($existing['payload_json'], true, 512, JSON_THROW_ON_ERROR)
                    + ['draftVersion' => (int) $existing['draft_version'], 'sequence' => (int) $existing['sequence'], 'baseVersion' => (int) $existing['base_version']]);
            }
            if ($existing === null && (int) $this->database->fetchOne('SELECT COUNT(*) AS total FROM page_editor_drafts WHERE user_id = :user', ['user' => $userId])['total'] >= 100) {
                throw new \InvalidArgumentException('Draft limit reached. Remove an old draft or wait for retention cleanup.');
            }
            $this->database->execute(
                'INSERT INTO page_editor_drafts (space_id, user_id, document_key, editor_id, sequence, base_version, payload_json, updated_at)
                 VALUES (:space, :user, :document, :editor, :sequence, :base, :payload, UTC_TIMESTAMP())
                 ON DUPLICATE KEY UPDATE
                 base_version = IF(VALUES(sequence) > sequence AND VALUES(sequence) > acknowledged_sequence, VALUES(base_version), base_version),
                 payload_json = IF(VALUES(sequence) > sequence AND VALUES(sequence) > acknowledged_sequence, VALUES(payload_json), payload_json),
                 updated_at = IF(VALUES(sequence) > sequence, UTC_TIMESTAMP(), updated_at),
                 draft_version = draft_version + IF(VALUES(sequence) > sequence AND VALUES(sequence) > acknowledged_sequence, 1, 0),
                 sequence = GREATEST(sequence, VALUES(sequence))',
                ['space' => $spaceId, 'user' => $userId, 'document' => $documentKey, 'editor' => $editorId,
                    'sequence' => $sequence, 'base' => $baseVersion, 'payload' => $encoded]
            );
            $saved = $this->database->fetchOne(
                'SELECT sequence, draft_version, updated_at FROM page_editor_drafts WHERE space_id = :space AND user_id = :user AND document_key = :document AND editor_id = :editor',
                ['space' => $spaceId, 'user' => $userId, 'document' => $documentKey, 'editor' => $editorId]
            );
            // The first insertion is version one; legacy monotonic saves also retain snapshots.
            if ($existing === null) {
                $this->database->execute('UPDATE page_editor_drafts SET draft_version = 1 WHERE ' . $where, $identity);
                $saved['draft_version'] = 1;
            }
            $result = ['sequence' => (int) $saved['sequence'], 'draft_version' => (int) $saved['draft_version'], 'saved_at' => $saved['updated_at'], 'operation_id' => $operationId];
            if ($existing === null || $sequence > (int) $existing['sequence']) {
                $this->database->execute('INSERT INTO editor_recovery_points (space_id, user_id, document_key, editor_id, draft_version, sequence, base_version, payload_json, created_at)
                    VALUES (:space, :user, :document, :editor, :version, :sequence, :base, :payload, UTC_TIMESTAMP())',
                    $identity + ['version' => $result['draft_version'], 'sequence' => $sequence, 'base' => $baseVersion, 'payload' => $encoded]);
                $keep = $this->database->fetchAll('SELECT id FROM editor_recovery_points WHERE ' . $where . ' ORDER BY id DESC LIMIT 20', $identity);
                $this->database->execute('DELETE FROM editor_recovery_points WHERE ' . $where . ' AND id < :oldest', $identity + ['oldest' => (int) end($keep)['id']]);
            }
            if ($operationId !== null) {
                $this->database->execute('INSERT INTO editor_draft_operations (space_id, user_id, document_key, editor_id, operation_id, request_hash, result_json, created_at)
                    VALUES (:space, :user, :document, :editor, :operation, :hash, :result, UTC_TIMESTAMP())',
                    $identity + ['operation' => $operationId, 'hash' => $hash, 'result' => json_encode($result, JSON_THROW_ON_ERROR)]);
            }
            return $result;
        });
    }

    public function recoveryPoints(int $spaceId, int $userId, string $documentKey): array
    {
        return $this->database->fetchAll('SELECT id, editor_id AS editorId, sequence, base_version AS baseVersion, draft_version AS draftVersion, created_at AS savedAt
            FROM editor_recovery_points WHERE space_id = :space AND user_id = :user AND document_key = :document ORDER BY id DESC LIMIT 20',
            ['space' => $spaceId, 'user' => $userId, 'document' => $documentKey]);
    }

    public function recoveryPoint(int $spaceId, int $userId, string $documentKey, int $id): ?array
    {
        $row = $this->database->fetchOne('SELECT * FROM editor_recovery_points WHERE id = :id AND space_id = :space AND user_id = :user AND document_key = :document',
            ['id' => $id, 'space' => $spaceId, 'user' => $userId, 'document' => $documentKey]);
        return $row === null ? null : json_decode($row['payload_json'], true, 512, JSON_THROW_ON_ERROR)
            + ['editorId' => $row['editor_id'], 'sequence' => (int) $row['sequence'], 'baseVersion' => (int) $row['base_version'], 'savedAt' => $row['created_at']];
    }

    public function prune(): void
    {
        foreach (['editor_recovery_points' => 'created_at', 'editor_draft_operations' => 'created_at', 'editor_page_operations' => 'created_at', 'page_editor_drafts' => 'updated_at'] as $table => $column) {
            $this->database->execute('DELETE FROM ' . $table . ' WHERE ' . $column . ' < UTC_TIMESTAMP() - INTERVAL 30 DAY');
        }
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
