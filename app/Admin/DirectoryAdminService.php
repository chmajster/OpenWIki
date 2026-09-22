<?php

declare(strict_types=1);

namespace OpenWiki\Admin;

use OpenWiki\Auth\AuthService;
use OpenWiki\Core\Database;
use OpenWiki\Wiki\Slugger;

final class DirectoryAdminService
{
    public function __construct(private readonly Database $database)
    {
    }

    public function users(string $query = ''): array
    {
        $query = trim($query);
        $where = 'u.deleted_at IS NULL';
        $params = [];

        if ($query !== '') {
            $where .= ' AND (u.username LIKE :username OR u.email LIKE :email OR u.first_name LIKE :first_name OR u.last_name LIKE :last_name)';
            $like = '%' . $query . '%';
            $params = [
                'username' => $like,
                'email' => $like,
                'first_name' => $like,
                'last_name' => $like,
            ];
        }

        return $this->database->fetchAll(
            'SELECT u.id, u.username, u.email, u.first_name, u.last_name, u.status, u.auth_source,
                    u.force_password_change, u.last_login_at, u.created_at, u.updated_at,
                    GROUP_CONCAT(DISTINCT r.name ORDER BY r.name SEPARATOR ", ") AS role_names,
                    GROUP_CONCAT(DISTINCT g.name ORDER BY g.name SEPARATOR ", ") AS group_names
             FROM users u
             LEFT JOIN user_roles ur ON ur.user_id = u.id
             LEFT JOIN roles r ON r.id = ur.role_id
             LEFT JOIN group_users gu ON gu.user_id = u.id
             LEFT JOIN user_groups g ON g.id = gu.group_id
             WHERE ' . $where . '
             GROUP BY u.id
             ORDER BY u.username ASC',
            $params
        );
    }

    public function user(int $id): ?array
    {
        $user = $this->database->fetchOne(
            'SELECT id, username, email, first_name, last_name, status, auth_source,
                    force_password_change, last_login_at, created_at, updated_at
             FROM users
             WHERE id = :id AND deleted_at IS NULL
             LIMIT 1',
            ['id' => $id]
        );
        if ($user === null) {
            return null;
        }

        $user['role_ids'] = array_map(
            'intval',
            array_column(
                $this->database->fetchAll(
                    'SELECT role_id FROM user_roles WHERE user_id = :user_id',
                    ['user_id' => $id]
                ),
                'role_id'
            )
        );
        $user['group_ids'] = array_map(
            'intval',
            array_column(
                $this->database->fetchAll(
                    'SELECT group_id FROM group_users WHERE user_id = :user_id',
                    ['user_id' => $id]
                ),
                'group_id'
            )
        );

        return $user;
    }

