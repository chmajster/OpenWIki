<?php

declare(strict_types=1);

use OpenWiki\Auth\MfaService;
use OpenWiki\Auth\UserSessionService;
use OpenWiki\Core\Application;
use OpenWiki\Core\ApplicationErrorResponse;
use OpenWiki\Core\Request;
use OpenWiki\Core\Response;
use OpenWiki\Core\Session;
use OpenWiki\Security\SecurityHeaders;

$basePath = dirname(__DIR__);
// Process liveness works even if Composer/configuration/database are unavailable.
if (parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) === '/health/live') {
    header('Content-Type: application/json; charset=UTF-8');
    header('Cache-Control: no-store');
    echo '{"status":"alive","process":true}';
    exit;
}
require $basePath . '/bootstrap/autoload.php';

try {
    $isHealth = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) === '/health';
    $writeGate = new \OpenWiki\Upgrade\WriteGate();
    if (!$isHealth) {
        try { $writeGate->acquire($basePath); }
        catch (RuntimeException) {
            header('Retry-After: 60');
            Response::json(['status' => 'maintenance', 'message' => 'Trwa aktualizacja OpenWiki. Spróbuj ponownie później.'], 503)->send();
        }
    }
    $app = Application::boot($basePath, !$isHealth);
    SecurityHeaders::send();

    $request = Request::fromGlobals();

    // Readiness runs before authentication, which may itself access an old schema.
    if ($isHealth) (new \OpenWiki\Http\Controllers\HealthController($app))->show($request)->send();

    if (!$app->installed() && !str_starts_with($request->path(), '/install') && $request->path() !== '/health') {
        Response::redirect('/install')->send();
    }

    if ($app->installed()) {
        $user = $app->auth()->user();

        if (
            $user !== null
            && !str_starts_with($request->path(), '/api/')
            && $request->path() !== '/health'
        ) {
            $sessionRegistry = new UserSessionService($app->database());
            if (!$sessionRegistry->validateAndTouchCurrent(
                (int) $user['id'],
                $request->ip(),
                $request->userAgent()
            )) {
                $app->auth()->logout();
                Session::flash('error', 'Your session expired or was revoked.');
                Response::redirect('/login')->send();
            }

            $mfa = new MfaService($app->database());
            $mfaVerified = Session::get('mfa_verified') === true;
            if ($mfaVerified && $mfa->required((int) $user['id']) && !$mfa->enabled((int) $user['id'])) {
                Session::put('mfa_verified', false);
                $mfaVerified = false;
            }
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
    ApplicationErrorResponse::fromThrowable($exception, $debug)->send();
}
