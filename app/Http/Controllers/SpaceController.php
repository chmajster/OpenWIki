<?php

declare(strict_types=1);

namespace OpenWiki\Http\Controllers;

use OpenWiki\Core\Request;
use OpenWiki\Core\Response;
use OpenWiki\Core\Session;
use OpenWiki\Http\Controller;
use OpenWiki\Permissions\PageAclService;
use OpenWiki\Permissions\SpaceAccessService;
use OpenWiki\Repositories\PageRepository;
use OpenWiki\Repositories\SpaceRepository;

final class SpaceController extends Controller
{
    public function create(Request $request): Response
    {
        if (($failure = $this->authorize($request, 'space.create')) !== null) {
            return $failure;
        }

        return $this->render('spaces/create', ['title' => 'Create space']);
    }

    public function store(Request $request): Response
    {
        if (($failure = $this->authorize($request, 'space.create')) !== null) return $failure;
        if (($failure = $this->verifyCsrf($request)) !== null) return $failure;
        try {
            $space = (new \OpenWiki\Spaces\SpaceService($this->app))->create((array) $request->input(), (int) $this->app->auth()->user()['id'], $request);
            Session::flash('success', 'Space created.');
            return Response::redirect('/spaces/' . rawurlencode($space['space_key']));
        } catch (\Throwable $error) {
            if (!$error instanceof \InvalidArgumentException && !$error instanceof \OpenWiki\Spaces\SpaceConflict && !$error instanceof \DomainException) error_log('[OpenWiki space create] ' . $error->getMessage());
            return $this->render('spaces/create', ['title' => 'Create space', 'old' => (array) $request->input(),
                'formError' => $error instanceof \InvalidArgumentException || $error instanceof \OpenWiki\Spaces\SpaceConflict || $error instanceof \DomainException ? $error->getMessage() : 'Nie udało się utworzyć przestrzeni.'],
                $error instanceof \OpenWiki\Spaces\SpaceConflict ? 409 : ($error instanceof \DomainException ? 403 : ($error instanceof \InvalidArgumentException ? 422 : 500)));
        }
    }

    public function show(Request $request, string $spaceKey): Response
    {
        $space = (new SpaceRepository($this->app->database()))->findByKey($spaceKey);
        if ($space === null) {
            return $this->render('errors/404', ['title' => 'Space not found'], 404);
        }

        $access = new SpaceAccessService($this->app);
        if (!$access->canView($space)) {
            return $this->render('errors/403', ['title' => 'Permission denied'], 403);
        }

        $canEdit = $access->canEdit($space);
        $pageAccess = new PageAclService($this->app);
        $homePage = $space['home_page_id'] === null ? null : (new PageRepository($this->app->database()))->findById((int) $space['home_page_id']);
        if ($homePage !== null && !$pageAccess->canView($homePage, $space)) $homePage = null;

        return $this->render('spaces/show', [
            'title' => $space['name'],
            'structureEnabled' => true,
            'space' => $space,
            'pages' => [],
            'canCreatePage' => $canEdit && $this->app->auth()->can('page.create'),
            'canSettings' => $this->app->auth()->check() && $this->settingsAllowed($space),
            'homePage' => $homePage,
            'isWatchingSpace' => $this->app->auth()->check() && (new \OpenWiki\Wiki\PageEngagementService($this->app->database()))->isWatching((int) $this->app->auth()->user()['id'], 'space', (int) $space['id']),
        ]);
    }

    private function settingsAllowed(array $space): bool
    {
        $actor = new \OpenWiki\Spaces\SpaceActor($this->app->database(), (int) $this->app->auth()->user()['id']);
        $service = new \OpenWiki\Spaces\SpaceService($this->app);
        return (bool) array_filter(['space.edit', 'space.manage_access', 'space.manage_owner', 'space.archive'], fn (string $permission): bool => $service->allowed($space, $actor, $permission));
    }

