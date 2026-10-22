<?php

declare(strict_types=1);

namespace OpenWiki\Tests\Unit;

use OpenWiki\Core\ApplicationErrorResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ApplicationErrorResponseTest extends TestCase
{
    protected function setUp(): void
    {
        unset($_COOKIE['openwiki_locale']);
    }

    public function testDatabaseAuthenticationFailureIsClearAndDoesNotExposeRawError(): void
    {
        $error = new \RuntimeException(
            'Database connection failed: SQLSTATE[HY000] [1045] Access denied for user root with password secret-value'
        );

        $response = ApplicationErrorResponse::fromThrowable($error);

        self::assertSame(503, $response->status());
        self::assertSame('30', $response->headers()['Retry-After']);
        self::assertStringContainsString('Uwierzytelnianie w bazie danych nie powiodło się', $response->body());
        self::assertStringContainsString('database_authentication_failed', $response->body());
        self::assertStringContainsString('DB_USERNAME', $response->body());
        self::assertStringNotContainsString('secret-value', $response->body());
        self::assertStringNotContainsString('Access denied for user', $response->body());
    }

    #[DataProvider('databaseFailureProvider')]
    public function testDatabaseFailuresReceiveSpecificGuidance(
        string $errorMessage,
        string $problemCode,
        string $expectedPolishTitle
    ): void {
        $response = ApplicationErrorResponse::fromThrowable(new \RuntimeException($errorMessage));

        self::assertSame(503, $response->status());
        self::assertStringContainsString($problemCode, $response->body());
        self::assertStringContainsString($expectedPolishTitle, $response->body());
    }

    public static function databaseFailureProvider(): array
    {
        return [
            'missing database' => [
                'Database connection failed: SQLSTATE[HY000] [1049] Unknown database',
                'database_not_found',
                'Baza danych nie istnieje',
            ],
            'insufficient privileges' => [
                'Database connection failed: SQLSTATE[42000] [1044] Access denied to database',
                'database_permission_denied',
                'Uprawnienia do bazy danych są niewystarczające',
            ],
            'server unavailable' => [
                'Database connection failed: SQLSTATE[HY000] [2002] Connection refused',
                'database_server_unavailable',
                'Serwer bazy danych jest niedostępny',
            ],
            'missing PDO driver' => [
                'Database connection failed: could not find driver',
                'database_driver_missing',
                'Sterownik bazy danych jest niedostępny',
            ],
        ];
    }

    public function testUnexpectedProductionErrorRemainsSafe(): void
    {
        $response = ApplicationErrorResponse::fromThrowable(new \RuntimeException('private failure detail'));

        self::assertSame(500, $response->status());
        self::assertStringContainsString('Błąd aplikacji', $response->body());
        self::assertStringContainsString('unexpected_error', $response->body());
        self::assertStringNotContainsString('private failure detail', $response->body());
    }

    public function testApplicationQueryErrorIsNotMisreportedAsConnectionFailure(): void
    {
        $response = ApplicationErrorResponse::fromThrowable(
            new \PDOException('SQLSTATE[42000] [1064] Syntax error in application query')
        );

        self::assertSame(500, $response->status());
        self::assertStringContainsString('unexpected_error', $response->body());
        self::assertStringNotContainsString('database_unavailable', $response->body());
    }

    public function testDebugModeEscapesTechnicalDetails(): void
    {
        $response = ApplicationErrorResponse::fromThrowable(
            new \RuntimeException('<script>alert(1)</script>'),
            true
        );

        self::assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $response->body());
        self::assertStringNotContainsString('<script>alert(1)</script>', $response->body());
    }
}
