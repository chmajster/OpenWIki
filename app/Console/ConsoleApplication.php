<?php

declare(strict_types=1);

namespace OpenWiki\Console;

use OpenWiki\Admin\DirectoryAdminService;
use OpenWiki\Backup\BackupService;
use OpenWiki\Core\Application;
use OpenWiki\Core\Database;
use OpenWiki\Database\MigrationRunner;
use OpenWiki\Install\InstallService;
use OpenWiki\Security\RateLimiter;
use OpenWiki\Search\SearchIndexer;
use OpenWiki\Webhooks\WebhookService;

final class ConsoleApplication
{
    public function __construct(private readonly string $basePath)
    {
    }

    public function run(array $argv): int
    {
        $command = $argv[1] ?? 'help';

        try {
            return match ($command) {
                'help', '--help', '-h' => $this->help(),
                'install' => $this->install(),
                'migrate' => $this->migrate(),
                'db:status' => $this->databaseStatus(),
                'cache:clear' => $this->cacheClear(),
                'cron:run' => $this->cronRun(),
                'system:check' => $this->systemCheck(),
                'user:create' => $this->userCreate(),
                'user:disable' => $this->userDisable($argv[2] ?? null),
                'admin:reset-password' => $this->adminResetPassword($argv[2] ?? null),
                'backup:create' => $this->backupCreate(),
                'search:index' => $this->searchIndex(),
                default => $this->unknown($command),
            };
        } catch (\Throwable $exception) {
            fwrite(STDERR, '[FAIL] ' . $exception->getMessage() . PHP_EOL);
            return 1;
        }
    }

    private function help(): int
    {
        fwrite(STDOUT, <<<'TEXT'
OpenWiki CLI

Usage:
  php bin/console <command>

Commands:
  install       Run the interactive installer
  migrate       Apply pending database migrations
  db:status     Show database and migration status
  cache:clear   Remove application cache files
  cron:run      Run scheduled housekeeping tasks
  system:check          Validate runtime, database and writable storage
  user:create           Create a local user
  user:disable <user>   Disable a user by ID, username or email
  admin:reset-password <user>
                        Generate a temporary password and force change on next login
  backup:create         Create a database, attachments and configuration backup
  search:index          Rebuild searchable plain-text page content
  help                  Show this help

TEXT);
        return 0;
    }

    private function install(): int
    {
        $service = new InstallService($this->basePath);
        $requirements = $service->requirements();

        fwrite(STDOUT, '[1/5] Runtime checks' . PHP_EOL);
        if (!$requirements['ok']) {
            foreach ($requirements['extensions'] as $extension => $ok) {
                if (!$ok) {
                    fwrite(STDERR, '  [FAIL] Missing PHP extension: ' . $extension . PHP_EOL);
                }
            }
            foreach ($requirements['writable'] as $path => $ok) {
                if (!$ok) {
                    fwrite(STDERR, '  [FAIL] Not writable: ' . $path . PHP_EOL);
                }
            }
            return 1;
        }
        fwrite(STDOUT, '  [ OK ] PHP ' . PHP_VERSION . PHP_EOL);

        fwrite(STDOUT, '[2/5] Application settings' . PHP_EOL);
        $appName = $this->prompt('Instance name', 'OpenWiki');
        $appUrl = $this->prompt('Application URL', 'http://localhost');
        $timezone = $this->prompt('Timezone', 'UTC');

        fwrite(STDOUT, '[3/5] Database settings' . PHP_EOL);
        $dbHost = $this->prompt('Database host', '127.0.0.1');
        $dbPort = $this->prompt('Database port', '3306');
        $dbName = $this->prompt('Database name', 'openwiki');
        $dbUser = $this->prompt('Database username');
        $dbPassword = $this->promptHidden('Database password');
        $createDatabase = strtolower($this->prompt('Create database if missing? [y/N]', 'n')) === 'y';

        fwrite(STDOUT, '[4/5] Administrator account' . PHP_EOL);
        $adminUsername = $this->prompt('Admin username', 'admin');
        $adminEmail = $this->prompt('Admin email');
        $adminPassword = $this->promptHidden('Admin password (minimum 12 characters)');
        $adminFirstName = $this->prompt('Admin first name', '');
        $adminLastName = $this->prompt('Admin last name', '');

        fwrite(STDOUT, '[5/5] Installing' . PHP_EOL);
        $service->install([
            'app_name' => $appName,
            'app_url' => $appUrl,
            'timezone' => $timezone,
            'db_host' => $dbHost,
            'db_port' => $dbPort,
            'db_database' => $dbName,
            'db_username' => $dbUser,
            'db_password' => $dbPassword,
            'create_database' => $createDatabase,
            'admin_username' => $adminUsername,
            'admin_email' => $adminEmail,
            'admin_password' => $adminPassword,
            'admin_first_name' => $adminFirstName,
            'admin_last_name' => $adminLastName,
        ]);

        fwrite(STDOUT, '[ OK ] OpenWiki installation completed.' . PHP_EOL);
        return 0;
    }