    public function settings(Request $request, string $spaceKey): Response
    {
        return $this->settingsOperation($request, $spaceKey, 'show');
    }

    public function tree(Request $request, string $spaceKey): Response
    {
        try {
            $space = (new SpaceRepository($this->app->database()))->findByKey($spaceKey) ?? throw new \OutOfBoundsException('Nie znaleziono przestrzeni.');
            $parent = $request->query('parent_id');
            if ($parent !== null && (!ctype_digit((string) $parent) || (int) $parent < 1)) throw new \OutOfBoundsException('Nie znaleziono gałęzi.');
            $query = $request->query('q', '');
            if (!is_string($query)) throw new \InvalidArgumentException('Nieprawidłowe wyszukiwanie.');
            return Response::json(['data' => (new \OpenWiki\Spaces\DocumentationTreeService($this->app))->branch($space,
                $this->app->auth()->user()['id'] ?? null, $this->app->auth()->can('*'), $this->app->auth()->can('page.view'), $this->app->auth()->can('page.move'),
                $parent === null ? null : (int) $parent, $query, max(0, (int) $request->query('offset', 0)), $this->app->auth()->can('page.create'), $request->query('siblings_only') === '1')]);
        } catch (\OutOfBoundsException $error) { return Response::json(['error' => ['message' => $error->getMessage()]], 404); }
        catch (\InvalidArgumentException $error) { return Response::json(['error' => ['message' => $error->getMessage()]], 422); }
    }

    public function saveSettings(Request $request, string $spaceKey): Response
    {
        return $this->settingsOperation($request, $spaceKey, 'update');
    }

    public function access(Request $request, string $spaceKey): Response
    {
        return $this->settingsOperation($request, $spaceKey, $request->method() === 'GET' ? 'effective' : 'rule');
    }

    public function principals(Request $request, string $spaceKey): Response
    {
        return $this->settingsOperation($request, $spaceKey, 'principals');
    }

    private function settingsOperation(Request $request, string $key, string $operation): Response
    {
        if (($failure = $this->requireAuth($request)) !== null) return $failure;
        if ($request->method() !== 'GET' && ($failure = $this->verifyCsrf($request)) !== null) return $failure;
        $space = (new SpaceRepository($this->app->database()))->findByKey($key);
        if ($space === null) return $this->render('errors/404', ['title' => 'Space not found'], 404);
        $service = new \OpenWiki\Spaces\SpaceService($this->app);
        $actor = new \OpenWiki\Spaces\SpaceActor($this->app->database(), (int) $this->app->auth()->user()['id']);
        if (!$this->settingsAllowed($space)) return $this->render('errors/403', ['title' => 'Permission denied'], 403);
        try {
            if ($operation === 'update') {
                $space = $service->update((int) $space['id'], (array) $request->input(), $actor->id, $request);
                Session::flash('success', 'Ustawienia przestrzeni zapisane.');
                return Response::redirect($this->settingsAllowed($space) ? '/spaces/' . rawurlencode($key) . '/settings' : '/');
            }
            if (in_array($operation, ['rule', 'principals', 'effective'], true)) {
                if ($operation === 'principals') {
                    $type = $request->query('type', 'user'); $query = $request->query('q', '');
                    if (!is_string($type) || !is_string($query)) throw new \InvalidArgumentException('Nieprawidłowe wyszukiwanie.');
                    if (!$service->allowed($space, $actor, 'space.manage_access') && !($type === 'user' && $service->allowed($space, $actor, 'space.manage_owner'))) throw new \DomainException('Brak uprawnienia do wyszukiwania adresatów.');
                    return Response::json(['data' => $service->principals($type, $query)]);
                }
                $service->assertAllowed($space, $actor, 'space.manage_access');
                if ($operation === 'effective') {
                    $id = $request->query('user_id');
                    if ($id !== null && (!ctype_digit((string) $id) || (int) $id < 1)) throw new \InvalidArgumentException('Nieprawidłowy użytkownik.');
                    $page = $request->query('page_id');
                    if ($page !== null && (!is_scalar($page) || !ctype_digit((string) $page) || (int) $page < 1)) throw new \InvalidArgumentException('Nieprawidłowa strona.');
                    return Response::json(['data' => $service->effective($space, $id === null ? null : (int) $id, $page === null ? null : (int) $page, $actor)]);
                }
                $delete = $request->input('delete_id');
                if ($delete !== null && (!ctype_digit((string) $delete) || (int) $delete < 1)) throw new \InvalidArgumentException('Nieprawidłowa reguła.');
                $space = $service->rule((int) $space['id'], (array) $request->input(), $actor->id, $request, $delete === null ? null : (int) $delete);
                Session::flash('success', 'Reguła dostępu zapisana.');
                return Response::redirect('/spaces/' . rawurlencode($key) . '/settings#access');
            }
            return $this->settingsForm($space, $actor, $service);
        } catch (\Throwable $error) {
            $status = $error instanceof \OpenWiki\Spaces\SpaceConflict ? 409 : ($error instanceof \DomainException ? 403 : ($error instanceof \InvalidArgumentException ? 422 : ($error instanceof \OutOfBoundsException ? 404 : 500)));
            $message = $status === 500 ? 'Nie udało się zapisać zmian. Spróbuj ponownie.' : $error->getMessage();
            if ($status === 500) error_log('[OpenWiki space settings] ' . $error->getMessage());
            if ($request->expectsJson()) return Response::json(['error' => ['message' => $message]], $status);
            $space = $service->find((int) $space['id']);
            if (!$this->settingsAllowed($space)) return $this->render('errors/403', ['title' => 'Permission denied'], 403);
            $actor = new \OpenWiki\Spaces\SpaceActor($this->app->database(), $actor->id);
            return $this->settingsForm($space, $actor, $service, $status, $message, (array) $request->input());
        }
    }

