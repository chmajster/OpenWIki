<?php

declare(strict_types=1);

use OpenWiki\Core\Application;
use OpenWiki\Core\Request;
use OpenWiki\Core\Response;
use OpenWiki\Security\SecurityHeaders;

$basePath = dirname(__DIR__);
require $basePath . '/bootstrap/autoload.php';

try {
    $app = Application::boot($basePath);
    SecurityHeaders::send();

    $request = Request::fromGlobals();

    if (!$app->installed() && !str_starts_with($request->path(), '/install') && $request->path() !== '/health') {
        Response::redirect('/install')->send();
    }

    if ($app->installed()) {
        $user = $app->auth()->user();
        $forcePasswordChange = $user !== null && (bool) ($user['force_password_change'] ?? false);
        $allowedDuringPasswordChange = in_array(
            $request->path(),
            ['/account/change-password', '/logout', '/health'],
            true
        );

        if (
            $forcePasswordChange
            && !$allowedDuringPasswordChange
            && !str_starts_with($request->path(), '/api/')
        ) {
            Response::redirect('/account/change-password')->send();
        }
    }

    require $basePath . '/routes/web.php';
    $app->router()->dispatch($request, $app)->send();
} catch (Throwable $exception) {
    error_log('[OpenWiki] ' . $exception->getMessage() . "\n" . $exception->getTraceAsString());

    $debug = \OpenWiki\Core\Env::bool('APP_DEBUG', false);
    $message = $debug
        ? htmlspecialchars($exception->getMessage(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
        : 'An unexpected error occurred.';

    Response::html(
        '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>OpenWiki error</title></head><body><main><h1>Application error</h1><p>' . $message . '</p></main></body></html>',
        500
    )->send();
}
