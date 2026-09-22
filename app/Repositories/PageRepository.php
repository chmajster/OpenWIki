<?php

declare(strict_types=1);

namespace OpenWiki\Repositories;

use OpenWiki\Core\Database;

final class PageRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    public function findBySlug(int $spaceId, string $slug): ?array
    {
        return $this->database->fetchOne(
            'SELECT p.*, u.username AS author_username
             FROM pages p
             INNER JOIN users u ON u.id = p.author_id
             WHERE p.space_id = :space_id AND p.slug = :slug AND p.deleted_at IS NULL
             LIMIT 1',
            ['space_id' => $spaceId, 'slug' => $slug]
        );
    }

    public function findById(int $pageId): ?array
    {
        return $this->database->fetchOne(
            'SELECT * FROM pages WHERE id = :id AND deleted_at IS NULL LIMIT 1',
            ['id' => $pageId]
        );
    }

    public function redirectTarget(int $spaceId, string $oldSlug): ?array
    {
        return $this->database->fetchOne(
            'SELECT p.*
             FROM page_redirects r
             INNER JOIN pages p ON p.id = r.page_id
             WHERE r.space_id = :space_id AND r.old_slug = :old_slug AND p.deleted_at IS NULL
             LIMIT 1',
            ['space_id' => $spaceId, 'old_slug' => $oldSlug]
        );
    }

    public function tree(int $spaceId): array
    {
        return $this->database->fetchAll(
            'SELECT id, parent_id, title, slug, status, order_index, updated_at
             FROM pages
             WHERE space_id = :space_id AND deleted_at IS NULL
             ORDER BY order_index ASC, title ASC',
            ['space_id' => $spaceId]
        );
    }

    public function create(array $data): int
    {
        return $this->database->transaction(function (Database $db) use ($data): int {
            $pageId = $db->insert(
                'INSERT INTO pages
                 (space_id, parent_id, title, slug, content_html, content_markdown, content_text, content_format,
                  author_id, owner_id, order_index, status, version, published_at, created_at, updated_at)
                 VALUES
                 (:space_id, :parent_id, :title, :slug, :content_html, :content_markdown, :content_text, :content_format,
                  :author_id, :owner_id, 0, :status, 1, :published_at, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
                [
                    'space_id' => $data['space_id'],
                    'parent_id' => $data['parent_id'],
                    'title' => $data['title'],
                    'slug' => $data['slug'],
                    'content_html' => $data['content_html'],
                    'content_markdown' => $data['content_markdown'],
                    'content_text' => $data['content_text'],
                    'content_format' => $data['content_format'],
                    'author_id' => $data['author_id'],
                    'owner_id' => $data['owner_id'],
                    'status' => $data['status'],
                    'published_at' => $data['status'] === 'published' ? gmdate('Y-m-d H:i:s') : null,
                ]
            );

            $db->execute(
                'INSERT INTO page_revisions
                 (page_id, revision_number, title, content_html, content_markdown, content_text, content_format,
                  status, author_id, change_summary, created_at)
                 VALUES
                 (:page_id, 1, :title, :content_html, :content_markdown, :content_text, :content_format,
                  :status, :author_id, :change_summary, UTC_TIMESTAMP())',
                [
                    'page_id' => $pageId,
                    'title' => $data['title'],
                    'content_html' => $data['content_html'],
                    'content_markdown' => $data['content_markdown'],
                    'content_text' => $data['content_text'],
                    'content_format' => $data['content_format'],
                    'status' => $data['status'],
                    'author_id' => $data['author_id'],
                    'change_summary' => $data['change_summary'] ?: 'Initial version',
                ]
            );

            return $pageId;
        });
    }

    public function update(array $current, array $data): array
    {
        return $this->database->transaction(function (Database $db) use ($current, $data): array {
            $baseVersion = (int) $data['base_version'];
            $newVersion = $baseVersion + 1;
            $publishedAt = $data['status'] === 'published'
                ? ($current['published_at'] ?: gmdate('Y-m-d H:i:s'))
                : null;

            $affected = $db->execute(
                'UPDATE pages
                 SET parent_id = :parent_id,
                     title = :title,
                     slug = :slug,
                     content_html = :content_html,
                     content_markdown = :content_markdown,
                     content_text = :content_text,
                     content_format = :content_format,
                     status = :status,
                     version = :new_version,
                     published_at = :published_at,
                     updated_at = UTC_TIMESTAMP()
                 WHERE id = :id AND version = :base_version AND deleted_at IS NULL',
                [
                    'parent_id' => $data['parent_id'],
                    'title' => $data['title'],
                    'slug' => $data['slug'],
                    'content_html' => $data['content_html'],
                    'content_markdown' => $data['content_markdown'],
                    'content_text' => $data['content_text'],
                    'content_format' => $data['content_format'],
                    'status' => $data['status'],
                    'new_version' => $newVersion,
                    'published_at' => $publishedAt,
                    'id' => (int) $current['id'],
                    'base_version' => $baseVersion,
                ]
            );

            if ($affected !== 1) {
                throw new \RuntimeException('EDIT_CONFLICT');
            }

            if ($current['slug'] !== $data['slug']) {
                $db->execute(
                    'INSERT INTO page_redirects (space_id, old_slug, page_id, created_at)
                     VALUES (:space_id, :old_slug, :page_id, UTC_TIMESTAMP())
                     ON DUPLICATE KEY UPDATE page_id = VALUES(page_id)',
                    [
                        'space_id' => (int) $current['space_id'],
                        'old_slug' => $current['slug'],
                        'page_id' => (int) $current['id'],
                    ]
                );
            }

            $db->execute(
                'INSERT INTO page_revisions
                 (page_id, revision_number, title, content_html, content_markdown, content_text, content_format,
                  status, author_id, change_summary, created_at)
                 VALUES
                 (:page_id, :revision_number, :title, :content_html, :content_markdown, :content_text, :content_format,
                  :status, :author_id, :change_summary, UTC_TIMESTAMP())',
                [
                    'page_id' => (int) $current['id'],
                    'revision_number' => $newVersion,
                    'title' => $data['title'],
                    'content_html' => $data['content_html'],
                    'content_markdown' => $data['content_markdown'],
                    'content_text' => $data['content_text'],
                    'content_format' => $data['content_format'],
                    'status' => $data['status'],
                    'author_id' => $data['author_id'],
                    'change_summary' => $data['change_summary'] ?: null,
                ]
            );

            $db->execute(
                'DELETE FROM draft_autosaves WHERE page_id = :page_id AND user_id = :user_id',
                ['page_id' => (int) $current['id'], 'user_id' => $data['author_id']]
            );

            return ['version' => $newVersion, 'published_at' => $publishedAt];
        });
    }

    public function revisions(int $pageId): array
    {
        return $this->database->fetchAll(
            'SELECT r.*, u.username
             FROM page_revisions r
             INNER JOIN users u ON u.id = r.author_id
             WHERE r.page_id = :page_id
             ORDER BY r.revision_number DESC',
            ['page_id' => $pageId]
        );
    }

    public function revision(int $pageId, int $revisionNumber): ?array
    {
        return $this->database->fetchOne(
            'SELECT * FROM page_revisions
             WHERE page_id = :page_id AND revision_number = :revision_number
             LIMIT 1',
            ['page_id' => $pageId, 'revision_number' => $revisionNumber]
        );
    }

    public function restore(array $current, array $revision, int $userId): int
    {
        $data = [
            'parent_id' => $current['parent_id'],
            'title' => $revision['title'],
            'slug' => $current['slug'],
            'content_html' => $revision['content_html'],
            'content_markdown' => $revision['content_markdown'],
            'content_text' => $revision['content_text'],
            'content_format' => $revision['content_format'],
            'status' => $revision['status'],
            'base_version' => (int) $current['version'],
            'author_id' => $userId,
            'change_summary' => 'Restored revision ' . (int) $revision['revision_number'],
        ];

        return $this->update($current, $data)['version'];
    }

    public function wouldCreateCycle(int $pageId, ?int $parentId): bool
    {
        if ($parentId === null) {
            return false;
        }

        $cursor = $parentId;
        $visited = [];

        while ($cursor !== null) {
            if ($cursor === $pageId || isset($visited[$cursor])) {
                return true;
            }

            $visited[$cursor] = true;
            if (count($visited) > 10000) {
                throw new \RuntimeException('Page hierarchy exceeds the supported traversal depth.');
            }

            $row = $this->database->fetchOne(
                'SELECT parent_id FROM pages WHERE id = :id AND deleted_at IS NULL LIMIT 1',
                ['id' => $cursor]
            );

            if ($row === null) {
                return true;
            }

            $cursor = $row['parent_id'] === null ? null : (int) $row['parent_id'];
        }

        return false;
    }

    public function recordView(int $pageId, int $userId): void
    {
        $this->database->execute(
            'INSERT INTO recent_views (user_id, page_id, viewed_at)
             VALUES (:user_id, :page_id, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE viewed_at = UTC_TIMESTAMP()',
            ['user_id' => $userId, 'page_id' => $pageId]
        );
    }

    public function recentForUser(int $userId, int $limit = 10): array
    {
        $limit = max(1, min($limit, 50));
        return $this->database->fetchAll(
            'SELECT p.id, p.title, p.slug, p.status, p.updated_at, s.space_key, s.name AS space_name, rv.viewed_at
             FROM recent_views rv
             INNER JOIN pages p ON p.id = rv.page_id AND p.deleted_at IS NULL
             INNER JOIN spaces s ON s.id = p.space_id AND s.deleted_at IS NULL
             WHERE rv.user_id = ?
             ORDER BY rv.viewed_at DESC
             LIMIT ' . $limit,
            [$userId]
        );
    }

    public function recentUpdated(int $limit = 20): array
    {
        $limit = max(1, min($limit, 50));
        return $this->database->fetchAll(
            'SELECT p.id, p.title, p.slug, p.status, p.updated_at, p.owner_id, p.author_id,
                    s.id AS space_id, s.space_key, s.name AS space_name, s.visibility, s.owner_id AS space_owner_id,
                    s.status AS space_status, s.deleted_at AS space_deleted_at
             FROM pages p
             INNER JOIN spaces s ON s.id = p.space_id
             WHERE p.deleted_at IS NULL AND s.deleted_at IS NULL
             ORDER BY p.updated_at DESC
             LIMIT ' . $limit
        );
    }

    public function draftsForUser(int $userId, int $limit = 10): array
    {
        $limit = max(1, min($limit, 50));
        return $this->database->fetchAll(
            'SELECT p.id, p.title, p.slug, p.updated_at, s.space_key, s.name AS space_name
             FROM pages p
             INNER JOIN spaces s ON s.id = p.space_id
             WHERE p.deleted_at IS NULL AND p.status = "draft" AND (p.owner_id = ? OR p.author_id = ?)
             ORDER BY p.updated_at DESC
             LIMIT ' . $limit,
            [$userId, $userId]
        );
    }
}
