<?php

declare(strict_types=1);

namespace OpenWiki\Admin;

use OpenWiki\Core\Database;

final class AdministrationPolicy
{
    private array $permissions = [];

    public function __construct(private readonly Database $database, private readonly ?AdminActor $actor) {}

    public function run(string $permission, callable $operation): mixed
    {
        if ($this->actor === null) throw new \DomainException('An authenticated administrator is required.');
        return self::guard($this->database, function (Database $db) use ($permission, $operation): mixed {
            if ($this->actor->userId === null) {
                $this->permissions = ['*'];
            } else {
                $user = $db->fetchOne('SELECT id FROM users WHERE id = ? AND status = "active" AND deleted_at IS NULL FOR UPDATE', [$this->actor->userId]);
                if ($user === null) throw new \DomainException('An active administrator is required.');
                $this->permissions = $this->userPermissions($this->actor->userId);
            }
            if (!$this->isSuper() && !in_array($permission, $this->permissions, true)) {
                throw new \DomainException('You do not have permission to administer this resource.');
            }
            return $operation($this);
        });
    }

    /** Every path changing accounts or roles, including LDAP, serializes this invariant. */
    public static function guard(Database $database, callable $operation): mixed
    {
        return $database->transaction(function (Database $db) use ($operation): mixed {
            $lock = $db->fetchOne('SELECT name FROM security_locks WHERE name = "administration" FOR UPDATE');
            if ($lock === null) throw new \RuntimeException('The administration lock migration is required.');
            $before = self::activeSuperIds($db);
            $result = $operation($db);
            if ($before !== [] && self::activeSuperIds($db) === []) {
                throw new \DomainException('The last active Super Admin account must remain active and retain full permissions.');
            }
            return $result;
        });
    }

    public function targetUser(int $id): void
    {
        if ($this->isSuper()) return;
        $this->grant($this->userPermissions($id));
        $roles = $this->database->fetchAll('SELECT r.id FROM roles r INNER JOIN user_roles ur ON ur.role_id = r.id WHERE ur.user_id = ? AND r.slug = "super-admin" FOR UPDATE', [$id]);
        if ($roles !== []) throw new \DomainException('Only a Super Admin may manage protected accounts.');
    }

    public function roles(array $ids): void
    {
        foreach ($ids as $id) $this->role((int) $id);
    }

    public function role(int $id): void
    {
        $row = $this->database->fetchOne('SELECT slug FROM roles WHERE id = ? FOR UPDATE', [$id]);
        if ($row === null) throw new \InvalidArgumentException('Role not found.');
        if (!$this->isSuper() && $row['slug'] === 'super-admin') throw new \DomainException('Only a Super Admin may grant or change the Super Admin role.');
        $this->grant(array_column($this->database->fetchAll('SELECT p.name FROM permissions p INNER JOIN role_permissions rp ON rp.permission_id = p.id WHERE rp.role_id = ? FOR UPDATE', [$id]), 'name'));
    }

    public function permissionIds(array $ids): void
    {
        if ($ids === []) return;
        $rows = $this->database->fetchAll('SELECT name FROM permissions WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ') FOR UPDATE', array_values($ids));
        if (count($rows) !== count(array_unique($ids))) throw new \InvalidArgumentException('Permission not found.');
        $this->grant(array_column($rows, 'name'));
    }

    private function grant(array $permissions): void
    {
        if (!$this->isSuper() && array_diff($permissions, $this->permissions) !== []) {
            throw new \DomainException('You may only delegate permissions you possess. Full administration requires a Super Admin.');
        }
    }

    private function isSuper(): bool { return in_array('*', $this->permissions, true); }

    private function userPermissions(int $id): array
    {
        return array_values(array_unique(array_column($this->database->fetchAll(
            'SELECT p.name FROM permissions p INNER JOIN role_permissions rp ON rp.permission_id = p.id INNER JOIN user_roles ur ON ur.role_id = rp.role_id WHERE ur.user_id = ? FOR UPDATE', [$id]), 'name')));
    }

    private static function activeSuperIds(Database $db): array
    {
        // Locking reads see the latest committed state even inside an older outer transaction.
        return array_values(array_unique(array_column($db->fetchAll(
            'SELECT u.id FROM users u INNER JOIN user_roles ur ON ur.user_id = u.id INNER JOIN role_permissions rp ON rp.role_id = ur.role_id INNER JOIN permissions p ON p.id = rp.permission_id WHERE u.status = "active" AND u.deleted_at IS NULL AND p.name = "*" FOR UPDATE'), 'id')));
    }
}
