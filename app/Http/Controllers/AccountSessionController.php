<?php

declare(strict_types=1);

namespace OpenWiki\Http\Controllers;

use OpenWiki\Audit\AuditLogger;
use OpenWiki\Auth\UserSessionService;
use OpenWiki\Core\Request;
use OpenWiki\Core\Response;
use OpenWiki\Core\Session;
use OpenWiki\Http\Controller;

final class AccountSessionController extends Controller
{
    public function index(Request $request): Response
    {
        if (($failure = $this->requireAuth($request)) !== null) {
            return $failure;
        }

        $user = $this->app->auth()->user();

        return $this->render('account/sessions', [
            'title' => 'Active sessions',
            'sessions' => (new UserSessionService($this->app->database()))
                ->activeForUser((int) $user['id']),
        ]);
    }

    public function revoke(Request $request, string $fingerprint): Response
    {
        if (($failure = $this->requireAuth($request)) !== null) {
            return $failure;
        }
        if (($failure = $this->verifyCsrf($request)) !== null) {
            return $failure;
        }

        $user = $this->app->auth()->user();
        $currentFingerprint = hash('sha256', session_id());
        $service = new UserSessionService($this->app->database());

        if (!$service->revokeByFingerprint((int) $user['id'], $fingerprint)) {
            return $this->render('errors/404', ['title' => 'Session not found'], 404);
        }

        (new AuditLogger($this->app->database()))->log(
            'SESSION_REVOKED',
            'user_session',
            null,
            (int) $user['id'],
            $request,
            null,
            ['fingerprint' => substr($fingerprint, 0, 12)]
        );

        if (hash_equals($currentFingerprint, $fingerprint)) {
            $this->app->auth()->logout();
            Session::flash('success', 'Current session revoked.');
            return Response::redirect('/login');
        }

        Session::flash('success', 'Session revoked.');
        return Response::redirect('/account/sessions');
    }

    public function revokeAll(Request $request): Response
    {
        if (($failure = $this->requireAuth($request)) !== null) {
            return $failure;
        }
        if (($failure = $this->verifyCsrf($request)) !== null) {
            return $failure;
        }

        $user = $this->app->auth()->user();
        $count = (new UserSessionService($this->app->database()))
            ->revokeAll((int) $user['id']);

        (new AuditLogger($this->app->database()))->log(
            'ALL_SESSIONS_REVOKED',
            'user',
            (int) $user['id'],
            (int) $user['id'],
            $request,
            null,
            ['revoked_count' => $count]
        );

        $this->app->auth()->logout();
        Session::flash('success', 'All sessions have been signed out.');
        return Response::redirect('/login');
    }
}