    private function migrate(): int
    {
        $app = Application::boot($this->basePath);
        if (!$app->installed()) {
            throw new \RuntimeException('OpenWiki is not installed.');
        }

        $runner = new MigrationRunner($app->database(), $this->basePath . '/database/migrations');
        $applied = $runner->migrate();

        if ($applied === []) {
            fwrite(STDOUT, '[ OK ] Database is already up to date.' . PHP_EOL);
        } else {
            foreach ($applied as $version) {
                fwrite(STDOUT, '[ OK ] Applied ' . $version . PHP_EOL);
            }
        }

        return 0;
    }

    private function databaseStatus(): int
    {
        $app = Application::boot($this->basePath);
        if (!$app->installed()) {
            throw new \RuntimeException('OpenWiki is not installed.');
        }

        $row = $app->database()->fetchOne('SELECT VERSION() AS version, DATABASE() AS database_name');
        fwrite(STDOUT, '[ OK ] Database: ' . ($row['database_name'] ?? 'unknown') . PHP_EOL);
        fwrite(STDOUT, '[INFO] Server version: ' . ($row['version'] ?? 'unknown') . PHP_EOL);

        $runner = new MigrationRunner($app->database(), $this->basePath . '/database/migrations');
        foreach ($runner->status() as $migration) {
            fwrite(
                STDOUT,
                ($migration['applied'] ? '[ OK ] ' : '[WARN] ')
                . $migration['version']
                . ($migration['applied'] ? ' applied' : ' pending')
                . PHP_EOL
            );
        }

        return 0;
    }

