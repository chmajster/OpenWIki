<?php

declare(strict_types=1);

namespace OpenWiki\Permissions;

use OpenWiki\Core\Application;
use OpenWiki\Core\Database;

final class PageAclService
{
    private ?int $snapshotSpace = null;
    private ?int $snapshotUser = null;
    private array $snapshotPages = [];
    private array $snapshotRules = [];
    private array $snapshotPrincipals = [];
    private array $snapshotAccess = [];
    private array $reviewSpaces = [];
    private array $workingActors = [];

    /** One consistent read operation, or a subtree mutation holding its space lock. */
    public function snapshot(array $space, ?int $userId, bool $superAdmin): void
    {
        $db = $this->database;
        if (!$db->pdo()->inTransaction()) throw new \LogicException('An ACL snapshot requires a transaction.');
        $lock = $this->lockingReads ? ' FOR UPDATE' : '';
        $this->snapshotSpace = (int) $space['id']; $this->snapshotUser = $userId;
        $this->snapshotPages = array_column($db->fetchAll('SELECT id, parent_id, inherit_acl FROM pages WHERE space_id = ?' . $lock, [$space['id']]), null, 'id');
        $this->snapshotRules = [];
        foreach ($db->fetchAll('SELECT a.* FROM page_acl a INNER JOIN pages p ON p.id = a.page_id WHERE p.space_id = ?' . $lock, [$space['id']]) as $rule) {
            $this->snapshotRules[(int) $rule['page_id']][$rule['permission']][] = $rule;
        }
        $this->snapshotPrincipals = $userId === null ? [] : ['user:' . $userId => true];
        foreach (['role' => ['user_roles', 'role_id'], 'group' => ['group_users', 'group_id']] as $kind => [$table, $column]) {
            if ($userId !== null) foreach ($db->fetchAll('SELECT ' . $column . ' AS id FROM ' . $table . ' WHERE user_id = ?' . $lock, [$userId]) as $row) $this->snapshotPrincipals[$kind . ':' . $row['id']] = true;
        }
        $access = new SpaceAccessService($this->database, $this->lockingReads);
        $this->snapshotAccess = ['view' => $access->canViewFor($space, $userId, $superAdmin), 'edit' => $userId !== null && $access->canEditFor($space, $userId, $superAdmin)];
    }

    public function visiblePages(array $space, callable $fetchRows): array
    {
        try {
            return $this->database->transaction(function () use ($space, $fetchRows): array {
                $user = $this->app->auth()->user();
                $this->snapshot($space, $user === null ? null : (int) $user['id'], $this->app->auth()->can('*'));
                return array_values(array_filter($fetchRows(), fn (array $page): bool => $this->canView($page, $space)));
            });
        } finally {
            $this->snapshotSpace = null;
        }
    }

    private readonly Database $database;
    public function __construct(private readonly Application|Database $app, private readonly bool $lockingReads = false)
    {
        $this->database = $app instanceof Database ? $app : $app->database();
    }

    /** Evaluate a proposed tree without mutating persisted pages or issuing per-page queries. */
    public function simulate(array $space, array $pages, array $rules, ?int $user, array $principals, bool $view, bool $edit): void
    {
        $this->snapshotSpace = (int) $space['id']; $this->snapshotUser = $user;
        $this->snapshotPages = array_column($pages, null, 'id'); $this->snapshotRules = [];
        foreach ($rules as $rule) $this->snapshotRules[(int) $rule['page_id']][$rule['permission']][] = $rule;
        $this->snapshotPrincipals = $principals;
        $this->snapshotAccess = ['view' => $view, 'edit' => $edit];
    }

