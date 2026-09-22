<?php

declare(strict_types=1);

namespace OpenWiki\Wiki;

use OpenWiki\Core\Database;

final class PageEngagementService
{
    public function __construct(private readonly Database $database)
    {
    }

    public function setFavorite(int $userId, int $pageId, bool $enabled): void
    {
        if ($enabled) {
            $this->database->execute(
                'INSERT IGNORE INTO favorites (user_id, page_id, created_at)
                 VALUES (:user_id, :page_id, UTC_TIMESTAMP())',
                ['user_id' => $userId, 'page_id' => $pageId]
            );
            return;
        }

        $this->database->execute(
            'DELETE FROM favorites WHERE user_id = :user_id AND page_id = :page_id',
            ['user_id' => $userId, 'page_id' => $pageId]
        );
    }

    public function isFavorite(int $userId, int $pageId): bool
    {
        return $this->database->fetchOne(
            'SELECT 1 AS found FROM favorites WHERE user_id = :user_id AND page_id = :page_id LIMIT 1',
            ['user_id' => $userId, 'page_id' => $pageId]
        ) !== null;
    }

    public function favoritesForUser(int $userId, int $limit = 10): array
    {
        $limit = max(1, min($limit, 50));

        return $this->database->fetchAll(
            'SELECT p.id, p.title, p.slug, p.status, p.updated_at, s.space_key, s.name AS space_name
             FROM favorites f
             INNER JOIN pages p ON p.id = f.page_id AND p.deleted_at IS NULL
             INNER JOIN spaces s ON s.id = p.space_id AND s.deleted_at IS NULL
             WHERE f.user_id = ?
             ORDER BY f.created_at DESC
             LIMIT ' . $limit,
            [$userId]
        );
    }

    public function setWatch(int $userId, string $type, int $id, bool $enabled): void
    {
        if (!in_array($type, ['page', 'space'], true)) {
            throw new \InvalidArgumentException('Invalid watch type.');
        }

        if ($enabled) {
            $this->database->execute(
                'INSERT IGNORE INTO watches (user_id, watchable_type, watchable_id, created_at)
                 VALUES (:user_id, :type, :id, UTC_TIMESTAMP())',
                ['user_id' => $userId, 'type' => $type, 'id' => $id]
            );
            return;
        }

        $this->database->execute(
            'DELETE FROM watches
             WHERE user_id = :user_id AND watchable_type = :type AND watchable_id = :id',
            ['user_id' => $userId, 'type' => $type, 'id' => $id]
        );
    }

    public function isWatching(int $userId, string $type, int $id): bool
    {
        return $this->database->fetchOne(
            'SELECT 1 AS found
             FROM watches
             WHERE user_id = :user_id AND watchable_type = :type AND watchable_id = :id
             LIMIT 1',
            ['user_id' => $userId, 'type' => $type, 'id' => $id]
        ) !== null;
    }

    public function notifyWatchers(
        int $pageId,
        int $spaceId,
        int $actorUserId,
        string $eventType,
        string $title,
        string $targetUrl
    ): void {
        $watchers = $this->database->fetchAll(
            'SELECT DISTINCT user_id
             FROM watches
             WHERE (watchable_type = "page" AND watchable_id = :page_id)
                OR (watchable_type = "space" AND watchable_id = :space_id)',
            ['page_id' => $pageId, 'space_id' => $spaceId]
        );

        foreach ($watchers as $watcher) {
            $userId = (int) $watcher['user_id'];
            if ($userId === $actorUserId) {
                continue;
            }

            $this->database->execute(
                'INSERT INTO notifications
                 (user_id, event_type, title, body, target_url, read_at, created_at)
                 VALUES (:user_id, :event_type, :title, NULL, :target_url, NULL, UTC_TIMESTAMP())',
                [
                    'user_id' => $userId,
                    'event_type' => $eventType,
                    'title' => $title,
                    'target_url' => $targetUrl,
                ]
            );
        }
    }

    public function notificationsForUser(int $userId, int $limit = 100): array
    {
        $limit = max(1, min($limit, 200));

        return $this->database->fetchAll(
            'SELECT id, event_type, title, body, target_url, read_at, created_at
             FROM notifications
             WHERE user_id = ?
             ORDER BY created_at DESC, id DESC
             LIMIT ' . $limit,
            [$userId]
        );
    }

    public function unreadCount(int $userId): int
    {
        $row = $this->database->fetchOne(
            'SELECT COUNT(*) AS total FROM notifications WHERE user_id = :user_id AND read_at IS NULL',
            ['user_id' => $userId]
        );

        return (int) ($row['total'] ?? 0);
    }

    public function markRead(int $userId, int $notificationId): bool
    {
        return $this->database->execute(
            'UPDATE notifications
             SET read_at = COALESCE(read_at, UTC_TIMESTAMP())
             WHERE id = :id AND user_id = :user_id',
            ['id' => $notificationId, 'user_id' => $userId]
        ) === 1;
    }

    public function markAllRead(int $userId): void
    {
        $this->database->execute(
            'UPDATE notifications SET read_at = UTC_TIMESTAMP()
             WHERE user_id = :user_id AND read_at IS NULL',
            ['user_id' => $userId]
        );
    }
}
