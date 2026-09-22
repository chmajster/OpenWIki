<?php

declare(strict_types=1);

namespace OpenWiki\Repositories;

use OpenWiki\Core\Database;

final class SearchRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    public function searchPages(string $query, int $limit = 50): array
    {
        $query = trim($query);
        if ($query === '') {
            return [];
        }

        $limit = max(1, min($limit, 100));
        $like = '%' . $this->escapeLike($query) . '%';

        return $this->database->fetchAll(
            'SELECT p.id, p.parent_id, p.inherit_acl, p.owner_id, p.author_id,
                    p.title, p.slug, p.content_text, p.status, p.updated_at,
                    s.id AS space_id, s.space_key, s.name AS space_name, s.visibility,
                    s.status AS space_status, s.owner_id AS space_owner_id, s.deleted_at AS space_deleted_at,
                    CASE
                        WHEN p.title = ? THEN 100
                        WHEN p.title LIKE ? ESCAPE "\\\\" THEN 50
                        ELSE 10
                    END AS relevance
             FROM pages p
             INNER JOIN spaces s ON s.id = p.space_id
             WHERE p.deleted_at IS NULL
               AND s.deleted_at IS NULL
               AND (p.title LIKE ? ESCAPE "\\\\" OR p.content_text LIKE ? ESCAPE "\\\\")
             ORDER BY relevance DESC, p.updated_at DESC
             LIMIT ' . $limit,
            [$query, $like, $like, $like]
        );
    }

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}
