<?php

declare(strict_types=1);

namespace OpenWiki\Install;

use OpenWiki\Auth\AuthService;
use OpenWiki\Core\Database;
use OpenWiki\Database\MigrationRunner;

final class InstallService
{
    public function __construct(private readonly string $basePath)
    {
    }

    public function requirements(): array
    {
        $requiredExtensions = ['pdo', 'pdo_mysql', 'mbstring', 'openssl', 'dom'];
        $extensions = [];
        foreach ($requiredExtensions as $extension) {
            $extensions[$extension] = extension_loaded($extension);
        }

        $paths = [
            $this->basePath,
            $this->basePath . '/storage',
            $this->basePath . '/storage/attachments',
            $this->basePath . '/storage/cache',
            $this->basePath . '/storage/logs',
            $this->basePath . '/storage/temp',
        ];

        $writable = [];
        foreach ($paths as $path) {
            if (!is_dir($path) && !@mkdir($path, 0770, true) && !is_dir($path)) {
                $writable[$path] = false;
                continue;
            }
            $writable[$path] = is_writable($path);
        }

        return [
            'php' => [
                'current' => PHP_VERSION,
                'minimum' => '8.2.0',
                'ok' => version_compare(PHP_VERSION, '8.2.0', '>='),
            ],
            'extensions' => $extensions,
            'writable' => $writable,
            'ok' => version_compare(PHP_VERSION, '8.2.0', '>=')
                && !in_array(false, $extensions, true)
                && !in_array(false, $writable, true),
        ];
    }

