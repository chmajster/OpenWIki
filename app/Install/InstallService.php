<?php

declare(strict_types=1);

namespace OpenWiki\Install;

use OpenWiki\Auth\AuthService;
use OpenWiki\Core\Database;
use OpenWiki\Core\RuntimePaths;
use OpenWiki\Database\MigrationRunner;

final class InstallService
{
    public function __construct(private readonly string $basePath)
    {
    }

    public function requirements(bool $prepareStorage = true): array
    {
        $requiredExtensions = ['pdo', 'pdo_mysql', 'mbstring', 'openssl', 'dom', 'phar', 'curl', 'ldap', 'zip', 'gd'];
        $extensions = [];
        foreach ($requiredExtensions as $extension) {
            $extensions[$extension] = extension_loaded($extension);
        }

        $paths = [
            RuntimePaths::storage($this->basePath),
            RuntimePaths::storage($this->basePath, 'attachments'),
            RuntimePaths::storage($this->basePath, 'backups'),
            RuntimePaths::storage($this->basePath, 'cache'),
            RuntimePaths::storage($this->basePath, 'logs'),
            RuntimePaths::storage($this->basePath, 'temp'),
        ];
        if (!is_file(RuntimePaths::storage($this->basePath, 'installed.lock'))) array_unshift($paths, dirname(RuntimePaths::configuration($this->basePath)));

        $writable = [];
        foreach ($paths as $path) {
            if (!is_dir($path) && (!$prepareStorage || !@mkdir($path, 0770, true)) && !is_dir($path)) {
                $writable[$path] = false;
                continue;
            }
            $writable[$path] = is_writable($path);
        }
        $compiled = true;
        foreach (['wiki-editor.js', 'diagram.js', 'document-reader.js', 'diagram.html'] as $asset) {
            $path = $this->basePath . '/public/assets/' . $asset;
            $compiled = $compiled && is_file($path) && is_readable($path) && filesize($path) > 0;
        }
        $assets = [
            'Composer runtime dependencies' => class_exists(\League\CommonMark\MarkdownConverter::class) && class_exists(\Masterminds\HTML5::class),
            'Compiled editor assets' => $compiled,
        ];

        return [
            'php' => [
                'current' => PHP_VERSION,
                'minimum' => '8.2.0',
                'ok' => version_compare(PHP_VERSION, '8.2.0', '>='),
            ],
            'extensions' => $extensions,
            'writable' => $writable,
            'assets' => $assets,
            'ok' => version_compare(PHP_VERSION, '8.2.0', '>=')
                && !in_array(false, $extensions, true)
                && !in_array(false, $assets, true)
                && !in_array(false, $writable, true),
        ];
    }

    public function testDatabaseConnection(array $input): array
    {
        if (is_file(RuntimePaths::storage($this->basePath, 'installed.lock'))) throw new \RuntimeException('OpenWiki is already installed.');
        $config = $this->databaseConfig($input);
        $createDatabase = filter_var($input['create_database'] ?? false, FILTER_VALIDATE_BOOL);

        try {
            $database = Database::connect($config, true);
            $database->fetchOne('SELECT 1 AS ok');
            $tables = $database->fetchAll('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()');
            $statePath = RuntimePaths::storage($this->basePath, 'installation.json');
            $state = is_file($statePath) ? json_decode((string) file_get_contents($statePath), true) : [];
            if ($tables !== [] && ($state['target'] ?? '') !== hash('sha256', json_encode($config, JSON_THROW_ON_ERROR))) {
                throw new \RuntimeException('The database is not empty. Choose a new, empty database. Existing data has not been changed.');
            }

            return [
                'ok' => true,
                'database_exists' => true,
                'message' => 'Database connection successful.',
            ];
        } catch (\RuntimeException $exception) {
            $previous = $exception->getPrevious();
            $mysqlError = $previous instanceof \PDOException
                ? (int) ($previous->errorInfo[1] ?? 0)
                : 0;

            if (!$createDatabase || $mysqlError !== 1049) {
                throw $exception;
            }
        }

        $server = Database::connect($config, false);
        $server->fetchOne('SELECT 1 AS ok');

        return [
            'ok' => true,
            'database_exists' => false,
            'message' => 'Server connection successful. The database does not exist yet and will be created during installation.',
        ];
    }

