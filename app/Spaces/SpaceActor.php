<?php
declare(strict_types=1);
namespace OpenWiki\Spaces;

use OpenWiki\Core\Database;

final class SpaceActor
{
    private array $permissions;

    public function __construct(Database $db, public readonly int $id, ?array $ceiling = null, bool $locking = true)
    {
        $lock = $locking && $db->pdo()->inTransaction() ? ' FOR UPDATE' : '';
        if ($db->fetchOne('SELECT id FROM users WHERE id = ? AND status = "active" AND deleted_at IS NULL' . $lock, [$id]) === null) {
            throw new \DomainException('Wymagane jest aktywne konto.');
        }
        $permissions = array_values(array_unique(array_column($db->fetchAll('SELECT p.name FROM permissions p INNER JOIN role_permissions rp ON rp.permission_id = p.id INNER JOIN user_roles ur ON ur.role_id = rp.role_id WHERE ur.user_id = ?' . $lock, [$id]), 'name')));
        $this->permissions = $ceiling === null || in_array('*', $ceiling, true) ? $permissions
            : (in_array('*', $permissions, true) ? $ceiling : array_values(array_intersect($permissions, $ceiling)));
    }

    public function can(string $permission): bool { return in_array('*', $this->permissions, true) || in_array($permission, $this->permissions, true); }
    public function permissions(): array { return $this->permissions; }
}
