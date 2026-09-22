<?php

declare(strict_types=1);

namespace OpenWiki\Http\Controllers;

use OpenWiki\Audit\AuditLogger;
use OpenWiki\Core\Request;
use OpenWiki\Core\Response;
use OpenWiki\Core\Session;
use OpenWiki\Http\Controller;
use OpenWiki\Permissions\SpaceAccessService;
use OpenWiki\Repositories\PageRepository;
use OpenWiki\Repositories\SpaceRepository;
use OpenWiki\Wiki\Slugger;

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
        if (($failure = $this->authorize($request, 'space.create')) !== null) {
            return $failure;
        }
        if (($failure = $this->verifyCsrf($request)) !== null) {
            return $failure;
        }

        $name = trim((string) $request->input('name', ''));
        $keyInput = trim((string) $request->input('space_key', ''));
        $description = trim((string) $request->input('description', ''));
        $visibility = (string) $request->input('visibility', 'private');

        if ($name === '' || mb_strlen($name) > 191) {
            return $this->render('spaces/create', [
                'title' => 'Create space',
                'formError' => 'Space name is required and may contain at most 191 characters.',
                'old' => (array) $request->input(),
            ], 422);
        }

        if (!in_array($visibility, ['public', 'private', 'restricted'], true)) {
            return $this->render('spaces/create', [
                'title' => 'Create space',
                'formError' => 'Invalid space visibility.',
                'old' => (array) $request->input(),
            ], 422);
        }

        try {
            $spaceKey = (new Slugger())->spaceKey($keyInput === '' ? $name : $keyInput);
            $user = $this->app->auth()->user();
            $spaceId = (new SpaceRepository($this->app->database()))->create([
                'name' => $name,
                'space_key' => $spaceKey,
                'description' => $description === '' ? null : $description,
                'owner_id' => (int) $user['id'],
                'visibility' => $visibility,
            ]);

            (new AuditLogger($this->app->database()))->log(
                'SPACE_CREATED',
                'space',
                $spaceId,
                (int) $user['id'],
                $request,
                null,
                ['name' => $name, 'space_key' => $spaceKey, 'visibility' => $visibility]
            );

            Session::flash('success', 'Space created.');
            return Response::redirect('/spaces/' . rawurlencode($spaceKey));
        } catch (\Throwable $exception) {
            error_log('[OpenWiki space create] ' . $exception->getMessage());
            $message = str_contains(strtolower($exception->getMessage()), 'duplicate')
                ? 'That space key is already in use.'
                : 'Unable to create the space. Verify the entered values.';

            return $this->render('spaces/create', [
                'title' => 'Create space',
                'formError' => $message,
                'old' => (array) $request->input(),
            ], 422);
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
        $pages = (new PageRepository($this->app->database()))->tree((int) $space['id']);

        if (!$canEdit) {
            $pages = array_values(array_filter($pages, static fn (array $page): bool => $page['status'] === 'published'));
        }

        return $this->render('spaces/show', [
            'title' => $space['name'],
            'space' => $space,
            'pages' => $pages,
            'canCreatePage' => $canEdit && $this->app->auth()->can('page.create'),
        ]);
    }
}
