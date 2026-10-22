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
    /** Identical space controls for overview, content, templates and scoped search. */
    protected function spaceNavigation(array $space): array
    {
        $user = $this->app->auth()->user();
        $canManageSpace = $this->spaceSettingsAllowed($space);
        return [
            'canCreatePage' => $user !== null && $this->app->auth()->can('page.create') && (new \OpenWiki\Permissions\SpaceAccessService($this->app))->canEdit($space),
            'canSettings' => $canManageSpace,
            'canMissionControl' => $canManageSpace,
            'isWatchingSpace' => $user !== null && (new \OpenWiki\Wiki\PageEngagementService($this->app->database()))->isWatching((int) $user['id'], 'space', (int) $space['id']),
        ];
    }

    protected function spaceSettingsAllowed(array $space): bool
    {
        $user = $this->app->auth()->user();
        if ($user === null) return false;
        $actor = new \OpenWiki\Spaces\SpaceActor($this->app->database(), (int) $user['id']);
        $service = new \OpenWiki\Spaces\SpaceService($this->app);
        return (bool) array_filter(['space.edit', 'space.manage_access', 'space.manage_owner', 'space.archive'],
            fn (string $permission): bool => $service->allowed($space, $actor, $permission));
    }

    protected function pageWritePolicy(array $space, ?array $context = null, bool $subtreeSnapshot = false): callable
    {
        $tokens = new \OpenWiki\Auth\ApiTokenService($this->app->database());
        $userId = (int) ($context['user']['id'] ?? $this->app->auth()->user()['id']);
        $permission = fn (string $name): bool => $context === null
            ? $this->app->auth()->can($name) : $tokens->hasPermission($context, $name);
        $superAdmin = $permission('*');
        $acl = new \OpenWiki\Permissions\PageAclService($this->app);
        $db = $this->app->database();
        $snapshot = null;
        return static function (string $name, ?array $page) use ($space, $userId, $permission, $superAdmin, $acl, $db, $subtreeSnapshot, &$snapshot): bool {
            if ($page === null) return $permission($name);
            $freshSpace = $snapshot ?? $db->fetchOne('SELECT * FROM spaces WHERE id = ? AND deleted_at IS NULL', [(int) $space['id']]);
            if ($freshSpace === null || (int) $page['space_id'] !== (int) $freshSpace['id']) return false;
            if ($subtreeSnapshot && $snapshot === null) {
                $acl->snapshot($freshSpace, $userId, $superAdmin);
                $snapshot = $freshSpace;
            }
            if ($name === 'page.view') return $acl->canViewFor($page, $freshSpace, $userId, $superAdmin, $permission($name));
            return $acl->canFor($page, $freshSpace, $name, $userId, $superAdmin, $permission($name));
        };
    }

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
