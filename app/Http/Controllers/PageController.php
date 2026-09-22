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
use OpenWiki\Wiki\ContentService;
use OpenWiki\Wiki\EditSessionService;
use OpenWiki\Wiki\Slugger;

final class PageController extends Controller
{
    public function create(Request $request, string $spaceKey): Response
    {
        if (($failure = $this->authorize($request, 'page.create')) !== null) {
            return $failure;
        }

        $space = $this->space($spaceKey);
        if ($space instanceof Response) {
            return $space;
        }

        if (!(new SpaceAccessService($this->app))->canEdit($space)) {
            return $this->render('errors/403', ['title' => 'Permission denied'], 403);
        }

        $templates = $this->app->database()->fetchAll(
            'SELECT id, name, description, content_html, content_markdown FROM page_templates ORDER BY is_system DESC, name ASC'
        );

        return $this->render('pages/editor', [
            'title' => 'Create page',
            'space' => $space,
            'page' => null,
            'serverDraft' => null,
            'pages' => (new PageRepository($this->app->database()))->tree((int) $space['id']),
            'templates' => $templates,
            'canPublish' => $this->app->auth()->can('page.publish'),
            'formAction' => '/spaces/' . rawurlencode($space['space_key']) . '/pages',
        ]);
    }

    public function store(Request $request, string $spaceKey): Response
    {
        if (($failure = $this->authorize($request, 'page.create')) !== null) {
            return $failure;
        }
        if (($failure = $this->verifyCsrf($request)) !== null) {
            return $failure;
        }

        $space = $this->space($spaceKey);
        if ($space instanceof Response) {
            return $space;
        }

        $access = new SpaceAccessService($this->app);
        if (!$access->canEdit($space)) {
            return $this->render('errors/403', ['title' => 'Permission denied'], 403);
        }

        try {
            $data = $this->validatedPageData($request, $space, null);
            $user = $this->app->auth()->user();
            $data['space_id'] = (int) $space['id'];
            $data['author_id'] = (int) $user['id'];
            $data['owner_id'] = (int) $user['id'];

            $pageId = (new PageRepository($this->app->database()))->create($data);

            (new AuditLogger($this->app->database()))->log(
                'PAGE_CREATED',
                'page',
                $pageId,
                (int) $user['id'],
                $request,
                null,
                ['title' => $data['title'], 'slug' => $data['slug'], 'status' => $data['status']]
            );

            try {
                (new PageEngagementService($this->app->database()))->notifyWatchers(
                    $pageId,
                    (int) $space['id'],
                    (int) $user['id'],
                    'page.created',
                    'New page: ' . $data['title'],
                    '/spaces/' . rawurlencode($space['space_key']) . '/pages/' . rawurlencode($data['slug'])
                );
            } catch (\Throwable $notificationError) {
                error_log('[OpenWiki notification] ' . $notificationError->getMessage());
            }

            Session::flash('success', 'Page created.');
            return Response::redirect(
                '/spaces/' . rawurlencode($space['space_key']) . '/pages/' . rawurlencode($data['slug'])
            );
        } catch (\Throwable $exception) {
            return $this->editorError($request, $space, null, $exception);
        }
    }

    public function show(Request $request, string $spaceKey, string $slug): Response
    {
        $space = $this->space($spaceKey);
        if ($space instanceof Response) {
            return $space;
        }

        $access = new SpaceAccessService($this->app);
        if (!$access->canView($space)) {
            return $this->render('errors/403', ['title' => 'Permission denied'], 403);
        }

        $repository = new PageRepository($this->app->database());
        $page = $repository->findBySlug((int) $space['id'], $slug);

        if ($page === null) {
            $redirect = $repository->redirectTarget((int) $space['id'], $slug);
            if ($redirect !== null) {
                return Response::redirect(
                    '/spaces/' . rawurlencode($space['space_key']) . '/pages/' . rawurlencode($redirect['slug']),
                    301
                );
            }
            return $this->render('errors/404', ['title' => 'Page not found'], 404);
        }

        if (!$this->canViewPage($page, $space, $access)) {
            return $this->render('errors/403', ['title' => 'Permission denied'], 403);
        }

        $user = $this->app->auth()->user();
        if ($user !== null) {
            $repository->recordView((int) $page['id'], (int) $user['id']);
        }

        $engagement = new PageEngagementService($this->app->database());

        return $this->render('pages/show', [
            'title' => $page['title'],
            'space' => $space,
            'page' => $page,
            'pages' => $repository->tree((int) $space['id']),
            'canEdit' => $access->canEdit($space) && $this->app->auth()->can('page.edit'),
            'isFavorite' => $user !== null && $engagement->isFavorite((int) $user['id'], (int) $page['id']),
            'isWatching' => $user !== null && $engagement->isWatching((int) $user['id'], 'page', (int) $page['id']),
            'comments' => (new CommentService($this->app->database()))->listForPage((int) $page['id']),
            'canComment' => $user !== null && $this->app->auth()->can('comment.create'),
            'canDeleteComment' => $user !== null && $this->app->auth()->can('comment.delete'),
        ]);
    }

