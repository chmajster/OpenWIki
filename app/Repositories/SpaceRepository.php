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

    public function visibleFor(?int $userId, bool $superAdmin): array
    {
        if ($superAdmin) {
            return $this->database->fetchAll(
                'SELECT * FROM spaces WHERE deleted_at IS NULL AND status = "active" ORDER BY name ASC'
            );
        }

        if ($userId === null) {
            return $this->database->fetchAll(
                'SELECT * FROM spaces
                 WHERE deleted_at IS NULL AND status = "active" AND visibility = "public"
                 ORDER BY name ASC'
            );
        }

        return $this->database->fetchAll(
            'SELECT s.*
             FROM spaces s
             WHERE s.deleted_at IS NULL
               AND s.status = "active"
               AND (
                    s.visibility = "public"
                    OR s.owner_id = ?
                    OR EXISTS (
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
                    )
               )
               AND NOT EXISTS (
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
               )
             ORDER BY s.name ASC',
            [$userId, $userId, $userId, $userId, $userId, $userId, $userId]
        );
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