    public function createUser(array $input): int
    {
        $data = $this->validatedUser($input, null);
        $password = (string) ($input['password'] ?? '');
        if (strlen($password) < 12) {
            throw new \InvalidArgumentException('Password must contain at least 12 characters.');
        }

        $hash = password_hash($password, AuthService::passwordAlgorithm());
        if (!is_string($hash)) {
            throw new \RuntimeException('Unable to hash password.');
        }

        return $this->database->transaction(function (Database $db) use ($data, $hash): int {
            $id = $db->insert(
                'INSERT INTO users
                 (username, email, password_hash, first_name, last_name, status, auth_source,
                  force_password_change, created_at, updated_at)
                 VALUES
                 (:username, :email, :password_hash, :first_name, :last_name, :status, "local",
                  :force_password_change, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
                [
                    'username' => $data['username'],
                    'email' => $data['email'],
                    'password_hash' => $hash,
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'],
                    'status' => $data['status'],
                    'force_password_change' => $data['force_password_change'],
                ]
            );

            $this->syncMemberships($id, $data['role_ids'], $data['group_ids']);
            return $id;
        });
    }

    public function updateUser(int $id, array $input): void
    {
        if ($this->user($id) === null) {
            throw new \InvalidArgumentException('User not found.');
        }

        $data = $this->validatedUser($input, $id);

        $this->database->transaction(function (Database $db) use ($id, $data): void {
            $db->execute(
                'UPDATE users
                 SET username = :username, email = :email, first_name = :first_name, last_name = :last_name,
                     status = :status, force_password_change = :force_password_change, updated_at = UTC_TIMESTAMP()
                 WHERE id = :id AND deleted_at IS NULL',
                [
                    'id' => $id,
                    'username' => $data['username'],
                    'email' => $data['email'],
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'],
                    'status' => $data['status'],
                    'force_password_change' => $data['force_password_change'],
                ]
            );

            $this->syncMemberships($id, $data['role_ids'], $data['group_ids']);
        });
    }

    public function softDeleteUser(int $id): bool
    {
        return $this->database->transaction(function (Database $db) use ($id): bool {
            $db->execute('DELETE FROM user_sessions WHERE user_id = :user_id', ['user_id' => $id]);

            return $db->execute(
                'UPDATE users
                 SET status = "disabled", deleted_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
                 WHERE id = :id AND deleted_at IS NULL',
                ['id' => $id]
            ) === 1;
        });
    }

    public function resetPassword(int $id): string
    {
        if ($this->user($id) === null) {
            throw new \InvalidArgumentException('User not found.');
        }

        $password = 'Ow!' . bin2hex(random_bytes(10)) . '9a';
        $hash = password_hash($password, AuthService::passwordAlgorithm());
        if (!is_string($hash)) {
            throw new \RuntimeException('Unable to hash password.');
        }

        $this->database->transaction(function (Database $db) use ($id, $hash): void {
            $db->execute(
                'UPDATE users
                 SET password_hash = :password_hash, force_password_change = 1, updated_at = UTC_TIMESTAMP()
                 WHERE id = :id AND deleted_at IS NULL',
                ['password_hash' => $hash, 'id' => $id]
            );
            $db->execute('DELETE FROM user_sessions WHERE user_id = :user_id', ['user_id' => $id]);
        });

        return $password;
    }

    public function resetMfa(int $id): void
    {
        if ($this->user($id) === null) {
            throw new \InvalidArgumentException('User not found.');
        }

        $this->database->transaction(function (Database $db) use ($id): void {
            $db->execute('DELETE FROM mfa_recovery_codes WHERE user_id = :user_id', ['user_id' => $id]);
            $db->execute('DELETE FROM mfa_factors WHERE user_id = :user_id', ['user_id' => $id]);
        });
    }

    public function roles(): array
    {
        return $this->database->fetchAll(
            'SELECT r.id, r.name, r.slug, r.description, r.is_system, r.created_at, r.updated_at,
                    COUNT(DISTINCT ur.user_id) AS user_count,
                    COUNT(DISTINCT rp.permission_id) AS permission_count
             FROM roles r
             LEFT JOIN user_roles ur ON ur.role_id = r.id
             LEFT JOIN role_permissions rp ON rp.role_id = r.id
             GROUP BY r.id
             ORDER BY r.is_system DESC, r.name ASC'
        );
    }

    public function role(int $id): ?array
    {
        $role = $this->database->fetchOne('SELECT * FROM roles WHERE id = :id LIMIT 1', ['id' => $id]);
        if ($role === null) {
            return null;
        }

        $role['permission_ids'] = array_map(
            'intval',
            array_column(
                $this->database->fetchAll(
                    'SELECT permission_id FROM role_permissions WHERE role_id = :role_id',
                    ['role_id' => $id]
                ),
                'permission_id'
            )
        );

        return $role;
    }

    public function permissions(): array
    {
        return $this->database->fetchAll('SELECT id, name, description FROM permissions ORDER BY name ASC');
    }

    public function saveRole(?int $id, array $input): int
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 100) {
            throw new \InvalidArgumentException('Role name is required and may contain at most 100 characters.');
        }

        $slugInput = trim((string) ($input['slug'] ?? ''));
        $slug = (new Slugger())->slug($slugInput === '' ? $name : $slugInput);
        if (mb_strlen($slug) > 100) {
            throw new \InvalidArgumentException('Role slug may contain at most 100 characters.');
        }

        $description = trim((string) ($input['description'] ?? ''));
        if (mb_strlen($description) > 500) {
            throw new \InvalidArgumentException('Role description may contain at most 500 characters.');
        }

        $permissionIds = $this->validatedIds($input['permission_ids'] ?? []);
        $this->assertExistingIds('permissions', $permissionIds);

        $existingRole = $id === null ? null : $this->role($id);
        if ($id !== null && $existingRole === null) {
            throw new \InvalidArgumentException('Role not found.');
        }
        if ($existingRole !== null && (bool) $existingRole['is_system']) {
            $name = (string) $existingRole['name'];
            $slug = (string) $existingRole['slug'];
        }
        if ($existingRole !== null && $existingRole['slug'] === 'super-admin') {
            $wildcard = $this->database->fetchOne(
                'SELECT id FROM permissions WHERE name = "*" LIMIT 1'
            );
            if ($wildcard === null) {
                throw new \RuntimeException('Wildcard permission is missing.');
            }
            $permissionIds[(int) $wildcard['id']] = (int) $wildcard['id'];
            $permissionIds = array_values($permissionIds);
        }

