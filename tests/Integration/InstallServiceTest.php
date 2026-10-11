<?php

declare(strict_types=1);

namespace OpenWiki\Tests\Integration;

use OpenWiki\Core\Database;
use OpenWiki\Install\InstallService;
use PHPUnit\Framework\TestCase;

final class InstallServiceTest extends TestCase
{
    private string $basePath;

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
    }

    protected function tearDown(): void
    {
        if (!isset($this->basePath)) return;
        if (!str_starts_with($this->basePath, sys_get_temp_dir() . '/openwiki-install-test-')) throw new \RuntimeException('Unexpected test directory.');
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->basePath, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        rmdir($this->basePath);
    }

    public function testDatabaseConnectionSucceedsForConfiguredDatabase(): void
    {
        $result = (new InstallService($this->basePath))->testDatabaseConnection([
            'db_host' => getenv('OPENWIKI_TEST_DB_HOST'),
            'db_port' => getenv('OPENWIKI_TEST_DB_PORT'),
            'db_database' => getenv('OPENWIKI_TEST_DB_DATABASE'),
            'db_username' => getenv('OPENWIKI_TEST_DB_USERNAME'),
            'db_password' => getenv('OPENWIKI_TEST_DB_PASSWORD'),
            'create_database' => false,
        ]);

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

    public function testFreshInstallCreatesSchemaAdminRolesAndLock(): void
    {
        (new InstallService($this->basePath))->install([
            'app_name' => 'OpenWiki Test',
            'app_url' => 'http://localhost',
            'timezone' => 'UTC',
            'db_host' => getenv('OPENWIKI_TEST_DB_HOST'),
            'db_port' => getenv('OPENWIKI_TEST_DB_PORT'),
            'db_database' => getenv('OPENWIKI_TEST_DB_DATABASE'),
            'db_username' => getenv('OPENWIKI_TEST_DB_USERNAME'),
            'db_password' => getenv('OPENWIKI_TEST_DB_PASSWORD'),
            'create_database' => false,
        ]);

        self::assertFileExists($this->basePath . '/.env');
        self::assertFileExists($this->basePath . '/storage/installed.lock');

        $db = $this->database();
        $admin = $db->fetchOne('SELECT * FROM users WHERE username = "admin"');
        self::assertNotNull($admin);
        self::assertSame('admin@localhost.invalid', $admin['email']);
        self::assertTrue(password_verify('admin', $admin['password_hash']));
        self::assertSame(1, (int) $admin['force_password_change']);

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
    }

    private function database(): Database
    {
        return Database::connect([
            'host' => (string) getenv('OPENWIKI_TEST_DB_HOST'),
            'port' => (int) getenv('OPENWIKI_TEST_DB_PORT'),
            'database' => (string) getenv('OPENWIKI_TEST_DB_DATABASE'),
            'username' => (string) getenv('OPENWIKI_TEST_DB_USERNAME'),
            'password' => (string) getenv('OPENWIKI_TEST_DB_PASSWORD'),
            'charset' => 'utf8mb4',
        ]);
    }
}
