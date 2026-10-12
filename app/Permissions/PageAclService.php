<?php

declare(strict_types=1);

namespace OpenWiki\Permissions;

use OpenWiki\Core\Application;

final class PageAclService
{
    private ?int $snapshotSpace = null;
    private ?int $snapshotUser = null;
    private array $snapshotPages = [];
    private array $snapshotRules = [];
    private array $snapshotPrincipals = [];
    private array $snapshotAccess = [];

    /** One consistent read operation, or a subtree mutation holding its space lock. */
    public function snapshot(array $space, ?int $userId, bool $superAdmin): void
    {
        $db = $this->app->database();
        if (!$db->pdo()->inTransaction()) throw new \LogicException('An ACL snapshot requires a transaction.');
        $this->snapshotSpace = (int) $space['id']; $this->snapshotUser = $userId;
        $this->snapshotPages = array_column($db->fetchAll('SELECT id, parent_id, inherit_acl FROM pages WHERE space_id = ?', [$space['id']]), null, 'id');
        $this->snapshotRules = [];
        foreach ($db->fetchAll('SELECT a.* FROM page_acl a INNER JOIN pages p ON p.id = a.page_id WHERE p.space_id = ?', [$space['id']]) as $rule) {
            $this->snapshotRules[(int) $rule['page_id']][$rule['permission']][] = $rule;
        }
        $this->snapshotPrincipals = $userId === null ? [] : ['user:' . $userId => true];
        foreach (['role' => ['user_roles', 'role_id'], 'group' => ['group_users', 'group_id']] as $kind => [$table, $column]) {
            if ($userId !== null) foreach ($db->fetchAll('SELECT ' . $column . ' AS id FROM ' . $table . ' WHERE user_id = ?', [$userId]) as $row) $this->snapshotPrincipals[$kind . ':' . $row['id']] = true;
        }
        $access = new SpaceAccessService($this->app);
        $this->snapshotAccess = ['view' => $access->canViewFor($space, $userId, $superAdmin), 'edit' => $userId !== null && $access->canEditFor($space, $userId, $superAdmin)];
    }

    public function visiblePages(array $space, callable $fetchRows): array
    {
        try {
            return $this->app->database()->transaction(function () use ($space, $fetchRows): array {
                $user = $this->app->auth()->user();
                $this->snapshot($space, $user === null ? null : (int) $user['id'], $this->app->auth()->can('*'));
                return array_values(array_filter($fetchRows(), fn (array $page): bool => $this->canView($page, $space)));
            });
        } finally {
            $this->snapshotSpace = null;
        }
    }

    public function __construct(private readonly Application $app)
    {
    }

    public function canView(array $page, array $space): bool
    {
        $user = $this->app->auth()->user();

        return $this->canViewFor(
            $page,
            $space,
            $user === null ? null : (int) $user['id'],
            $this->app->auth()->can('*'),
            $this->app->auth()->can('page.view')
        );
    }

    public function canViewFor(
        array $page,
        array $space,
        ?int $userId,
        bool $superAdmin = false,
        bool $hasPageViewPermission = true
    ): bool {
        $spaceAccess = new SpaceAccessService($this->app);
        if (!($this->usesSnapshot($space, $userId) ? $this->snapshotAccess['view'] : $spaceAccess->canViewFor($space, $userId, $superAdmin))) {
            return false;
        }

        if ($superAdmin) {
            return true;
        }

        $decision = $this->decision($page, 'page.view', $userId);
        if ($decision === false) {
            return false;
        }

        if (($page['status'] ?? null) === 'published') {
            if ($decision === true) {
                return $userId !== null && $hasPageViewPermission;
            }
            return true;
        }

        if ($userId === null) {
            return false;
        }

        if ($decision === true && $hasPageViewPermission) {
            return true;
        }

        return (int) ($page['owner_id'] ?? 0) === $userId
            || (int) ($page['author_id'] ?? 0) === $userId
            || ($this->usesSnapshot($space, $userId) ? $this->snapshotAccess['edit'] : $spaceAccess->canEditFor($space, $userId, $superAdmin));
    }

    public function canEdit(array $page, array $space): bool
    {
        return $this->can($page, $space, 'page.edit');
    }

    public function can(array $page, array $space, string $permission): bool
    {
        $user = $this->app->auth()->user();
        if ($user === null) {
            return false;
        }

        return $this->canFor(
            $page,
            $space,
            $permission,
            (int) $user['id'],
            $this->app->auth()->can('*'),
            $this->app->auth()->can($permission)
        );
    }