        return $this->database->transaction(function (Database $db) use ($id, $name, $slug, $description, $permissionIds): int {
            if ($id === null) {
                $id = $db->insert(
                    'INSERT INTO roles (name, slug, description, is_system, created_at, updated_at)
                     VALUES (:name, :slug, :description, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
                    ['name' => $name, 'slug' => $slug, 'description' => $description ?: null]
                );
            } else {
                $db->execute(
                    'UPDATE roles
                     SET name = :name, slug = :slug, description = :description, updated_at = UTC_TIMESTAMP()
                     WHERE id = :id',
                    ['id' => $id, 'name' => $name, 'slug' => $slug, 'description' => $description ?: null]
                );
            }

            $db->execute('DELETE FROM role_permissions WHERE role_id = :role_id', ['role_id' => $id]);
            foreach ($permissionIds as $permissionId) {
                $db->execute(
                    'INSERT INTO role_permissions (role_id, permission_id) VALUES (:role_id, :permission_id)',
                    ['role_id' => $id, 'permission_id' => $permissionId]
                );
            }

            return $id;
        });
    }

    public function deleteRole(int $id): bool
    {
        $role = $this->role($id);
        if ($role === null) {
            return false;
        }
        if ((bool) $role['is_system']) {
            throw new \InvalidArgumentException('System roles cannot be deleted.');
        }

        return $this->database->execute('DELETE FROM roles WHERE id = :id', ['id' => $id]) === 1;
    }

    public function groups(): array
    {
        return $this->database->fetchAll(
            'SELECT g.id, g.name, g.slug, g.description, g.source, g.external_id, g.created_at, g.updated_at,
                    COUNT(gu.user_id) AS member_count
             FROM user_groups g
             LEFT JOIN group_users gu ON gu.group_id = g.id
             GROUP BY g.id
             ORDER BY g.name ASC'
        );
    }

    public function group(int $id): ?array
    {
        $group = $this->database->fetchOne('SELECT * FROM user_groups WHERE id = :id LIMIT 1', ['id' => $id]);
        if ($group === null) {
            return null;
        }

        $group['user_ids'] = array_map(
            'intval',
            array_column(
                $this->database->fetchAll(
                    'SELECT user_id FROM group_users WHERE group_id = :group_id',
                    ['group_id' => $id]
                ),
                'user_id'
            )
        );

        return $group;
    }

