<?php

declare(strict_types=1);

namespace OpenWiki\Http\Controllers;

use OpenWiki\Attachments\AttachmentService;
use OpenWiki\Audit\AuditLogger;
use OpenWiki\Core\Request;
use OpenWiki\Core\Response;
use OpenWiki\Core\Session;
use OpenWiki\Http\Controller;
use OpenWiki\Permissions\SpaceAccessService;
use OpenWiki\Repositories\PageRepository;
use OpenWiki\Repositories\SpaceRepository;
use OpenWiki\Wiki\WikiMetadataService;

final class TrashController extends Controller
{
    public function index(Request $request): Response
    {
        if (($failure = $this->authorize($request, 'page.delete')) !== null) {
            return $failure;
        }

        $access = new SpaceAccessService($this->app);
        $rows = $this->app->database()->fetchAll(
            'SELECT p.id, p.space_id, p.title, p.slug, p.status, p.deleted_at, p.owner_id, p.author_id,
                    s.name AS space_name, s.space_key, s.owner_id AS space_owner_id,
                    s.visibility, s.status AS space_status, s.deleted_at AS space_deleted_at
             FROM pages p
             INNER JOIN spaces s ON s.id = p.space_id
             WHERE p.deleted_at IS NOT NULL AND s.deleted_at IS NULL
             ORDER BY p.deleted_at DESC'
        );

        $pages = array_values(array_filter($rows, static function (array $row) use ($access): bool {
            $space = [
                'id' => (int) $row['space_id'],
                'owner_id' => (int) $row['space_owner_id'],
                'visibility' => $row['visibility'],
                'status' => $row['space_status'],
                'deleted_at' => $row['space_deleted_at'],
            ];

            return $access->canEdit($space);
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
        if (!(new SpaceAccessService($this->app))->canEdit($space)) {
            return $this->render('errors/403', ['title' => 'Permission denied'], 403);
        }

        $page = (new PageRepository($this->app->database()))->findBySlug((int) $space['id'], $slug);
        if ($page === null) {
            return $this->render('errors/404', ['title' => 'Page not found'], 404);
        }

        $user = $this->app->auth()->user();
        $this->app->database()->transaction(function () use ($page, $space): void {
            $this->app->database()->execute(
                'UPDATE pages SET deleted_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
                 WHERE id = :id AND deleted_at IS NULL',
                ['id' => (int) $page['id']]
            );
            (new WikiMetadataService($this->app->database()))->refreshSpaceLinks((int) $space['id']);
        });

        (new AuditLogger($this->app->database()))->log(
            'PAGE_DELETED',
            'page',
            (int) $page['id'],
            (int) $user['id'],
            $request,
            ['title' => $page['title'], 'slug' => $page['slug'], 'status' => $page['status']],
            ['deleted' => true]
        );

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

        $conflict = $this->app->database()->fetchOne(
            'SELECT id
             FROM pages
             WHERE space_id = :space_id AND slug = :slug AND deleted_at IS NULL
             LIMIT 1',
            ['space_id' => (int) $page['space_id'], 'slug' => (string) $page['slug']]
        );
        if ($conflict !== null) {
            Session::flash('error', 'Cannot restore this page because its slug is already in use.');
            return Response::redirect('/trash');
        }

        $this->app->database()->transaction(function () use ($page, $space): void {
            $this->app->database()->execute(
                'UPDATE pages SET deleted_at = NULL, updated_at = UTC_TIMESTAMP() WHERE id = :id',
                ['id' => (int) $page['id']]
            );
            (new WikiMetadataService($this->app->database()))->refreshSpaceLinks((int) $space['id']);
        });

        $user = $this->app->auth()->user();
        (new AuditLogger($this->app->database()))->log(
            'PAGE_RESTORED',
            'page',
            (int) $page['id'],
            (int) $user['id'],
            $request,
            ['deleted' => true],
            ['deleted' => false]
        );

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

        $attachments = new AttachmentService($this->app->database(), $this->app->basePath());
        $storageKeys = $attachments->storageKeysForPage((int) $page['id']);

        $this->app->database()->transaction(function () use ($page, $space): void {
            $this->app->database()->execute(
                'DELETE FROM pages WHERE id = :id AND deleted_at IS NOT NULL',
                ['id' => (int) $page['id']]
            );
            (new WikiMetadataService($this->app->database()))->refreshSpaceLinks((int) $space['id']);
        });

        $attachments->purgeStorageKeys($storageKeys);

        $user = $this->app->auth()->user();
        (new AuditLogger($this->app->database()))->log(
            'PAGE_PERMANENTLY_DELETED',
            'page',
            (int) $page['id'],
            (int) $user['id'],
            $request,
            ['title' => $page['title'], 'slug' => $page['slug'], 'deleted_at' => $page['deleted_at']],
            ['permanent' => true]
        );

        Session::flash('success', 'Page permanently deleted.');
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

        if (!(new SpaceAccessService($this->app))->canEdit($space)) {
            return [null, null, $this->render('errors/403', ['title' => 'Permission denied'], 403)];
        }

        return [$page, $space, null];
    }
}
