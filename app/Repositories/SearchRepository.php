<?php

declare(strict_types=1);

namespace OpenWiki\Repositories;

use OpenWiki\Core\Database;

final class SearchRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    public function searchPages(string $query, array|int $filters = [], int $limit = 100): array
    {
        if (is_int($filters)) {
            $limit = $filters;
            $filters = [];
        }

        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $limit = max(1, min($limit, 250));
        $like = '%' . $this->escapeLike($query) . '%';
        $prefix = $this->escapeLike($query) . '%';
        $where = [
            'p.deleted_at IS NULL',
            's.deleted_at IS NULL',
            '(p.title LIKE ? ESCAPE "\\\\"'
                . ' OR p.content_text LIKE ? ESCAPE "\\\\"'
                . ' OR s.name LIKE ? ESCAPE "\\\\"'
                . ' OR s.space_key LIKE ? ESCAPE "\\\\"'
                . ' OR u.username LIKE ? ESCAPE "\\\\"'
                . ' OR EXISTS ('
                    . 'SELECT 1 FROM page_tags qpt '
                    . 'INNER JOIN tags qt ON qt.id = qpt.tag_id '
                    . 'WHERE qpt.page_id = p.id AND qt.name LIKE ? ESCAPE "\\\\"'
                . '))',
        ];
        $params = [$like, $like, $like, $like, $like, $like];

        $this->appendPageFilters($where, $params, $filters);

        return $this->database->fetchAll(
            'SELECT p.id, p.space_id, p.parent_id, p.inherit_acl, p.owner_id, p.author_id,
                    p.title, p.slug, p.content_text, p.status, p.created_at, p.updated_at,
                    u.username AS author_username,
                    s.space_key, s.name AS space_name, s.visibility,
                    s.status AS space_status, s.owner_id AS space_owner_id, s.deleted_at AS space_deleted_at,
                    CASE
                        WHEN LOWER(p.title) = LOWER(?) THEN 120
                        WHEN p.title LIKE ? ESCAPE "\\\\" THEN 90
                        WHEN p.title LIKE ? ESCAPE "\\\\" THEN 70
                        WHEN EXISTS (
                            SELECT 1 FROM page_tags rpt
                            INNER JOIN tags rt ON rt.id = rpt.tag_id
                            WHERE rpt.page_id = p.id AND rt.name LIKE ? ESCAPE "\\\\"
                        ) THEN 60
                        WHEN s.name LIKE ? ESCAPE "\\\\" OR s.space_key LIKE ? ESCAPE "\\\\" THEN 50
                        WHEN u.username LIKE ? ESCAPE "\\\\" THEN 40
                        ELSE 20
                    END AS relevance
             FROM pages p
             INNER JOIN spaces s ON s.id = p.space_id
             INNER JOIN users u ON u.id = p.author_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY relevance DESC, p.updated_at DESC
             LIMIT ' . $limit,
            array_merge(
                [$query, $prefix, $like, $like, $like, $like, $like],
                $params
            )
        );
    }

    public function searchSpaces(string $query, array $filters = [], int $limit = 100): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $limit = max(1, min($limit, 250));
        $like = '%' . $this->escapeLike($query) . '%';
        $where = [
            'deleted_at IS NULL',
            '(name LIKE ? ESCAPE "\\\\" OR space_key LIKE ? ESCAPE "\\\\" OR description LIKE ? ESCAPE "\\\\")',
        ];
        $params = [$like, $like, $like];

        if (($filters['space'] ?? '') !== '') {
            $spaceLike = '%' . $this->escapeLike((string) $filters['space']) . '%';
            $where[] = '(space_key LIKE ? ESCAPE "\\\\" OR name LIKE ? ESCAPE "\\\\")';
            $params[] = $spaceLike;
            $params[] = $spaceLike;
        }
        $this->appendDateFilters($where, $params, $filters, 'created_at', 'updated_at');

        return $this->database->fetchAll(
            'SELECT id, name, space_key, description, owner_id, visibility, status, created_at, updated_at,
                    CASE
                        WHEN LOWER(name) = LOWER(?) OR LOWER(space_key) = LOWER(?) THEN 110
                        WHEN name LIKE ? ESCAPE "\\\\" OR space_key LIKE ? ESCAPE "\\\\" THEN 75
                        ELSE 30
                    END AS relevance
             FROM spaces
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY relevance DESC, updated_at DESC
             LIMIT ' . $limit,
            array_merge([$query, $query, $like, $like], $params)
        );
    }

    public function searchUsers(string $query, array $filters = [], int $limit = 100): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $limit = max(1, min($limit, 250));
        $like = '%' . $this->escapeLike($query) . '%';
        $where = [
            'deleted_at IS NULL',
            '(username LIKE ? ESCAPE "\\\\" OR first_name LIKE ? ESCAPE "\\\\" OR last_name LIKE ? ESCAPE "\\\\")',
        ];
        $params = [$like, $like, $like];

        if (($filters['author'] ?? '') !== '') {
            $authorLike = '%' . $this->escapeLike((string) $filters['author']) . '%';
            $where[] = 'username LIKE ? ESCAPE "\\\\"';
            $params[] = $authorLike;
        }
        $this->appendDateFilters($where, $params, $filters, 'created_at', 'updated_at');

        return $this->database->fetchAll(
            'SELECT id, username, first_name, last_name, status, auth_source, created_at, updated_at,
                    CASE
                        WHEN LOWER(username) = LOWER(?) THEN 100
                        WHEN username LIKE ? ESCAPE "\\\\" THEN 70
                        ELSE 25
                    END AS relevance
             FROM users
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY relevance DESC, username ASC
             LIMIT ' . $limit,
            array_merge([$query, $like], $params)
        );
    }

    public function searchComments(string $query, array $filters = [], int $limit = 100): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $limit = max(1, min($limit, 250));
        $like = '%' . $this->escapeLike($query) . '%';
        $where = [
            'c.deleted_at IS NULL',
            'p.deleted_at IS NULL',
            's.deleted_at IS NULL',
            'c.body_html LIKE ? ESCAPE "\\\\"',
        ];
        $params = [$like];
        $this->appendPageFilters($where, $params, $filters, 'p', 's', 'u');

        return $this->database->fetchAll(
            'SELECT c.id AS comment_id, c.body_html, c.created_at, c.updated_at,
                    p.id, p.space_id, p.parent_id, p.inherit_acl, p.owner_id, p.author_id,
                    p.title, p.slug, p.status,
                    u.username AS comment_author_username,
                    s.space_key, s.name AS space_name, s.visibility,
                    s.status AS space_status, s.owner_id AS space_owner_id, s.deleted_at AS space_deleted_at,
                    CASE WHEN c.body_html LIKE ? ESCAPE "\\\\" THEN 45 ELSE 20 END AS relevance
             FROM comments c
             INNER JOIN pages p ON p.id = c.page_id
             INNER JOIN spaces s ON s.id = p.space_id
             INNER JOIN users u ON u.id = c.author_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY relevance DESC, c.updated_at DESC
             LIMIT ' . $limit,
            array_merge([$like], $params)
        );
    }

    public function searchAttachments(string $query, array $filters = [], int $limit = 100): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $limit = max(1, min($limit, 250));
        $like = '%' . $this->escapeLike($query) . '%';
        $where = [
            'a.deleted_at IS NULL',
            'p.deleted_at IS NULL',
            's.deleted_at IS NULL',
            '(a.name LIKE ? ESCAPE "\\\\" OR a.mime_type LIKE ? ESCAPE "\\\\")',
        ];
        $params = [$like, $like];
        $this->appendPageFilters($where, $params, $filters, 'p', 's', 'u');

        return $this->database->fetchAll(
            'SELECT a.id AS attachment_id, a.name AS attachment_name, a.mime_type, a.size_bytes,
                    a.created_at, a.updated_at,
                    p.id, p.space_id, p.parent_id, p.inherit_acl, p.owner_id, p.author_id,
                    p.title, p.slug, p.status,
                    u.username AS uploader_username,
                    s.space_key, s.name AS space_name, s.visibility,
                    s.status AS space_status, s.owner_id AS space_owner_id, s.deleted_at AS space_deleted_at,
                    CASE
                        WHEN LOWER(a.name) = LOWER(?) THEN 100
                        WHEN a.name LIKE ? ESCAPE "\\\\" THEN 70
                        ELSE 25
                    END AS relevance
             FROM attachments a
             INNER JOIN pages p ON p.id = a.page_id
             INNER JOIN spaces s ON s.id = p.space_id
             INNER JOIN users u ON u.id = a.uploader_id
             WHERE ' . implode(' AND ', $where) . '
             ORDER BY relevance DESC, a.updated_at DESC
             LIMIT ' . $limit,
            array_merge([$query, $like], $params)
        );
    }

    public function searchTags(string $query, int $limit = 100): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $limit = max(1, min($limit, 250));
        $like = '%' . $this->escapeLike($query) . '%';

        return $this->database->fetchAll(
            'SELECT id, name, slug, created_at,
                    CASE
                        WHEN LOWER(name) = LOWER(?) OR LOWER(slug) = LOWER(?) THEN 90
                        ELSE 55
                    END AS relevance
             FROM tags
             WHERE name LIKE ? ESCAPE "\\\\" OR slug LIKE ? ESCAPE "\\\\"
             ORDER BY relevance DESC, name ASC
             LIMIT ' . $limit,
            [$query, $query, $like, $like]
        );
    }

    private function appendPageFilters(
        array &$where,
        array &$params,
        array $filters,
        string $pageAlias = 'p',
        string $spaceAlias = 's',
        string $authorAlias = 'u'
    ): void {
        if (($filters['space'] ?? '') !== '') {
            $like = '%' . $this->escapeLike((string) $filters['space']) . '%';
            $where[] = '(' . $spaceAlias . '.space_key LIKE ? ESCAPE "\\\\"'
                . ' OR ' . $spaceAlias . '.name LIKE ? ESCAPE "\\\\")';
            $params[] = $like;
            $params[] = $like;
        }

        if (($filters['author'] ?? '') !== '') {
            $like = '%' . $this->escapeLike((string) $filters['author']) . '%';
            $where[] = $authorAlias . '.username LIKE ? ESCAPE "\\\\"';
            $params[] = $like;
        }

        if (($filters['tag'] ?? '') !== '') {
            $tag = (string) $filters['tag'];
            $where[] = 'EXISTS (
                SELECT 1 FROM page_tags fpt
                INNER JOIN tags ft ON ft.id = fpt.tag_id
                WHERE fpt.page_id = ' . $pageAlias . '.id
                  AND (LOWER(ft.name) = LOWER(?) OR LOWER(ft.slug) = LOWER(?))
            )';
            $params[] = $tag;
            $params[] = $tag;
        }

        $this->appendDateFilters(
            $where,
            $params,
            $filters,
            $pageAlias . '.created_at',
            $pageAlias . '.updated_at'
        );
    }

    private function appendDateFilters(
        array &$where,
        array &$params,
        array $filters,
        string $createdColumn,
        string $updatedColumn
    ): void {
        foreach ([
            'created_from' => [$createdColumn, '>='],
            'created_to' => [$createdColumn, '<='],
            'updated_from' => [$updatedColumn, '>='],
            'updated_to' => [$updatedColumn, '<='],
        ] as $key => [$column, $operator]) {
            if (($filters[$key] ?? '') === '') {
                continue;
            }
            $where[] = $column . ' ' . $operator . ' ?';
            $params[] = $filters[$key];
        }
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