    private function cacheClear(): int
    {
        $path = $this->basePath . '/storage/cache';
        if (!is_dir($path)) {
            fwrite(STDOUT, '[ OK ] Cache directory does not exist.' . PHP_EOL);
            return 0;
        }

        $deleted = 0;
        $iterator = new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS);
        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getFilename() === '.gitkeep') {
                continue;
            }

            if (!@unlink($file->getPathname())) {
                throw new \RuntimeException('Unable to remove cache file: ' . $file->getFilename());
            }
            $deleted++;
        }

        fwrite(STDOUT, '[ OK ] Cache cleared. Removed files: ' . $deleted . PHP_EOL);
        return 0;
    }

    private function cronRun(): int
    {
        $app = Application::boot($this->basePath);
        if (!$app->installed()) {
            throw new \RuntimeException('OpenWiki is not installed.');
        }

        $database = $app->database();
        (new RateLimiter($database))->prune(86400);

        $database->execute(
            'DELETE FROM page_edit_locks WHERE expires_at < UTC_TIMESTAMP()'
        );
        $database->execute(
            'DELETE FROM user_sessions WHERE expires_at < UTC_TIMESTAMP()'
        );

        $expiredDraftCutoff = gmdate('Y-m-d H:i:s', time() - 30 * 86400);
        $database->execute(
            'DELETE FROM draft_autosaves WHERE updated_at < :cutoff',
            ['cutoff' => $expiredDraftCutoff]
        );

        $webhooks = (new WebhookService($database))->processDue(25);
        fwrite(
            STDOUT,
            '[INFO] Webhook deliveries: processed=' . $webhooks['processed']
            . ', delivered=' . $webhooks['delivered']
            . ', retrying=' . $webhooks['retrying']
            . ', failed=' . $webhooks['failed']
            . PHP_EOL
        );

        $database->execute(
            'INSERT INTO settings
             (setting_key, setting_value, is_secret, updated_by, updated_at)
             VALUES ("system.cron_last_run", :last_run, 0, NULL, UTC_TIMESTAMP())
             ON DUPLICATE KEY UPDATE
                setting_value = VALUES(setting_value),
                updated_by = NULL,
                updated_at = UTC_TIMESTAMP()',
            ['last_run' => gmdate(DATE_ATOM)]
        );

        fwrite(STDOUT, '[ OK ] Housekeeping completed.' . PHP_EOL);
        return 0;
    }

    private function systemCheck(): int
    {
        $requirements = (new InstallService($this->basePath))->requirements();
        $failed = false;

        fwrite(STDOUT, '[INFO] PHP: ' . PHP_VERSION . PHP_EOL);
        if (!$requirements['php']['ok']) {
            fwrite(STDOUT, '[FAIL] PHP 8.2+ required.' . PHP_EOL);
            $failed = true;
        }

        foreach ($requirements['extensions'] as $extension => $ok) {
            fwrite(STDOUT, ($ok ? '[ OK ] ' : '[FAIL] ') . 'Extension ' . $extension . PHP_EOL);
            $failed = $failed || !$ok;
        }

        foreach ($requirements['writable'] as $path => $ok) {
            fwrite(STDOUT, ($ok ? '[ OK ] ' : '[FAIL] ') . 'Writable ' . $path . PHP_EOL);
            $failed = $failed || !$ok;
        }

        $app = Application::boot($this->basePath);
        if ($app->installed()) {
            try {
                $row = $app->database()->fetchOne('SELECT 1 AS ok');
                $dbOk = (int) ($row['ok'] ?? 0) === 1;
                fwrite(STDOUT, ($dbOk ? '[ OK ] ' : '[FAIL] ') . 'Database connection' . PHP_EOL);
                $failed = $failed || !$dbOk;
            } catch (\Throwable $exception) {
                fwrite(STDOUT, '[FAIL] Database connection: ' . $exception->getMessage() . PHP_EOL);
                $failed = true;
            }
        } else {
            fwrite(STDOUT, '[WARN] Application is not installed.' . PHP_EOL);
        }

        return $failed ? 1 : 0;
    }

    private function backupCreate(): int
    {
        $app = Application::boot($this->basePath);
        if (!$app->installed()) {
            throw new \RuntimeException('OpenWiki is not installed.');
        }

        fwrite(STDOUT, '[1/2] Creating backup archive' . PHP_EOL);
        $backup = (new BackupService($app->database(), $this->basePath))->create();

        fwrite(STDOUT, '[2/2] Verifying backup archive' . PHP_EOL);
        if (!is_file($backup['path']) || (int) $backup['size_bytes'] < 1) {
            throw new \RuntimeException('Backup verification failed.');
        }

        fwrite(STDOUT, '[ OK ] Backup: ' . $backup['path'] . PHP_EOL);
        fwrite(STDOUT, '[INFO] Size: ' . $backup['size_bytes'] . ' bytes' . PHP_EOL);
        return 0;
    }

    private function searchIndex(): int
    {
        $app = Application::boot($this->basePath);
        if (!$app->installed()) {
            throw new \RuntimeException('OpenWiki is not installed.');
        }

        fwrite(STDOUT, '[1/2] Rebuilding page search content' . PHP_EOL);
        $result = (new SearchIndexer($app->database()))->rebuild();

        fwrite(STDOUT, '[2/2] Search index refresh completed' . PHP_EOL);
        fwrite(STDOUT, '[ OK ] Scanned: ' . $result['scanned'] . PHP_EOL);
        fwrite(STDOUT, '[INFO] Updated: ' . $result['updated'] . PHP_EOL);
        fwrite(STDOUT, '[INFO] Unchanged: ' . $result['unchanged'] . PHP_EOL);
        return 0;
    }

    private function userCreate(): int
    {
        $app = Application::boot($this->basePath);
        if (!$app->installed()) {
            throw new \RuntimeException('OpenWiki is not installed.');
        }

        $database = $app->database();
        $service = new DirectoryAdminService($database);

        fwrite(STDOUT, '[1/3] User details' . PHP_EOL);
        $username = $this->prompt('Username');
        $email = $this->prompt('Email');
        $firstName = $this->prompt('First name', '');
        $lastName = $this->prompt('Last name', '');
        $password = $this->promptHidden('Initial password (minimum 12 characters)');

        fwrite(STDOUT, '[2/3] Default role' . PHP_EOL);
        $roleSlug = $this->prompt('Role slug', 'viewer');
        $role = $database->fetchOne(
            'SELECT id, slug FROM roles WHERE slug = :slug LIMIT 1',
            ['slug' => $roleSlug]
        );
        if ($role === null) {
            throw new \InvalidArgumentException('Role not found: ' . $roleSlug);
        }

        fwrite(STDOUT, '[3/3] Creating user' . PHP_EOL);
        $id = $service->createUser([
            'username' => $username,
            'email' => $email,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'password' => $password,
            'status' => 'active',
            'force_password_change' => 1,
            'role_ids' => [(int) $role['id']],
            'group_ids' => [],
        ]);

        fwrite(STDOUT, '[ OK ] Created user #' . $id . ' with role ' . $role['slug'] . '.' . PHP_EOL);
        return 0;
    }

    private function userDisable(?string $identifier): int
    {
        if ($identifier === null || trim($identifier) === '') {
            throw new \InvalidArgumentException('Usage: php bin/console user:disable <id|username|email>');
        }

        $app = Application::boot($this->basePath);
        if (!$app->installed()) {
            throw new \RuntimeException('OpenWiki is not installed.');
        }

        $database = $app->database();
        $user = $this->resolveUser($database, $identifier);
        if ($user === null) {
            throw new \InvalidArgumentException('User not found: ' . $identifier);
        }

        (new DirectoryAdminService($database))->disableUser((int) $user['id']);
        fwrite(STDOUT, '[ OK ] Disabled user ' . $user['username'] . ' (#' . $user['id'] . ').' . PHP_EOL);
        return 0;
    }

    private function adminResetPassword(?string $identifier): int
    {
        if ($identifier === null || trim($identifier) === '') {
            throw new \InvalidArgumentException('Usage: php bin/console admin:reset-password <id|username|email>');
        }

        $app = Application::boot($this->basePath);
        if (!$app->installed()) {
            throw new \RuntimeException('OpenWiki is not installed.');
        }

        $database = $app->database();
        $user = $this->resolveUser($database, $identifier);
        if ($user === null) {
            throw new \InvalidArgumentException('User not found: ' . $identifier);
        }

        $password = (new DirectoryAdminService($database))->resetPassword((int) $user['id']);
        fwrite(STDOUT, '[ OK ] Password reset for ' . $user['username'] . ' (#' . $user['id'] . ').' . PHP_EOL);
        fwrite(STDOUT, '[INFO] Temporary password: ' . $password . PHP_EOL);
        fwrite(STDOUT, '[INFO] User must change it on next login.' . PHP_EOL);
        return 0;
    }

    private function resolveUser(Database $database, string $identifier): ?array
    {
        $identifier = trim($identifier);
        if (ctype_digit($identifier) && (int) $identifier > 0) {
            return $database->fetchOne(
                'SELECT id, username, email FROM users WHERE id = :id AND deleted_at IS NULL LIMIT 1',
                ['id' => (int) $identifier]
            );
        }

        return $database->fetchOne(
            'SELECT id, username, email
             FROM users
             WHERE deleted_at IS NULL
               AND (LOWER(username) = LOWER(:username) OR LOWER(email) = LOWER(:email))
             LIMIT 1',
            ['username' => $identifier, 'email' => $identifier]
        );
    }

    private function prompt(string $label, ?string $default = null): string
    {
        $suffix = $default !== null && $default !== '' ? ' [' . $default . ']' : '';
        fwrite(STDOUT, $label . $suffix . ': ');
        $value = fgets(STDIN);
        if ($value === false) {
            throw new \RuntimeException('Unable to read from STDIN.');
        }

        $value = trim($value);
        return $value === '' && $default !== null ? $default : $value;
    }

    private function promptHidden(string $label): string
    {
        fwrite(STDOUT, $label . ': ');

        if (PHP_OS_FAMILY !== 'Windows' && function_exists('shell_exec')) {
            $stty = shell_exec('stty -g 2>/dev/null');
            if (is_string($stty) && trim($stty) !== '') {
                shell_exec('stty -echo 2>/dev/null');
                $value = fgets(STDIN);
                shell_exec('stty ' . escapeshellarg(trim($stty)) . ' 2>/dev/null');
                fwrite(STDOUT, PHP_EOL);
                if ($value === false) {
                    throw new \RuntimeException('Unable to read from STDIN.');
                }
                return rtrim($value, "\r\n");
            }
        }

        $value = fgets(STDIN);
        if ($value === false) {
            throw new \RuntimeException('Unable to read from STDIN.');
        }
        return rtrim($value, "\r\n");
    }

    private function unknown(string $command): int
    {
        fwrite(STDERR, '[FAIL] Unknown command: ' . $command . PHP_EOL);
        fwrite(STDERR, 'Run: php bin/console help' . PHP_EOL);
        return 2;
    }
}
