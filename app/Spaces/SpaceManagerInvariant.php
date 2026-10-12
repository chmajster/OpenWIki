<?php
declare(strict_types=1);
namespace OpenWiki\Spaces;

use OpenWiki\Core\Database;

/** Called under the shared administration lock, also for account/group/role changes. */
final class SpaceManagerInvariant
{
    public static function managedIds(Database $db): array
    {
        if ($db->fetchOne('SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = "spaces" AND COLUMN_NAME = "management_version"') === null) return [];
        $users = array_column($db->fetchAll('SELECT id FROM users WHERE status = "active" AND deleted_at IS NULL FOR UPDATE'), null, 'id');
        $principals = $permissions = [];
        foreach ($users as $id => $_) $principals[$id]['user:' . $id] = true;
        foreach ($db->fetchAll('SELECT ur.user_id, ur.role_id, p.name FROM user_roles ur LEFT JOIN role_permissions rp ON rp.role_id = ur.role_id LEFT JOIN permissions p ON p.id = rp.permission_id FOR UPDATE') as $row) {
            $principals[$row['user_id']]['role:' . $row['role_id']] = true;
            if ($row['name'] !== null) $permissions[$row['user_id']][$row['name']] = true;
        }
        foreach ($db->fetchAll('SELECT user_id, group_id FROM group_users FOR UPDATE') as $row) $principals[$row['user_id']]['group:' . $row['group_id']] = true;
        $rules = [];
        foreach ($db->fetchAll('SELECT space_id, permission, principal_type, principal_id, effect FROM space_acl FOR UPDATE') as $row) $rules[$row['space_id']][$row['permission']][] = $row;
        $managed = [];
        $candidates = array_filter($users, static fn (array $row): bool => isset($permissions[$row['id']]['*']) || isset($permissions[$row['id']]['space.manage_access']));
        foreach ($db->fetchAll('SELECT id, owner_id, visibility FROM spaces WHERE deleted_at IS NULL ORDER BY id FOR UPDATE') as $space) {
            if (isset($candidates[(int) $space['owner_id']])) { $managed[] = (int) $space['id']; continue; }
            foreach ($candidates as $id => $_) {
                if ($space['visibility'] === 'private') continue;
                $decision = static function (string $permission) use ($rules, $space, $principals, $id): ?bool {
                    $allow = false;
                    foreach ($rules[$space['id']][$permission] ?? [] as $rule) {
                        if (!isset($principals[$id][$rule['principal_type'] . ':' . $rule['principal_id']])) continue;
                        if ($rule['effect'] === 'deny') return false;
                        $allow = true;
                    }
                    return $allow ? true : null;
                };
                if ($decision('space.manage_access') === true && ($decision('space.view') === true || ($space['visibility'] === 'public' && $decision('space.view') !== false))) {
                    $managed[] = (int) $space['id']; break;
                }
            }
        }
        return $managed;
    }
}
