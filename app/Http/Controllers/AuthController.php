<?php

declare(strict_types=1);

namespace OpenWiki\Http\Controllers;

use OpenWiki\Audit\AuditLogger;
use OpenWiki\Auth\MfaService;
use OpenWiki\Core\Request;
use OpenWiki\Core\Response;
use OpenWiki\Core\Session;
use OpenWiki\Http\Controller;
use OpenWiki\Security\RateLimiter;

final class AuthController extends Controller
{
    public function loginForm(Request $request): Response
    {
        if ($this->app->auth()->check()) {
            return Response::redirect('/');
        }

        return $this->render('auth/login', ['title' => 'Sign in']);
    }

    public function login(Request $request): Response
    {
        if (($failure = $this->verifyCsrf($request)) !== null) {
            return $failure;
        }

        $identifier = trim((string) $request->input('identifier', ''));
        $password = (string) $request->input('password', '');
        $limiter = new RateLimiter($this->app->database());
        $key = $request->ip() . '|' . mb_strtolower($identifier);

        if ($limiter->tooManyAttempts('login', $key, 5, 900)) {
            return $this->render('auth/login', [
                'title' => 'Sign in',
                'loginError' => 'Too many sign-in attempts. Try again later.',
                'identifier' => $identifier,
            ], 429);
        }

        if ($identifier === '' || $password === '' || !$this->app->auth()->attempt($identifier, $password)) {
            $limiter->hit('login', $key);
            (new AuditLogger($this->app->database()))->log('LOGIN_FAILED', 'user', null, null, $request);
            return $this->render('auth/login', [
                'title' => 'Sign in',
                'loginError' => 'Invalid username/email or password.',
                'identifier' => $identifier,
            ], 422);
        }

        $limiter->clear('login', $key);
        $user = $this->app->auth()->user();
        Session::put('mfa_verified', false);

        $mfa = new MfaService($this->app->database());
        if ($mfa->enabled((int) $user['id'])) {
            Session::put('mfa_pending_login', true);
            return Response::redirect('/mfa/challenge');
        }
        if ($mfa->required((int) $user['id'])) {
            Session::put('mfa_pending_login', true);
            return Response::redirect('/account/mfa/setup');
        }

        Session::forget('mfa_pending_login');
        Session::put('mfa_verified', true);
        (new AuditLogger($this->app->database()))->log(
            'LOGIN_SUCCEEDED',
            'user',
            (int) $user['id'],
            (int) $user['id'],
            $request
        );

        if ((bool) ($user['force_password_change'] ?? false)) {
            return Response::redirect('/account/change-password');
        }

        return Response::redirect('/');
    }

    public function logout(Request $request): Response
    {
        if (($failure = $this->verifyCsrf($request)) !== null) {
            return $failure;
        }

        $user = $this->app->auth()->user();
        if ($user !== null) {
            (new AuditLogger($this->app->database()))->log(
                'LOGOUT',
                'user',
                (int) $user['id'],
                (int) $user['id'],
                $request
            );
        }

        $this->app->auth()->logout();
        Session::flash('success', 'You have been signed out.');
        return Response::redirect('/login');
    }
}
