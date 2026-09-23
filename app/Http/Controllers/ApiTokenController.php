<?php

declare(strict_types=1);

namespace OpenWiki\Http\Controllers;

use OpenWiki\Auth\ApiTokenService;
use OpenWiki\Core\Request;
use OpenWiki\Core\Response;
use OpenWiki\Core\Session;
use OpenWiki\Http\Controller;
use OpenWiki\Security\RateLimiter;

final class ApiTokenController extends Controller
{
    public function index(Request $request): Response
    {
        if (($failure = $this->requireAuth($request)) !== null) {
            return $failure;
        }

        $user = $this->app->auth()->user();
        $service = new ApiTokenService($this->app->database());

        return $this->render('account/api-tokens', [
            'title' => 'API tokens',
            'tokens' => $service->listForUser((int) $user['id']),
            'availableScopes' => ApiTokenService::SCOPES,
            'newToken' => Session::pullFlash('new_api_token'),
        ]);
    }

    public function create(Request $request): Response
    {
        if (($failure = $this->requireAuth($request)) !== null) {
            return $failure;
        }
        if (($failure = $this->verifyCsrf($request)) !== null) {
            return $failure;
        }

        $user = $this->app->auth()->user();
        $limiter = new RateLimiter($this->app->database());
        $rateKey = 'user:' . (int) $user['id'];

        if ($limiter->tooManyAttempts('api_token_create', $rateKey, 10, 3600)) {
            Session::flash('error', 'Too many API tokens created recently. Try again later.');
            return Response::redirect('/account/api-tokens');
        }

        try {
            $scopes = $request->input('scopes', []);
            if (!is_array($scopes)) {
                $scopes = [];
            }

            $created = (new ApiTokenService($this->app->database()))->create(
                (int) $user['id'],
                (string) $request->input('name', ''),
                $scopes,
                ($request->input('expires_at') === null || $request->input('expires_at') === '')
                    ? null
                    : (string) $request->input('expires_at')
            );

            $limiter->hit('api_token_create', $rateKey);
            Session::flash('new_api_token', $created['token']);
            Session::flash('success', 'API token created. Copy it now; it will not be shown again.');
        } catch (\InvalidArgumentException $exception) {
            Session::flash('error', $exception->getMessage());
        } catch (\Throwable $exception) {
            error_log('[OpenWiki API token] ' . $exception->getMessage());
            Session::flash('error', 'Unable to create API token.');
        }

        return Response::redirect('/account/api-tokens');
    }

    public function revoke(Request $request, string $id): Response
    {
        if (($failure = $this->requireAuth($request)) !== null) {
            return $failure;
        }
        if (($failure = $this->verifyCsrf($request)) !== null) {
            return $failure;
        }

        $tokenId = filter_var($id, FILTER_VALIDATE_INT);
        if ($tokenId === false || $tokenId < 1) {
            return $this->render('errors/404', ['title' => 'API token not found'], 404);
        }

        $user = $this->app->auth()->user();
        if (!(new ApiTokenService($this->app->database()))->revoke((int) $user['id'], (int) $tokenId)) {
            return $this->render('errors/404', ['title' => 'API token not found'], 404);
        }

        Session::flash('success', 'API token revoked.');
        return Response::redirect('/account/api-tokens');
    }
}