    public function saveGroup(?int $id, array $input): int
    {
        $name = trim((string) ($input['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 150) {
            throw new \InvalidArgumentException('Group name is required and may contain at most 150 characters.');
        }

        $slugInput = trim((string) ($input['slug'] ?? ''));
        $slug = (new Slugger())->slug($slugInput === '' ? $name : $slugInput);
        if (mb_strlen($slug) > 150) {
            throw new \InvalidArgumentException('Group slug may contain at most 150 characters.');
        }

        $description = trim((string) ($input['description'] ?? ''));
        if (mb_strlen($description) > 500) {
            throw new \InvalidArgumentException('Group description may contain at most 500 characters.');
        }

        $userIds = $this->validatedIds($input['user_ids'] ?? []);
        $this->assertExistingIds('users', $userIds, 'deleted_at IS NULL');

        return $this->database->transaction(function (Database $db) use ($id, $name, $slug, $description, $userIds): int {
            if ($id === null) {
                $id = $db->insert(
                    'INSERT INTO user_groups (name, slug, description, source, external_id, created_at, updated_at)
                     VALUES (:name, :slug, :description, "local", NULL, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
                    ['name' => $name, 'slug' => $slug, 'description' => $description ?: null]
                );
            } else {
                $existing = $this->group($id);
                if ($existing === null) {
                    throw new \InvalidArgumentException('Group not found.');
                }
                if ($existing['source'] !== 'local') {
                    throw new \InvalidArgumentException('LDAP groups cannot be edited locally.');
                }

                $db->execute(
                    'UPDATE user_groups
                     SET name = :name, slug = :slug, description = :description, updated_at = UTC_TIMESTAMP()
                     WHERE id = :id',
                    ['id' => $id, 'name' => $name, 'slug' => $slug, 'description' => $description ?: null]
                );
            }

            $db->execute('DELETE FROM group_users WHERE group_id = :group_id', ['group_id' => $id]);
            foreach ($userIds as $userId) {
                $db->execute(
                    'INSERT INTO group_users (group_id, user_id) VALUES (:group_id, :user_id)',
                    ['group_id' => $id, 'user_id' => $userId]
                );
            }

            return $id;
        });
    }

    public function deleteGroup(int $id): bool
    {
        $group = $this->group($id);
        if ($group === null) {
            return false;
        }
        if ($group['source'] !== 'local') {
            throw new \InvalidArgumentException('LDAP groups cannot be deleted locally.');
        }

        return $this->database->execute('DELETE FROM user_groups WHERE id = :id', ['id' => $id]) === 1;
    }

    private function validatedUser(array $input, ?int $currentId): array
    {
        $username = trim((string) ($input['username'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9._-]{3,100}$/', $username)) {
            throw new \InvalidArgumentException('Username must contain 3-100 letters, numbers, dots, underscores or hyphens.');
        }

        $email = mb_strtolower(trim((string) ($input['email'] ?? '')));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 191) {
            throw new \InvalidArgumentException('A valid email address is required.');
        }

        $duplicate = $this->database->fetchOne(
            'SELECT id
             FROM users
             WHERE (LOWER(username) = LOWER(:username) OR LOWER(email) = LOWER(:email))
               AND deleted_at IS NULL
               AND (:current_id IS NULL OR id <> :exclude_id)
             LIMIT 1',
            [
                'username' => $username,
                'email' => $email,
                'current_id' => $currentId,
                'exclude_id' => $currentId ?? 0,
            ]
        );
        if ($duplicate !== null) {
            throw new \InvalidArgumentException('Username or email address is already in use.');
        }

        $status = (string) ($input['status'] ?? 'active');
        if (!in_array($status, ['active', 'disabled', 'locked'], true)) {
            throw new \InvalidArgumentException('Invalid user status.');
        }

        $firstName = trim((string) ($input['first_name'] ?? ''));
        $lastName = trim((string) ($input['last_name'] ?? ''));
        if (mb_strlen($firstName) > 100 || mb_strlen($lastName) > 100) {
            throw new \InvalidArgumentException('First and last names may contain at most 100 characters.');
        }

        $roleIds = $this->validatedIds($input['role_ids'] ?? []);
        $groupIds = $this->validatedIds($input['group_ids'] ?? []);
        $this->assertExistingIds('roles', $roleIds);
        $this->assertExistingIds('user_groups', $groupIds);

        return [
            'username' => $username,
            'email' => $email,
            'first_name' => $firstName ?: null,
            'last_name' => $lastName ?: null,
            'status' => $status,
            'force_password_change' => !empty($input['force_password_change']) ? 1 : 0,
            'role_ids' => $roleIds,
            'group_ids' => $groupIds,
        ];
    }

    private function syncMemberships(int $userId, array $roleIds, array $groupIds): void
    {
        $this->database->execute('DELETE FROM user_roles WHERE user_id = :user_id', ['user_id' => $userId]);
        foreach ($roleIds as $roleId) {
            $this->database->execute(
                'INSERT INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id)',
                ['user_id' => $userId, 'role_id' => $roleId]
            );
        }

        $this->database->execute('DELETE FROM group_users WHERE user_id = :user_id', ['user_id' => $userId]);
        foreach ($groupIds as $groupId) {
            $this->database->execute(
                'INSERT INTO group_users (group_id, user_id) VALUES (:group_id, :user_id)',
                ['group_id' => $groupId, 'user_id' => $userId]
            );
        }
    }

    private function validatedIds(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (!is_array($value)) {
            $value = [$value];
        }

        $ids = [];
        foreach ($value as $item) {
            $id = filter_var($item, FILTER_VALIDATE_INT);
            if ($id === false || $id < 1) {
                throw new \InvalidArgumentException('Invalid identifier in selection.');
            }
            $ids[(int) $id] = (int) $id;
        }

        return array_values($ids);
    }

    private function assertExistingIds(string $table, array $ids, ?string $extraWhere = null): void
    {
        if ($ids === []) {
            return;
        }
        if (!in_array($table, ['permissions', 'roles', 'user_groups', 'users'], true)) {
            throw new \LogicException('Unsupported validation table.');
        }

        $sql = 'SELECT COUNT(*) AS total FROM ' . $table
            . ' WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
        if ($extraWhere !== null) {
            $sql .= ' AND ' . $extraWhere;
        }

        $row = $this->database->fetchOne($sql, $ids);
        if ((int) ($row['total'] ?? 0) !== count($ids)) {
            throw new \InvalidArgumentException('One or more selected records do not exist.');
        }
    }
}
