<?php

declare(strict_types=1);

namespace OpenWiki\Http\Controllers;

use OpenWiki\Admin\DirectoryAdminService;
use OpenWiki\Audit\AuditLogger;
use OpenWiki\Auth\LdapService;
use OpenWiki\Auth\MfaService;
use OpenWiki\Backup\BackupService;
use OpenWiki\Core\Request;
use OpenWiki\Core\Response;
use OpenWiki\Core\Session;
use OpenWiki\Database\MigrationRunner;
use OpenWiki\Http\Controller;
use OpenWiki\Webhooks\WebhookService;

final class DirectoryAdminController extends Controller
{
    public function dashboard(Request $request): Response
    {
        if (($failure = $this->authorize($request, 'settings.manage')) !== null) {
            return $failure;
        }

        $counts = [];
        foreach ([
            'users' => 'SELECT COUNT(*) AS total FROM users WHERE deleted_at IS NULL',
            'groups' => 'SELECT COUNT(*) AS total FROM user_groups',
            'roles' => 'SELECT COUNT(*) AS total FROM roles',
            'spaces' => 'SELECT COUNT(*) AS total FROM spaces WHERE deleted_at IS NULL',
            'pages' => 'SELECT COUNT(*) AS total FROM pages WHERE deleted_at IS NULL',
            'broken_links' => 'SELECT COUNT(*) AS total FROM page_links WHERE is_broken = 1',
            'webhooks' => 'SELECT COUNT(*) AS total FROM webhooks',
        ] as $key => $sql) {
            $row = $this->app->database()->fetchOne($sql);
            $counts[$key] = (int) ($row['total'] ?? 0);
        }

        $counts['backups'] = count(
            (new BackupService($this->app->database(), $this->app->basePath()))->list()
        );
        $mfaPolicy = (new MfaService($this->app->database()))->policy();
        $counts['mfa_policy'] = count($mfaPolicy['role_ids']) + ($mfaPolicy['enforce_global'] ? 1 : 0);
        $counts['ldap'] = (new LdapService($this->app->database()))->enabled() ? 1 : 0;

        $migrationStatus = (new MigrationRunner(
            $this->app->database(),
            $this->app->basePath('database/migrations')
        ))->status();
        $pendingMigrations = count(array_filter(
            $migrationStatus,
            static fn (array $migration): bool => !$migration['applied']
        ));
        $cron = $this->app->database()->fetchOne(
            'SELECT setting_value FROM settings WHERE setting_key = "system.cron_last_run" LIMIT 1'
        );
        $cronTimestamp = $cron === null ? false : strtotime((string) $cron['setting_value']);
        $cronStale = $cronTimestamp === false || (time() - $cronTimestamp) > 600;
        $counts['system'] = $pendingMigrations + ($cronStale ? 1 : 0);

        return $this->render('admin/dashboard', [
            'title' => 'Administration',
            'counts' => $counts,
        ]);
    }

    public function users(Request $request): Response
    {
        if (($failure = $this->authorize($request, 'user.manage')) !== null) {
            return $failure;
        }

        $query = trim((string) $request->query('q', ''));
        return $this->render('admin/users/index', [
            'title' => 'Users',
            'users' => $this->service()->users($query),
            'query' => $query,
            'newPassword' => Session::pullFlash('admin_new_password'),
        ]);
    }

    public function createUserForm(Request $request): Response
    {
        if (($failure = $this->authorize($request, 'user.manage')) !== null) {
            return $failure;
        }

        return $this->userForm(null);
    }

    public function createUser(Request $request): Response
    {
        if (($failure = $this->authorizeMutation($request, 'user.manage')) !== null) {
            return $failure;
        }

        try {
            $id = $this->service()->createUser((array) $request->input());
            $this->audit('USER_CREATED', 'user', $id, $request, null, [
                'username' => (string) $request->input('username', ''),
                'email' => (string) $request->input('email', ''),
            ]);
            try {
                (new WebhookService($this->app->database()))->queue('user.created', [
                    'id' => $id,
                    'username' => (string) $request->input('username', ''),
                    'email' => (string) $request->input('email', ''),
                ]);
            } catch (\Throwable $webhookError) {
                error_log('[OpenWiki webhook queue] ' . $webhookError->getMessage());
            }
            Session::flash('success', 'User created.');
            return Response::redirect('/admin/users/' . $id . '/edit');
        } catch (\Throwable $exception) {
            return $this->userFormError(null, $request, $exception);
        }
    }