    public function edit(Request $request, string $spaceKey, string $slug): Response
    {
        if (($failure = $this->authorize($request, 'page.edit')) !== null) {
            return $failure;
        }

        $space = $this->space($spaceKey);
        if ($space instanceof Response) {
            return $space;
        }

        $access = new SpaceAccessService($this->app);
        if (!$access->canEdit($space)) {
            return $this->render('errors/403', ['title' => 'Permission denied'], 403);
        }

        $repository = new PageRepository($this->app->database());
        $page = $repository->findBySlug((int) $space['id'], $slug);
        if ($page === null) {
            return $this->render('errors/404', ['title' => 'Page not found'], 404);
        }

        $user = $this->app->auth()->user();
        $serverDraft = (new EditSessionService($this->app->database()))->draft(
            (int) $page['id'],
            (int) $user['id']
        );

        return $this->render('pages/editor', [
            'title' => 'Edit ' . $page['title'],
            'space' => $space,
            'page' => $page,
            'serverDraft' => $serverDraft,
            'pages' => $repository->tree((int) $space['id']),
            'templates' => [],
            'canPublish' => $this->app->auth()->can('page.publish'),
            'formAction' => '/spaces/' . rawurlencode($space['space_key']) . '/pages/' . rawurlencode($page['slug']),
        ]);
    }

    public function update(Request $request, string $spaceKey, string $slug): Response
    {
        if (($failure = $this->authorize($request, 'page.edit')) !== null) {
            return $failure;
        }
        if (($failure = $this->verifyCsrf($request)) !== null) {
            return $failure;
        }

        $space = $this->space($spaceKey);
        if ($space instanceof Response) {
            return $space;
        }

        $access = new SpaceAccessService($this->app);
        if (!$access->canEdit($space)) {
            return $this->render('errors/403', ['title' => 'Permission denied'], 403);
        }

        $repository = new PageRepository($this->app->database());
        $current = $repository->findBySlug((int) $space['id'], $slug);
        if ($current === null) {
            return $this->render('errors/404', ['title' => 'Page not found'], 404);
        }

        try {
            $data = $this->validatedPageData($request, $space, $current);
            $user = $this->app->auth()->user();
            $data['author_id'] = (int) $user['id'];
            $data['base_version'] = (int) $request->input('base_version', 0);

            $before = ['title' => $current['title'], 'slug' => $current['slug'], 'status' => $current['status'], 'version' => $current['version']];
            $result = $repository->update($current, $data);

            (new AuditLogger($this->app->database()))->log(
                'PAGE_UPDATED',
                'page',
                (int) $current['id'],
                (int) $user['id'],
                $request,
                $before,
                ['title' => $data['title'], 'slug' => $data['slug'], 'status' => $data['status'], 'version' => $result['version']]
            );

            try {
                (new PageEngagementService($this->app->database()))->notifyWatchers(
                    (int) $current['id'],
                    (int) $space['id'],
                    (int) $user['id'],
                    'page.updated',
                    'Page updated: ' . $data['title'],
                    '/spaces/' . rawurlencode($space['space_key']) . '/pages/' . rawurlencode($data['slug'])
                );
            } catch (\Throwable $notificationError) {
                error_log('[OpenWiki notification] ' . $notificationError->getMessage());
            }

            Session::flash('success', 'Page saved.');
            return Response::redirect(
                '/spaces/' . rawurlencode($space['space_key']) . '/pages/' . rawurlencode($data['slug'])
            );
        } catch (\Throwable $exception) {
            return $this->editorError($request, $space, $current, $exception);
        }
    }

