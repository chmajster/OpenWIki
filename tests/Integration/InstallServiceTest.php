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
        $this->basePath = dirname(__DIR__, 2);

        if (!getenv('OPENWIKI_TEST_DB_HOST')) {
            self::markTestSkipped('MySQL integration environment is not configured.');
        }

        @unlink($this->basePath . '/.env');
        @unlink($this->basePath . '/storage/installed.lock');
    }

    protected function tearDown(): void
    {
        @unlink($this->basePath . '/.env');
        @unlink($this->basePath . '/storage/installed.lock');
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
            'admin_username' => 'admin',
            'admin_email' => 'admin@example.test',
            'admin_password' => 'CorrectHorseBatteryStaple!42',
            'admin_first_name' => 'OpenWiki',
            'admin_last_name' => 'Admin',
        ]);

        self::assertFileExists($this->basePath . '/.env');
        self::assertFileExists($this->basePath . '/storage/installed.lock');

        $db = $this->database();
        $admin = $db->fetchOne('SELECT * FROM users WHERE username = "admin"');
        self::assertNotNull($admin);
        self::assertTrue(password_verify('CorrectHorseBatteryStaple!42', $admin['password_hash']));

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