    public function editUser(Request $request, string $id): Response
    {
        if (($failure = $this->authorize($request, 'user.manage')) !== null) {
            return $failure;
        }

        $userId = $this->id($id);
        if ($userId === null) {
            return $this->render('errors/404', ['title' => 'User not found'], 404);
        }

        $user = $this->service()->user($userId);
        if ($user === null) {
            return $this->render('errors/404', ['title' => 'User not found'], 404);
        }

        return $this->userForm($user);
    }

    public function updateUser(Request $request, string $id): Response
    {
        if (($failure = $this->authorizeMutation($request, 'user.manage')) !== null) {
            return $failure;
        }

        $userId = $this->id($id);
        $before = $userId === null ? null : $this->service()->user($userId);
        if ($before === null) {
            return $this->render('errors/404', ['title' => 'User not found'], 404);
        }

        $actor = $this->app->auth()->user();
        if ((int) $actor['id'] === $userId && (string) $request->input('status', 'active') !== 'active') {
            return $this->userFormError($before, $request, new \InvalidArgumentException('You cannot disable or lock your own account.'));
        }

        try {
            $this->service()->updateUser($userId, (array) $request->input());
            $after = $this->service()->user($userId);
            $this->audit('USER_UPDATED', 'user', $userId, $request, $this->safeUserAudit($before), $this->safeUserAudit($after));
            Session::flash('success', 'User updated.');
            return Response::redirect('/admin/users/' . $userId . '/edit');
        } catch (\Throwable $exception) {
            return $this->userFormError($before, $request, $exception);
        }
    }

    public function resetPassword(Request $request, string $id): Response
    {
        if (($failure = $this->authorizeMutation($request, 'user.manage')) !== null) {
            return $failure;
        }

        $userId = $this->id($id);
        if ($userId === null || $this->service()->user($userId) === null) {
            return $this->render('errors/404', ['title' => 'User not found'], 404);
        }

        $password = $this->service()->resetPassword($userId);
        Session::flash('admin_new_password', ['user_id' => $userId, 'password' => $password]);
        $this->audit('USER_PASSWORD_RESET', 'user', $userId, $request, null, ['force_password_change' => true]);
        Session::flash('success', 'Password reset. Copy the temporary password now.');
        return Response::redirect('/admin/users');
    }

    public function resetMfa(Request $request, string $id): Response
    {
        if (($failure = $this->authorizeMutation($request, 'user.manage')) !== null) {
            return $failure;
        }

        $userId = $this->id($id);
        if ($userId === null || $this->service()->user($userId) === null) {
            return $this->render('errors/404', ['title' => 'User not found'], 404);
        }

        $this->service()->resetMfa($userId);
        $this->audit('USER_MFA_RESET', 'user', $userId, $request);
        Session::flash('success', 'MFA reset for user.');
        return Response::redirect('/admin/users/' . $userId . '/edit');
    }

    public function deleteUser(Request $request, string $id): Response
    {
        if (($failure = $this->authorizeMutation($request, 'user.manage')) !== null) {
            return $failure;
        }

        $userId = $this->id($id);
        if ($userId === null) {
            return $this->render('errors/404', ['title' => 'User not found'], 404);
        }

        $actor = $this->app->auth()->user();
        if ((int) $actor['id'] === $userId) {
            Session::flash('error', 'You cannot delete your own account.');
            return Response::redirect('/admin/users/' . $userId . '/edit');
        }

        $before = $this->service()->user($userId);
        if ($before === null || !$this->service()->softDeleteUser($userId)) {
            return $this->render('errors/404', ['title' => 'User not found'], 404);
        }

        $this->audit('USER_DELETED', 'user', $userId, $request, $this->safeUserAudit($before), ['deleted' => true]);
        Session::flash('success', 'User deleted.');
        return Response::redirect('/admin/users');
    }

    public function groups(Request $request): Response
    {
        if (($failure = $this->authorize($request, 'group.manage')) !== null) {
            return $failure;
        }

        return $this->render('admin/groups/index', [
            'title' => 'Groups',
            'groups' => $this->service()->groups(),
        ]);
    }