    public function canFor(
        array $page,
        array $space,
        string $permission,
        int $userId,
        bool $superAdmin = false,
        bool $hasPermission = true
    ): bool {
        if (!$hasPermission) {
            return false;
        }

        if ($superAdmin) {
            return true;
        }

        if (!$this->canViewFor($page, $space, $userId)) {
            return false;
        }

        $spaceAccess = new SpaceAccessService($this->app);
        $baseAllowed = $this->usesSnapshot($space, $userId) ? $this->snapshotAccess[$permission === 'page.view' ? 'view' : 'edit'] : ($permission === 'page.view'
            ? $spaceAccess->canViewFor($space, $userId, $superAdmin)
            : $spaceAccess->canEditFor($space, $userId, $superAdmin));

        if (!$baseAllowed) {
            return false;
        }

        return $this->decision($page, $permission, $userId) !== false;
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
        $this->lockedMutation($pageId, fn () => $this->setInheritanceUnchecked($pageId, $inherit));
    }

    private function setInheritanceUnchecked(int $pageId, bool $inherit): void
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
        int $pageId, string $principalType, int $principalId, string $permission, string $effect, bool $inheritToChildren
    ): int {
        return $this->lockedMutation($pageId, fn () => $this->addRuleUnchecked($pageId, $principalType, $principalId, $permission, $effect, $inheritToChildren));
    }

    private function addRuleUnchecked(
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
        return $this->lockedMutation($pageId, fn () => $this->deleteRuleUnchecked($pageId, $ruleId));
    }

    private function deleteRuleUnchecked(int $pageId, int $ruleId): bool
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

    private function usesSnapshot(array $space, ?int $userId): bool
    {
        return $this->app->database()->pdo()->inTransaction() && $this->snapshotSpace === (int) $space['id'] && $this->snapshotUser === $userId;
    }

    private function lockedMutation(int $pageId, callable $operation): mixed
    {
        return $this->app->database()->transaction(function () use ($pageId, $operation): mixed {
            $db = $this->app->database();
            $page = $db->fetchOne('SELECT space_id FROM pages WHERE id = ?', [$pageId]);
            if ($page === null) throw new \InvalidArgumentException('Page not found.');
            $db->fetchOne('SELECT id FROM spaces WHERE id = ? FOR UPDATE', [$page['space_id']]);
            $fresh = $db->fetchOne('SELECT id FROM pages WHERE id = ? AND deleted_at IS NULL FOR UPDATE', [$pageId]);
            if ($fresh === null) throw new \InvalidArgumentException('Page not found.');
            return $operation();
        });
    }

    private function decision(array $page, string $permission, ?int $userId): ?bool
    {
        $snapshot = $this->usesSnapshot(['id' => $page['space_id'] ?? -1], $userId);
        $direct = $this->scopeDecision(
            (int) $page['id'],
            $userId,
            $permission,
            false,
            $snapshot
        );
        if ($direct !== null) {
            return $direct;
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

            $parent = $snapshot ? ($this->snapshotPages[$parentId] ?? null) : $this->app->database()->fetchOne(
                'SELECT id, parent_id, inherit_acl
                 FROM pages
                 WHERE id = :id
                 LIMIT 1',
                ['id' => $parentId]
            );
            if ($parent === null) {
                break;
            }

            $decision = $this->scopeDecision(
                (int) $parent['id'],
                $userId,
                $permission,
                true,
                $snapshot
            );
            if ($decision !== null) {
                return $decision;
            }

            if (!(bool) $parent['inherit_acl']) {
                break;
            }

            $parentId = $parent['parent_id'] === null ? 0 : (int) $parent['parent_id'];
        }

        return null;
    }

    private function scopeDecision(
        int $pageId,
        ?int $userId,
        string $permission,
        bool $inheritedOnly,
        bool $snapshot = false
    ): ?bool {
        if ($snapshot) {
            $allowList = false; $allowed = false;
            foreach ($this->snapshotRules[$pageId][$permission] ?? [] as $rule) {
                if ($inheritedOnly && !(bool) $rule['inherit_to_children']) continue;
                if ($rule['effect'] === 'allow') $allowList = true;
                if (!isset($this->snapshotPrincipals[$rule['principal_type'] . ':' . $rule['principal_id']])) continue;
                if ($rule['effect'] === 'deny') return false;
                $allowed = true;
            }
            return $allowed ? true : ($allowList ? false : null);
        }
        $where = 'page_id = :page_id AND permission = :permission';
        $params = ['page_id' => $pageId, 'permission' => $permission];

        if ($inheritedOnly) {
            $where .= ' AND inherit_to_children = 1';
        }

        $allow = $this->app->database()->fetchOne(
            'SELECT id FROM page_acl
             WHERE ' . $where . ' AND effect = "allow"
             LIMIT 1',
            $params
        );
        $hasAllowList = $allow !== null;

        if ($userId === null) {
            return $hasAllowList ? false : null;
        }

        $effects = $this->matchingEffects($pageId, $userId, $permission, $inheritedOnly);
        if (in_array('deny', $effects, true)) {
            return false;
        }
        if (in_array('allow', $effects, true)) {
            return true;
        }

        return $hasAllowList ? false : null;
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
