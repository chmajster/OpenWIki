<?php
declare(strict_types=1);
namespace OpenWiki\Permissions;

use OpenWiki\Core\Application;
use OpenWiki\Core\Database;

final class SpaceAccessService
{
    private readonly Database $database;
    public function __construct(private readonly Application|Database $app, private readonly bool $lockingReads = false) {
        $this->database = $app instanceof Database ? $app : $app->database();
    }

    public function canView(array $space): bool
    {
        return $this->canViewFor($space, $this->app->auth()->user()['id'] ?? null, $this->app->auth()->can('*'));
    }

    public function canEdit(array $space): bool
    {
        $id = $this->app->auth()->user()['id'] ?? null;
        return $id !== null && $this->canEditFor($space, (int) $id, $this->app->auth()->can('*'));
    }

    public function canViewFor(array $space, ?int $userId, bool $superAdmin = false): bool
    {
        return $this->explainFor($space, $userId, 'space.view', $superAdmin)['allowed'];
    }

    public function canEditFor(array $space, int $userId, bool $superAdmin = false): bool
    {
        return ($space['status'] ?? 'active') === 'active' && $this->explainFor($space, $userId, 'space.edit', $superAdmin)['allowed'];
    }

    public function canManageFor(array $space, int $id, string $permission, bool $super = false, bool $global = true): bool
    {
        return $this->explainFor($space, $id, $permission, $super, $global)['allowed'];
    }

    public function explainFor(array $space, ?int $id, string $permission = 'space.view', bool $super = false, bool $global = true): array
    {
        if ($this->lockingReads && $this->database->pdo()->inTransaction()) {
            $db = $this->database; $principals = $id === null ? [] : ['user:' . $id => true];
            if ($id !== null) foreach (['role' => ['user_roles', 'role_id'], 'group' => ['group_users', 'group_id']] as $kind => [$table, $column]) foreach ($db->fetchAll('SELECT ' . $column . ' AS id FROM ' . $table . ' WHERE user_id = ? FOR UPDATE', [$id]) as $row) $principals[$kind . ':' . $row['id']] = true;
            $rules = array_values(array_filter($db->fetchAll('SELECT id, principal_type, principal_id, permission, effect FROM space_acl WHERE space_id = ? AND permission = ? ORDER BY id FOR UPDATE', [$space['id'], $permission]), static fn (array $rule): bool => isset($principals[$rule['principal_type'] . ':' . $rule['principal_id']])));
            return self::evaluate($space, $id, $permission, $super, $global, $rules);
        }
        $rules = $id === null ? [] : $this->database->fetchAll(
            'SELECT a.id, a.principal_type, a.principal_id, a.permission, a.effect FROM space_acl a WHERE a.space_id = ? AND a.permission = ? AND (
                (a.principal_type = "user" AND a.principal_id = ?) OR
                (a.principal_type = "group" AND EXISTS (SELECT 1 FROM group_users gu WHERE gu.group_id = a.principal_id AND gu.user_id = ?)) OR
                (a.principal_type = "role" AND EXISTS (SELECT 1 FROM user_roles ur WHERE ur.role_id = a.principal_id AND ur.user_id = ?))) ORDER BY a.id',
            [$space['id'], $permission, $id, $id, $id]);
        return self::evaluate($space, $id, $permission, $super, $global, $rules);
    }

    public static function evaluate(array $space, ?int $id, string $permission, bool $super, bool $global, array $rules): array
    {
        $result = static fn (bool $allow, string $reason, array $rules = []): array => ['allowed' => $allow, 'effect' => $allow ? 'allow' : 'deny', 'reason' => $reason, 'rules' => $rules];
        if (($space['deleted_at'] ?? null) !== null) return $result(false, 'Przestrzeń została usunięta.');
        if ($permission !== 'space.view' && (!$global || $id === null)) return $result(false, 'Brak wymaganego uprawnienia globalnego.');
        if ($super) return $result(true, 'Uprawnienia superadministratora.');
        if ($id !== null && (int) $space['owner_id'] === $id) return $result(true, 'Właściciel przestrzeni.');
        if ($space['visibility'] === 'private') return $result(false, 'Przestrzeń prywatna: dostęp właściciela i superadministratora.');
        if (in_array('deny', array_column($rules, 'effect'), true)) return $result(false, 'Dopasowane deny ma pierwszeństwo przed allow.', $rules);
        if ($rules !== []) return $result(true, 'Dopasowane allow użytkownika, grupy lub roli.', $rules);
        if ($permission === 'space.view' && $space['visibility'] === 'public') return $result(true, 'Publiczny odczyt; ACL strony może go ograniczyć.');
        return $result(false, 'Brak pasującego zezwolenia ACL.');
    }
}