    public function groupForm(Request $request, ?string $id = null): Response
    {
        if (($failure = $this->authorize($request, 'group.manage')) !== null) {
            return $failure;
        }

        $group = null;
        if ($id !== null) {
            $groupId = $this->id($id);
            $group = $groupId === null ? null : $this->service()->group($groupId);
            if ($group === null) {
                return $this->render('errors/404', ['title' => 'Group not found'], 404);
            }
        }

        return $this->render('admin/groups/edit', [
            'title' => $group === null ? 'Create group' : 'Edit group',
            'group' => $group,
            'users' => $this->service()->users(),
            'formAction' => $group === null ? '/admin/groups' : '/admin/groups/' . (int) $group['id'],
        ]);
    }

    public function saveGroup(Request $request, ?string $id = null): Response
    {
        if (($failure = $this->authorizeMutation($request, 'group.manage')) !== null) {
            return $failure;
        }

        $groupId = $id === null ? null : $this->id($id);
        if ($id !== null && $groupId === null) {
            return $this->render('errors/404', ['title' => 'Group not found'], 404);
        }

        $before = $groupId === null ? null : $this->service()->group($groupId);
        try {
            $savedId = $this->service()->saveGroup($groupId, (array) $request->input());
            $after = $this->service()->group($savedId);
            $this->audit($before === null ? 'GROUP_CREATED' : 'GROUP_UPDATED', 'group', $savedId, $request, $before, $after);
            Session::flash('success', $before === null ? 'Group created.' : 'Group updated.');
            return Response::redirect('/admin/groups/' . $savedId . '/edit');
        } catch (\Throwable $exception) {
            return $this->render('admin/groups/edit', [
                'title' => $before === null ? 'Create group' : 'Edit group',
                'group' => $before,
                'users' => $this->service()->users(),
                'formAction' => $before === null ? '/admin/groups' : '/admin/groups/' . (int) $before['id'],
                'formError' => $this->safeError($exception),
                'old' => (array) $request->input(),
            ], 422);
        }
    }

    public function deleteGroup(Request $request, string $id): Response
    {
        if (($failure = $this->authorizeMutation($request, 'group.manage')) !== null) {
            return $failure;
        }

        $groupId = $this->id($id);
        if ($groupId === null) {
            return $this->render('errors/404', ['title' => 'Group not found'], 404);
        }

        try {
            $before = $this->service()->group($groupId);
            if ($before === null || !$this->service()->deleteGroup($groupId)) {
                return $this->render('errors/404', ['title' => 'Group not found'], 404);
            }
            $this->audit('GROUP_DELETED', 'group', $groupId, $request, $before, ['deleted' => true]);
            Session::flash('success', 'Group deleted.');
        } catch (\InvalidArgumentException $exception) {
            Session::flash('error', $exception->getMessage());
        }

        return Response::redirect('/admin/groups');
    }

    public function roles(Request $request): Response
    {
        if (($failure = $this->authorize($request, 'role.manage')) !== null) {
            return $failure;
        }

        return $this->render('admin/roles/index', [
            'title' => 'Roles',
            'roles' => $this->service()->roles(),
        ]);
    }

    public function roleForm(Request $request, ?string $id = null): Response
    {
        if (($failure = $this->authorize($request, 'role.manage')) !== null) {
            return $failure;
        }

        $role = null;
        if ($id !== null) {
            $roleId = $this->id($id);
            $role = $roleId === null ? null : $this->service()->role($roleId);
            if ($role === null) {
                return $this->render('errors/404', ['title' => 'Role not found'], 404);
            }
        }

        return $this->render('admin/roles/edit', [
            'title' => $role === null ? 'Create role' : 'Edit role',
            'role' => $role,
            'permissions' => $this->service()->permissions(),
            'formAction' => $role === null ? '/admin/roles' : '/admin/roles/' . (int) $role['id'],
        ]);
    }

