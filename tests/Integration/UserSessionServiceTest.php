<?php

declare(strict_types=1);

namespace OpenWiki\Tests\Integration;

use OpenWiki\Auth\UserSessionService;
use OpenWiki\Core\Database;
use OpenWiki\Database\MigrationRunner;
use PHPUnit\Framework\TestCase;

final class UserSessionServiceTest extends TestCase
{
    private Database $database;
    private string|false $oldLifetime;

    protected function setUp(): void
    {
        if (!getenv('OPENWIKI_TEST_DB_HOST')) {
            self::markTestSkipped('MySQL integration environment is not configured.');
        }

        $this->oldLifetime = getenv('SESSION_LIFETIME');
        putenv('SESSION_LIFETIME=7200');

        if (session_status() !== PHP_SESSION_ACTIVE) {
            if (!session_start()) {
                self::fail('Unable to start PHP session for integration test.');
            }
        }
        if (session_id() === '') {
            self::fail('PHP session ID is unavailable.');
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

        if ($this->oldLifetime === false) {
            putenv('SESSION_LIFETIME');
        } else {
            putenv('SESSION_LIFETIME=' . $this->oldLifetime);
        }
    }

    public function testRegisterValidateRotateAndRevokeSessions(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $userId = $this->database->insert(
            'INSERT INTO users
             (username, email, password_hash, status, auth_source, force_password_change, created_at, updated_at)
             VALUES (:username, :email, :password_hash, "active", "local", 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            [
                'username' => 'session-' . $suffix,
                'email' => 'session-' . $suffix . '@example.test',
                'password_hash' => password_hash('CorrectHorseBatteryStaple!42', PASSWORD_DEFAULT),
            ]
        );

        $service = new UserSessionService($this->database);
        $service->registerCurrent($userId, '127.0.0.1', 'PHPUnit Browser');

        self::assertTrue($service->validateAndTouchCurrent($userId, '127.0.0.2', 'PHPUnit Browser 2'));

        $sessions = $service->activeForUser($userId);
        self::assertCount(1, $sessions);
        self::assertTrue($sessions[0]['current']);
        self::assertSame('127.0.0.2', $sessions[0]['ip_address']);
        self::assertSame(64, strlen($sessions[0]['fingerprint']));
        self::assertStringNotContainsString(session_id(), $sessions[0]['label']);

        $oldSessionId = session_id();
        session_regenerate_id(true);
        self::assertNotSame($oldSessionId, session_id());

        $service->replaceAfterRegeneration(
            $oldSessionId,
            $userId,
            '127.0.0.3',
            'PHPUnit MFA'
        );

        self::assertNull($this->database->fetchOne(
            'SELECT session_id FROM user_sessions WHERE session_id = :session_id',
            ['session_id' => $oldSessionId]
        ));
        self::assertNotNull($this->database->fetchOne(
            'SELECT session_id FROM user_sessions WHERE session_id = :session_id AND user_id = :user_id',
            ['session_id' => session_id(), 'user_id' => $userId]
        ));

        $sessions = $service->activeForUser($userId);
        self::assertCount(1, $sessions);
        self::assertTrue($service->revokeByFingerprint($userId, $sessions[0]['fingerprint']));
        self::assertFalse($service->validateAndTouchCurrent($userId, '127.0.0.3', 'PHPUnit MFA'));

        $service->registerCurrent($userId, '127.0.0.4', 'PHPUnit Browser');
        self::assertSame(1, $service->revokeAll($userId));
        self::assertSame([], $service->activeForUser($userId));
    }
}