    public function explainFor(array $page, array $space, string $permission, ?int $user, bool $super, bool $global): array
    {
        if (!$this->usesSnapshot($space, $user)) throw new \LogicException('An explanation requires a consistent ACL snapshot.');
        $allowed = $permission === 'page.view' ? $this->canViewFor($page, $space, $user, $super, $global) : ($user !== null && $this->canFor($page, $space, $permission, $user, $super, $global));
        $reason = $allowed ? 'Domyślny dostęp strony, jej autora lub właściciela w przestrzeni.' : 'Brak wymaganego dostępu do strony lub przestrzeni.';
        $origin = 'default'; $rules = [];
        if ($super) $reason = $permission !== 'page.view' && $space['status'] !== 'active' ? 'Archiwum jest tylko do odczytu.' : 'Uprawnienia superadministratora.';
        elseif ($permission !== 'page.view' && !$global) $reason = 'Brak wymaganego uprawnienia globalnego.';
        else {
            $current = $page; $level = 0; $visited = [];
            while ($current !== null && !isset($visited[$current['id']])) {
                $visited[$current['id']] = true;
                $decision = $this->scopeDecision((int) $current['id'], $user, $permission, $level > 0, true);
                if ($decision !== null) {
                    $origin = $level === 0 ? 'direct' : 'inherited';
                    $rules = array_values(array_filter($this->snapshotRules[$current['id']][$permission] ?? [], fn (array $rule): bool => ($level === 0 || (bool) $rule['inherit_to_children']) && (isset($this->snapshotPrincipals[$rule['principal_type'] . ':' . $rule['principal_id']]) || $rule['effect'] === 'allow')));
                    $rules = array_map(static fn (array $rule): array => array_intersect_key($rule, array_flip(['principal_type', 'principal_id', 'permission', 'effect'])), $rules);
                    $reason = ($level === 0 ? 'Bezpośrednie ACL strony. ' : 'ACL dziedziczone z poziomu ' . $level . '. ') . ($decision ? 'Dopasowane allow.' : 'Dopasowane deny lub brak członkostwa na liście allow.');
                    if (!$allowed && $decision) $reason .= ' Dostęp nadal blokuje przestrzeń lub uprawnienie globalne.';
                    break;
                }
                if (!(bool) ($current['inherit_acl'] ?? true)) break;
                $current = $this->snapshotPages[(int) ($current['parent_id'] ?? 0)] ?? null; $level++;
            }
        }
        return ['allowed' => $allowed, 'effect' => $allowed ? 'allow' : 'deny', 'reason' => $reason, 'origin' => $origin, 'rules' => $rules];
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
        bool $hasPageViewPermission = true,
        ?array $ceiling = null
    ): bool {
        $spaceAccess = new SpaceAccessService($this->database, $this->lockingReads);
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

        $workflow = $space['review_workflow'] ?? ($this->reviewSpaces[(int) $space['id']] ??= $this->database->fetchOne('SELECT review_workflow FROM spaces WHERE id = ?', [$space['id']])['review_workflow'] ?? 0);
        if ((bool) $workflow) return $this->canReadWorkingFor($page, $space, $userId, $superAdmin, $ceiling);

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

    public function canReadWorkingFor(array $page, array $space, ?int $user, bool $super = false, ?array $ceiling = null): bool
    {
        if ($user === null) return false;
        $key = $user . ':' . hash('sha256', json_encode($ceiling));
        try { $actor = $this->workingActors[$key] ??= new \OpenWiki\Spaces\SpaceActor($this->database, $user, $ceiling, $this->lockingReads); }
        catch (\DomainException) { return false; }
        // Evaluate the resource ACL without recursing through draft visibility.
        $resource = array_replace($page, ['status' => 'published']);
        foreach (['page.edit', 'page.review', 'page.publish'] as $permission) {
            if ($this->canFor($resource, $space, $permission, $user, $super && $actor->can('*'), $actor->can($permission))) return true;
        }
        return false;
    }

    public function canReadWorking(array $page, array $space): bool
    {
        return $this->canReadWorkingFor($page, $space, $this->app->auth()->user()['id'] ?? null, $this->app->auth()->can('*'));
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
        if (($space['status'] ?? 'active') !== 'active' || ($space['deleted_at'] ?? null) !== null) return false;
        if (!$hasPermission) {
            return false;
        }

        if ($superAdmin) {
            return true;
        }

        if (!$this->canViewFor($page, $space, $userId)) {
            return false;
        }

        $spaceAccess = new SpaceAccessService($this->database, $this->lockingReads);
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
        $rows = $this->database->fetchAll(
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
        $updated = $this->database->execute(
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

        $existing = $this->database->fetchOne(
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
            $this->database->execute(
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

        return $this->database->insert(
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
        return $this->database->execute(
            'DELETE FROM page_acl WHERE id = :id AND page_id = :page_id',
            ['id' => $ruleId, 'page_id' => $pageId]
        ) === 1;
    }

    public function pagePermissions(): array
    {
        return $this->database->fetchAll(
            'SELECT id, name, description
             FROM permissions
             WHERE name LIKE "page.%"
             ORDER BY name ASC'
        );
    }

    private function usesSnapshot(array $space, ?int $userId): bool
    {
        return $this->database->pdo()->inTransaction() && $this->snapshotSpace === (int) $space['id'] && $this->snapshotUser === $userId;
    }

    private function lockedMutation(int $pageId, callable $operation): mixed
    {
        return $this->database->transaction(function () use ($pageId, $operation): mixed {
            $db = $this->database;
            $page = $db->fetchOne('SELECT space_id FROM pages WHERE id = ?', [$pageId]);
            if ($page === null) throw new \InvalidArgumentException('Page not found.');
            $db->fetchOne('SELECT id FROM spaces WHERE id = ? FOR UPDATE', [$page['space_id']]);
            $fresh = $db->fetchOne('SELECT id, space_id FROM pages WHERE id = ? AND deleted_at IS NULL FOR UPDATE', [$pageId]);
            if ($fresh === null) throw new \InvalidArgumentException('Page not found.');
            if ((int) $fresh['space_id'] !== (int) $page['space_id']) throw new \DomainException('Strona została przeniesiona. Odśwież jej uprawnienia.');
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

            $parent = $snapshot ? ($this->snapshotPages[$parentId] ?? null) : $this->database->fetchOne(
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

        $allow = $this->database->fetchOne(
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

        return array_column($this->database->fetchAll($sql, $params), 'effect');
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

        return $this->database->fetchOne($sql, ['id' => $id]) !== null;
    }

    private function principalName(string $type, int $id): string
    {
        return match ($type) {
            'user' => (string) (($this->database->fetchOne(
                'SELECT username AS name FROM users WHERE id = :id LIMIT 1',
                ['id' => $id]
            )['name'] ?? 'Deleted user #' . $id)),
            'group' => (string) (($this->database->fetchOne(
                'SELECT name FROM user_groups WHERE id = :id LIMIT 1',
                ['id' => $id]
            )['name'] ?? 'Deleted group #' . $id)),
            'role' => (string) (($this->database->fetchOne(
                'SELECT name FROM roles WHERE id = :id LIMIT 1',
                ['id' => $id]
            )['name'] ?? 'Deleted role #' . $id)),
            default => 'Unknown #' . $id,
        };
    }

    private function permissionExists(string $permission): bool
    {
        return $this->database->fetchOne(
            'SELECT id FROM permissions WHERE name = :name LIMIT 1',
            ['name' => $permission]
        ) !== null;
    }
}
