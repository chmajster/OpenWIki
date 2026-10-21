<?php

declare(strict_types=1);

namespace OpenWiki\Repositories;

use OpenWiki\Core\Database;

final class SpaceRepository
{
    public function __construct(private readonly Database $database)
    {
    }

    public function findByKey(string $key): ?array
    {
        return $this->database->fetchOne(
            'SELECT * FROM spaces WHERE space_key = :space_key AND deleted_at IS NULL LIMIT 1',
            ['space_key' => strtoupper($key)]
        );
    }

    public function visibleFor(?int $userId, bool $superAdmin, ?int $limit = null): array
    {
        [$where, $params] = $this->visibility($userId, $superAdmin);
        $limitSql = $limit === null ? '' : ' LIMIT ' . max(1, min(1000, $limit));
        $order = $superAdmin ? 's.status, s.name, s.id' : 's.name, s.id';
        return $this->database->fetchAll('SELECT s.* FROM spaces s WHERE ' . $where . ' ORDER BY ' . $order . $limitSql, $params);
    }

    public function browse(?int $userId, bool $superAdmin, array $filters): array
    {
        return $this->database->transaction(function () use ($userId, $superAdmin, $filters): array {
            [$where, $params] = $this->visibility($userId, $superAdmin);
            if ($filters['q'] !== '') {
                $where .= " AND (s.name LIKE ? ESCAPE '!' OR s.space_key LIKE ? ESCAPE '!' OR s.description LIKE ? ESCAPE '!')";
                $like = \OpenWiki\Search\SearchInput::like($filters['q']);
                array_push($params, $like, $like, $like);
            }
            foreach (['visibility' => 'visibility', 'state' => 'status'] as $filter => $column) {
                if ($filters[$filter] !== 'all') { $where .= ' AND s.' . $column . ' = ?'; $params[] = $filters[$filter]; }
            }
            $total = (int) $this->database->fetchOne('SELECT COUNT(*) total FROM spaces s WHERE ' . $where, $params)['total'];
            $perPage = \OpenWiki\Spaces\BrowseInput::PER_PAGE;
            $pages = max(1, (int) ceil($total / $perPage));
            $page = min($filters['page'], $pages);
            $order = $filters['sort'] === 'updated' ? 's.updated_at DESC, s.name, s.id' : 's.name, s.id';
            $items = $this->database->fetchAll('SELECT s.id, s.name, s.space_key, s.description, s.icon, s.visibility, s.status, s.updated_at FROM spaces s WHERE ' . $where . ' ORDER BY ' . $order . ' LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage), $params);
            return ['data' => $items, 'filters' => $filters, 'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total, 'total_pages' => $pages]];
        });
    }

    private function visibility(?int $userId, bool $superAdmin): array
    {
        if ($superAdmin) return ['s.deleted_at IS NULL', []];
        if ($userId === null) return ['s.deleted_at IS NULL AND s.visibility = "public"', []];
        return [
            's.deleted_at IS NULL
               AND (
                    s.visibility = "public"
                    OR s.owner_id = ?
                    OR (s.visibility = "restricted" AND EXISTS (
                        SELECT 1
                        FROM space_acl a
                        WHERE a.space_id = s.id
                          AND a.permission = "space.view"
                          AND a.effect = "allow"
                          AND (
                              (a.principal_type = "user" AND a.principal_id = ?)
                              OR (a.principal_type = "group" AND EXISTS (
                                  SELECT 1 FROM group_users gu
                                  WHERE gu.group_id = a.principal_id AND gu.user_id = ?
                              ))
                              OR (a.principal_type = "role" AND EXISTS (
                                  SELECT 1 FROM user_roles ur
                                  WHERE ur.role_id = a.principal_id AND ur.user_id = ?
                              ))
                          )
                    ))
               )
               AND (s.owner_id = ? OR NOT EXISTS (
                    SELECT 1
                    FROM space_acl d
                    WHERE d.space_id = s.id
                      AND d.permission = "space.view"
                      AND d.effect = "deny"
                      AND (
                          (d.principal_type = "user" AND d.principal_id = ?)
                          OR (d.principal_type = "group" AND EXISTS (
                              SELECT 1 FROM group_users gu2
                              WHERE gu2.group_id = d.principal_id AND gu2.user_id = ?
                          ))
                          OR (d.principal_type = "role" AND EXISTS (
                              SELECT 1 FROM user_roles ur2
                              WHERE ur2.role_id = d.principal_id AND ur2.user_id = ?
                          ))
                      )
               ))',
            [$userId, $userId, $userId, $userId, $userId, $userId, $userId, $userId],
        ];
    }

    public function create(array $data): int
    {
        return $this->database->insert(
            'INSERT INTO spaces
             (name, space_key, description, icon, owner_id, visibility, status, created_at, updated_at)
             VALUES
             (:name, :space_key, :description, NULL, :owner_id, :visibility, "active", UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            $data
        );
    }
}
