<?php

declare(strict_types=1);

namespace OpenWiki\Http\Controllers;

use OpenWiki\Core\Request;
use OpenWiki\Core\Response;
use OpenWiki\Core\Session;
use OpenWiki\Http\Controller;
use OpenWiki\Permissions\PageAclService;
use OpenWiki\Repositories\PageRepository;
use OpenWiki\Repositories\SpaceRepository;

final class TrashController extends Controller
{
    public function index(Request $request): Response
    {
        if (($failure = $this->authorize($request, 'page.delete')) !== null) {
            return $failure;
        }

        $pageAccess = new PageAclService($this->app);
        $rows = $this->app->database()->fetchAll(
            'SELECT p.id, p.space_id, p.parent_id, p.inherit_acl, p.title, p.slug, p.status, p.deleted_at, p.owner_id, p.author_id,
                    s.name AS space_name, s.space_key, s.owner_id AS space_owner_id,
                    s.visibility, s.status AS space_status, s.deleted_at AS space_deleted_at
             FROM pages p
             INNER JOIN spaces s ON s.id = p.space_id
             WHERE p.deleted_at IS NOT NULL AND s.deleted_at IS NULL
             ORDER BY p.deleted_at DESC'
        );

        $pages = array_values(array_filter($rows, static function (array $row) use ($pageAccess): bool {
            $space = [
                'id' => (int) $row['space_id'],
                'owner_id' => (int) $row['space_owner_id'],
                'visibility' => $row['visibility'],
                'status' => $row['space_status'],
                'deleted_at' => $row['space_deleted_at'],
            ];

            return $pageAccess->can($row, $space, 'page.delete');
        }));

        return $this->render('trash/index', [
            'title' => 'Trash',
            'pages' => $pages,
        ]);
    }

    public function delete(Request $request, string $spaceKey, string $slug): Response
    {
        if (($failure = $this->authorize($request, 'page.delete')) !== null) {
            return $failure;
        }
        if (($failure = $this->verifyCsrf($request)) !== null) {
            return $failure;
        }

        $space = (new SpaceRepository($this->app->database()))->findByKey($spaceKey);
        if ($space === null) {
            return $this->render('errors/404', ['title' => 'Space not found'], 404);
        }
        $page = (new PageRepository($this->app->database()))->findBySlug((int) $space['id'], $slug);
        if ($page === null) {
            return $this->render('errors/404', ['title' => 'Page not found'], 404);
        }
        if (!(new PageAclService($this->app))->can($page, $space, 'page.delete')) {
            return $this->render('errors/403', ['title' => 'Permission denied'], 403);
        }

        try {
            $this->lifecycle()->trash($space, (int) $page['id'], (int) $this->app->auth()->user()['id'], $this->pageWritePolicy($space, subtreeSnapshot: true), $request);
        } catch (\Throwable $exception) {
            return $this->lifecycleError($exception);
        }

        Session::flash('success', 'Page moved to trash.');
        return Response::redirect('/spaces/' . rawurlencode((string) $space['space_key']));
    }

    public function restore(Request $request, string $id): Response
    {
        if (($failure = $this->authorize($request, 'page.delete')) !== null) {
            return $failure;
        }
        if (($failure = $this->verifyCsrf($request)) !== null) {
            return $failure;
        }

        [$page, $space, $failure] = $this->trashedPage($id);
        if ($failure !== null) {
            return $failure;
        }

        try {
            $this->lifecycle()->restore($space, (int) $page['id'], (int) $this->app->auth()->user()['id'], $this->pageWritePolicy($space, subtreeSnapshot: true), $request);
        } catch (\Throwable $exception) {
            return $this->lifecycleError($exception);
        }

        Session::flash('success', 'Page restored.');
        return Response::redirect(
            '/spaces/' . rawurlencode((string) $space['space_key'])
            . '/pages/' . rawurlencode((string) $page['slug'])
        );
    }

    public function destroy(Request $request, string $id): Response
    {
        if (($failure = $this->authorize($request, 'page.delete')) !== null) {
            return $failure;
        }
        if (($failure = $this->verifyCsrf($request)) !== null) {
            return $failure;
        }

        [$page, $space, $failure] = $this->trashedPage($id);
        if ($failure !== null) {
            return $failure;
        }

        try {
            $this->lifecycle()->destroy($space, (int) $page['id'], (int) $this->app->auth()->user()['id'], $this->pageWritePolicy($space, subtreeSnapshot: true), $request);
        } catch (\Throwable $exception) {
            return $this->lifecycleError($exception);
        }

        Session::flash('success', 'Page permanently deleted.');
        return Response::redirect('/trash');
    }

    private function lifecycle(): \OpenWiki\Wiki\PageLifecycleService
    {
        return new \OpenWiki\Wiki\PageLifecycleService($this->app->database(), $this->app->basePath());
    }

    private function lifecycleError(\Throwable $exception): Response
    {
        if ($exception instanceof \DomainException) return $this->render('errors/403', ['title' => 'Permission denied', 'message' => $exception->getMessage()], 403);
        if ($exception instanceof \InvalidArgumentException) {
            Session::flash('error', $exception->getMessage());
            return Response::redirect('/trash');
        }
        error_log('[OpenWiki page lifecycle] ' . $exception->getMessage());
        Session::flash('error', 'Unable to change the page subtree. Your pages remain recoverable.');
        return Response::redirect('/trash');
    }

    private function trashedPage(string $id): array
    {
        $pageId = filter_var($id, FILTER_VALIDATE_INT);
        if ($pageId === false || $pageId < 1) {
            return [null, null, $this->render('errors/404', ['title' => 'Page not found'], 404)];
        }

        $page = $this->app->database()->fetchOne(
            'SELECT p.*, s.name AS space_name, s.space_key,
                    s.owner_id AS space_owner_id, s.visibility,
                    s.status AS space_status, s.deleted_at AS space_deleted_at
             FROM pages p
             INNER JOIN spaces s ON s.id = p.space_id
             WHERE p.id = :id AND p.deleted_at IS NOT NULL AND s.deleted_at IS NULL
             LIMIT 1',
            ['id' => (int) $pageId]
        );
        if ($page === null) {
            return [null, null, $this->render('errors/404', ['title' => 'Page not found'], 404)];
        }

        $space = [
            'id' => (int) $page['space_id'],
            'name' => $page['space_name'],
            'space_key' => $page['space_key'],
            'owner_id' => (int) $page['space_owner_id'],
            'visibility' => $page['visibility'],
            'status' => $page['space_status'],
            'deleted_at' => $page['space_deleted_at'],
        ];

        if (!(new PageAclService($this->app))->can($page, $space, 'page.delete')) {
            return [null, null, $this->render('errors/403', ['title' => 'Permission denied'], 403)];
        }

        return [$page, $space, null];
    }
}
