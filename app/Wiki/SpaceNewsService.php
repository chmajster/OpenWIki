<?php

declare(strict_types=1);

namespace OpenWiki\Wiki;

use OpenWiki\Core\Database;

final class SpaceNewsService
{
    public function __construct(private readonly Database $database)
    {
    }

    public function listForSpace(int $spaceId, int $limit = 50): array
    {
        $limit = max(1, min($limit, 100));
        return $this->database->fetchAll(
            'SELECT n.*, u.username, u.first_name, u.last_name
             FROM space_news n INNER JOIN users u ON u.id=n.author_id
             WHERE n.space_id=? AND n.deleted_at IS NULL
             ORDER BY n.created_at DESC, n.id DESC LIMIT ' . $limit,
            [$spaceId]
        );
    }

    public function find(int $spaceId, int $id): ?array
    {
        return $this->database->fetchOne(
            'SELECT n.*, u.username, u.first_name, u.last_name FROM space_news n
             INNER JOIN users u ON u.id=n.author_id
             WHERE n.id=? AND n.space_id=? AND n.deleted_at IS NULL',
            [$id, $spaceId]
        );
    }

    public function create(int $spaceId, int $authorId, mixed $title, mixed $body): int
    {
        [$title, $body] = $this->validate($title, $body);
        return $this->database->insert(
            'INSERT INTO space_news (space_id, author_id, title, body, created_at, updated_at)
             VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            [$spaceId, $authorId, $title, $body]
        );
    }

    public function update(int $id, int $spaceId, mixed $title, mixed $body): void
    {
        [$title, $body] = $this->validate($title, $body);
        $this->database->execute(
            'UPDATE space_news SET title=?, body=?, updated_at=UTC_TIMESTAMP() WHERE id=? AND space_id=? AND deleted_at IS NULL',
            [$title, $body, $id, $spaceId]
        );
    }

    public function delete(int $id, int $spaceId): void
    {
        $this->database->execute(
            'UPDATE space_news SET deleted_at=UTC_TIMESTAMP() WHERE id=? AND space_id=? AND deleted_at IS NULL',
            [$id, $spaceId]
        );
    }

    private function validate(mixed $title, mixed $body): array
    {
        if (!is_string($title) || !is_string($body)) throw new \InvalidArgumentException('Wpis musi zawierać tytuł i treść.');
        $title = trim($title);
        $body = trim($body);
        if ($title === '' || mb_strlen($title) > 200) throw new \InvalidArgumentException('Tytuł musi mieć od 1 do 200 znaków.');
        if ($body === '' || mb_strlen($body) > 5000) throw new \InvalidArgumentException('Treść musi mieć od 1 do 5000 znaków.');
        return [$title, $body];
    }
}