    public function install(array $input): void
    {
        if (is_file(RuntimePaths::storage($this->basePath, 'installed.lock'))) {
            throw new \RuntimeException('OpenWiki is already installed.');
        }

        $requirements = $this->requirements();
        if (!$requirements['ok']) {
            throw new \RuntimeException('Server requirements are not satisfied.');
        }

        $config = $this->validate($input);

        $handle = @fopen(RuntimePaths::storage($this->basePath, 'installation.mutex'), 'c');
        if ($handle === false) throw new \RuntimeException('Unable to lock installation. Check storage permissions.');
        try {
            if (!flock($handle, LOCK_EX | LOCK_NB)) throw new \RuntimeException('Another installation is running. Wait and reload this page.');
            clearstatcache();
            if (is_file(RuntimePaths::storage($this->basePath, 'installed.lock'))) throw new \RuntimeException('OpenWiki is already installed.');
            $this->installLocked($config);
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }

    private function installLocked(array $config): void
    {
        $statePath = RuntimePaths::storage($this->basePath, 'installation.json');
        $state = is_file($statePath) ? json_decode((string) file_get_contents($statePath), true, 512, JSON_THROW_ON_ERROR) : null;
        $target = hash('sha256', json_encode($config['database'], JSON_THROW_ON_ERROR));
        if ($state !== null && (!is_array($state) || ($state['target'] ?? '') !== $target || !preg_match('/^[a-f0-9]{64}$/D', (string) ($state['key'] ?? '')))) {
            throw new \RuntimeException('An unfinished installation uses different database credentials. Use the original connection settings to resume.');
        }
        // Never turn a lost installation marker into an unauthenticated account reset.
        $environment = RuntimePaths::configuration($this->basePath);
        if ($state === null && is_file($environment) && preg_match('/^APP_KEY[ \t]*=[ \t]*(.*)$/m', (string) file_get_contents($environment), $existingKey)
            && trim($existingKey[1], " \t\r\n\"'") !== '') {
            throw new \RuntimeException('Existing application configuration found. Restore its installation marker instead of reinstalling.');
        }

        $server = Database::connect($config['database'], false);
        if ($config['create_database']) {
            $databaseName = $config['database']['database'];
            $server->pdo()->exec(
                'CREATE DATABASE IF NOT EXISTS `' . $databaseName . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci'
            );
        }

        $database = Database::connect($config['database'], true);
        $lockName = 'openwiki-install-' . substr(hash('sha256', $config['database']['database']), 0, 40);
        if ((int) ($database->fetchOne('SELECT GET_LOCK(?, 0) AS acquired', [$lockName])['acquired'] ?? 0) !== 1) {
            throw new \RuntimeException('Another installer is using this database. Wait and retry.');
        }
        try {
            $tables = $database->fetchAll('SELECT table_name FROM information_schema.tables WHERE table_schema = DATABASE()');
            if ($state === null && $tables !== []) {
                throw new \RuntimeException('The database is not empty. Choose a new, empty database. Existing data has not been changed.');
            }
            if ($state === null) {
                $state = ['target' => $target, 'key' => bin2hex(random_bytes(32))];
                $this->writePrivateFile($statePath, json_encode($state, JSON_THROW_ON_ERROR));
            }
            $runner = new MigrationRunner($database, $this->basePath . '/database/migrations');
            $runner->migrate();

            $database->transaction(function () use ($database, $config, $state): void {
                $owner = $database->fetchOne('SELECT setting_value FROM settings WHERE setting_key = "system.installation_owner"');
                if ($owner === null && $database->fetchOne('SELECT id FROM users LIMIT 1') !== null) {
                    throw new \RuntimeException('Existing user accounts found. Installation cannot replace an existing administrator.');
                }
                if ($owner !== null && !hash_equals(hash('sha256', $state['key']), $owner['setting_value'])) {
                    throw new \RuntimeException('This database belongs to another installation.');
                }
                $adminId = $this->seedAdministrator($database, $config['admin']);
                $this->seedAuthorization($database, $adminId);
                $this->seedSettingsAndTemplates($database, $adminId, $config['app']);
                $database->execute('INSERT IGNORE INTO settings (setting_key, setting_value, is_secret, updated_at) VALUES ("system.installation_owner", ?, 1, UTC_TIMESTAMP())', [hash('sha256', $state['key'])]);
            });

            $env = [
                'APP_NAME' => $config['app']['name'],
                'APP_ENV' => 'production',
                'APP_DEBUG' => 'false',
                'APP_URL' => $config['app']['url'],
                'APP_KEY' => $state['key'],
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
                'version' => \OpenWiki\System\ReadinessService::manifest($this->basePath)['version'] ?? '0.1.0',
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

            $this->writePrivateFile(RuntimePaths::storage($this->basePath, 'installed.lock'), $lockData . PHP_EOL);
            @unlink($statePath);
        } finally {
            try { $database->fetchOne('SELECT RELEASE_LOCK(?)', [$lockName]); }
            catch (\Throwable) { /* Disconnecting also releases the named lock. */ }
        }
    }

    private function validate(array $input): array
    {
        $this->validateScalarInput($input);
        $appName = trim((string) ($input['app_name'] ?? ''));
        $appUrl = rtrim(trim((string) ($input['app_url'] ?? '')), '/');
        $timezone = trim((string) ($input['timezone'] ?? 'UTC'));

        if ($appName === '' || mb_strlen($appName) > 120) {
            throw new \InvalidArgumentException('Application name is required and must be at most 120 characters.');
        }

        if (!filter_var($appUrl, FILTER_VALIDATE_URL) || !in_array(strtolower((string) parse_url($appUrl, PHP_URL_SCHEME)), ['http', 'https'], true)
            || !in_array(parse_url($appUrl, PHP_URL_PATH), [null, '', '/'], true)
            || parse_url($appUrl, PHP_URL_USER) !== null || parse_url($appUrl, PHP_URL_PASS) !== null
            || parse_url($appUrl, PHP_URL_QUERY) !== null || parse_url($appUrl, PHP_URL_FRAGMENT) !== null) {
            throw new \InvalidArgumentException('Application URL must be an HTTP or HTTPS origin, without a path, credentials, query or fragment.');
        }

        if (!in_array($timezone, timezone_identifiers_list(), true)) {
            throw new \InvalidArgumentException('Invalid timezone.');
        }

        $databaseConfig = $this->databaseConfig($input);

        $username = trim((string) ($input['admin_username'] ?? ''));
        $email = trim((string) ($input['admin_email'] ?? ''));
        $password = (string) ($input['admin_password'] ?? '');

        if (!preg_match('/^[A-Za-z0-9._-]{3,100}$/', $username)) {
            throw new \InvalidArgumentException('Administrator username must contain 3-100 safe characters.');
        }

        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || mb_strlen($email) > 191) {
            throw new \InvalidArgumentException('Administrator email is invalid.');
        }

        if (mb_strlen($password, 'UTF-8') < 12 || strlen($password) > 72 || str_contains($password, "\0")) {
            throw new \InvalidArgumentException(
                'Administrator password must contain at least 12 characters, at most 72 bytes and no null characters.'
            );
        }
        foreach (['admin_first_name', 'admin_last_name'] as $name) {
            if (mb_strlen((string) ($input[$name] ?? '')) > 100) throw new \InvalidArgumentException('Administrator names must be at most 100 characters.');
        }

        return [
            'app' => ['name' => $appName, 'url' => $appUrl, 'timezone' => $timezone],
            'database' => $databaseConfig,
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

    private function databaseConfig(array $input): array
    {
        $this->validateScalarInput($input);
        $host = trim((string) ($input['db_host'] ?? '127.0.0.1'));
        if ($host === '' || strlen($host) > 253 || preg_match('/[;\s\x00-\x1f\x7f]/', $host)) {
            throw new \InvalidArgumentException('Database host must be a hostname or IP address without spaces or DSN options.');
        }

        $databaseName = trim((string) ($input['db_database'] ?? ''));
        if (!preg_match('/^[A-Za-z0-9_]{1,64}$/D', $databaseName)) {
            throw new \InvalidArgumentException('Database name may contain only letters, numbers and underscore.');
        }

        $port = filter_var(
            $input['db_port'] ?? 3306,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 65535]]
        );
        if ($port === false) {
            throw new \InvalidArgumentException('Invalid database port.');
        }

        $username = trim((string) ($input['db_username'] ?? ''));
        if ($username === '') {
            throw new \InvalidArgumentException('Database username is required.');
        }

        return [
            'host' => $host,
            'port' => (int) $port,
            'database' => $databaseName,
            'username' => $username,
            'password' => (string) ($input['db_password'] ?? ''),
            'charset' => 'utf8mb4',
        ];
    }

    private function validateScalarInput(array $input): void
    {
        foreach ($input as $value) {
            if (!is_scalar($value) && $value !== null) throw new \InvalidArgumentException('Installation fields must contain single values.');
        }
    }

    private function seedAdministrator(Database $database, array $admin): int
    {
        $existing = $database->fetchOne('SELECT * FROM users ORDER BY id LIMIT 1');

        if ($existing !== null) {
            if ($existing['username'] !== $admin['username'] || $existing['email'] !== $admin['email'] || !password_verify($admin['password'], $existing['password_hash'])) {
                throw new \RuntimeException('To resume installation, enter the same administrator username, email and password.');
            }
            return (int) $existing['id'];
        }

        $hash = password_hash($admin['password'], AuthService::passwordAlgorithm());
        if (!is_string($hash)) {
            throw new \RuntimeException('Unable to hash administrator password.');
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
            'space.view', 'space.create', 'space.edit', 'space.delete', 'space.manage_access', 'space.manage_owner', 'space.archive',
            'page.view', 'page.create', 'page.edit', 'page.delete', 'page.publish', 'page.archive', 'page.move', 'page.review', 'page.verify',
            'attachment.upload', 'attachment.delete',
            'comment.create', 'comment.delete', 'comment.resolve', 'task.create', 'task.manage', 'task.update_own',
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
            ['Reviewer', 'reviewer', 1],
            ['Publisher', 'publisher', 1],
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
            'admin' => ['space.view','space.create','space.edit','space.delete','space.manage_access','space.manage_owner','space.archive','page.view','page.create','page.edit','page.delete','page.publish','page.archive','page.move','page.review','page.verify','attachment.upload','attachment.delete','comment.create','comment.delete','user.manage','group.manage','role.manage','audit.view','settings.manage','template.manage','api.manage','webhook.manage'],
            'space-admin' => ['space.view','space.edit','space.manage_access','space.manage_owner','space.archive','page.view','page.create','page.edit','page.delete','page.publish','page.archive','page.move','page.review','page.verify','attachment.upload','attachment.delete','comment.create','comment.delete'],
            'editor' => ['space.view','page.view','page.create','page.edit','page.publish','page.move','page.verify','attachment.upload','comment.create'],
            'reviewer' => ['space.view','page.view','page.review','page.verify'],
            'publisher' => ['space.view','page.view','page.publish'],
            'author' => ['space.view','page.view','page.create','page.edit','page.verify','attachment.upload','comment.create'],
            'viewer' => ['space.view','page.view','comment.create'],
            'guest' => ['space.view','page.view'],
        ];

        foreach ($rolePermissionMap as $roleSlug => $rolePermissions) {
            if (in_array($roleSlug, ['admin','space-admin'], true)) $rolePermissions = [...$rolePermissions, 'task.create','task.manage','task.update_own','comment.resolve'];
            elseif (in_array($roleSlug, ['editor','author','reviewer','publisher'], true)) $rolePermissions = [...$rolePermissions, 'task.create','task.update_own','comment.create','comment.resolve'];
            elseif ($roleSlug === 'viewer') $rolePermissions[] = 'task.update_own';
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
            $encoded = json_encode(
                $value,
                JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
            );
            $lines[] = $key . '=' . $encoded;
        }

        $this->writePrivateFile(RuntimePaths::configuration($this->basePath), implode(PHP_EOL, $lines) . PHP_EOL);
    }

    private function writePrivateFile(string $path, string $content): void
    {
        $temp = $path . '.tmp-' . bin2hex(random_bytes(6));
        $handle = @fopen($temp, 'x');
        if ($handle === false) throw new \RuntimeException('Unable to write installation configuration. Check directory permissions and disk space.');
        @chmod($temp, 0600);
        try {
            if (fwrite($handle, $content) !== strlen($content) || !fflush($handle)) throw new \RuntimeException('Unable to save installation configuration. Check disk space.');
        } catch (\Throwable $error) {
            fclose($handle);
            @unlink($temp);
            throw $error;
        }
        fclose($handle);
        if (!@rename($temp, $path)) {
            @unlink($temp);
            throw new \RuntimeException('Unable to activate installation configuration. Check directory permissions.');
        }
    }
}
