<?php

declare(strict_types=1);

namespace OpenWiki\Http\Controllers;

use OpenWiki\Audit\AuditLogger;
use OpenWiki\Auth\MfaService;
use OpenWiki\Auth\UserSessionService;
use OpenWiki\Core\Request;
use OpenWiki\Core\Response;
use OpenWiki\Core\Session;
use OpenWiki\Http\Controller;
use OpenWiki\Security\RateLimiter;

final class MfaController extends Controller
{
    public function setup(Request $request): Response
    {
        if (($failure = $this->requireAuth($request)) !== null) {
            return $failure;
        }

        $user = $this->app->auth()->user();
        $service = new MfaService($this->app->database());
        $enabled = $service->enabled((int) $user['id']);
        $enrollment = null;

        if (!$enabled) {
            $enrollment = $service->enrollment(
                (int) $user['id'],
                (string) $user['username'],
                $this->app->name()
            );
        }

        return $this->render('auth/mfa-setup', [
            'title' => 'Multi-factor authentication',
            'enabled' => $enabled,
            'required' => $service->required((int) $user['id']),
            'enrollment' => $enrollment,
            'recoveryCodes' => Session::pullFlash('mfa_recovery_codes'),
        ]);
    }

    public function confirm(Request $request): Response
    {
        if (($failure = $this->requireAuth($request)) !== null) {
            return $failure;
        }
        if (($failure = $this->verifyCsrf($request)) !== null) {
            return $failure;
        }

        $user = $this->app->auth()->user();
        $service = new MfaService($this->app->database());

        try {
            $codes = $service->confirmEnrollment(
                (int) $user['id'],
                (string) $request->input('code', '')
            );
            $oldSessionId = session_id();
            Session::regenerate();
            (new UserSessionService($this->app->database()))->replaceAfterRegeneration(
                $oldSessionId,
                (int) $user['id'],
                $request->ip(),
                $request->userAgent()
            );
            Session::put('mfa_verified', true);
            Session::flash('mfa_recovery_codes', $codes);
            Session::flash('success', 'MFA enabled. Save the recovery codes now.');

            (new AuditLogger($this->app->database()))->log(
                'MFA_ENABLED',
                'user',
                (int) $user['id'],
                (int) $user['id'],
                $request
            );

            if (Session::get('mfa_pending_login') === true) {
                (new AuditLogger($this->app->database()))->log(
                    'LOGIN_SUCCEEDED',
                    'user',
                    (int) $user['id'],
                    (int) $user['id'],
                    $request
                );
                Session::forget('mfa_pending_login');
            }

            return Response::redirect('/account/mfa/setup');
        } catch (\InvalidArgumentException $exception) {
            Session::flash('error', $exception->getMessage());
            return Response::redirect('/account/mfa/setup');
        }
    }

    public function challenge(Request $request): Response
    {
        if (($failure = $this->requireAuth($request)) !== null) {
            return $failure;
        }

        $user = $this->app->auth()->user();
        $service = new MfaService($this->app->database());

        if (!$service->enabled((int) $user['id'])) {
            if ($service->required((int) $user['id'])) {
                return Response::redirect('/account/mfa/setup');
            }

            Session::put('mfa_verified', true);
            return Response::redirect('/');
        }

        if (Session::get('mfa_verified') === true) {
            return Response::redirect('/');
        }

        return $this->render('auth/mfa-challenge', [
            'title' => 'Verify sign-in',
        ]);
    }

    public function verify(Request $request): Response
    {
        if (($failure = $this->requireAuth($request)) !== null) {
            return $failure;
        }
        if (($failure = $this->verifyCsrf($request)) !== null) {
            return $failure;
        }

        $user = $this->app->auth()->user();
        $key = $request->ip() . '|' . (int) $user['id'];
        $limiter = new RateLimiter($this->app->database());

        if ($limiter->tooManyAttempts('mfa', $key, 5, 900)) {
            return $this->render('auth/mfa-challenge', [
                'title' => 'Verify sign-in',
                'mfaError' => 'Too many verification attempts. Try again later.',
            ], 429);
        }

        $service = new MfaService($this->app->database());
        if (!$service->verifyUserCode((int) $user['id'], (string) $request->input('code', ''))) {
            $limiter->hit('mfa', $key);
            (new AuditLogger($this->app->database()))->log(
                'MFA_FAILED',
                'user',
                (int) $user['id'],
                (int) $user['id'],
                $request
            );

            return $this->render('auth/mfa-challenge', [
                'title' => 'Verify sign-in',
                'mfaError' => 'Invalid authentication or recovery code.',
            ], 422);
        }

        $limiter->clear('mfa', $key);
        $oldSessionId = session_id();
        Session::regenerate();
        (new UserSessionService($this->app->database()))->replaceAfterRegeneration(
            $oldSessionId,
            (int) $user['id'],
            $request->ip(),
            $request->userAgent()
        );
        Session::put('mfa_verified', true);

        (new AuditLogger($this->app->database()))->log(
            'MFA_VERIFIED',
            'user',
            (int) $user['id'],
            (int) $user['id'],
            $request
        );
        if (Session::get('mfa_pending_login') === true) {
            (new AuditLogger($this->app->database()))->log(
                'LOGIN_SUCCEEDED',
                'user',
                (int) $user['id'],
                (int) $user['id'],
                $request
            );
            Session::forget('mfa_pending_login');
        }

        if ((bool) ($user['force_password_change'] ?? false)) {
            return Response::redirect('/account/change-password');
        }

        return Response::redirect('/');
    }
}
