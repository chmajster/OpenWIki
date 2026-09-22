<?php

declare(strict_types=1);

namespace OpenWiki\Tests\Integration;

use OpenWiki\Auth\ApiTokenService;
use OpenWiki\Core\Database;
use OpenWiki\Database\MigrationRunner;
use PHPUnit\Framework\TestCase;

final class ApiTokenServiceTest extends TestCase
{
    private Database $database;

    protected function setUp(): void
    {
        if (!getenv('OPENWIKI_TEST_DB_HOST')) {
            self::markTestSkipped('MySQL integration environment is not configured.');
        }

        $this->database = Database::connect([
            'host' => (string) getenv('OPENWIKI_TEST_DB_HOST'),
            'port' => (int) getenv('OPENWIKI_TEST_DB_PORT'),
            'database' => (string) getenv('OPENWIKI_TEST_DB_DATABASE'),
            'username' => (string) getenv('OPENWIKI_TEST_DB_USERNAME'),
            'password' => (string) getenv('OPENWIKI_TEST_DB_PASSWORD'),
            'charset' => 'utf8mb4',
        ]);

        (new MigrationRunner($this->database, dirname(__DIR__, 2) . '/database/migrations'))->migrate();
        $this->database->pdo()->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->database) && $this->database->pdo()->inTransaction()) {
            $this->database->pdo()->rollBack();
        }
    }

    public function testTokenIsShownOnceStoredAsHashAndAuthenticatesWithScopes(): void
    {
        $userId = $this->database->insert(
            'INSERT INTO users
             (username, email, password_hash, status, auth_source, created_at, updated_at)
             VALUES (:username, :email, :password_hash, "active", "local", UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            [
                'username' => 'api-' . bin2hex(random_bytes(4)),
                'email' => bin2hex(random_bytes(4)) . '@api.example.test',
                'password_hash' => password_hash('CorrectHorseBatteryStaple!42', PASSWORD_DEFAULT),
            ]
        );

        $permissionId = $this->database->insert(
            'INSERT INTO permissions (name, description, created_at)
             VALUES (:name, NULL, UTC_TIMESTAMP())',
            ['name' => 'page.view.' . bin2hex(random_bytes(4))]
        );
        $roleId = $this->database->insert(
            'INSERT INTO roles (name, slug, is_system, created_at, updated_at)
             VALUES (:name, :slug, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            ['name' => 'API Test', 'slug' => 'api-test-' . bin2hex(random_bytes(4))]
        );
        $this->database->execute(
            'INSERT INTO role_permissions (role_id, permission_id) VALUES (:role_id, :permission_id)',
            ['role_id' => $roleId, 'permission_id' => $permissionId]
        );
        $this->database->execute(
            'INSERT INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id)',
            ['user_id' => $userId, 'role_id' => $roleId]
        );

        $service = new ApiTokenService($this->database);
        $created = $service->create($userId, 'CI', ['pages:read', 'search:read'], null);

        self::assertStringStartsWith('owk_', $created['token']);

        $stored = $this->database->fetchOne(
            'SELECT token_hash FROM api_tokens WHERE id = :id',
            ['id' => $created['id']]
        );
        self::assertNotSame($created['token'], $stored['token_hash']);
        self::assertSame(hash('sha256', $created['token']), $stored['token_hash']);

        $context = $service->authenticate('Bearer ' . $created['token']);
        self::assertNotNull($context);
        self::assertSame($userId, $context['user']['id']);
        self::assertTrue($service->hasScope($context, 'pages:read'));
        self::assertFalse($service->hasScope($context, 'pages:write'));

        self::assertTrue($service->revoke($userId, (int) $created['id']));
        self::assertNull($service->authenticate('Bearer ' . $created['token']));
    }
}
