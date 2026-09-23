<?php

declare(strict_types=1);

namespace OpenWiki\Wiki;

use OpenWiki\Core\Database;

final class CommentService
{
    public function __construct(private readonly Database $database)
    {
    }

    public function listForPage(int $pageId): array
    {
        return $this->database->fetchAll(
            'SELECT c.id, c.page_id, c.parent_id, c.author_id, c.body_html, c.created_at, c.updated_at,
                    u.username, u.first_name, u.last_name
             FROM comments c
             INNER JOIN users u ON u.id = c.author_id
             WHERE c.page_id = :page_id AND c.deleted_at IS NULL
             ORDER BY c.created_at ASC, c.id ASC',
            ['page_id' => $pageId]
        );
    }

    public function create(
        int $pageId,
        int $spaceId,
        int $authorId,
        string $body,
        ?int $parentId,
        string $pageTitle,
        string $targetUrl
    ): int {
        $body = $this->validateBody($body);

        $parent = null;
        if ($parentId !== null) {
            $parent = $this->database->fetchOne(
                'SELECT id, page_id, author_id
                 FROM comments
                 WHERE id = :id AND deleted_at IS NULL
                 LIMIT 1',
                ['id' => $parentId]
            );
            if ($parent === null || (int) $parent['page_id'] !== $pageId) {
                throw new \InvalidArgumentException('Reply target does not belong to this page.');
            }
        }

        $commentId = $this->database->insert(
            'INSERT INTO comments
             (page_id, parent_id, author_id, body_html, created_at, updated_at, deleted_at)
             VALUES (:page_id, :parent_id, :author_id, :body_html, UTC_TIMESTAMP(), UTC_TIMESTAMP(), NULL)',
            [
                'page_id' => $pageId,
                'parent_id' => $parentId,
                'author_id' => $authorId,
                'body_html' => $this->renderBody($body),
            ]
        );

        if ($parent !== null && (int) $parent['author_id'] !== $authorId) {
            $this->notifyUser(
                (int) $parent['author_id'],
                'reply',
                'New reply on ' . $pageTitle,
                $targetUrl . '#comment-' . $commentId
            );
        }

        $this->notifyMentions($body, $authorId, $pageTitle, $targetUrl . '#comment-' . $commentId);

        try {
            (new PageEngagementService($this->database))->notifyWatchers(
                $pageId,
                $spaceId,
                $authorId,
                'comment.created',
                'New comment on ' . $pageTitle,
                $targetUrl . '#comment-' . $commentId
            );
        } catch (\Throwable $exception) {
            error_log('[OpenWiki comment notification] ' . $exception->getMessage());
        }

        return $commentId;
    }

    public function update(int $commentId, int $actorId, string $body): bool
    {
        $comment = $this->database->fetchOne(
            'SELECT c.id, c.author_id, c.page_id, c.body_html, p.title AS page_title,
                    s.space_key, p.slug
             FROM comments c
             INNER JOIN pages p ON p.id = c.page_id
             INNER JOIN spaces s ON s.id = p.space_id
             WHERE c.id = :id AND c.deleted_at IS NULL
             LIMIT 1',
            ['id' => $commentId]
        );

        if ($comment === null) {
            return false;
        }
        if ((int) $comment['author_id'] !== $actorId) {
            throw new \RuntimeException('COMMENT_EDIT_FORBIDDEN');
        }

        $body = $this->validateBody($body);
        $oldMentions = $this->mentions(strip_tags((string) $comment['body_html']));
        $newMentions = $this->mentions($body);

        $this->database->execute(
            'UPDATE comments
             SET body_html = :body_html, updated_at = UTC_TIMESTAMP()
             WHERE id = :id AND deleted_at IS NULL',
            ['body_html' => $this->renderBody($body), 'id' => $commentId]
        );

        $newOnly = array_values(array_diff($newMentions, $oldMentions));
        if ($newOnly !== []) {
            $targetUrl = '/spaces/' . rawurlencode((string) $comment['space_key'])
                . '/pages/' . rawurlencode((string) $comment['slug'])
                . '#comment-' . $commentId;
            $this->notifyMentions(implode(' ', array_map(static fn (string $name): string => '@' . $name, $newOnly)), $actorId, (string) $comment['page_title'], $targetUrl);
        }

        return true;
    }

    public function delete(int $commentId, int $actorId, bool $canModerate): bool
    {
        $comment = $this->database->fetchOne(
            'SELECT id, author_id FROM comments WHERE id = :id AND deleted_at IS NULL LIMIT 1',
            ['id' => $commentId]
        );
        if ($comment === null) {
            return false;
        }

        if ((int) $comment['author_id'] !== $actorId && !$canModerate) {
            throw new \RuntimeException('COMMENT_DELETE_FORBIDDEN');
        }

        return $this->database->execute(
            'UPDATE comments SET deleted_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
             WHERE id = :id AND deleted_at IS NULL',
            ['id' => $commentId]
        ) === 1;
    }

    private function validateBody(string $body): string
    {
        $body = trim($body);
        if ($body === '') {
            throw new \InvalidArgumentException('Comment cannot be empty.');
        }
        if (mb_strlen($body) > 10000) {
            throw new \InvalidArgumentException('Comment may contain at most 10000 characters.');
        }

        return $body;
    }

    private function renderBody(string $body): string
    {
        return nl2br(
            htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'),
            false
        );
    }

    private function notifyMentions(string $body, int $actorId, string $pageTitle, string $targetUrl): void
    {
        foreach ($this->mentions($body) as $username) {
            $user = $this->database->fetchOne(
                'SELECT id
                 FROM users
                 WHERE LOWER(username) = LOWER(:username)
                   AND status = "active"
                   AND deleted_at IS NULL
                 LIMIT 1',
                ['username' => $username]
            );

            if ($user === null || (int) $user['id'] === $actorId) {
                continue;
            }

            $this->notifyUser(
                (int) $user['id'],
                'mention',
                'You were mentioned on ' . $pageTitle,
                $targetUrl
            );
        }
    }

    private function mentions(string $body): array
    {
        preg_match_all('/(?<![A-Za-z0-9._-])@([A-Za-z0-9._-]{3,100})/u', $body, $matches);
        $names = array_values(array_unique($matches[1] ?? []));
        return array_slice($names, 0, 20);
    }

    private function notifyUser(int $userId, string $eventType, string $title, string $targetUrl): void
    {
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
