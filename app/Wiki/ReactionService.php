<?php

declare(strict_types=1);

namespace OpenWiki\Wiki;

use OpenWiki\Core\Database;

final class ReactionService
{
    public const EMOJIS = ['👍', '❤️', '👀', '🎉', '🤔'];

    public function __construct(private readonly Database $database)
    {
    }

    public function pageSummary(int $pageId, ?int $userId): array
    {
        $rows = $this->database->fetchAll(
            'SELECT emoji, COUNT(*) AS total, MAX(user_id=?) AS selected_by_user
             FROM page_reactions WHERE page_id=? GROUP BY emoji',
            [$userId ?? 0, $pageId]
        );
        return self::formatSummary($rows);
    }

    public function commentSummaries(array $commentIds, ?int $userId): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $commentIds), static fn (int $id): bool => $id > 0)));
        if ($ids === []) return [];
        $summaries = array_fill_keys($ids, self::emptySummary());
        foreach ($this->database->fetchAll(
            'SELECT comment_id, emoji, COUNT(*) AS total, MAX(user_id=?) AS selected_by_user
             FROM comment_reactions WHERE comment_id IN (' . implode(',', $ids) . ') GROUP BY comment_id, emoji',
            [$userId ?? 0]
        ) as $row) {
            $emoji = (string) $row['emoji'];
            if (!in_array($emoji, self::EMOJIS, true)) continue;
            $id = (int) $row['comment_id'];
            $summaries[$id]['counts'][$emoji] = (int) $row['total'];
            if ((bool) $row['selected_by_user']) $summaries[$id]['selected'] = $emoji;
        }
        return $summaries;
    }

    public function togglePage(int $pageId, int $actorId, int $authorId, string $username, string $emoji, string $title, string $url): void
    {
        $this->toggle('page_reactions', 'page_id', $pageId, $actorId, $authorId, $username, $emoji, $title,
            'page.reaction', 'A page you created got a reaction.', $url, null, null, $pageId);
    }

    public function toggleComment(int $commentId, int $pageId, int $actorId, int $authorId, string $username, string $emoji, string $title, string $url): void
    {
        $this->toggle('comment_reactions', 'comment_id', $commentId, $actorId, $authorId, $username, $emoji, $title,
            'comment.reaction', 'A comment you wrote got a reaction.', $url, 'comment', $commentId, $pageId);
    }

    public function popularPages(int $limit = 100): array
    {
        $limit = max(10, min(300, $limit));
        return $this->database->fetchAll(
            'SELECT p.id, p.space_id, p.parent_id, p.inherit_acl, p.owner_id, p.author_id,
                    p.title, p.slug, p.status, p.updated_at, p.created_at,
                    s.space_key, s.name AS space_name, s.visibility, s.owner_id AS space_owner_id,
                    s.status AS space_status, s.deleted_at AS space_deleted_at,
                    COUNT(r.user_id) AS reaction_count
             FROM page_reactions r
             INNER JOIN pages p ON p.id=r.page_id AND p.deleted_at IS NULL AND p.status="published"
             INNER JOIN spaces s ON s.id=p.space_id AND s.deleted_at IS NULL
             GROUP BY p.id, p.space_id, p.parent_id, p.inherit_acl, p.owner_id, p.author_id,
                      p.title, p.slug, p.status, p.updated_at, p.created_at,
                      s.space_key, s.name, s.visibility, s.owner_id, s.status, s.deleted_at
             ORDER BY reaction_count DESC, p.updated_at DESC, p.id DESC LIMIT ' . $limit
        );
    }

    private function toggle(string $table, string $targetColumn, int $targetId, int $actorId, int $authorId, string $username, string $emoji, string $title, string $event, string $notificationTitle, string $url, ?string $resourceType, ?int $resourceId, int $pageId): void
    {
        if (!in_array($emoji, self::EMOJIS, true)) throw new \InvalidArgumentException('Wybierz jedną z dostępnych reakcji.');
        $this->database->transaction(function () use ($table, $targetColumn, $targetId, $actorId, $authorId, $username, $emoji, $title, $event, $notificationTitle, $url, $resourceType, $resourceId, $pageId): void {
            $current = $this->database->fetchOne("SELECT emoji FROM $table WHERE $targetColumn=? AND user_id=? FOR UPDATE", [$targetId, $actorId]);
            if ($current !== null && $current['emoji'] === $emoji) {
                $this->database->execute("DELETE FROM $table WHERE $targetColumn=? AND user_id=?", [$targetId, $actorId]);
                return;
            }
            if ($current === null) {
                $this->database->execute("INSERT INTO $table ($targetColumn, user_id, emoji, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP())", [$targetId, $actorId, $emoji]);
            } else {
                $this->database->execute("UPDATE $table SET emoji=? WHERE $targetColumn=? AND user_id=?", [$emoji, $targetId, $actorId]);
            }
            if ($authorId === $actorId) return;
            $this->database->execute(
                'INSERT INTO notifications
                 (user_id, event_type, title, body, target_url, read_at, created_at, page_id, revision_number, resource_type, resource_id, working, event_key)
                 VALUES (?, ?, ?, ?, ?, NULL, UTC_TIMESTAMP(), ?, NULL, ?, ?, 0, ?)',
                [$authorId, $event, $notificationTitle, $username . ' · ' . $emoji . ' · ' . $title, $url, $pageId, $resourceType, $resourceId, bin2hex(random_bytes(32))]
            );
        });
    }

    private static function formatSummary(array $rows): array
    {
        $summary = self::emptySummary();
        foreach ($rows as $row) {
            $emoji = (string) $row['emoji'];
            if (!in_array($emoji, self::EMOJIS, true)) continue;
            $summary['counts'][$emoji] = (int) $row['total'];
            if ((bool) $row['selected_by_user']) $summary['selected'] = $emoji;
        }
        return $summary;
    }

    private static function emptySummary(): array
    {
        return ['counts' => array_fill_keys(self::EMOJIS, 0), 'selected' => null];
    }
}
