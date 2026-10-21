<?php

declare(strict_types=1);

namespace OpenWiki\Tests\Integration;

use OpenWiki\Core\Database;
use OpenWiki\Install\InstallService;
use PHPUnit\Framework\TestCase;

final class InstallServiceTest extends TestCase
{
    private string $basePath;
    private string $databaseName;

    protected function setUp(): void
    {
        if (!getenv('OPENWIKI_TEST_DB_HOST')) {
            self::markTestSkipped('MySQL integration environment is not configured.');
        }

        $this->basePath = sys_get_temp_dir() . '/openwiki-install-test-' . bin2hex(random_bytes(8));
        mkdir($this->basePath . '/database/migrations', 0770, true);
        mkdir($this->basePath . '/public/assets', 0770, true);
        foreach (glob(dirname(__DIR__, 2) . '/database/migrations/*.php') as $file) copy($file, $this->basePath . '/database/migrations/' . basename($file));
        foreach (['wiki-editor.js', 'diagram.js', 'document-reader.js', 'diagram.html'] as $asset) copy(dirname(__DIR__, 2) . '/public/assets/' . $asset, $this->basePath . '/public/assets/' . $asset);
        $this->databaseName = 'openwiki_install_' . bin2hex(random_bytes(6)) . '_test';
        $this->server()->pdo()->exec('CREATE DATABASE `' . $this->databaseName . '` CHARACTER SET utf8mb4');
    }

    protected function tearDown(): void
    {
        if (!isset($this->basePath)) return;
        if (isset($this->databaseName)) $this->server()->pdo()->exec('DROP DATABASE `' . $this->databaseName . '`');
        if (!str_starts_with($this->basePath, sys_get_temp_dir() . '/openwiki-install-test-')) throw new \RuntimeException('Unexpected test directory.');
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->basePath, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        rmdir($this->basePath);
    }

    public function testDatabaseConnectionSucceedsForConfiguredDatabase(): void
    {
        $result = (new InstallService($this->basePath))->testDatabaseConnection($this->input());

        self::assertTrue($result['ok']);
        self::assertTrue($result['database_exists']);
    }

    public function testMissingBuiltEditorBlocksInstallationBeforeDatabaseChanges(): void
    {
        unlink($this->basePath . '/public/assets/wiki-editor.js');
        $requirements = (new InstallService($this->basePath))->requirements();
        self::assertFalse($requirements['ok']);
        self::assertFalse($requirements['assets']['Compiled editor assets']);
        $this->expectExceptionMessage('Server requirements are not satisfied.');
        (new InstallService($this->basePath))->install([]);
    }

    public function testAnEmptySecondaryBundleBlocksInstallation(): void
    {
        file_put_contents($this->basePath . '/public/assets/diagram.js', '');
        clearstatcache();
        self::assertFalse((new InstallService($this->basePath))->requirements()['assets']['Compiled editor assets']);
    }

    public function testFreshInstallCreatesSchemaAdminRolesAndLock(): void
    {
        (new InstallService($this->basePath))->install($this->input());

        self::assertFileExists($this->basePath . '/.env');
        self::assertFileExists($this->basePath . '/storage/installed.lock');

        $db = $this->database();
        $admin = $db->fetchOne('SELECT * FROM users WHERE username = "admin"');
        self::assertNotNull($admin);
        self::assertSame('admin@example.test', $admin['email']);
        self::assertTrue(password_verify($this->input()['admin_password'], $admin['password_hash']));
        self::assertSame(0, (int) $admin['force_password_change']);

        $role = $db->fetchOne(
            'SELECT r.slug
             FROM roles r
             INNER JOIN user_roles ur ON ur.role_id = r.id
             WHERE ur.user_id = :user_id AND r.slug = "super-admin"',
            ['user_id' => (int) $admin['id']]
        );
        self::assertSame('super-admin', $role['slug'] ?? null);

        $migration = $db->fetchOne(
            'SELECT version FROM schema_migrations WHERE version = "001_initial"'
        );
        self::assertSame('001_initial', $migration['version'] ?? null);

        $templates = $db->fetchOne(
            'SELECT COUNT(*) AS total FROM page_templates WHERE is_system = 1'
        );
        self::assertGreaterThanOrEqual(5, (int) ($templates['total'] ?? 0));
        self::assertFileDoesNotExist($this->basePath . '/storage/installation.json');
        $before = file_get_contents($this->basePath . '/.env');
        try { (new InstallService($this->basePath))->install($this->input()); self::fail('Reinstallation must fail.'); }
        catch (\RuntimeException $error) { self::assertSame('OpenWiki is already installed.', $error->getMessage()); }
        self::assertSame($before, file_get_contents($this->basePath . '/.env'));
    }