    public function history(Request $request, string $spaceKey, string $slug): Response
    {
        $space = $this->space($spaceKey);
        if ($space instanceof Response) {
            return $space;
        }

        $access = new SpaceAccessService($this->app);
        if (!$access->canView($space)) {
            return $this->render('errors/403', ['title' => 'Permission denied'], 403);
        }

        $repository = new PageRepository($this->app->database());
        $page = $repository->findBySlug((int) $space['id'], $slug);
        if ($page === null) {
            return $this->render('errors/404', ['title' => 'Page not found'], 404);
        }

        if (!$this->canViewPage($page, $space, $access)) {
            return $this->render('errors/403', ['title' => 'Permission denied'], 403);
        }

        return $this->render('pages/history', [
            'title' => 'History: ' . $page['title'],
            'space' => $space,
            'page' => $page,
            'revisions' => $repository->revisions((int) $page['id']),
            'canRestore' => $access->canEdit($space) && $this->app->auth()->can('page.edit'),
        ]);
    }

    public function restore(Request $request, string $spaceKey, string $slug): Response
    {
        if (($failure = $this->authorize($request, 'page.edit')) !== null) {
            return $failure;
        }
        if (($failure = $this->verifyCsrf($request)) !== null) {
            return $failure;
        }

        $space = $this->space($spaceKey);
        if ($space instanceof Response) {
            return $space;
        }

        $access = new SpaceAccessService($this->app);
        if (!$access->canEdit($space)) {
            return $this->render('errors/403', ['title' => 'Permission denied'], 403);
        }

        $repository = new PageRepository($this->app->database());
        $page = $repository->findBySlug((int) $space['id'], $slug);
        $revisionNumber = filter_var($request->input('revision'), FILTER_VALIDATE_INT);

        if ($page === null || $revisionNumber === false || $revisionNumber < 1) {
            return $this->render('errors/404', ['title' => 'Revision not found'], 404);
        }

        $revision = $repository->revision((int) $page['id'], (int) $revisionNumber);
        if ($revision === null) {
            return $this->render('errors/404', ['title' => 'Revision not found'], 404);
        }

        if ($revision['status'] === 'published' && !$this->app->auth()->can('page.publish')) {
            return $this->render('errors/403', ['title' => 'Publishing permission required'], 403);
        }

        $user = $this->app->auth()->user();
        $newVersion = $repository->restore($page, $revision, (int) $user['id']);

        (new AuditLogger($this->app->database()))->log(
            'PAGE_REVISION_RESTORED',
            'page',
            (int) $page['id'],
            (int) $user['id'],
            $request,
            ['version' => $page['version']],
            ['version' => $newVersion, 'restored_revision' => (int) $revisionNumber]
        );

        try {
            (new PageEngagementService($this->app->database()))->notifyWatchers(
                (int) $page['id'],
                (int) $space['id'],
                (int) $user['id'],
                'page.updated',
                'Page revision restored: ' . $page['title'],
                '/spaces/' . rawurlencode($space['space_key']) . '/pages/' . rawurlencode($page['slug'])
            );
        } catch (\Throwable $notificationError) {
            error_log('[OpenWiki notification] ' . $notificationError->getMessage());
        }

        Session::flash('success', 'Revision restored as a new version.');
        return Response::redirect(
            '/spaces/' . rawurlencode($space['space_key']) . '/pages/' . rawurlencode($page['slug'])
        );
    }

