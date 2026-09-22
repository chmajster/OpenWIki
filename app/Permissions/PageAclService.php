<?php

declare(strict_types=1);

namespace OpenWiki\Permissions;

use OpenWiki\Core\Application;
use OpenWiki\Core\Database;

final class PageAclService
{
    public function __construct(private readonly Application $app)
    {
    }

    public function canView(array $page, array $space): bool
    {
        $spaceAccess = new SpaceAccessService($this->app);
        if (!$spaceAccess->canView($space)) {
            return false;
        }

        $user = $this->app->auth()->user();
        if ($user !== null && $this->app->auth()->can('*')) {
            return true;
        }

        $decision = $this->decision($page, 'page.view');
        if ($decision === false) {
            return false;
        }

        if ($page['status'] === 'published') {
            if ($decision === true) {
                return $user !== null && $this->app->auth()->can('page.view');
            }
            return true;
        }

        if ($user === null) {
            return false;
        }

        if ($decision === true && $this->app->auth()->can('page.view')) {
            return true;
        }

        return (int) $page['owner_id'] === (int) $user['id']
            || (int) $page['author_id'] === (int) $user['id']
            || $spaceAccess->canEdit($space);
    }

    public function canEdit(array $page, array $space): bool
    {
        return $this->can($page, $space, 'page.edit');
    }

    public function can(array $page, array $space, string $permission): bool
    {
        $user = $this->app->auth()->user();
        if ($user === null || !$this->app->auth()->can($permission)) {
            return false;
        }

        if ($this->app->auth()->can('*')) {
            return true;
        }

        $spaceAccess = new SpaceAccessService($this->app);
        $baseAllowed = $permission === 'page.view'
            ? $spaceAccess->canView($space)
            : $spaceAccess->canEdit($space);

        if (!$baseAllowed) {
            return false;
        }

        if ((int) $space['owner_id'] === (int) $user['id']) {
            return true;
        }

        return $this->decision($page, $permission) !== false;
    }

    public function entries(int $pageId): array
    {
        $rows = $this->app->database()->fetchAll(
            'SELECT id, page_id, principal_type, principal_id, permission, effect,
                    inherit_to_children, created_at
             FROM page_acl
             WHERE page_id = :page_id
             ORDER BY permission ASC, principal_type ASC, principal_id ASC',
            ['page_id' => $pageId]
        );

        foreach ($rows as &$row) {
            $row['principal_name'] = $this->principalName(
                (string) $row['principal_type'],
                (int) $row['principal_id']
            );
        }
        unset($row);

        return $rows;
    }

    public function setInheritance(int $pageId, bool $inherit): void
    {
        $updated = $this->app->database()->execute(
            'UPDATE pages SET inherit_acl = :inherit_acl, updated_at = UTC_TIMESTAMP()
             WHERE id = :id AND deleted_at IS NULL',
            ['inherit_acl' => $inherit ? 1 : 0, 'id' => $pageId]
        );

        if ($updated > 1) {
            throw new \RuntimeException('Unexpected page ACL update result.');
        }
    }

    public function addRule(
        int $pageId,
        string $principalType,
        int $principalId,
        string $permission,
        string $effect,
        bool $inheritToChildren
    ): int {
        if (!in_array($principalType, ['user', 'group', 'role'], true)) {
            throw new \InvalidArgumentException('Invalid ACL principal type.');
        }
        if ($principalId < 1 || !$this->principalExists($principalType, $principalId)) {
            throw new \InvalidArgumentException('ACL principal does not exist.');
        }
        if (!str_starts_with($permission, 'page.') || !$this->permissionExists($permission)) {
            throw new \InvalidArgumentException('Invalid page permission.');
        }
        if (!in_array($effect, ['allow', 'deny'], true)) {
            throw new \InvalidArgumentException('Invalid ACL effect.');
        }

        $existing = $this->app->database()->fetchOne(
            'SELECT id FROM page_acl
             WHERE page_id = :page_id
               AND principal_type = :principal_type
               AND principal_id = :principal_id
               AND permission = :permission
             LIMIT 1',
            [
                'page_id' => $pageId,
                'principal_type' => $principalType,
                'principal_id' => $principalId,
                'permission' => $permission,
            ]
        );

        if ($existing !== null) {
            $this->app->database()->execute(
                'UPDATE page_acl
                 SET effect = :effect, inherit_to_children = :inherit_to_children
                 WHERE id = :id',
                [
                    'effect' => $effect,
                    'inherit_to_children' => $inheritToChildren ? 1 : 0,
                    'id' => (int) $existing['id'],
                ]
            );
            return (int) $existing['id'];
        }

        return $this->app->database()->insert(
            'INSERT INTO page_acl
             (page_id, principal_type, principal_id, permission, effect, inherit_to_children, created_at)
             VALUES
             (:page_id, :principal_type, :principal_id, :permission, :effect, :inherit_to_children, UTC_TIMESTAMP())',
            [
                'page_id' => $pageId,
                'principal_type' => $principalType,
                'principal_id' => $principalId,
                'permission' => $permission,
                'effect' => $effect,
                'inherit_to_children' => $inheritToChildren ? 1 : 0,
            ]
        );
    }

