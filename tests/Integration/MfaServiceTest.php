<?php

declare(strict_types=1);

namespace OpenWiki\Tests\Integration;

use OpenWiki\Auth\MfaService;
use OpenWiki\Core\Database;
use OpenWiki\Database\MigrationRunner;
use PHPUnit\Framework\TestCase;

final class MfaServiceTest extends TestCase
{
    private Database $database;
    private string|false $oldAppKey;

    protected function setUp(): void
    {
        if (!getenv('OPENWIKI_TEST_DB_HOST')) {
            self::markTestSkipped('MySQL integration environment is not configured.');
        }

        $this->oldAppKey = getenv('APP_KEY');
        putenv('APP_KEY=' . str_repeat('d', 64));

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

    public function testTotpEnrollmentRecoveryCodesAndRolePolicy(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $userId = $this->database->insert(
            'INSERT INTO users
             (username, email, password_hash, status, auth_source, force_password_change, created_at, updated_at)
             VALUES
             (:username, :email, :password_hash, "active", "local", 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            [
                'username' => 'mfa-' . $suffix,
                'email' => 'mfa-' . $suffix . '@example.test',
                'password_hash' => password_hash('CorrectHorseBatteryStaple!42', PASSWORD_DEFAULT),
            ]
        );
        $roleId = $this->database->insert(
            'INSERT INTO roles (name, slug, description, is_system, created_at, updated_at)
             VALUES (:name, :slug, NULL, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            [
                'name' => 'MFA Role ' . $suffix,
                'slug' => 'mfa-role-' . $suffix,
            ]
        );
        $this->database->execute(
            'INSERT INTO user_roles (user_id, role_id) VALUES (:user_id, :role_id)',
            ['user_id' => $userId, 'role_id' => $roleId]
        );

        $service = new MfaService($this->database);
        $service->updatePolicy(false, [$roleId], $userId);

        self::assertTrue($service->required($userId));
        self::assertFalse($service->enabled($userId));

        $enrollment = $service->enrollment($userId, 'mfa-' . $suffix, 'OpenWiki Test');
        self::assertNotEmpty($enrollment['secret']);
        self::assertStringStartsWith('otpauth://totp/', $enrollment['otpauth_uri']);

        $code = $service->currentCode($enrollment['secret']);
        $recoveryCodes = $service->confirmEnrollment($userId, $code);

        self::assertCount(10, $recoveryCodes);
        self::assertTrue($service->enabled($userId));
        self::assertTrue($service->verifyUserCode($userId, $service->currentCode($enrollment['secret'])));

        $recovery = $recoveryCodes[0];
        self::assertTrue($service->verifyUserCode($userId, $recovery));
        self::assertFalse($service->verifyUserCode($userId, $recovery));
    }

    public function testTotpMatchesRfc6238SixDigitVector(): void
    {
        $service = new MfaService($this->database);
        $secret = 'GEZDGNBVGY3TQOJQGEZDGNBVGY3TQOJQ';

        self::assertSame('287082', $service->currentCode($secret, 59));
        self::assertTrue($service->verifyTotp($secret, '287082', 59, 0));
        self::assertFalse($service->verifyTotp($secret, '287083', 59, 0));
    }
}
