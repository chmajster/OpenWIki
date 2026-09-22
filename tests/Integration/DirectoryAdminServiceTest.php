<?php

declare(strict_types=1);

namespace OpenWiki\Tests\Integration;

use OpenWiki\Admin\DirectoryAdminService;
use OpenWiki\Core\Database;
use OpenWiki\Database\MigrationRunner;
use PHPUnit\Framework\TestCase;

final class DirectoryAdminServiceTest extends TestCase
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

    public function testLocalDirectoryAdministrationLifecycle(): void
    {
        $service = new DirectoryAdminService($this->database);
        $suffix = bin2hex(random_bytes(4));

        $permission = $this->database->fetchOne(
            'SELECT id FROM permissions WHERE name = "page.view" LIMIT 1'
        );
        self::assertNotNull($permission);

        $roleId = $service->saveRole(null, [
            'name' => 'Test Role ' . $suffix,
            'slug' => 'test-role-' . $suffix,
            'description' => 'Integration role',
            'permission_ids' => [(int) $permission['id']],
        ]);
        self::assertNotNull($service->role($roleId));

        $groupId = $service->saveGroup(null, [
            'name' => 'Test Group ' . $suffix,
            'slug' => 'test-group-' . $suffix,
            'description' => 'Integration group',
            'user_ids' => [],
        ]);
        self::assertNotNull($service->group($groupId));

        $userId = $service->createUser([
            'username' => 'admin-test-' . $suffix,
            'email' => 'admin-test-' . $suffix . '@example.test',
            'first_name' => 'Admin',
            'last_name' => 'Test',
            'status' => 'active',
            'force_password_change' => '1',
            'password' => 'CorrectHorseBatteryStaple!42',
            'role_ids' => [$roleId],
            'group_ids' => [$groupId],
        ]);

        $user = $service->user($userId);
        self::assertNotNull($user);
        self::assertContains($roleId, $user['role_ids']);
        self::assertContains($groupId, $user['group_ids']);
        self::assertSame(1, (int) $user['force_password_change']);

        $temporary = $service->resetPassword($userId);
        self::assertGreaterThanOrEqual(12, strlen($temporary));

        $stored = $this->database->fetchOne(
            'SELECT password_hash, force_password_change FROM users WHERE id = :id',
            ['id' => $userId]
        );
        self::assertTrue(password_verify($temporary, $stored['password_hash']));
        self::assertSame(1, (int) $stored['force_password_change']);

        $service->updateUser($userId, [
            'username' => 'admin-test-' . $suffix,
            'email' => 'updated-' . $suffix . '@example.test',
            'first_name' => 'Updated',
            'last_name' => 'User',
            'status' => 'disabled',
            'force_password_change' => '',
            'role_ids' => [],
            'group_ids' => [],
        ]);

        $updated = $service->user($userId);
        self::assertSame('disabled', $updated['status']);
        self::assertSame([], $updated['role_ids']);
        self::assertSame([], $updated['group_ids']);

        self::assertTrue($service->softDeleteUser($userId));
        self::assertNull($service->user($userId));

        self::assertTrue($service->deleteGroup($groupId));
        self::assertTrue($service->deleteRole($roleId));
    }

    public function testDisableUserKeepsAccountAndRevokesSessions(): void
    {
        $service = new DirectoryAdminService($this->database);
        $suffix = bin2hex(random_bytes(4));

        $userId = $service->createUser([
            'username' => 'disable-test-' . $suffix,
            'email' => 'disable-test-' . $suffix . '@example.test',
            'first_name' => 'Disable',
            'last_name' => 'Test',
            'status' => 'active',
            'force_password_change' => 0,
            'password' => 'CorrectHorseBatteryStaple!42',
            'role_ids' => [],
            'group_ids' => [],
        ]);

        $this->database->execute(
            'INSERT INTO user_sessions
             (session_id, user_id, ip_address, user_agent, last_activity_at, expires_at)
             VALUES
             (:session_id, :user_id, "127.0.0.1", "phpunit", UTC_TIMESTAMP(), DATE_ADD(UTC_TIMESTAMP(), INTERVAL 1 HOUR))',
            ['session_id' => 'test-session-' . $suffix, 'user_id' => $userId]
        );

        self::assertTrue($service->disableUser($userId));

        $row = $this->database->fetchOne(
            'SELECT status, deleted_at FROM users WHERE id = :id',
            ['id' => $userId]
        );
        self::assertSame('disabled', $row['status']);
        self::assertNull($row['deleted_at']);

        $session = $this->database->fetchOne(
            'SELECT session_id FROM user_sessions WHERE user_id = :user_id',
            ['user_id' => $userId]
        );
        self::assertNull($session);
        self::assertNotNull($service->user($userId));
    }

}