    private function settingsForm(array $space, \OpenWiki\Spaces\SpaceActor $actor, \OpenWiki\Spaces\SpaceService $service, int $status = 200, ?string $error = null, array $old = []): Response
    {
        $pages = (new PageAclService($this->app))->visiblePages($space, fn () => (new PageRepository($this->app->database()))->tree((int) $space['id']));
        $capabilities = [];
        foreach (\OpenWiki\Spaces\SpaceService::PERMISSIONS as $permission) $capabilities[$permission] = $service->allowed($space, $actor, $permission);
        $selectedOwner = $space['owner_id'];
        if ($capabilities['space.manage_owner'] && filter_var($old['owner_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false) $selectedOwner = (int) $old['owner_id'];
        $owner = $this->app->database()->fetchOne('SELECT id, username FROM users WHERE id = ? AND deleted_at IS NULL', [$selectedOwner]);
        $owner ??= $this->app->database()->fetchOne('SELECT id, username FROM users WHERE id = ?', [$space['owner_id']]);
        $selectedPrincipal = null;
        if ($capabilities['space.manage_access'] && filter_var($old['principal_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]) !== false) {
            $table = match ($old['principal_type'] ?? '') { 'user' => 'users', 'group' => 'user_groups', 'role' => 'roles', default => null };
            if ($table !== null) $selectedPrincipal = $this->app->database()->fetchOne('SELECT id, ' . ($table === 'users' ? 'username' : 'name') . ' AS name FROM ' . $table . ' WHERE id = ?', [(int) $old['principal_id']]);
        }
        return $this->render('spaces/settings', ['title' => 'Ustawienia przestrzeni', 'space' => $space, 'pages' => $pages,
            'capabilities' => $capabilities, 'rules' => $capabilities['space.manage_access'] ? $service->entries((int) $space['id']) : [],
            'owner' => $owner, 'selectedPrincipal' => $selectedPrincipal,
            'formError' => $error, 'hasConflict' => $status === 409, 'old' => $old], $status);
    }
}