    public function install(array $input): void
    {
        if (is_file($this->basePath . '/storage/installed.lock')) {
            throw new \RuntimeException('OpenWiki is already installed.');
        }

        $requirements = $this->requirements();
        if (!$requirements['ok']) {
            throw new \RuntimeException('Server requirements are not satisfied.');
        }

        $config = $this->validate($input);

        $server = Database::connect($config['database'], false);
        if ($config['create_database']) {
            $databaseName = $config['database']['database'];
            $server->pdo()->exec(
                'CREATE DATABASE IF NOT EXISTS ' . $databaseName . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
            );
        }

        $database = Database::connect($config['database'], true);
        $runner = new MigrationRunner($database, $this->basePath . '/database/migrations');
        $runner->migrate();

        $adminId = $this->seedAdministrator($database, $config['admin']);
        $this->seedAuthorization($database, $adminId);
        $this->seedSettingsAndTemplates($database, $adminId, $config['app']);

        $env = [
            'APP_NAME' => $config['app']['name'],
            'APP_ENV' => 'production',
            'APP_DEBUG' => 'false',
            'APP_URL' => $config['app']['url'],
            'APP_KEY' => bin2hex(random_bytes(32)),
            'APP_TIMEZONE' => $config['app']['timezone'],
            'SESSION_SECURE_COOKIE' => str_starts_with(strtolower($config['app']['url']), 'https://') ? 'true' : 'false',
            'SESSION_SAME_SITE' => 'Lax',
            'SESSION_LIFETIME' => '7200',
            'DB_HOST' => $config['database']['host'],
            'DB_PORT' => (string) $config['database']['port'],
            'DB_DATABASE' => $config['database']['database'],
            'DB_USERNAME' => $config['database']['username'],
            'DB_PASSWORD' => $config['database']['password'],
            'DB_CHARSET' => 'utf8mb4',
        ];

        $this->writeEnvironment($env);

        $lockData = json_encode([
            'installed_at' => gmdate(DATE_ATOM),
            'version' => '0.1.0',
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        if (file_put_contents($this->basePath . '/storage/installed.lock', $lockData . PHP_EOL, LOCK_EX) === false) {
            throw new \RuntimeException('Unable to create installation lock.');
        }
        @chmod($this->basePath . '/storage/installed.lock', 0640);
    }

    private function validate(array $input): array
    {
        $appName = trim((string) ($input['app_name'] ?? ''));
        $appUrl = rtrim(trim((string) ($input['app_url'] ?? '')), '/');
        $timezone = trim((string) ($input['timezone'] ?? 'UTC'));

        if ($appName === '' || mb_strlen($appName) > 120) {
            throw new \InvalidArgumentException('Application name is required and must be at most 120 characters.');
        }

        if (!filter_var($appUrl, FILTER_VALIDATE_URL) || !in_array(strtolower((string) parse_url($appUrl, PHP_URL_SCHEME)), ['http', 'https'], true)) {
            throw new \InvalidArgumentException('Application URL must be a valid HTTP or HTTPS URL.');
        }

        if (!in_array($timezone, timezone_identifiers_list(), true)) {
            throw new \InvalidArgumentException('Invalid timezone.');
        }

        $databaseName = trim((string) ($input['db_database'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_]+$/', $databaseName)) {
            throw new \InvalidArgumentException('Database name may contain only letters, numbers and underscore.');
        }

        $port = filter_var($input['db_port'] ?? 3306, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 65535]]);
        if ($port === false) {
            throw new \InvalidArgumentException('Invalid database port.');
        }

        $username = trim((string) ($input['admin_username'] ?? ''));
        $email = trim((string) ($input['admin_email'] ?? ''));
        $password = (string) ($input['admin_password'] ?? '');

        if (!preg_match('/^[A-Za-z0-9._-]{3,100}$/', $username)) {
            throw new \InvalidArgumentException('Administrator username must contain 3-100 safe characters.');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 191) {
            throw new \InvalidArgumentException('Administrator email is invalid.');
        }

        if (strlen($password) < 12 || strlen($password) > 1024) {
            throw new \InvalidArgumentException('Administrator password must contain at least 12 characters.');
        }

        return [
            'app' => ['name' => $appName, 'url' => $appUrl, 'timezone' => $timezone],
            'database' => [
                'host' => trim((string) ($input['db_host'] ?? '127.0.0.1')),
                'port' => (int) $port,
                'database' => $databaseName,
                'username' => (string) ($input['db_username'] ?? ''),
                'password' => (string) ($input['db_password'] ?? ''),
                'charset' => 'utf8mb4',
            ],
            'create_database' => filter_var($input['create_database'] ?? false, FILTER_VALIDATE_BOOL),
            'admin' => [
                'username' => $username,
                'email' => $email,
                'password' => $password,
                'first_name' => trim((string) ($input['admin_first_name'] ?? '')),
                'last_name' => trim((string) ($input['admin_last_name'] ?? '')),
            ],
        ];
    }

    private function seedAdministrator(Database $database, array $admin): int
    {
        $existing = $database->fetchOne(
            'SELECT id FROM users WHERE username = :username OR email = :email LIMIT 1',
            ['username' => $admin['username'], 'email' => $admin['email']]
        );

        $hash = password_hash($admin['password'], AuthService::passwordAlgorithm());
        if (!is_string($hash)) {
            throw new \RuntimeException('Unable to hash administrator password.');
        }

        if ($existing !== null) {
            $id = (int) $existing['id'];
            $database->execute(
                'UPDATE users
                 SET username = :username, email = :email, password_hash = :password_hash,
                     first_name = :first_name, last_name = :last_name, status = "active",
                     auth_source = "local", updated_at = UTC_TIMESTAMP()
                 WHERE id = :id',
                [
                    'id' => $id,
                    'username' => $admin['username'],
                    'email' => $admin['email'],
                    'password_hash' => $hash,
                    'first_name' => $admin['first_name'] ?: null,
                    'last_name' => $admin['last_name'] ?: null,
                ]
            );
            return $id;
        }

        return $database->insert(
            'INSERT INTO users
             (username, email, password_hash, first_name, last_name, status, auth_source, created_at, updated_at)
             VALUES
             (:username, :email, :password_hash, :first_name, :last_name, "active", "local", UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            [
                'username' => $admin['username'],
                'email' => $admin['email'],
                'password_hash' => $hash,
                'first_name' => $admin['first_name'] ?: null,
                'last_name' => $admin['last_name'] ?: null,
            ]
        );
    }

    private function seedAuthorization(Database $database, int $adminId): void
    {
        $permissions = [
            '*',
            'space.view', 'space.create', 'space.edit', 'space.delete',
            'page.view', 'page.create', 'page.edit', 'page.delete', 'page.publish', 'page.archive', 'page.move',
            'attachment.upload', 'attachment.delete',
            'comment.create', 'comment.delete',
            'user.manage', 'group.manage', 'role.manage',
            'audit.view', 'settings.manage', 'template.manage', 'api.manage', 'webhook.manage',
        ];

        foreach ($permissions as $permission) {
            $database->execute(
                'INSERT IGNORE INTO permissions (name, description, created_at) VALUES (:name, NULL, UTC_TIMESTAMP())',
                ['name' => $permission]
            );
        }

        $roles = [
            ['Super Admin', 'super-admin', 1],
            ['Admin', 'admin', 1],
            ['Space Admin', 'space-admin', 1],
            ['Editor', 'editor', 1],
            ['Author', 'author', 1],
            ['Viewer', 'viewer', 1],
            ['Guest', 'guest', 1],
        ];

        foreach ($roles as [$name, $slug, $system]) {
            $database->execute(
                'INSERT IGNORE INTO roles (name, slug, is_system, created_at, updated_at)
                 VALUES (:name, :slug, :system, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
                ['name' => $name, 'slug' => $slug, 'system' => $system]
            );
        }

        $rolePermissionMap = [
            'super-admin' => ['*'],
            'admin' => ['space.view','space.create','space.edit','space.delete','page.view','page.create','page.edit','page.delete','page.publish','page.archive','page.move','attachment.upload','attachment.delete','comment.create','comment.delete','user.manage','group.manage','role.manage','audit.view','settings.manage','template.manage','api.manage','webhook.manage'],
            'space-admin' => ['space.view','space.edit','page.view','page.create','page.edit','page.delete','page.publish','page.archive','page.move','attachment.upload','attachment.delete','comment.create','comment.delete'],
            'editor' => ['space.view','page.view','page.create','page.edit','page.publish','page.move','attachment.upload','comment.create'],
            'author' => ['space.view','page.view','page.create','page.edit','attachment.upload','comment.create'],
            'viewer' => ['space.view','page.view','comment.create'],
            'guest' => ['space.view','page.view'],
        ];

        foreach ($rolePermissionMap as $roleSlug => $rolePermissions) {
            $role = $database->fetchOne('SELECT id FROM roles WHERE slug = :slug', ['slug' => $roleSlug]);
            if ($role === null) {
                throw new \RuntimeException('Unable to seed role: ' . $roleSlug);
            }

            foreach ($rolePermissions as $permissionName) {
                $permission = $database->fetchOne('SELECT id FROM permissions WHERE name = :name', ['name' => $permissionName]);
                if ($permission === null) {
                    throw new \RuntimeException('Unable to seed permission: ' . $permissionName);
                }

                $database->execute(
                    'INSERT IGNORE INTO role_permissions (role_id, permission_id) VALUES (:role_id, :permission_id)',
                    ['role_id' => (int) $role['id'], 'permission_id' => (int) $permission['id']]
                );
            }
        }

        $superAdmin = $database->fetchOne('SELECT id FROM roles WHERE slug = "super-admin"');
        if ($superAdmin === null) {
            throw new \RuntimeException('Super Admin role was not created.');
        }

        $database->execute(
            'INSERT IGNORE INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id)',
            ['user_id' => $adminId, 'role_id' => (int) $superAdmin['id']]
        );
    }

    private function seedSettingsAndTemplates(Database $database, int $adminId, array $app): void
    {
        $database->execute(
            'INSERT INTO settings (setting_key, setting_value, is_secret, updated_by, updated_at)
             VALUES ("app.name", :name, 0, :user_id, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_by = VALUES(updated_by), updated_at = UTC_TIMESTAMP()',
            ['name' => $app['name'], 'user_id' => $adminId]
        );

        $templates = [
            ['Blank Page', 'Start with an empty page.', '<p></p>'],
            ['Technical Documentation', 'Structured technical documentation.', '<h1>Overview</h1><p></p><h2>Architecture</h2><p></p><h2>Configuration</h2><p></p><h2>Operations</h2><p></p>'],
            ['Runbook', 'Operational procedure with validation steps.', '<h1>Purpose</h1><p></p><h2>Prerequisites</h2><p></p><h2>Procedure</h2><ol><li></li></ol><h2>Validation</h2><p></p><h2>Rollback</h2><p></p>'],
            ['Incident Report', 'Incident chronology and remediation.', '<h1>Summary</h1><p></p><h2>Impact</h2><p></p><h2>Timeline</h2><p></p><h2>Root cause</h2><p></p><h2>Corrective actions</h2><p></p>'],
            ['Decision Record', 'Architecture or business decision record.', '<h1>Decision</h1><p></p><h2>Context</h2><p></p><h2>Options considered</h2><p></p><h2>Consequences</h2><p></p>'],
        ];

        foreach ($templates as [$name, $description, $content]) {
            $exists = $database->fetchOne('SELECT id FROM page_templates WHERE name = :name AND is_system = 1', ['name' => $name]);
            if ($exists === null) {
                $database->execute(
                    'INSERT INTO page_templates
                     (name, description, content_html, content_markdown, created_by, is_system, created_at, updated_at)
                     VALUES (:name, :description, :content, NULL, :created_by, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
                    ['name' => $name, 'description' => $description, 'content' => $content, 'created_by' => $adminId]
                );
            }
        }
    }

    private function writeEnvironment(array $values): void
    {
        $lines = [];
        foreach ($values as $key => $value) {
            $value = (string) $value;
            if (str_contains($value, "\n") || str_contains($value, "\r")) {
                throw new \InvalidArgumentException('Environment values cannot contain line breaks.');
            }
            $escaped = str_replace(['\\', '"'], ['\\\\', '\\"'], $value);
            $lines[] = $key . '="' . $escaped . '"';
        }

        $path = $this->basePath . '/.env';
        $temp = $path . '.tmp-' . bin2hex(random_bytes(6));
        if (file_put_contents($temp, implode(PHP_EOL, $lines) . PHP_EOL, LOCK_EX) === false) {
            throw new \RuntimeException('Unable to write environment configuration.');
        }
        @chmod($temp, 0600);

        if (!@rename($temp, $path)) {
            @unlink($temp);
            throw new \RuntimeException('Unable to activate environment configuration.');
        }
    }
}
