<?php

declare(strict_types=1);

namespace OpenWiki\Http;

use OpenWiki\Core\Application;
use OpenWiki\Core\Request;
use OpenWiki\Core\Response;
use OpenWiki\Core\Session;
use OpenWiki\Security\Csrf;

abstract class Controller
{
    public function __construct(protected readonly Application $app)
    {
    }

    protected function render(string $template, array $data = [], int $status = 200): Response
    {
        return Response::html($this->app->view()->render($template, $data), $status);
    }

    protected function requireAuth(Request $request): ?Response
    {
        if ($this->app->auth()->check()) {
            return null;
        }

        if ($request->expectsJson()) {
            return Response::json(['error' => ['code' => 'unauthenticated', 'message' => 'Authentication required.']], 401);
        }

        Session::flash('error', 'Sign in to continue.');
        return Response::redirect('/login');
    }

    protected function authorize(Request $request, string $permission): ?Response
    {
        $authResponse = $this->requireAuth($request);
        if ($authResponse !== null) {
            return $authResponse;
        }

        if ($this->app->auth()->can($permission)) {
            return null;
        }

        return $request->expectsJson()
            ? Response::json(['error' => ['code' => 'forbidden', 'message' => 'Permission denied.']], 403)
            : $this->render('errors/403', ['title' => 'Permission denied'], 403);
    }

    protected function verifyCsrf(Request $request): ?Response
    {
        $token = $request->input('_token') ?? $request->header('X-CSRF-Token');
        if (Csrf::verify(is_string($token) ? $token : null)) {
            return null;
        }

        return $request->expectsJson()
            ? Response::json(['error' => ['code' => 'csrf_failed', 'message' => 'Invalid CSRF token.']], 419)
            : $this->render('errors/419', ['title' => 'Session expired'], 419);
    }
}
