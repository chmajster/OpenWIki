<?php

declare(strict_types=1);

namespace OpenWiki\Http\Controllers;

use OpenWiki\Attachments\AttachmentService;
use OpenWiki\Audit\AuditLogger;
use OpenWiki\Core\Request;
use OpenWiki\Core\Response;
use OpenWiki\Core\Session;
use OpenWiki\Http\Controller;
use OpenWiki\Images\InlineImageService;
use OpenWiki\Permissions\PageAclService;
use OpenWiki\Permissions\SpaceAccessService;
use OpenWiki\Repositories\PageRepository;
use OpenWiki\Repositories\SpaceRepository;
use OpenWiki\Wiki\CommentService;
use OpenWiki\Wiki\ContentService;
use OpenWiki\Wiki\EditSessionService;
use OpenWiki\Wiki\MacroService;
use OpenWiki\Wiki\PageEngagementService;
use OpenWiki\Wiki\Slugger;
use OpenWiki\Wiki\WikiMetadataService;
use OpenWiki\Webhooks\WebhookService;

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
            'pages' => $this->visibleTree($space),
            'templates' => $templates,
            'tags' => [],
            'canPublish' => $this->app->auth()->can('page.publish'),
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
            $data = $this->validatedPageData($request, $space, null);
            $user = $this->app->auth()->user();
            $data['space_id'] = (int) $space['id'];
            $data['author_id'] = (int) $user['id'];
            $data['owner_id'] = (int) $user['id'];

            if (
                str_contains((string) $data['content_html'], 'data:image/')
                && !$this->app->auth()->can('attachment.upload')
            ) {
                throw new \InvalidArgumentException('You do not have permission to upload pasted images.');
            }

            $metadata = new WikiMetadataService($this->app->database());
            $tagInput = (string) $request->input('tags', '');
            $metadata->normalizeTags($tagInput);
            $decorated = $metadata->decorateWikiLinks(
                (int) $space['id'],
                (string) $space['space_key'],
                (string) $data['content_html']
            );
            $data['content_html'] = $decorated['html'];

            $inlineImages = new InlineImageService(
                $this->app->database(),
                $this->app->basePath()
            );

            try {
                $pageId = $this->app->database()->transaction(
                    function () use ($data, $metadata, $tagInput, $decorated, $space, $user, $inlineImages): int {
                        $id = (new PageRepository($this->app->database()))->create($data);

                        $materialized = $inlineImages->materializeDataImages(
                            (string) $data['content_html'],
                            $id,
                            (int) $user['id']
                        );
                        if ($materialized['attachment_ids'] !== []) {
                            $this->app->database()->execute(
                                'UPDATE pages
                                 SET content_html = :content_html, content_text = :content_text
                                 WHERE id = :id',
                                [
                                    'content_html' => $materialized['html'],
                                    'content_text' => $materialized['text'],
                                    'id' => $id,
                                ]
                            );
                            $this->app->database()->execute(
                                'UPDATE page_revisions
                                 SET content_html = :content_html, content_text = :content_text
                                 WHERE page_id = :page_id AND revision_number = 1',
                                [
                                    'content_html' => $materialized['html'],
                                    'content_text' => $materialized['text'],
                                    'page_id' => $id,
                                ]
                            );
                        }

                        $metadata->syncTags($id, $tagInput);
                        $metadata->syncLinks($id, (int) $space['id'], $decorated['references']);
                        $metadata->refreshSpaceLinks((int) $space['id']);
                        return $id;
                    }
                );
                $inlineImages->clearTracking();
            } catch (\Throwable $imageTransactionError) {
                $inlineImages->cleanupCreatedFiles();
                throw $imageTransactionError;
            }

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

            try {
                $webhooks = new WebhookService($this->app->database());
                $payload = [
                    'id' => $pageId,
                    'space_id' => (int) $space['id'],
                    'title' => $data['title'],
                    'slug' => $data['slug'],
                    'status' => $data['status'],
                    'author_id' => (int) $user['id'],
                ];
                $webhooks->queue('page.created', $payload);
                if ($data['status'] === 'published') {
                    $webhooks->queue('page.published', $payload);
                }
            } catch (\Throwable $webhookError) {
                error_log('[OpenWiki webhook queue] ' . $webhookError->getMessage());
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

        $rendered = (new MacroService($this->app))->renderPage($page, $space);

        return $this->render('pages/show', [
            'title' => $page['title'],
            'space' => $space,
            'page' => $page,
            'pages' => array_values(array_filter(
                $repository->tree((int) $space['id']),
                fn (array $treePage): bool => $pageAccess->canView($treePage, $space)
            )),
            'canEdit' => $pageAccess->canEdit($page, $space),
            'canManagePermissions' => $pageAccess->canEdit($page, $space),
            'canDeletePage' => $user !== null && $pageAccess->can($page, $space, 'page.delete'),
            'isFavorite' => $user !== null && $engagement->isFavorite((int) $user['id'], (int) $page['id']),
            'isWatching' => $user !== null && $engagement->isWatching((int) $user['id'], 'page', (int) $page['id']),
            'comments' => (new CommentService($this->app->database()))->listForPage((int) $page['id']),
            'canComment' => $user !== null && $this->app->auth()->can('comment.create'),
            'canDeleteComment' => $user !== null && $this->app->auth()->can('comment.delete'),
            'attachments' => (new AttachmentService($this->app->database(), $this->app->basePath()))->listForPage((int) $page['id']),
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
            'pages' => $this->visibleTree($space),
            'templates' => [],
            'tags' => (new WikiMetadataService($this->app->database()))->tagsForPage((int) $page['id']),
            'canPublish' => $this->app->auth()->can('page.publish'),
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
        $current = $repository->findBySlug((int) $space['id'], $slug);
        if ($current === null) {
            return $this->render('errors/404', ['title' => 'Page not found'], 404);
        }
        if (!(new PageAclService($this->app))->canEdit($current, $space)) {
            return $this->render('errors/403', ['title' => 'Permission denied'], 403);
        }

        try {
            $data = $this->validatedPageData($request, $space, $current);
            $user = $this->app->auth()->user();
            $data['author_id'] = (int) $user['id'];

            $baseVersion = filter_var($request->input('base_version'), FILTER_VALIDATE_INT);
            if ($baseVersion === false || $baseVersion < 1) {
                throw new \InvalidArgumentException('Invalid base page version.');
            }
            $data['base_version'] = (int) $baseVersion;

            if (
                str_contains((string) $data['content_html'], 'data:image/')
                && !$this->app->auth()->can('attachment.upload')
            ) {
                throw new \InvalidArgumentException('You do not have permission to upload pasted images.');
            }

            $metadata = new WikiMetadataService($this->app->database());
            $tagInput = (string) $request->input('tags', '');
            $metadata->normalizeTags($tagInput);
            $decorated = $metadata->decorateWikiLinks(
                (int) $space['id'],
                (string) $space['space_key'],
                (string) $data['content_html']
            );
            $data['content_html'] = $decorated['html'];

            $before = ['title' => $current['title'], 'slug' => $current['slug'], 'status' => $current['status'], 'version' => $current['version']];
            $inlineImages = new InlineImageService(
                $this->app->database(),
                $this->app->basePath()
            );

            try {
                $result = $this->app->database()->transaction(
                    function () use (
                        $repository,
                        $current,
                        $data,
                        $metadata,
                        $tagInput,
                        $decorated,
                        $space,
                        $user,
                        $inlineImages
                    ): array {
                        $updated = $repository->update($current, $data);

                        $materialized = $inlineImages->materializeDataImages(
                            (string) $data['content_html'],
                            (int) $current['id'],
                            (int) $user['id']
                        );
                        if ($materialized['attachment_ids'] !== []) {
                            $this->app->database()->execute(
                                'UPDATE pages
                                 SET content_html = :content_html, content_text = :content_text
                                 WHERE id = :id',
                                [
                                    'content_html' => $materialized['html'],
                                    'content_text' => $materialized['text'],
                                    'id' => (int) $current['id'],
                                ]
                            );
                            $this->app->database()->execute(
                                'UPDATE page_revisions
                                 SET content_html = :content_html, content_text = :content_text
                                 WHERE page_id = :page_id AND revision_number = :revision_number',
                                [
                                    'content_html' => $materialized['html'],
                                    'content_text' => $materialized['text'],
                                    'page_id' => (int) $current['id'],
                                    'revision_number' => (int) $updated['version'],
                                ]
                            );
                        }

                        $metadata->syncTags((int) $current['id'], $tagInput);
                        $metadata->syncLinks((int) $current['id'], (int) $space['id'], $decorated['references']);
                        $metadata->refreshSpaceLinks((int) $space['id']);
                        return $updated;
                    }
                );
                $inlineImages->clearTracking();
            } catch (\Throwable $imageTransactionError) {
                $inlineImages->cleanupCreatedFiles();
                throw $imageTransactionError;
            }

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

            try {
                $webhooks = new WebhookService($this->app->database());
                $payload = [
                    'id' => (int) $current['id'],
                    'space_id' => (int) $space['id'],
                    'title' => $data['title'],
                    'slug' => $data['slug'],
                    'status' => $data['status'],
                    'version' => $result['version'],
                    'author_id' => (int) $user['id'],
                ];
                $webhooks->queue('page.updated', $payload);
                if ($data['status'] === 'published' && $current['status'] !== 'published') {
                    $webhooks->queue('page.published', $payload);
                }
            } catch (\Throwable $webhookError) {
                error_log('[OpenWiki webhook queue] ' . $webhookError->getMessage());
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

        $pageAccess = new PageAclService($this->app);
        if (!$pageAccess->canView($page, $space)) {
            return $this->render('errors/403', ['title' => 'Permission denied'], 403);
        }

        return $this->render('pages/history', [
            'title' => 'History: ' . $page['title'],
            'space' => $space,
            'page' => $page,
            'revisions' => $repository->revisions((int) $page['id']),
            'canRestore' => $pageAccess->canEdit($page, $space),
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
        if (!(new PageAclService($this->app))->canEdit($page, $space)) {
            return $this->render('errors/403', ['title' => 'Permission denied'], 403);
        }

        $revision = $repository->revision((int) $page['id'], (int) $revisionNumber);
        if ($revision === null) {
            return $this->render('errors/404', ['title' => 'Revision not found'], 404);
        }

        if ($revision['status'] === 'published' && !$this->app->auth()->can('page.publish')) {
            return $this->render('errors/403', ['title' => 'Publishing permission required'], 403);
        }

        $user = $this->app->auth()->user();
        $metadata = new WikiMetadataService($this->app->database());
        $decorated = $metadata->decorateWikiLinks(
            (int) $space['id'],
            (string) $space['space_key'],
            (string) $revision['content_html']
        );
        $revision['content_html'] = $decorated['html'];

        $newVersion = $this->app->database()->transaction(
            function () use ($repository, $page, $revision, $user, $metadata, $decorated, $space): int {
                $version = $repository->restore($page, $revision, (int) $user['id']);
                $metadata->syncLinks((int) $page['id'], (int) $space['id'], $decorated['references']);
                $metadata->refreshSpaceLinks((int) $space['id']);
                return $version;
            }
        );

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
        if ($current !== null) {
            $pageAccess = new PageAclService($this->app);
            if ($status === 'published' && !$pageAccess->can($current, $space, 'page.publish')) {
                throw new \InvalidArgumentException('Page ACL does not allow publishing this page.');
            }
            if ($status === 'archived' && !$pageAccess->can($current, $space, 'page.archive')) {
                throw new \InvalidArgumentException('Page ACL does not allow archiving this page.');
            }
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

            $pageAccess = new PageAclService($this->app);
            if ($current === null) {
                if (!$pageAccess->can($parent, $space, 'page.create')) {
                    throw new \InvalidArgumentException('You cannot create a child page under the selected parent.');
                }
            } else {
                $parentChanged = (int) ($current['parent_id'] ?? 0) !== (int) $parentId;
                if ($parentChanged) {
                    if (
                        !$this->app->auth()->can('page.move')
                        || !$pageAccess->can($current, $space, 'page.move')
                        || !$pageAccess->canView($parent, $space)
                    ) {
                        throw new \InvalidArgumentException('You do not have permission to move this page to the selected parent.');
                    }
                }
            }

            if (
                $current !== null
                && (new PageRepository($this->app->database()))->wouldCreateCycle((int) $current['id'], (int) $parentId)
            ) {
                throw new \InvalidArgumentException('The selected parent would create a cycle in the page tree.');
            }
        }

        if (
            $current !== null
            && $parentId === null
            && $current['parent_id'] !== null
            && (
                !$this->app->auth()->can('page.move')
                || !(new PageAclService($this->app))->can($current, $space, 'page.move')
            )
        ) {
            throw new \InvalidArgumentException('You do not have permission to move this page to the root level.');
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

    private function visibleTree(array $space): array
    {
        $access = new PageAclService($this->app);
        $pages = (new PageRepository($this->app->database()))->tree((int) $space['id']);

        return array_values(array_filter(
            $pages,
            fn (array $page): bool => $access->canView($page, $space)
        ));
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
            'tags' => $page === null
                ? []
                : (new WikiMetadataService($this->app->database()))->tagsForPage((int) $page['id']),
            'canPublish' => $this->app->auth()->can('page.publish'),
            'canUploadImage' => $this->app->auth()->can('attachment.upload'),
            'imageUploadUrl' => $page === null
                ? null
                : '/spaces/' . rawurlencode($space['space_key'])
                    . '/pages/' . rawurlencode($page['slug']) . '/images',
            'formAction' => $page === null
                ? '/spaces/' . rawurlencode($space['space_key']) . '/pages'
                : '/spaces/' . rawurlencode($space['space_key']) . '/pages/' . rawurlencode($page['slug']),
            'formError' => $message,
            'old' => (array) $request->input(),
        ], $exception->getMessage() === 'EDIT_CONFLICT' ? 409 : 422);
    }
}
