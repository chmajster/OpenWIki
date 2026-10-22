<?php

declare(strict_types=1);

namespace OpenWiki\Core;

final class ApplicationErrorResponse
{
    public static function fromThrowable(\Throwable $error, bool $debug = false): Response
    {
        $failure = self::databaseFailure($error) ?? [
            'code' => 'unexpected_error',
            'title' => 'Application error',
            'message' => 'OpenWiki encountered an unexpected error.',
            'action' => 'Try again. If the problem continues, check the server error log.',
            'status' => 500,
        ];

        $locale = I18n::locale();
        $e = static fn (mixed $value): string => htmlspecialchars(
            (string) $value,
            ENT_QUOTES | ENT_SUBSTITUTE,
            'UTF-8'
        );
        $t = static fn (string $value): string => I18n::text($value);
        $technicalDetails = $debug
            ? '<details class="system-error-details"><summary>' . $e($t('Technical details')) . '</summary><pre>'
                . $e($error::class . ': ' . $error->getMessage()) . '</pre></details>'
            : '';

        $body = '<!doctype html><html lang="' . $e($locale) . '"><head>'
            . '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">'
            . '<title>' . $e($t($failure['title'])) . ' · OpenWiki</title>'
            . '<script src="/assets/theme.js"></script><link rel="stylesheet" href="/assets/app.css">'
            . '</head><body><main class="system-error-layout" id="main-content">'
            . '<section class="card system-error-card" role="alert">'
            . '<div class="system-error-brand"><span aria-hidden="true">OW</span><strong>OpenWiki</strong></div>'
            . '<div class="system-error-status">' . $e($t('Service temporarily unavailable')) . '</div>'
            . '<h1>' . $e($t($failure['title'])) . '</h1>'
            . '<p class="system-error-message">' . $e($t($failure['message'])) . '</p>'
            . '<div class="system-error-advice"><strong>' . $e($t('How to fix it')) . '</strong><p>'
                . $e($t($failure['action'])) . '</p></div>'
            . '<p class="system-error-reference"><span>' . $e($t('Problem code')) . '</span><code>'
                . $e($failure['code']) . '</code></p>'
            . $technicalDetails
            . '<div class="system-error-actions"><a class="button button--primary" href="/">'
                . $e($t('Try again')) . '</a><a class="button button--secondary" href="/health">'
                . $e($t('Technical status')) . '</a></div>'
            . '</section></main></body></html>';

        return Response::html($body, $failure['status'], [
            'Cache-Control' => 'no-store',
            'Retry-After' => '30',
        ]);
    }

    /** @return array{code: string, title: string, message: string, action: string, status: int}|null */
    private static function databaseFailure(\Throwable $error): ?array
    {
        $messages = [];
        $driverCode = null;
        $isDatabaseFailure = false;

        for ($cause = $error; $cause !== null; $cause = $cause->getPrevious()) {
            $message = $cause->getMessage();
            $messages[] = $message;
            $isDatabaseFailure = $isDatabaseFailure
                || str_contains(strtolower($message), 'database connection failed')
                || str_contains(strtolower($message), 'could not find driver');

            if ($cause instanceof \PDOException && isset($cause->errorInfo[1]) && is_numeric($cause->errorInfo[1])) {
                $driverCode = (int) $cause->errorInfo[1];
            }
            if ($driverCode === null && preg_match('/\[(1044|1045|1049|1142|1227|2002|2003|2005)\]/', $message, $match)) {
                $driverCode = (int) $match[1];
            }
        }

        $isDatabaseFailure = $isDatabaseFailure || in_array(
            $driverCode,
            [1044, 1045, 1049, 1142, 1227, 2002, 2003, 2005],
            true
        );

        if (!$isDatabaseFailure) {
            return null;
        }

        $combinedMessage = strtolower(implode("\n", $messages));
        if (str_contains($combinedMessage, 'could not find driver')) {
            return [
                'code' => 'database_driver_missing',
                'title' => 'Database driver is unavailable',
                'message' => 'PHP cannot connect to MySQL because the PDO MySQL driver is not enabled.',
                'action' => 'Enable the pdo_mysql PHP extension and restart the PHP service.',
                'status' => 503,
            ];
        }

        return match ($driverCode) {
            1045 => [
                'code' => 'database_authentication_failed',
                'title' => 'Database authentication failed',
                'message' => 'OpenWiki reached the database server, but the configured username or password was rejected.',
                'action' => 'Check DB_USERNAME and DB_PASSWORD in the runtime .env file, then reload this page.',
                'status' => 503,
            ],
            1049 => [
                'code' => 'database_not_found',
                'title' => 'Database does not exist',
                'message' => 'The database server is available, but the configured database name was not found.',
                'action' => 'Create the database or correct DB_DATABASE in the runtime .env file.',
                'status' => 503,
            ],
            1044, 1142, 1227 => [
                'code' => 'database_permission_denied',
                'title' => 'Database permissions are insufficient',
                'message' => 'The configured database account does not have permission to perform the required operation.',
                'action' => 'Grant the account access to the configured database and reload this page.',
                'status' => 503,
            ],
            2002, 2003, 2005 => [
                'code' => 'database_server_unavailable',
                'title' => 'Database server is unavailable',
                'message' => 'OpenWiki could not reach the configured MySQL or MariaDB server.',
                'action' => 'Check DB_HOST and DB_PORT, then make sure the database service is running.',
                'status' => 503,
            ],
            default => [
                'code' => 'database_unavailable',
                'title' => 'Database is unavailable',
                'message' => 'OpenWiki could not establish a usable database connection.',
                'action' => 'Check the database service and the DB_* settings in the runtime .env file.',
                'status' => 503,
            ],
        };
    }
}