    private function validatedPageData(Request $request, array $space, ?array $current): array
    {
        $title = trim((string) $request->input('title', ''));
        if ($title === '' || mb_strlen($title) > 255) {
            throw new \InvalidArgumentException('Page title is required and may contain at most 255 characters.');
        }

        $slugger = new Slugger();
        $slugInput = trim((string) $request->input('slug', ''));
        $slug = $slugger->slug($slugInput === '' ? $title : $slugInput);

        $status = (string) $request->input('status', 'draft');
        if (!in_array($status, ['draft', 'published', 'archived'], true)) {
            throw new \InvalidArgumentException('Invalid page status.');
        }

        if ($status === 'published' && !$this->app->auth()->can('page.publish')) {
            throw new \InvalidArgumentException('You do not have permission to publish pages.');
        }
        if ($status === 'archived' && !$this->app->auth()->can('page.archive')) {
            throw new \InvalidArgumentException('You do not have permission to archive pages.');
        }

        $parentId = $request->input('parent_id');
        $parentId = ($parentId === null || $parentId === '') ? null : filter_var($parentId, FILTER_VALIDATE_INT);
        if ($parentId === false || (is_int($parentId) && $parentId < 1)) {
            throw new \InvalidArgumentException('Invalid parent page.');
        }

        if ($parentId !== null) {
            $parent = (new PageRepository($this->app->database()))->findById((int) $parentId);
            if ($parent === null || (int) $parent['space_id'] !== (int) $space['id']) {
                throw new \InvalidArgumentException('Parent page must belong to the same space.');
            }
            if (
                $current !== null
                && (new PageRepository($this->app->database()))->wouldCreateCycle((int) $current['id'], (int) $parentId)
            ) {
                throw new \InvalidArgumentException('The selected parent would create a cycle in the page tree.');
            }
        }

        $format = (string) $request->input('content_format', 'visual');
        $content = (new ContentService())->normalize(
            $format,
            (string) $request->input('content_html', ''),
            (string) $request->input('content_markdown', '')
        );

        return [
            'parent_id' => $parentId,
            'title' => $title,
            'slug' => $slug,
            'content_html' => $content['html'],
            'content_markdown' => $content['markdown'],
            'content_text' => $content['text'],
            'content_format' => $content['format'],
            'status' => $status,
            'change_summary' => mb_substr(trim((string) $request->input('change_summary', '')), 0, 500),
        ];
    }

    private function canViewPage(array $page, array $space, SpaceAccessService $access): bool
    {
        if ($page['status'] === 'published') {
            return true;
        }

        $user = $this->app->auth()->user();
        if ($user === null) {
            return false;
        }

        return (int) $page['owner_id'] === (int) $user['id']
            || (int) $page['author_id'] === (int) $user['id']
            || $access->canEdit($space);
    }

    private function space(string $spaceKey): array|Response
    {
        $space = (new SpaceRepository($this->app->database()))->findByKey($spaceKey);
        return $space ?? $this->render('errors/404', ['title' => 'Space not found'], 404);
    }

    private function editorError(Request $request, array $space, ?array $page, \Throwable $exception): Response
    {
        error_log('[OpenWiki page save] ' . $exception->getMessage());

        $message = match (true) {
            $exception->getMessage() === 'EDIT_CONFLICT' => 'This page changed after you opened the editor. Reload the page before saving again.',
            str_contains(strtolower($exception->getMessage()), 'duplicate') => 'A page with that slug already exists in this space.',
            $exception instanceof \InvalidArgumentException => $exception->getMessage(),
            default => 'Unable to save the page.',
        };

        $repository = new PageRepository($this->app->database());

        return $this->render('pages/editor', [
            'title' => $page === null ? 'Create page' : 'Edit ' . $page['title'],
            'space' => $space,
            'page' => $page,
            'serverDraft' => $page === null
                ? null
                : (new EditSessionService($this->app->database()))->draft(
                    (int) $page['id'],
                    (int) $this->app->auth()->user()['id']
                ),
            'pages' => $repository->tree((int) $space['id']),
            'templates' => $page === null
                ? $this->app->database()->fetchAll('SELECT id, name, description, content_html, content_markdown FROM page_templates ORDER BY is_system DESC, name ASC')
                : [],
            'canPublish' => $this->app->auth()->can('page.publish'),
            'formAction' => $page === null
                ? '/spaces/' . rawurlencode($space['space_key']) . '/pages'
                : '/spaces/' . rawurlencode($space['space_key']) . '/pages/' . rawurlencode($page['slug']),
            'formError' => $message,
            'old' => (array) $request->input(),
        ], $exception->getMessage() === 'EDIT_CONFLICT' ? 409 : 422);
    }
}
