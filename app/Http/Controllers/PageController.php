<?php

declare(strict_types=1);

namespace OpenWiki\Http\Controllers;

use OpenWiki\Attachments\AttachmentService;
use OpenWiki\Core\Request;
use OpenWiki\Core\Response;
use OpenWiki\Core\Session;
use OpenWiki\Http\Controller;
use OpenWiki\Permissions\PageAclService;
use OpenWiki\Permissions\SpaceAccessService;
use OpenWiki\Repositories\PageRepository;
use OpenWiki\Repositories\SpaceRepository;
use OpenWiki\Wiki\CommentService;
use OpenWiki\Wiki\MacroService;
use OpenWiki\Wiki\PageEngagementService;
use OpenWiki\Wiki\WikiMetadataService;

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

        $templates = (new \OpenWiki\Wiki\PageTemplateService($this->app->database()))->catalog();

        $parent = null;
        if ($request->query('parent') !== null) {
            $parentId = filter_var($request->query('parent'), FILTER_VALIDATE_INT);
            $parent = $parentId === false ? null : (new PageRepository($this->app->database()))->findById($parentId);
            if ($parent === null || (int) $parent['space_id'] !== (int) $space['id'] || !$this->pageWritePolicy($space)('page.view', $parent)
                || !$this->pageWritePolicy($space)('page.create', $parent)) return $this->render('errors/404', ['title' => 'Page not found'], 404);
        }

        return $this->render('pages/editor', [
            'title' => 'Create page',
            'space' => $space,
            'page' => null,
            'serverDraft' => null,
            'pages' => $this->visibleTree($space),
            'templates' => $templates,
            'tags' => [],
            'initialParentId' => $parent['id'] ?? null,
            'workspaceSpaces' => (new \OpenWiki\Wiki\PageWorkspaceService($this->app))->spaces(),
            'canPublish' => !(bool) $space['review_workflow'] && $this->pageWritePolicy($space)('page.publish', $page ?? null),
            'canArchive' => $this->pageWritePolicy($space)('page.archive', $page ?? null),
            'canUploadImage' => $this->app->auth()->can('attachment.upload'),
            'imageUploadUrl' => null,
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
            $user = $this->app->auth()->user();
            if ($request->input('editor_user_id') !== null && (int) $request->input('editor_user_id') !== (int) $user['id']) {
                throw new \DomainException('The signed-in account changed. Reopen the editor for this account.');
            }
            $input = (array) $request->input();
            $destination = $space;
            if (($input['publication_space_id'] ?? '') !== '') {
                $targetId = filter_var($input['publication_space_id'], FILTER_VALIDATE_INT);
                if ($targetId === false || $targetId < 1) throw new \InvalidArgumentException('Nieprawidłowa przestrzeń docelowa.');
                $destination = $this->app->database()->fetchOne('SELECT * FROM spaces WHERE id = ? AND deleted_at IS NULL', [$targetId]);
                if ($destination === null || !$access->canEdit($destination)) throw new \DomainException('Nie masz prawa tworzyć stron w wybranej przestrzeni.');
                $input['parent_id'] = $input['publication_parent_id'] ?? '';
            }
            $input['draft_space_id'] = (int) $space['id'];
            $saved = (new \OpenWiki\Wiki\PageWriteService($this->app->database(), $this->app->basePath()))->save(
                $destination, null, $input, (int) $user['id'], $request,
                $this->pageWritePolicy($destination), $this->app->auth()->can('attachment.upload')
            );
            $url = '/spaces/' . rawurlencode($destination['space_key']) . '/pages/' . rawurlencode($saved['slug']) . ((bool) $destination['review_workflow'] || ($saved['published_revision'] !== null && $saved['workflow_state'] !== 'published') ? '/review' : '');
            if ($request->expectsJson()) {
                return Response::json(['data' => ['page' => $saved + ['space_key' => $destination['space_key']], 'url' => $url, 'user_id' => (int) $user['id']]]);
            }
            Session::flash('success', 'Page saved.');
            return Response::redirect($url);
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
                $redirectSpace = (new SpaceRepository($this->app->database()))->findByKey((string) ($redirect['space_key'] ?? $space['space_key']));
                if ($redirectSpace === null || !(new PageAclService($this->app))->canView($redirect, $redirectSpace)) return $this->render('errors/404', ['title' => 'Page not found'], 404);
                return Response::redirect(
                    '/spaces/' . rawurlencode($redirectSpace['space_key']) . '/pages/' . rawurlencode($redirect['slug']),
                    301
                );
            }
            return $this->render('errors/404', ['title' => 'Page not found'], 404);
        }

        $pageAccess = new PageAclService($this->app);
        if (!$pageAccess->canView($page, $space)) {
            return $this->render('errors/403', ['title' => 'Permission denied'], 403);
        }

        $user = $this->app->auth()->user();
        if ($user !== null) {
            $repository->recordView((int) $page['id'], (int) $user['id']);
        }

        $engagement = new PageEngagementService($this->app->database());
        $metadata = new WikiMetadataService($this->app->database());
        $backlinks = array_values(array_filter(
            $metadata->backlinks((int) $page['id']),
            function (array $source) use ($access, $pageAccess): bool {
                $sourceSpace = [
                    'id' => (int) $source['space_id'],
                    'owner_id' => (int) $source['space_owner_id'],
                    'visibility' => $source['space_visibility'],
                    'status' => $source['space_status'],
                    'deleted_at' => $source['space_deleted_at'],
                ];

                return $access->canView($sourceSpace)
                    && $pageAccess->canView($source, $sourceSpace);
            }
        ));

        $workingAccess = $pageAccess->canReadWorking($page, $space);
        $headVersion = (int) $page['version'];
        $page = $repository->published($page);
        $contentOwnerName = $this->app->database()->fetchOne('SELECT username FROM users WHERE id = ?', [$page['content_owner_id']])['username'] ?? 'Nieaktywne konto';
        $rendered = (new MacroService($this->app))->renderPage($page, $space);
        if ($page['published_revision'] !== null && ((bool) $space['review_workflow'] || $headVersion > (int) $page['published_revision'])) $rendered['html'] = (new \OpenWiki\Wiki\RevisionAccessService($this->app->database()))->renderedHtml($rendered['html'], (int) $page['id'], (int) $page['published_revision']);

        return $this->render('pages/show', [
            'structureEnabled' => true,
            'title' => $page['title'],
            'space' => $space,
            'page' => $page,
            'pages' => [],
            'breadcrumbs' => (new \OpenWiki\Spaces\DocumentationTreeService($this->app))->breadcrumbs($space, $page, $user['id'] ?? null, $this->app->auth()->can('*'), $this->app->auth()->can('page.view')),
            'canEdit' => $pageAccess->canEdit($page, $space),
            'canReadWorking' => $workingAccess, 'headVersion' => $headVersion, 'contentOwnerName' => $contentOwnerName,
            'canMove' => $pageAccess->can($page, $space, 'page.move'),
            'canCopy' => $user !== null && $this->app->auth()->can('page.create'),
            'visibleParentId' => $page['parent_id'] === null ? null : (($parent = $repository->findById((int) $page['parent_id'])) !== null && $pageAccess->canView($parent, $space) ? (int) $parent['id'] : null),
            'canManagePermissions' => $pageAccess->canEdit($page, $space),
            'canDeletePage' => $user !== null && $pageAccess->can($page, $space, 'page.delete'),
            'isFavorite' => $user !== null && $engagement->isFavorite((int) $user['id'], (int) $page['id']),
            'isWatching' => $user !== null && $engagement->isWatching((int) $user['id'], 'page', (int) $page['id']),
            'comments' => (new CommentService($this->app->database()))->listForPage((int) $page['id']),
            'canComment' => $user !== null && $this->app->auth()->can('comment.create'),
            'canDeleteComment' => $user !== null && $this->app->auth()->can('comment.delete'),
            'attachments' => (new \OpenWiki\Attachments\AttachmentVisibility($this->app->database()))->forPage((new AttachmentService($this->app->database(), $this->app->basePath()))->listForPage((int) $page['id'], true), $user['id'] ?? null, $this->app->auth()->can('*')),
            'canUploadAttachment' => $user !== null
                && $pageAccess->canEdit($page, $space)
                && $this->app->auth()->can('attachment.upload'),
            'canDeleteAttachment' => $user !== null
                && $pageAccess->canEdit($page, $space)
                && $this->app->auth()->can('attachment.delete'),
            'tags' => $metadata->tagsForPage((int) $page['id']),
            'backlinks' => $backlinks,
            'renderedContent' => $rendered['html'],
            'tableOfContents' => $rendered['toc'],
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
        if (!(new PageAclService($this->app))->canEdit($page, $space)) {
            return $this->render('errors/403', ['title' => 'Permission denied'], 403);
        }

        $page = $repository->working($page);
        $user = $this->app->auth()->user();
        return $this->render('pages/editor', [
            'title' => 'Edit ' . $page['title'],
            'space' => $space,
            'page' => $page,
            'serverDraft' => null,
            'pages' => $this->visibleTree($space),
            'templates' => [],
            'workspaceSpaces' => (new \OpenWiki\Wiki\PageWorkspaceService($this->app))->spaces($page),
            'tags' => isset($page['tags']) ? array_map(static fn (string $name): array => ['name' => $name], $page['tags']) : (new WikiMetadataService($this->app->database()))->tagsForPage((int) $page['id']),
            'canPublish' => !(bool) $space['review_workflow'] && $this->pageWritePolicy($space)('page.publish', $page ?? null),
            'canArchive' => $this->pageWritePolicy($space)('page.archive', $page ?? null),
            'canUploadImage' => $this->app->auth()->can('attachment.upload'),
            'imageUploadUrl' => '/spaces/' . rawurlencode($space['space_key'])
                . '/pages/' . rawurlencode($page['slug']) . '/images',
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
        $current = $repository->findBySlug((int) $space['id'], $slug)
            ?? $repository->redirectTarget((int) $space['id'], $slug);
        if ($current === null) {
            return $this->render('errors/404', ['title' => 'Page not found'], 404);
        }
        if (!(new PageAclService($this->app))->canEdit($current, $space)) {
            return $this->render('errors/403', ['title' => 'Permission denied'], 403);
        }

        try {
            $user = $this->app->auth()->user();
            if ($request->input('editor_user_id') !== null && (int) $request->input('editor_user_id') !== (int) $user['id']) {
                throw new \DomainException('The signed-in account changed. Reopen the editor for this account.');
            }
            $saved = (new \OpenWiki\Wiki\PageWriteService($this->app->database(), $this->app->basePath()))->save(
                $space, $current, (array) $request->input(), (int) $user['id'], $request,
                $this->pageWritePolicy($space), $this->app->auth()->can('attachment.upload')
            );
            $url = '/spaces/' . rawurlencode($space['space_key']) . '/pages/' . rawurlencode($saved['slug']) . ((bool) $space['review_workflow'] || ($saved['published_revision'] !== null && $saved['workflow_state'] !== 'published') ? '/review' : '');
            if ($request->expectsJson()) {
                return Response::json(['data' => ['page' => $saved + ['space_key' => $space['space_key']], 'url' => $url, 'user_id' => (int) $user['id']]]);
            }
            Session::flash('success', 'Page saved.');
            return Response::redirect($url);
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

        $pageAccess = new PageAclService($this->app);
        if (!$pageAccess->canView($page, $space)) {
            return $this->render('errors/403', ['title' => 'Permission denied'], 403);
        }

        $canReadWorking = $pageAccess->canReadWorking($page, $space);
        return $this->render('pages/history', [
            'title' => 'History: ' . $page['title'],
            'space' => $space,
            'page' => $page,
            'revisions' => array_values(array_filter($repository->revisionSummaries((int) $page['id']), static fn (array $r): bool => (bool) $r['was_published'] || $canReadWorking)),
            'diff' => $this->revisionDiff($request, $page, $space, $repository, $pageAccess),
            'canRestore' => $pageAccess->canEdit($page, $space),
            'canRestorePublished' => $pageAccess->can($page, $space, 'page.publish'),
            'canRestoreArchived' => $pageAccess->can($page, $space, 'page.archive'),
        ]);
    }

    public function revision(Request $request, string $spaceKey, string $slug, string $number): Response
    {
        $space = $this->space($spaceKey);
        if ($space instanceof Response) return $space;
        $repository = new PageRepository($this->app->database());
        $page = $repository->findBySlug((int) $space['id'], $slug);
        if ($page === null || !ctype_digit($number)) return $this->render('errors/404', ['title' => 'Revision not found'], 404);
        if (!(new PageAclService($this->app))->canView($page, $space)) return $this->render('errors/403', ['title' => 'Permission denied'], 403);
        $revision = $repository->revision((int) $page['id'], (int) $number);
        if ($revision === null || (!(bool) $revision['was_published'] && !(new PageAclService($this->app))->canReadWorking($page, $space))) return $this->render('errors/404', ['title' => 'Revision not found'], 404);
        $safeRevision = (new \OpenWiki\Wiki\ContentService())->safeProjection($revision);
        $safeRevision['content_html'] = (new \OpenWiki\Wiki\RevisionAccessService($this->app->database()))->renderedHtml($safeRevision['content_html'], (int) $page['id'], (int) $revision['revision_number']);
        // Historical source is immutable; read-time sanitation never writes it back.
        return $this->render('pages/revision', ['title' => $revision['title'], 'space' => $space, 'page' => $page, 'revision' => $safeRevision, 'html' => $safeRevision['content_html']]);
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
        $page = $repository->findBySlug((int) $space['id'], $slug)
            ?? $repository->redirectTarget((int) $space['id'], $slug);
        $revisionNumber = filter_var($request->input('revision'), FILTER_VALIDATE_INT);

        if ($page === null || $revisionNumber === false || $revisionNumber < 1) {
            return $this->render('errors/404', ['title' => 'Revision not found'], 404);
        }
        if (!(new PageAclService($this->app))->canEdit($page, $space)) {
            return $this->render('errors/403', ['title' => 'Permission denied'], 403);
        }

        $revision = $repository->revision((int) $page['id'], (int) $revisionNumber);
        if ($revision === null) {
            return $this->render('errors/404', ['title' => 'Revision not found'], 404);
        }

        try {
            $input = (new \OpenWiki\Wiki\RevisionAccessService($this->app->database()))->restoreInput($page, $revision, $request->input('base_version'), (string) $request->input('restore_conversion', '') === '1');
            $saved = (new \OpenWiki\Wiki\PageWriteService($this->app->database(), $this->app->basePath()))->save(
                $space, $page, $input, (int) $this->app->auth()->user()['id'], $request,
                $this->pageWritePolicy($space), $this->app->auth()->can('attachment.upload'), (int) $revisionNumber
            );
            Session::flash('success', 'Revision restored as a new version.');
            return Response::redirect('/spaces/' . rawurlencode($space['space_key']) . '/pages/' . rawurlencode($saved['slug']) . ((bool) $space['review_workflow'] || ($saved['published_revision'] !== null && $saved['workflow_state'] !== 'published') ? '/review' : ''));
        } catch (\Throwable $exception) {
            return $this->editorError($request, $space, $page, $exception, $input ?? null);
        }
    }

    private function visibleTree(array $space): array
    {
        return (new PageAclService($this->app))->visiblePages($space,
            fn (): array => (new PageRepository($this->app->database()))->tree((int) $space['id']));
    }

    private function revisionDiff(Request $request, array $page, array $space, PageRepository $repository, PageAclService $acl): ?array
    {
        if ($request->query('from') === null && $request->query('to') === null) return null;
        $from = filter_var($request->query('from'), FILTER_VALIDATE_INT);
        $to = filter_var($request->query('to'), FILTER_VALIDATE_INT);
        $a = $from === false ? null : $repository->revision((int) $page['id'], (int) $from);
        $b = $to === false ? null : $repository->revision((int) $page['id'], (int) $to);
        if ($a === null || $b === null || (!$acl->canReadWorking($page, $space) && (!(bool) $a['was_published'] || !(bool) $b['was_published']))) return ['error' => 'Wybrane rewizje nie są dostępne.'];
        foreach (['a', 'b'] as $key) {
            $revision = &${$key};
            $parent = $revision['parent_id'] === null ? null : $repository->findById((int) $revision['parent_id']);
            if ($revision['parent_id'] !== null && ($parent === null || (int) $parent['space_id'] !== (int) $space['id'] || !$acl->canView($parent, $space))) $revision['parent_id'] = 'Niedostępny poziom';
            unset($revision);
        }
        return ['from' => $from, 'to' => $to] + \OpenWiki\Wiki\RevisionDiff::compare($a, $b);
    }

    private function space(string $spaceKey): array|Response
    {
        $space = (new SpaceRepository($this->app->database()))->findByKey($spaceKey);
        return $space ?? $this->render('errors/404', ['title' => 'Space not found'], 404);
    }

    private function editorError(Request $request, array $space, ?array $page, \Throwable $exception, ?array $document = null): Response
    {
        error_log('[OpenWiki page save] ' . $exception->getMessage());

        $message = match (true) {
            $exception->getMessage() === 'EDIT_CONFLICT' => 'This page changed after you opened the editor. Compare all three versions and resolve the conflict explicitly.',
            str_contains(strtolower($exception->getMessage()), 'duplicate') => 'A page with that slug already exists in this space.',
            $exception instanceof \InvalidArgumentException, $exception instanceof \DomainException => $exception->getMessage(),
            default => 'Unable to save the page.',
        };

        $repository = new PageRepository($this->app->database());
        $old = $document ?? (array) $request->input();
        if (is_array($old['document'] ?? null)) {
            try {
                $source = (new \OpenWiki\Wiki\ContentService())->document($old['document']);
                $old['content_format'] = $source['format']; $old['content_html'] = $source['html']; $old['content_markdown'] = $source['markdown'] ?? '';
            } catch (\InvalidArgumentException) { /* Render safely retained legacy fields for an invalid envelope. */ }
        }
        $baseVersion = (int) ($old['base_version'] ?? 0);
        $conflict = null;
        if ($exception->getMessage() === 'EDIT_CONFLICT' && $page !== null) {
            $server = $repository->findById((int) $page['id']);
            if ($server !== null) $server = $repository->working($server);
            if ($server !== null && !$this->pageWritePolicy($space)('page.view', $server)) {
                $exception = new \DomainException('You no longer have permission to access the current page. Your local document remains recoverable.');
                $message = $exception->getMessage();
            } else {
            $conflict = [
                'base_version' => $baseVersion,
                'base' => (new \OpenWiki\Wiki\ContentService())->safeProjection($repository->revision((int) $page['id'], $baseVersion)),
                'local' => $old,
                'server' => (new \OpenWiki\Wiki\ContentService())->safeProjection($server),
            ];
            if ($conflict['server'] !== null) {
                $conflict['server']['tags'] = array_column((new WikiMetadataService($this->app->database()))->tagsForPage((int) $page['id']), 'name');
            }
            }
        }
        $status = match (true) {
            $exception->getMessage() === 'EDIT_CONFLICT' => 409,
            $exception instanceof \DomainException => 403,
            $exception instanceof \InvalidArgumentException => 422,
            str_contains(strtolower($exception->getMessage()), 'duplicate') => 422,
            default => 500,
        };
        if ($request->expectsJson()) {
            return Response::json(['error' => ['message' => $message, 'conflict' => $conflict]], $status);
        }
        $old['content_html'] = (new \OpenWiki\Security\HtmlSanitizer())->sanitize(is_string($old['content_html'] ?? null) ? $old['content_html'] : '');


        return $this->render('pages/editor', [
            'title' => $page === null ? 'Create page' : 'Edit ' . $page['title'],
            'space' => $space,
            'page' => $page,
            'serverDraft' => null,
            'pages' => $this->visibleTree($space),
            'templates' => $page === null
                ? (new \OpenWiki\Wiki\PageTemplateService($this->app->database()))->catalog()
                : [],
            'workspaceSpaces' => (new \OpenWiki\Wiki\PageWorkspaceService($this->app))->spaces($page),
            'tags' => $page === null
                ? []
                : (new WikiMetadataService($this->app->database()))->tagsForPage((int) $page['id']),
            'canPublish' => !(bool) $space['review_workflow'] && $this->pageWritePolicy($space)('page.publish', $page ?? null),
            'canArchive' => $this->pageWritePolicy($space)('page.archive', $page ?? null),
            'canUploadImage' => $this->app->auth()->can('attachment.upload'),
            'imageUploadUrl' => $page === null
                ? null
                : '/spaces/' . rawurlencode($space['space_key'])
                    . '/pages/' . rawurlencode($page['slug']) . '/images',
            'formAction' => $page === null
                ? '/spaces/' . rawurlencode($space['space_key']) . '/pages'
                : '/spaces/' . rawurlencode($space['space_key']) . '/pages/' . rawurlencode($page['slug']),
            'formError' => $message,
            'old' => $old,
            'conflict' => $conflict,
        ], $status);
    }
}
