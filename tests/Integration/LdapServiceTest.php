<?php

declare(strict_types=1);

namespace OpenWiki\Tests\Integration;

use OpenWiki\Auth\LdapService;
use OpenWiki\Core\Database;
use OpenWiki\Database\MigrationRunner;
use PHPUnit\Framework\TestCase;

final class LdapServiceTest extends TestCase
{
    private Database $database;
    private string|false $oldAppKey;

    protected function setUp(): void
    {
        if (!getenv('OPENWIKI_TEST_DB_HOST')) {
            self::markTestSkipped('MySQL integration environment is not configured.');
        }

        $this->oldAppKey = getenv('APP_KEY');
        putenv('APP_KEY=' . str_repeat('e', 64));

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

        if ($this->oldAppKey === false) {
            putenv('APP_KEY');
        } else {
            putenv('APP_KEY=' . $this->oldAppKey);
        }
    }

    public function testConfigurationEncryptsBindPasswordAndDoesNotExposeIt(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $actorId = $this->createLocalUser('ldap-admin-' . $suffix);
        $roleId = $this->createRole('ldap-editor-' . $suffix);

        $service = new LdapService($this->database);
        $service->saveConfig([
            'enabled' => '1',
            'host' => 'ldap.example.test',
            'port' => '636',
            'security' => 'ldaps',
            'base_dn' => 'DC=example,DC=test',
            'bind_dn' => 'CN=OpenWiki,OU=Service,DC=example,DC=test',
            'bind_password' => 'DirectorySecret!42',
            'user_dn' => 'OU=Users,DC=example,DC=test',
            'group_dn' => 'OU=Groups,DC=example,DC=test',
            'username_attribute' => 'sAMAccountName',
            'mail_attribute' => 'mail',
            'first_name_attribute' => 'givenName',
            'last_name_attribute' => 'sn',
            'group_attribute' => 'memberOf',
            'group_mapping' => 'CN=Wiki Editors,OU=Groups,DC=example,DC=test => ldap-editor-' . $suffix,
        ], $actorId);

        $stored = $this->database->fetchOne(
            'SELECT setting_value, is_secret
             FROM settings
             WHERE setting_key = "ldap.bind_password"'
        );

        self::assertNotNull($stored);
        self::assertSame(1, (int) $stored['is_secret']);
        self::assertNotSame('DirectorySecret!42', $stored['setting_value']);
        self::assertStringNotContainsString('DirectorySecret!42', (string) $stored['setting_value']);

        $display = $service->displayConfig();
        self::assertTrue($display['enabled']);
        self::assertTrue($display['bind_password_set']);
        self::assertArrayNotHasKey('bind_password', $display);
        self::assertStringContainsString('ldap-editor-' . $suffix, $display['group_mapping']);

        self::assertGreaterThan(0, $roleId);
    }

    public function testProvisionSynchronizesOnlyLdapManagedAccess(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $mappedRoleId = $this->createRole('mapped-' . $suffix);
        $manualRoleId = $this->createRole('manual-' . $suffix);
        $groupDn = 'CN=Wiki Editors,OU=Groups,DC=example,DC=test';

        $service = new LdapService($this->database);
        $profile = [
            'username' => 'ldap-user-' . $suffix,
            'email' => 'ldap-user-' . $suffix . '@example.test',
            'first_name' => 'LDAP',
            'last_name' => 'User',
            'groups' => [$groupDn],
            'group_mapping' => [$groupDn => 'mapped-' . $suffix],
        ];

        $userId = $service->provision($profile);
        $user = $this->database->fetchOne(
            'SELECT username, email, password_hash, auth_source, status
             FROM users WHERE id = :id',
            ['id' => $userId]
        );

        self::assertNotNull($user);
        self::assertSame('ldap', $user['auth_source']);
        self::assertNull($user['password_hash']);
        self::assertSame('active', $user['status']);

        $mapped = $this->database->fetchOne(
            'SELECT ldap_group FROM ldap_user_roles WHERE user_id = :user_id AND role_id = :role_id',
            ['user_id' => $userId, 'role_id' => $mappedRoleId]
        );
        self::assertSame($groupDn, $mapped['ldap_group'] ?? null);

        $ldapGroup = $this->database->fetchOne(
            'SELECT g.id
             FROM user_groups g
             INNER JOIN group_users gu ON gu.group_id = g.id
             WHERE gu.user_id = :user_id AND g.source = "ldap" AND g.external_id = :external_id',
            ['user_id' => $userId, 'external_id' => $groupDn]
        );
        self::assertNotNull($ldapGroup);

        $this->database->execute(
            'INSERT INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id)',
            ['user_id' => $userId, 'role_id' => $manualRoleId]
        );

        $profile['groups'] = [];
        $service->provision($profile);

        self::assertNull($this->database->fetchOne(
            'SELECT 1 AS found FROM user_roles WHERE user_id = :user_id AND role_id = :role_id',
            ['user_id' => $userId, 'role_id' => $mappedRoleId]
        ));
        self::assertNotNull($this->database->fetchOne(
            'SELECT 1 AS found FROM user_roles WHERE user_id = :user_id AND role_id = :role_id',
            ['user_id' => $userId, 'role_id' => $manualRoleId]
        ));
        self::assertNull($this->database->fetchOne(
            'SELECT 1 AS found
             FROM group_users gu
             INNER JOIN user_groups g ON g.id = gu.group_id
             WHERE gu.user_id = :user_id AND g.source = "ldap"',
            ['user_id' => $userId]
        ));
    }

    public function testProvisionRejectsCollisionWithLocalAccount(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $this->createLocalUser('collision-' . $suffix);

        $service = new LdapService($this->database);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('conflicts with an existing local account');

        $service->provision([
            'username' => 'collision-' . $suffix,
            'email' => 'different-' . $suffix . '@example.test',
            'first_name' => 'LDAP',
            'last_name' => 'Collision',
            'groups' => [],
            'group_mapping' => [],
        ]);
    }

    private function createLocalUser(string $username): int
    {
        return $this->database->insert(
            'INSERT INTO users
             (username, email, password_hash, status, auth_source, force_password_change, created_at, updated_at)
             VALUES (:username, :email, :password_hash, "active", "local", 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            [
                'username' => $username,
                'email' => $username . '@example.test',
                'password_hash' => password_hash('CorrectHorseBatteryStaple!42', PASSWORD_DEFAULT),
            ]
        );
    }

    private function createRole(string $slug): int
    {
        return $this->database->insert(
            'INSERT INTO roles (name, slug, description, is_system, created_at, updated_at)
             VALUES (:name, :slug, NULL, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            [
                'name' => strtoupper($slug),
                'slug' => $slug,
            ]
        );
    }
}