    public function testExistingDatabaseIsNeverModified(): void
    {
        $db = $this->database();
        $db->pdo()->exec('CREATE TABLE existing_data (value INT)');
        $db->pdo()->exec('INSERT INTO existing_data VALUES (42)');
        $this->expectExceptionMessage('The database is not empty.');
        try { (new InstallService($this->basePath))->install($this->input()); }
        finally {
            self::assertSame(42, (int) $db->fetchOne('SELECT value FROM existing_data')['value']);
            self::assertCount(1, $db->fetchAll('SHOW TABLES'));
            self::assertFileDoesNotExist($this->basePath . '/.env');
        }
    }

    public function testParallelInstallationIsRejectedBeforeDatabaseWrites(): void
    {
        $service = new InstallService($this->basePath);
        $service->requirements();
        $handle = fopen($this->basePath . '/storage/installation.mutex', 'c');
        flock($handle, LOCK_EX);
        try {
            $this->expectExceptionMessage('Another installation is running.');
            $service->install($this->input());
        } finally {
            flock($handle, LOCK_UN); fclose($handle);
            self::assertSame([], $this->database()->fetchAll('SHOW TABLES'));
        }
    }

    public function testFailedConfigurationWriteCanResumeWithoutChangingAdministratorOrKey(): void
    {
        // A directory at the .env destination simulates a configuration activation failure.
        mkdir($this->basePath . '/.env');
        $service = new InstallService($this->basePath);
        try { $service->install($this->input()); self::fail('Configuration activation must fail.'); }
        catch (\RuntimeException $error) { self::assertStringContainsString('Unable to activate', $error->getMessage()); }
        $state = json_decode(file_get_contents($this->basePath . '/storage/installation.json'), true, 512, JSON_THROW_ON_ERROR);
        $admin = $this->database()->fetchOne('SELECT * FROM users');
        rmdir($this->basePath . '/.env');
        $service->install($this->input());
        self::assertSame($admin['password_hash'], $this->database()->fetchOne('SELECT * FROM users')['password_hash']);
        self::assertStringContainsString($state['key'], file_get_contents($this->basePath . '/.env'));
        self::assertSame(1, (int) $this->database()->fetchOne('SELECT COUNT(*) AS total FROM users')['total']);
    }

    public function testLostInstallationMarkerCannotResetAnExistingAccount(): void
    {
        $service = new InstallService($this->basePath);
        $service->install($this->input());
        unlink($this->basePath . '/storage/installed.lock');
        $this->expectExceptionMessage('Existing application configuration found.');
        $service->install($this->input());
    }

    public function testInvalidInputIsRejectedBeforeDatabaseChanges(): void
    {
        $service = new InstallService($this->basePath);
        foreach ([['db_host' => 'localhost;dbname=other'], ['admin_password' => 'admin'], ['admin_password' => str_repeat('x', 73)],
            ['admin_email' => 'invalid'], ['app_url' => 'https://example.test/subdirectory'], ['db_database' => str_repeat('x', 65)], ['app_name' => ['invalid']]] as $invalid) {
            try { $service->install(array_replace($this->input(), $invalid)); self::fail('Invalid input accepted.'); }
            catch (\InvalidArgumentException) { self::assertSame([], $this->database()->fetchAll('SHOW TABLES')); }
        }
    }

    private function input(): array
    {
        return ['app_name' => 'OpenWiki Test', 'app_url' => 'http://localhost', 'timezone' => 'UTC',
            'db_host' => getenv('OPENWIKI_TEST_DB_HOST'), 'db_port' => getenv('OPENWIKI_TEST_DB_PORT'),
            'db_database' => $this->databaseName, 'db_username' => getenv('OPENWIKI_TEST_DB_USERNAME'),
            'db_password' => getenv('OPENWIKI_TEST_DB_PASSWORD'), 'create_database' => false,
            'admin_username' => 'admin', 'admin_email' => 'admin@example.test', 'admin_password' => 'Install-Test-Password!42'];
    }

    private function server(): Database
    {
        return Database::connect(['host' => getenv('OPENWIKI_TEST_DB_HOST'), 'port' => getenv('OPENWIKI_TEST_DB_PORT'),
            'username' => getenv('OPENWIKI_TEST_DB_USERNAME'), 'password' => getenv('OPENWIKI_TEST_DB_PASSWORD')], false);
    }

    private function database(): Database
    {
        return Database::connect([
            'host' => (string) getenv('OPENWIKI_TEST_DB_HOST'),
            'port' => (int) getenv('OPENWIKI_TEST_DB_PORT'),
            'database' => $this->databaseName,
            'username' => (string) getenv('OPENWIKI_TEST_DB_USERNAME'),
            'password' => (string) getenv('OPENWIKI_TEST_DB_PASSWORD'),
            'charset' => 'utf8mb4',
        ]);
    }
}