    public function saveRole(Request $request, ?string $id = null): Response
    {
        if (($failure = $this->authorizeMutation($request, 'role.manage')) !== null) {
            return $failure;
        }

        $roleId = $id === null ? null : $this->id($id);
        if ($id !== null && $roleId === null) {
            return $this->render('errors/404', ['title' => 'Role not found'], 404);
        }

        $before = $roleId === null ? null : $this->service()->role($roleId);
        try {
            $savedId = $this->service()->saveRole($roleId, (array) $request->input());
            $after = $this->service()->role($savedId);
            $this->audit($before === null ? 'ROLE_CREATED' : 'ROLE_CHANGED', 'role', $savedId, $request, $before, $after);
            Session::flash('success', $before === null ? 'Role created.' : 'Role updated.');
            return Response::redirect('/admin/roles/' . $savedId . '/edit');
        } catch (\Throwable $exception) {
            return $this->render('admin/roles/edit', [
                'title' => $before === null ? 'Create role' : 'Edit role',
                'role' => $before,
                'permissions' => $this->service()->permissions(),
                'formAction' => $before === null ? '/admin/roles' : '/admin/roles/' . (int) $before['id'],
                'formError' => $this->safeError($exception),
                'old' => (array) $request->input(),
            ], 422);
        }
    }

    public function deleteRole(Request $request, string $id): Response
    {
        if (($failure = $this->authorizeMutation($request, 'role.manage')) !== null) {
            return $failure;
        }

        $roleId = $this->id($id);
        if ($roleId === null) {
            return $this->render('errors/404', ['title' => 'Role not found'], 404);
        }

        try {
            $before = $this->service()->role($roleId);
            if ($before === null || !$this->service()->deleteRole($roleId)) {
                return $this->render('errors/404', ['title' => 'Role not found'], 404);
            }
            $this->audit('ROLE_DELETED', 'role', $roleId, $request, $before, ['deleted' => true]);
            Session::flash('success', 'Role deleted.');
        } catch (\InvalidArgumentException $exception) {
            Session::flash('error', $exception->getMessage());
        }

        return Response::redirect('/admin/roles');
    }

    private function userForm(?array $user, ?array $old = null, ?string $error = null, int $status = 200): Response
    {
        return $this->render('admin/users/edit', [
            'title' => $user === null ? 'Create user' : 'Edit user',
            'userRecord' => $user,
            'roles' => $this->service()->roles(),
            'groups' => $this->service()->groups(),
            'formAction' => $user === null ? '/admin/users' : '/admin/users/' . (int) $user['id'],
            'old' => $old ?? [],
            'formError' => $error,
        ], $status);
    }

    private function userFormError(?array $user, Request $request, \Throwable $exception): Response
    {
        return $this->userForm($user, (array) $request->input(), $this->safeError($exception), 422);
    }

    private function authorizeMutation(Request $request, string $permission): ?Response
    {
        if (($failure = $this->authorize($request, $permission)) !== null) {
            return $failure;
        }

        return $this->verifyCsrf($request);
    }

    private function service(): DirectoryAdminService
    {
        return new DirectoryAdminService($this->app->database());
    }

    private function id(string $id): ?int
    {
        $value = filter_var($id, FILTER_VALIDATE_INT);
        return $value === false || $value < 1 ? null : (int) $value;
    }

    private function audit(
        string $action,
        string $objectType,
        ?int $objectId,
        Request $request,
        ?array $before = null,
        ?array $after = null
    ): void {
        $actor = $this->app->auth()->user();
        (new AuditLogger($this->app->database()))->log(
            $action,
            $objectType,
            $objectId,
            $actor === null ? null : (int) $actor['id'],
            $request,
            $before,
            $after
        );
    }

    private function safeUserAudit(?array $user): ?array
    {
        if ($user === null) {
            return null;
        }

        return [
            'id' => (int) $user['id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'status' => $user['status'],
            'force_password_change' => (bool) $user['force_password_change'],
            'role_ids' => $user['role_ids'] ?? [],
            'group_ids' => $user['group_ids'] ?? [],
        ];
    }

    private function safeError(\Throwable $exception): string
    {
        error_log('[OpenWiki admin] ' . $exception->getMessage());

        if ($exception instanceof \InvalidArgumentException) {
            return $exception->getMessage();
        }
        if (str_contains(strtolower($exception->getMessage()), 'duplicate')) {
            return 'A record with the same unique value already exists.';
        }

        return 'Unable to save changes.';
    }
}