    public function deleteRule(int $pageId, int $ruleId): bool
    {
        return $this->app->database()->execute(
            'DELETE FROM page_acl WHERE id = :id AND page_id = :page_id',
            ['id' => $ruleId, 'page_id' => $pageId]
        ) === 1;
    }

    public function pagePermissions(): array
    {
        return $this->app->database()->fetchAll(
            'SELECT id, name, description
             FROM permissions
             WHERE name LIKE "page.%"
             ORDER BY name ASC'
        );
    }

    private function decision(array $page, string $permission): ?bool
    {
        $user = $this->app->auth()->user();
        if ($user === null) {
            return null;
        }

        $direct = $this->matchingEffects(
            (int) $page['id'],
            (int) $user['id'],
            $permission,
            false
        );
        if ($direct !== []) {
            return in_array('deny', $direct, true) ? false : true;
        }

        if (!(bool) ($page['inherit_acl'] ?? true)) {
            return null;
        }

        $parentId = isset($page['parent_id']) ? (int) $page['parent_id'] : 0;
        $visited = [];

        while ($parentId > 0) {
            if (isset($visited[$parentId])) {
                throw new \RuntimeException('Page hierarchy cycle detected during ACL evaluation.');
            }
            $visited[$parentId] = true;

            $parent = $this->app->database()->fetchOne(
                'SELECT id, parent_id, inherit_acl
                 FROM pages
                 WHERE id = :id AND deleted_at IS NULL
                 LIMIT 1',
                ['id' => $parentId]
            );
            if ($parent === null) {
                break;
            }

            $effects = $this->matchingEffects(
                (int) $parent['id'],
                (int) $user['id'],
                $permission,
                true
            );
            if ($effects !== []) {
                return in_array('deny', $effects, true) ? false : true;
            }

            if (!(bool) $parent['inherit_acl']) {
                break;
            }

            $parentId = $parent['parent_id'] === null ? 0 : (int) $parent['parent_id'];
        }

        return null;
    }

    private function matchingEffects(
        int $pageId,
        int $userId,
        string $permission,
        bool $inheritedOnly
    ): array {
        $sql = 'SELECT a.effect
                FROM page_acl a
                WHERE a.page_id = ?
                  AND a.permission = ?';
        $params = [$pageId, $permission];

        if ($inheritedOnly) {
            $sql .= ' AND a.inherit_to_children = 1';
        }

        $sql .= ' AND (
                    (a.principal_type = "user" AND a.principal_id = ?)
                    OR (a.principal_type = "group" AND EXISTS (
                        SELECT 1 FROM group_users gu
                        WHERE gu.group_id = a.principal_id AND gu.user_id = ?
                    ))
                    OR (a.principal_type = "role" AND EXISTS (
                        SELECT 1 FROM user_roles ur
                        WHERE ur.role_id = a.principal_id AND ur.user_id = ?
                    ))
                  )';

        $params[] = $userId;
        $params[] = $userId;
        $params[] = $userId;

        return array_column($this->app->database()->fetchAll($sql, $params), 'effect');
    }

    private function principalExists(string $type, int $id): bool
    {
        $table = match ($type) {
            'user' => 'users',
            'group' => 'user_groups',
            'role' => 'roles',
            default => throw new \InvalidArgumentException('Invalid ACL principal type.'),
        };

        $sql = 'SELECT id FROM ' . $table . ' WHERE id = :id';
        if ($type === 'user') {
            $sql .= ' AND deleted_at IS NULL';
        }
        $sql .= ' LIMIT 1';

        return $this->app->database()->fetchOne($sql, ['id' => $id]) !== null;
    }

    private function principalName(string $type, int $id): string
    {
        return match ($type) {
            'user' => (string) (($this->app->database()->fetchOne(
                'SELECT username AS name FROM users WHERE id = :id LIMIT 1',
                ['id' => $id]
            )['name'] ?? 'Deleted user #' . $id)),
            'group' => (string) (($this->app->database()->fetchOne(
                'SELECT name FROM user_groups WHERE id = :id LIMIT 1',
                ['id' => $id]
            )['name'] ?? 'Deleted group #' . $id)),
            'role' => (string) (($this->app->database()->fetchOne(
                'SELECT name FROM roles WHERE id = :id LIMIT 1',
                ['id' => $id]
            )['name'] ?? 'Deleted role #' . $id)),
            default => 'Unknown #' . $id,
        };
    }

    private function permissionExists(string $permission): bool
    {
        return $this->app->database()->fetchOne(
            'SELECT id FROM permissions WHERE name = :name LIMIT 1',
            ['name' => $permission]
        ) !== null;
    }
}
