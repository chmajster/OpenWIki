<?php

declare(strict_types=1);

namespace OpenWiki\Auth;

use OpenWiki\Core\Application;
use OpenWiki\Core\Session;
use OpenWiki\Security\Csrf;

final class AuthService
{
    private bool $resolved = false;
    private ?array $user = null;
    private ?array $permissions = null;

    public function __construct(private readonly Application $app)
    {
    }

    public function check(): bool
    {
        return $this->user() !== null;
    }

    public function user(): ?array
    {
        if ($this->resolved) {
            return $this->user;
        }

        $this->resolved = true;
        if (!$this->app->installed()) {
            return null;
        }

        $userId = Session::get('user_id');
        if (!is_int($userId) && !ctype_digit((string) $userId)) {
            return null;
        }

        $this->user = $this->app->database()->fetchOne(
            'SELECT id, username, email, first_name, last_name, avatar_path, status, force_password_change
             FROM users
             WHERE id = :id AND deleted_at IS NULL
             LIMIT 1',
            ['id' => (int) $userId]
        );

        if ($this->user === null || $this->user['status'] !== 'active') {
            $this->logout();
            return null;
        }

        return $this->user;
    }

    public function attempt(string $identifier, string $password): bool
    {
        $identifier = trim($identifier);
        $user = $this->app->database()->fetchOne(
            'SELECT id, username, email, password_hash, status, auth_source
             FROM users
             WHERE (LOWER(username) = LOWER(:identifier_username) OR LOWER(email) = LOWER(:identifier_email))
               AND deleted_at IS NULL
             LIMIT 1',
            ['identifier_username' => $identifier, 'identifier_email' => $identifier]
        );

        if ($user !== null && $user['status'] !== 'active') {
            return false;
        }

        $userId = null;

        if ($user !== null && $user['auth_source'] === 'local') {
            $valid = is_string($user['password_hash'])
                && password_verify($password, $user['password_hash']);

            if (!$valid) {
                return false;
            }

            if (password_needs_rehash($user['password_hash'], self::passwordAlgorithm())) {
                $this->app->database()->execute(
                    'UPDATE users SET password_hash = :hash WHERE id = :id',
                    ['hash' => password_hash($password, self::passwordAlgorithm()), 'id' => (int) $user['id']]
                );
            }

            $userId = (int) $user['id'];
        } else {
            if ($user !== null && $user['auth_source'] !== 'ldap') {
                return false;
            }

            try {
                $ldap = new LdapService($this->app->database());
                $profile = $ldap->authenticate($identifier, $password);
                if ($profile === null) {
                    return false;
                }

                $userId = $ldap->provision($profile);
                if ($user !== null && $userId !== (int) $user['id']) {
                    throw new \RuntimeException('LDAP identity resolved to a different local account.');
                }
            } catch (\Throwable $exception) {
                error_log('[OpenWiki LDAP login] ' . $exception->getMessage());
                return false;
            }
        }

        if ($userId === null) {
            return false;
        }

        Session::regenerate();
        Csrf::rotate();
        Session::put('user_id', $userId);
        Session::forget('mfa_verified');
        Session::forget('mfa_pending_login');
        $this->resolved = false;
        $this->user = null;
        $this->permissions = null;

        $this->app->database()->execute(
            'UPDATE users SET last_login_at = UTC_TIMESTAMP() WHERE id = :id',
            ['id' => $userId]
        );

        return true;
    }

    public function logout(): void
    {
        Session::forget('user_id');
        Session::forget('mfa_verified');
        Session::forget('mfa_pending_login');
        Session::regenerate();
        Csrf::rotate();
        $this->resolved = true;
        $this->user = null;
        $this->permissions = null;
    }

    public function can(string $permission): bool
    {
        $user = $this->user();
        if ($user === null) {
            return false;
        }

        if ($this->permissions === null) {
            $rows = $this->app->database()->fetchAll(
                'SELECT DISTINCT p.name
                 FROM permissions p
                 INNER JOIN role_permissions rp ON rp.permission_id = p.id
                 INNER JOIN user_roles ur ON ur.role_id = rp.role_id
                 WHERE ur.user_id = :user_id',
                ['user_id' => (int) $user['id']]
            );
            $this->permissions = array_column($rows, 'name');
        }

        return in_array('*', $this->permissions, true)
            || in_array($permission, $this->permissions, true);
    }

    public static function passwordAlgorithm(): string|int|null
    {
        return defined('PASSWORD_ARGON2ID') ? PASSWORD_ARGON2ID : PASSWORD_DEFAULT;
    }
}
