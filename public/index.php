<?php

declare(strict_types=1);

use OpenWiki\Auth\MfaService;
use OpenWiki\Core\Application;
use OpenWiki\Core\Request;
use OpenWiki\Core\Response;
use OpenWiki\Core\Session;
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

        if ($user !== null && !str_starts_with($request->path(), '/api/')) {
            $mfa = new MfaService($app->database());
            $mfaVerified = Session::get('mfa_verified') === true;
            $allowedDuringMfa = in_array(
                $request->path(),
                ['/mfa/challenge', '/account/mfa/setup', '/account/mfa/confirm', '/logout', '/health'],
                true
            );

            if (!$mfaVerified) {
                if ($mfa->enabled((int) $user['id'])) {
                    if (!$allowedDuringMfa) {
                        Response::redirect('/mfa/challenge')->send();
                    }
                } elseif ($mfa->required((int) $user['id'])) {
                    if (!$allowedDuringMfa) {
                        Response::redirect('/account/mfa/setup')->send();
                    }
                } else {
                    Session::put('mfa_verified', true);
                    $mfaVerified = true;
                }
            }

            $forcePasswordChange = (bool) ($user['force_password_change'] ?? false);
            $allowedDuringPasswordChange = in_array(
                $request->path(),
                [
                    '/account/change-password',
                    '/account/mfa/setup',
                    '/account/mfa/confirm',
                    '/mfa/challenge',
                    '/logout',
                    '/health',
                ],
                true
            );

            if ($mfaVerified && $forcePasswordChange && !$allowedDuringPasswordChange) {
                Response::redirect('/account/change-password')->send();
            }
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
