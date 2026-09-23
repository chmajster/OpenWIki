<?php

declare(strict_types=1);

namespace OpenWiki\Http\Controllers\Api;

use OpenWiki\Admin\DirectoryAdminService;
use OpenWiki\Attachments\AttachmentService;
use OpenWiki\Audit\AuditLogger;
use OpenWiki\Auth\ApiTokenService;
use OpenWiki\Core\Request;
use OpenWiki\Core\Response;
use OpenWiki\Http\Controller;
use OpenWiki\Images\ImageService;
use OpenWiki\Images\InlineImageService;
use OpenWiki\Permissions\PageAclService;
use OpenWiki\Permissions\SpaceAccessService;
use OpenWiki\Repositories\PageRepository;
use OpenWiki\Repositories\SearchRepository;
use OpenWiki\Repositories\SpaceRepository;
use OpenWiki\Security\RateLimiter;
use OpenWiki\Wiki\CommentService;
use OpenWiki\Wiki\ContentService;
use OpenWiki\Wiki\Slugger;
use OpenWiki\Wiki\WikiMetadataService;
use OpenWiki\Webhooks\WebhookService;

final class ApiV1Controller extends Controller
{
    public function spaces(Request $request): Response
    {
        [$context, $failure] = $this->apiAuth($request, 'spaces:read', 'space.view');
        if ($failure !== null) {
            return $failure;
        }

        $spaces = (new SpaceRepository($this->app->database()))->visibleFor(
            (int) $context['user']['id'],
            $this->isSuperAdmin($context)
        );

        $visibility = trim((string) $request->query('visibility', ''));
        if ($visibility !== '') {
            if (!in_array($visibility, ['public', 'private', 'restricted'], true)) {
                return $this->error('validation_error', 'Invalid visibility filter.', 422);
            }
            $spaces = array_values(array_filter(
                $spaces,
                static fn (array $space): bool => $space['visibility'] === $visibility
            ));
        }

        $sort = (string) $request->query('sort', 'name');
        if (!in_array($sort, ['name', 'created_at', 'updated_at'], true)) {
            return $this->error('validation_error', 'Invalid sort field.', 422);
        }
        $order = strtolower((string) $request->query('order', 'asc'));
        if (!in_array($order, ['asc', 'desc'], true)) {
            return $this->error('validation_error', 'Invalid sort order.', 422);
        }

        usort($spaces, static function (array $a, array $b) use ($sort, $order): int {
            $result = strcmp((string) $a[$sort], (string) $b[$sort]);
            return $order === 'asc' ? $result : -$result;
        });

        [$page, $perPage, $offset] = $this->pagination($request);
        $total = count($spaces);
        $data = array_slice($spaces, $offset, $perPage);

        return Response::json([
            'data' => array_map([$this, 'spaceResource'], $data),
            'meta' => $this->paginationMeta($page, $perPage, $total),
        ]);
    }

    public function createSpace(Request $request): Response
    {
        [$context, $failure] = $this->apiAuth($request, 'spaces:write', 'space.create');
        if ($failure !== null) {
            return $failure;
        }

        try {
            $name = trim((string) $request->input('name', ''));
            if ($name === '' || mb_strlen($name) > 191) {
                throw new \InvalidArgumentException('Space name is required and may contain at most 191 characters.');
            }

            $keyInput = trim((string) $request->input('space_key', ''));
            $key = (new Slugger())->spaceKey($keyInput === '' ? $name : $keyInput);
            $visibility = (string) $request->input('visibility', 'private');
            if (!in_array($visibility, ['public', 'private', 'restricted'], true)) {
                throw new \InvalidArgumentException('Invalid space visibility.');
            }

            $id = (new SpaceRepository($this->app->database()))->create([
                'name' => $name,
                'space_key' => $key,
                'description' => mb_substr(trim((string) $request->input('description', '')), 0, 5000) ?: null,
                'owner_id' => (int) $context['user']['id'],
                'visibility' => $visibility,
            ]);

            $space = (new SpaceRepository($this->app->database()))->findByKey($key);

            (new AuditLogger($this->app->database()))->log(
                'SPACE_CREATED',
                'space',
                $id,
                (int) $context['user']['id'],
                $request,
                null,
                ['name' => $name, 'space_key' => $key, 'visibility' => $visibility]
            );

            return Response::json(['data' => $this->spaceResource($space ?? ['id' => $id, 'name' => $name, 'space_key' => $key, 'description' => null, 'visibility' => $visibility, 'status' => 'active', 'created_at' => null, 'updated_at' => null])], 201);
        } catch (\InvalidArgumentException $exception) {
            return $this->error('validation_error', $exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            if (str_contains(strtolower($exception->getMessage()), 'duplicate')) {
                return $this->error('conflict', 'A space with this key already exists.', 409);
            }
            error_log('[OpenWiki API space] ' . $exception->getMessage());
            return $this->error('server_error', 'Unable to create space.', 500);
        }
    }

    public function space(Request $request, string $id): Response
    {
        [$context, $failure] = $this->apiAuth($request, 'spaces:read', 'space.view');
        if ($failure !== null) {
            return $failure;
        }

        $spaceId = $this->positiveId($id);
        if ($spaceId === null) {
            return $this->error('not_found', 'Space not found.', 404);
        }

        $space = $this->app->database()->fetchOne(
            'SELECT * FROM spaces WHERE id = :id AND deleted_at IS NULL LIMIT 1',
            ['id' => $spaceId]
        );
        if ($space === null || !(new SpaceAccessService($this->app))->canViewFor(
            $space,
            (int) $context['user']['id'],
            $this->isSuperAdmin($context)
        )) {
            return $this->error('not_found', 'Space not found.', 404);
        }

        return Response::json(['data' => $this->spaceResource($space)]);
    }

    public function updateSpace(Request $request, string $id): Response
    {
        [$context, $failure] = $this->apiAuth($request, 'spaces:write', 'space.edit');
        if ($failure !== null) {
            return $failure;
        }

        $spaceId = $this->positiveId($id);
        if ($spaceId === null) {
            return $this->error('not_found', 'Space not found.', 404);
        }

        $space = $this->app->database()->fetchOne(
            'SELECT * FROM spaces WHERE id = :id AND deleted_at IS NULL LIMIT 1',
            ['id' => $spaceId]
        );
        if ($space === null) {
            return $this->error('not_found', 'Space not found.', 404);
        }

        if (!(new SpaceAccessService($this->app))->canEditFor(
            $space,
            (int) $context['user']['id'],
            $this->isSuperAdmin($context)
        )) {
            return $this->error('forbidden', 'You cannot edit this Space.', 403);
        }

        try {
            $name = trim((string) $request->input('name', $space['name']));
            if ($name === '' || mb_strlen($name) > 191) {
                throw new \InvalidArgumentException(
                    'Space name is required and may contain at most 191 characters.'
                );
            }

            $description = trim((string) $request->input(
                'description',
                (string) ($space['description'] ?? '')
            ));
            if (mb_strlen($description) > 5000) {
                throw new \InvalidArgumentException(
                    'Space description may contain at most 5000 characters.'
                );
            }

            $visibility = (string) $request->input('visibility', $space['visibility']);
            if (!in_array($visibility, ['public', 'private', 'restricted'], true)) {
                throw new \InvalidArgumentException('Invalid Space visibility.');
            }

            $before = [
                'name' => $space['name'],
                'description' => $space['description'],
                'visibility' => $space['visibility'],
            ];

            $this->app->database()->execute(
                'UPDATE spaces
                 SET name = :name,
                     description = :description,
                     visibility = :visibility,
                     updated_at = UTC_TIMESTAMP()
                 WHERE id = :id AND deleted_at IS NULL',
                [
                    'name' => $name,
                    'description' => $description === '' ? null : $description,
                    'visibility' => $visibility,
                    'id' => $spaceId,
                ]
            );

            $updated = $this->app->database()->fetchOne(
                'SELECT * FROM spaces WHERE id = :id AND deleted_at IS NULL LIMIT 1',
                ['id' => $spaceId]
            );
            if ($updated === null) {
                throw new \RuntimeException('Updated Space cannot be resolved.');
            }

            (new AuditLogger($this->app->database()))->log(
                'SPACE_UPDATED',
                'space',
                $spaceId,
                (int) $context['user']['id'],
                $request,
                $before,
                [
                    'name' => $updated['name'],
                    'description' => $updated['description'],
                    'visibility' => $updated['visibility'],
                ]
            );

            return Response::json(['data' => $this->spaceResource($updated)]);
        } catch (\InvalidArgumentException $exception) {
            return $this->error('validation_error', $exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            error_log('[OpenWiki API space update] ' . $exception->getMessage());
            return $this->error('server_error', 'Unable to update Space.', 500);
        }
    }

    public function deleteSpace(Request $request, string $id): Response
    {
        [$context, $failure] = $this->apiAuth($request, 'spaces:write', 'space.delete');
        if ($failure !== null) {
            return $failure;
        }

        $spaceId = $this->positiveId($id);
        if ($spaceId === null) {
            return $this->error('not_found', 'Space not found.', 404);
        }

        $space = $this->app->database()->fetchOne(
            'SELECT * FROM spaces WHERE id = :id AND deleted_at IS NULL LIMIT 1',
            ['id' => $spaceId]
        );
        if ($space === null) {
            return $this->error('not_found', 'Space not found.', 404);
        }

        if (!(new SpaceAccessService($this->app))->canEditFor(
            $space,
            (int) $context['user']['id'],
            $this->isSuperAdmin($context)
        )) {
            return $this->error('forbidden', 'You cannot delete this Space.', 403);
        }

        $this->app->database()->execute(
            'UPDATE spaces
             SET deleted_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
             WHERE id = :id AND deleted_at IS NULL',
            ['id' => $spaceId]
        );

        (new AuditLogger($this->app->database()))->log(
            'SPACE_DELETED',
            'space',
            $spaceId,
            (int) $context['user']['id'],
            $request,
            [
                'name' => $space['name'],
                'space_key' => $space['space_key'],
                'visibility' => $space['visibility'],
            ],
            ['deleted' => true]
        );

        return new Response('', 204);
    }

    public function pages(Request $request): Response
    {
        [$context, $failure] = $this->apiAuth($request, 'pages:read', 'page.view');
        if ($failure !== null) {
            return $failure;
        }

        $state = $this->spaceState($context, trim((string) $request->query('space', '')));
        if ($state instanceof Response) {
            return $state;
        }

        [$page, $perPage, $offset] = $this->pagination($request);
        $sort = (string) $request->query('sort', 'updated_at');
        $sortMap = ['updated_at' => 'p.updated_at', 'created_at' => 'p.created_at', 'title' => 'p.title'];
        if (!isset($sortMap[$sort])) {
            return $this->error('validation_error', 'Invalid sort field.', 422);
        }

        $order = strtolower((string) $request->query('order', 'desc'));
        if (!in_array($order, ['asc', 'desc'], true)) {
            return $this->error('validation_error', 'Invalid sort order.', 422);
        }

        if ($state['visible_ids'] === []) {
            return Response::json(['data' => [], 'meta' => $this->paginationMeta($page, $perPage, 0)]);
        }

        $where = 'p.deleted_at IS NULL AND p.space_id IN ('
            . implode(',', array_fill(0, count($state['visible_ids']), '?')) . ')';
        $params = $state['visible_ids'];

        $query = trim((string) $request->query('q', ''));
        if ($query !== '') {
            $where .= ' AND (p.title LIKE ? OR p.content_text LIKE ?)';
            $like = '%' . $query . '%';
            $params[] = $like;
            $params[] = $like;
        }

        $rows = $this->app->database()->fetchAll(
            'SELECT p.*, u.username AS author_username,
                    s.space_key, s.name AS space_name, s.visibility AS space_visibility,
                    s.owner_id AS space_owner_id, s.status AS space_status, s.deleted_at AS space_deleted_at
             FROM pages p
             INNER JOIN spaces s ON s.id = p.space_id
             INNER JOIN users u ON u.id = p.author_id
             WHERE ' . $where . '
             ORDER BY ' . $sortMap[$sort] . ' ' . strtoupper($order),
            $params
        );

        $filtered = [];
        foreach ($rows as $row) {
            if ($this->canViewPage($context, $row)) {
                $filtered[] = $row;
            }
        }

        $total = count($filtered);
        $slice = array_slice($filtered, $offset, $perPage);

        return Response::json([
            'data' => array_map([$this, 'pageListResource'], $slice),
            'meta' => $this->paginationMeta($page, $perPage, $total),
        ]);
    }

    public function page(Request $request, string $id): Response
    {
        [$context, $failure] = $this->apiAuth($request, 'pages:read', 'page.view');
        if ($failure !== null) {
            return $failure;
        }

        $pageId = $this->positiveId($id);
        if ($pageId === null) {
            return $this->error('not_found', 'Page not found.', 404);
        }

        $row = $this->pageRow($pageId);
        if ($row === null || !$this->canViewPage($context, $row)) {
            return $this->error('not_found', 'Page not found.', 404);
        }

        return Response::json(['data' => $this->pageResource($row)]);
    }

    public function createPage(Request $request): Response
    {
        [$context, $failure] = $this->apiAuth($request, 'pages:write', 'page.create');
        if ($failure !== null) {
            return $failure;
        }

        $space = $this->resolveSpace($request);
        if ($space instanceof Response) {
            return $space;
        }

        $access = new SpaceAccessService($this->app);
        if (!$access->canEditFor($space, (int) $context['user']['id'], $this->isSuperAdmin($context))) {
            return $this->error('forbidden', 'You cannot create pages in this space.', 403);
        }

        try {
            $data = $this->validatedPageData($request, $context, $space, null);
            $data['space_id'] = (int) $space['id'];
            $data['author_id'] = (int) $context['user']['id'];
            $data['owner_id'] = (int) $context['user']['id'];

            $metadata = new WikiMetadataService($this->app->database());
            $tagInput = $request->input('tags', []);
            if (!is_string($tagInput) && !is_array($tagInput) && $tagInput !== null) {
                throw new \InvalidArgumentException('tags must be a string or array.');
            }
            $metadata->normalizeTags($tagInput);
            $decorated = $metadata->decorateWikiLinks(
                (int) $space['id'],
                (string) $space['space_key'],
                (string) $data['content_html']
            );
            $data['content_html'] = $decorated['html'];

            $tokenService = new ApiTokenService($this->app->database());
            if (str_contains((string) $data['content_html'], 'data:image/')) {
                if (
                    !$tokenService->hasScope($context, 'attachments:write')
                    || !$tokenService->hasPermission($context, 'attachment.upload')
                ) {
                    throw new \InvalidArgumentException(
                        'Inline images require attachments:write scope and attachment.upload permission.'
                    );
                }
            }

            $inlineImages = new InlineImageService(
                $this->app->database(),
                $this->app->basePath()
            );

            try {
                $id = $this->app->database()->transaction(
                    function () use (
                        $data,
                        $metadata,
                        $tagInput,
                        $decorated,
                        $space,
                        $context,
                        $inlineImages
                    ): int {
                        $pageId = (new PageRepository($this->app->database()))->create($data);

                        $materialized = $inlineImages->materializeDataImages(
                            (string) $data['content_html'],
                            $pageId,
                            (int) $context['user']['id']
                        );
                        if ($materialized['attachment_ids'] !== []) {
                            $this->app->database()->execute(
                                'UPDATE pages
                                 SET content_html = :content_html, content_text = :content_text
                                 WHERE id = :id',
                                [
                                    'content_html' => $materialized['html'],
                                    'content_text' => $materialized['text'],
                                    'id' => $pageId,
                                ]
                            );
                            $this->app->database()->execute(
                                'UPDATE page_revisions
                                 SET content_html = :content_html, content_text = :content_text
                                 WHERE page_id = :page_id AND revision_number = 1',
                                [
                                    'content_html' => $materialized['html'],
                                    'content_text' => $materialized['text'],
                                    'page_id' => $pageId,
                                ]
                            );
                        }

                        $metadata->syncTags($pageId, $tagInput);
                        $metadata->syncLinks($pageId, (int) $space['id'], $decorated['references']);
                        $metadata->refreshSpaceLinks((int) $space['id']);
                        return $pageId;
                    }
                );
                $inlineImages->clearTracking();
            } catch (\Throwable $inlineImageError) {
                $inlineImages->cleanupCreatedFiles();
                throw $inlineImageError;
            }
            $row = $this->pageRow($id);

            (new AuditLogger($this->app->database()))->log(
                'PAGE_CREATED',
                'page',
                $id,
                (int) $context['user']['id'],
                $request,
                null,
                ['title' => $data['title'], 'slug' => $data['slug'], 'status' => $data['status']]
            );

            return Response::json(['data' => $this->pageResource($row)], 201);
        } catch (\InvalidArgumentException $exception) {
            return $this->error('validation_error', $exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            if (str_contains(strtolower($exception->getMessage()), 'duplicate')) {
                return $this->error('conflict', 'A page with this slug already exists in the space.', 409);
            }
            error_log('[OpenWiki API page create] ' . $exception->getMessage());
            return $this->error('server_error', 'Unable to create page.', 500);
        }
    }

    public function updatePage(Request $request, string $id): Response
    {
        [$context, $failure] = $this->apiAuth($request, 'pages:write', 'page.edit');
        if ($failure !== null) {
            return $failure;
        }

        $pageId = $this->positiveId($id);
        $row = $pageId === null ? null : $this->pageRow($pageId);
        if ($row === null) {
            return $this->error('not_found', 'Page not found.', 404);
        }

        $space = $this->spaceFromPage($row);
        if (!(new PageAclService($this->app))->canFor(
            $row,
            $space,
            'page.edit',
            (int) $context['user']['id'],
            $this->isSuperAdmin($context),
            true
        )) {
            return $this->error('forbidden', 'You cannot edit this page.', 403);
        }

        $baseVersion = filter_var($request->input('base_version'), FILTER_VALIDATE_INT);
        if ($baseVersion === false || $baseVersion < 1) {
            return $this->error('validation_error', 'base_version is required and must be a positive integer.', 422);
        }

        try {
            $data = $this->validatedPageData($request, $context, $space, $row);
            $data['author_id'] = (int) $context['user']['id'];
            $data['base_version'] = (int) $baseVersion;

            $metadata = new WikiMetadataService($this->app->database());
            $tagInput = $request->input('tags', array_column($metadata->tagsForPage($pageId), 'name'));
            if (!is_string($tagInput) && !is_array($tagInput) && $tagInput !== null) {
                throw new \InvalidArgumentException('tags must be a string or array.');
            }
            $metadata->normalizeTags($tagInput);
            $decorated = $metadata->decorateWikiLinks(
                (int) $space['id'],
                (string) $space['space_key'],
                (string) $data['content_html']
            );
            $data['content_html'] = $decorated['html'];

            $tokenService = new ApiTokenService($this->app->database());
            if (str_contains((string) $data['content_html'], 'data:image/')) {
                if (
                    !$tokenService->hasScope($context, 'attachments:write')
                    || !$tokenService->hasPermission($context, 'attachment.upload')
                ) {
                    throw new \InvalidArgumentException(
                        'Inline images require attachments:write scope and attachment.upload permission.'
                    );
                }
            }

            $before = ['title' => $row['title'], 'slug' => $row['slug'], 'status' => $row['status'], 'version' => (int) $row['version']];
            $inlineImages = new InlineImageService(
                $this->app->database(),
                $this->app->basePath()
            );

            try {
                $result = $this->app->database()->transaction(
                    function () use (
                        $row,
                        $data,
                        $metadata,
                        $tagInput,
                        $decorated,
                        $space,
                        $pageId,
                        $context,
                        $inlineImages
                    ): array {
                        $updatedResult = (new PageRepository($this->app->database()))->update($row, $data);

                        $materialized = $inlineImages->materializeDataImages(
                            (string) $data['content_html'],
                            $pageId,
                            (int) $context['user']['id']
                        );
                        if ($materialized['attachment_ids'] !== []) {
                            $this->app->database()->execute(
                                'UPDATE pages
                                 SET content_html = :content_html, content_text = :content_text
                                 WHERE id = :id',
                                [
                                    'content_html' => $materialized['html'],
                                    'content_text' => $materialized['text'],
                                    'id' => $pageId,
                                ]
                            );
                            $this->app->database()->execute(
                                'UPDATE page_revisions
                                 SET content_html = :content_html, content_text = :content_text
                                 WHERE page_id = :page_id AND revision_number = :revision_number',
                                [
                                    'content_html' => $materialized['html'],
                                    'content_text' => $materialized['text'],
                                    'page_id' => $pageId,
                                    'revision_number' => (int) $updatedResult['version'],
                                ]
                            );
                        }

                        $metadata->syncTags($pageId, $tagInput);
                        $metadata->syncLinks($pageId, (int) $space['id'], $decorated['references']);
                        $metadata->refreshSpaceLinks((int) $space['id']);
                        return $updatedResult;
                    }
                );
                $inlineImages->clearTracking();
            } catch (\Throwable $inlineImageError) {
                $inlineImages->cleanupCreatedFiles();
                throw $inlineImageError;
            }
            $updated = $this->pageRow($pageId);

            (new AuditLogger($this->app->database()))->log(
                'PAGE_UPDATED',
                'page',
                $pageId,
                (int) $context['user']['id'],
                $request,
                $before,
                ['title' => $data['title'], 'slug' => $data['slug'], 'status' => $data['status'], 'version' => $result['version']]
            );

            return Response::json(['data' => $this->pageResource($updated)]);
        } catch (\InvalidArgumentException $exception) {
            return $this->error('validation_error', $exception->getMessage(), 422);
        } catch (\RuntimeException $exception) {
            if ($exception->getMessage() === 'EDIT_CONFLICT') {
                return $this->error('edit_conflict', 'The page has changed. Reload it and retry with the current base_version.', 409);
            }
            error_log('[OpenWiki API page update] ' . $exception->getMessage());
            return $this->error('server_error', 'Unable to update page.', 500);
        } catch (\Throwable $exception) {
            if (str_contains(strtolower($exception->getMessage()), 'duplicate')) {
                return $this->error('conflict', 'A page with this slug already exists in the space.', 409);
            }
            error_log('[OpenWiki API page update] ' . $exception->getMessage());
            return $this->error('server_error', 'Unable to update page.', 500);
        }
    }

    public function deletePage(Request $request, string $id): Response
    {
        [$context, $failure] = $this->apiAuth($request, 'pages:write', 'page.delete');
        if ($failure !== null) {
            return $failure;
        }

        $pageId = $this->positiveId($id);
        $row = $pageId === null ? null : $this->pageRow($pageId);
        if ($row === null) {
            return $this->error('not_found', 'Page not found.', 404);
        }

        $space = $this->spaceFromPage($row);
        if (!(new PageAclService($this->app))->canFor(
            $row,
            $space,
            'page.delete',
            (int) $context['user']['id'],
            $this->isSuperAdmin($context),
            true
        )) {
            return $this->error('forbidden', 'You cannot delete this page.', 403);
        }

        $this->app->database()->execute(
            'UPDATE pages SET deleted_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP()
             WHERE id = :id AND deleted_at IS NULL',
            ['id' => $pageId]
        );

        (new AuditLogger($this->app->database()))->log(
            'PAGE_DELETED',
            'page',
            $pageId,
            (int) $context['user']['id'],
            $request,
            ['title' => $row['title'], 'slug' => $row['slug'], 'status' => $row['status']],
            ['deleted' => true]
        );

        return new Response('', 204);
    }

    public function comments(Request $request): Response
    {
        [$context, $failure] = $this->apiAuth($request, 'comments:read', 'page.view');
        if ($failure !== null) {
            return $failure;
        }

        $pageId = $this->positiveId((string) $request->query('page_id', ''));
        if ($pageId === null) {
            return $this->error('validation_error', 'page_id is required.', 422);
        }

        $pageRow = $this->pageRow($pageId);
        if ($pageRow === null || !$this->canViewPage($context, $pageRow)) {
            return $this->error('not_found', 'Page not found.', 404);
        }

        [$page, $perPage, $offset] = $this->pagination($request);
        $rows = (new CommentService($this->app->database()))->listForPage($pageId);
        $total = count($rows);

        return Response::json([
            'data' => array_map(
                [$this, 'commentResource'],
                array_slice($rows, $offset, $perPage)
            ),
            'meta' => $this->paginationMeta($page, $perPage, $total),
        ]);
    }

    public function createComment(Request $request): Response
    {
        [$context, $failure] = $this->apiAuth(
            $request,
            'comments:write',
            'comment.create'
        );
        if ($failure !== null) {
            return $failure;
        }

        $pageId = $this->positiveId((string) $request->input('page_id', ''));
        if ($pageId === null) {
            return $this->error('validation_error', 'page_id is required.', 422);
        }

        $pageRow = $this->pageRow($pageId);
        if ($pageRow === null || !$this->canViewPage($context, $pageRow)) {
            return $this->error('not_found', 'Page not found.', 404);
        }

        $parentValue = $request->input('parent_id');
        $parentId = ($parentValue === null || $parentValue === '')
            ? null
            : filter_var($parentValue, FILTER_VALIDATE_INT);
        if ($parentId === false || (is_int($parentId) && $parentId < 1)) {
            return $this->error('validation_error', 'Invalid parent_id.', 422);
        }

        try {
            $service = new CommentService($this->app->database());
            $commentId = $service->create(
                $pageId,
                (int) $pageRow['space_id'],
                (int) $context['user']['id'],
                (string) $request->input('body', ''),
                $parentId === null ? null : (int) $parentId,
                (string) $pageRow['title'],
                '/spaces/' . rawurlencode((string) $pageRow['space_key'])
                    . '/pages/' . rawurlencode((string) $pageRow['slug'])
            );

            try {
                (new WebhookService($this->app->database()))->queue(
                    'comment.created',
                    [
                        'id' => $commentId,
                        'page_id' => $pageId,
                        'space_id' => (int) $pageRow['space_id'],
                        'author_id' => (int) $context['user']['id'],
                        'parent_id' => $parentId === null ? null : (int) $parentId,
                        'source' => 'api',
                    ]
                );
            } catch (\Throwable $webhookError) {
                error_log('[OpenWiki API comment webhook] ' . $webhookError->getMessage());
            }

            $comment = $this->commentRow($commentId);
            if ($comment === null) {
                throw new \RuntimeException('Created comment cannot be resolved.');
            }

            (new AuditLogger($this->app->database()))->log(
                'COMMENT_CREATED',
                'comment',
                $commentId,
                (int) $context['user']['id'],
                $request,
                null,
                [
                    'page_id' => $pageId,
                    'parent_id' => $parentId === null ? null : (int) $parentId,
                ]
            );

            return Response::json(['data' => $this->commentResource($comment)], 201);
        } catch (\InvalidArgumentException $exception) {
            return $this->error('validation_error', $exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            error_log('[OpenWiki API comment create] ' . $exception->getMessage());
            return $this->error('server_error', 'Unable to create comment.', 500);
        }
    }

    public function updateComment(Request $request, string $id): Response
    {
        [$context, $failure] = $this->apiAuth($request, 'comments:write');
        if ($failure !== null) {
            return $failure;
        }

        $commentId = $this->positiveId($id);
        $comment = $commentId === null ? null : $this->commentRow($commentId);
        if ($comment === null) {
            return $this->error('not_found', 'Comment not found.', 404);
        }

        $pageRow = $this->pageRow((int) $comment['page_id']);
        if ($pageRow === null || !$this->canViewPage($context, $pageRow)) {
            return $this->error('not_found', 'Comment not found.', 404);
        }

        try {
            $service = new CommentService($this->app->database());
            if (!$service->update(
                $commentId,
                (int) $context['user']['id'],
                (string) $request->input('body', '')
            )) {
                return $this->error('not_found', 'Comment not found.', 404);
            }

            $updated = $this->commentRow($commentId);
            if ($updated === null) {
                throw new \RuntimeException('Updated comment cannot be resolved.');
            }

            (new AuditLogger($this->app->database()))->log(
                'COMMENT_UPDATED',
                'comment',
                $commentId,
                (int) $context['user']['id'],
                $request,
                ['page_id' => (int) $comment['page_id']],
                ['updated' => true]
            );

            return Response::json(['data' => $this->commentResource($updated)]);
        } catch (\InvalidArgumentException $exception) {
            return $this->error('validation_error', $exception->getMessage(), 422);
        } catch (\RuntimeException $exception) {
            if ($exception->getMessage() === 'COMMENT_EDIT_FORBIDDEN') {
                return $this->error('forbidden', 'You can edit only your own comments.', 403);
            }
            throw $exception;
        }
    }

    public function deleteComment(Request $request, string $id): Response
    {
        [$context, $failure] = $this->apiAuth($request, 'comments:write');
        if ($failure !== null) {
            return $failure;
        }

        $commentId = $this->positiveId($id);
        $comment = $commentId === null ? null : $this->commentRow($commentId);
        if ($comment === null) {
            return $this->error('not_found', 'Comment not found.', 404);
        }

        $pageRow = $this->pageRow((int) $comment['page_id']);
        if ($pageRow === null || !$this->canViewPage($context, $pageRow)) {
            return $this->error('not_found', 'Comment not found.', 404);
        }

        try {
            $deleted = (new CommentService($this->app->database()))->delete(
                $commentId,
                (int) $context['user']['id'],
                $this->contextHasPermission($context, 'comment.delete')
            );
            if (!$deleted) {
                return $this->error('not_found', 'Comment not found.', 404);
            }

            (new AuditLogger($this->app->database()))->log(
                'COMMENT_DELETED',
                'comment',
                $commentId,
                (int) $context['user']['id'],
                $request,
                ['page_id' => (int) $comment['page_id']],
                ['deleted' => true]
            );

            return new Response('', 204);
        } catch (\RuntimeException $exception) {
            if ($exception->getMessage() === 'COMMENT_DELETE_FORBIDDEN') {
                return $this->error('forbidden', 'You cannot delete this comment.', 403);
            }
            throw $exception;
        }
    }

    public function search(Request $request): Response
    {
        [$context, $failure] = $this->apiAuth(
            $request,
            'search:read',
            'page.view'
        );
        if ($failure !== null) {
            return $failure;
        }

        $query = trim((string) $request->query('q', ''));
        if ($query === '') {
            return $this->error('validation_error', 'q is required.', 422);
        }
        if (mb_strlen($query) > 200) {
            return $this->error(
                'validation_error',
                'q may contain at most 200 characters.',
                422
            );
        }

        $type = strtolower(trim((string) $request->query('type', 'page')));
        $types = [
            'page',
            'all',
            'space',
            'user',
            'comment',
            'attachment',
            'tag',
        ];
        if (!in_array($type, $types, true)) {
            return $this->error(
                'validation_error',
                'Invalid search type.',
                422
            );
        }

        try {
            $filters = $this->apiSearchFilters($request);
        } catch (\InvalidArgumentException $exception) {
            return $this->error(
                'validation_error',
                $exception->getMessage(),
                422
            );
        }

        [$page, $perPage, $offset] = $this->pagination($request);
        $repository = new SearchRepository($this->app->database());
        $results = [];

        if ($type === 'page' || $type === 'all') {
            foreach (
                $repository->searchPages($query, $filters, 200)
                as $row
            ) {
                if (!$this->canViewSearchRow($context, $row)) {
                    continue;
                }

                $results[] = [
                    'type' => 'page',
                    'id' => (int) $row['id'],
                    'title' => $row['title'],
                    'slug' => $row['slug'],
                    'space' => [
                        'id' => (int) $row['space_id'],
                        'key' => $row['space_key'],
                        'name' => $row['space_name'],
                    ],
                    'status' => $row['status'],
                    'updated_at' => $row['updated_at'],
                    'relevance' => (int) $row['relevance'],
                    'snippet' => mb_substr(
                        trim((string) $row['content_text']),
                        0,
                        240
                    ),
                ];
            }
        }

        if (
            ($type === 'space' || $type === 'all')
            && $filters['author'] === ''
            && $filters['tag'] === ''
        ) {
            $spaceAccess = new SpaceAccessService($this->app);
            foreach (
                $repository->searchSpaces($query, $filters, 100)
                as $row
            ) {
                if (!$spaceAccess->canViewFor(
                    $row,
                    (int) $context['user']['id'],
                    $this->isSuperAdmin($context)
                )) {
                    continue;
                }

                $results[] = [
                    'type' => 'space',
                    'id' => (int) $row['id'],
                    'name' => $row['name'],
                    'key' => $row['space_key'],
                    'description' => $row['description'],
                    'visibility' => $row['visibility'],
                    'status' => $row['status'],
                    'updated_at' => $row['updated_at'],
                    'relevance' => (int) $row['relevance'],
                ];
            }
        }

        if (
            ($type === 'user' || $type === 'all')
            && $filters['space'] === ''
            && $filters['tag'] === ''
        ) {
            foreach (
                $repository->searchUsers($query, $filters, 100)
                as $row
            ) {
                $results[] = [
                    'type' => 'user',
                    'id' => (int) $row['id'],
                    'username' => $row['username'],
                    'first_name' => $row['first_name'],
                    'last_name' => $row['last_name'],
                    'status' => $row['status'],
                    'auth_source' => $row['auth_source'],
                    'updated_at' => $row['updated_at'],
                    'relevance' => (int) $row['relevance'],
                ];
            }
        }

        if ($type === 'comment' || $type === 'all') {
            foreach (
                $repository->searchComments($query, $filters, 150)
                as $row
            ) {
                if (!$this->canViewSearchRow($context, $row)) {
                    continue;
                }

                $plain = html_entity_decode(
                    strip_tags((string) $row['body_html']),
                    ENT_QUOTES | ENT_HTML5,
                    'UTF-8'
                );

                $results[] = [
                    'type' => 'comment',
                    'id' => (int) $row['comment_id'],
                    'page_id' => (int) $row['id'],
                    'page_title' => $row['title'],
                    'space' => [
                        'key' => $row['space_key'],
                        'name' => $row['space_name'],
                    ],
                    'author' => $row['comment_author_username'],
                    'snippet' => mb_substr(trim($plain), 0, 240),
                    'updated_at' => $row['updated_at'],
                    'relevance' => (int) $row['relevance'],
                ];
            }
        }

        if ($type === 'attachment' || $type === 'all') {
            foreach (
                $repository->searchAttachments(
                    $query,
                    $filters,
                    150
                ) as $row
            ) {
                if (!$this->canViewSearchRow($context, $row)) {
                    continue;
                }

                $results[] = [
                    'type' => 'attachment',
                    'id' => (int) $row['attachment_id'],
                    'name' => $row['attachment_name'],
                    'mime_type' => $row['mime_type'],
                    'size_bytes' => (int) $row['size_bytes'],
                    'page_id' => (int) $row['id'],
                    'page_title' => $row['title'],
                    'space' => [
                        'key' => $row['space_key'],
                        'name' => $row['space_name'],
                    ],
                    'updated_at' => $row['updated_at'],
                    'relevance' => (int) $row['relevance'],
                ];
            }
        }

        if ($type === 'tag' || $type === 'all') {
            $metadata = new WikiMetadataService(
                $this->app->database()
            );

            foreach ($repository->searchTags($query, 100) as $row) {
                $visible = 0;
                foreach (
                    $metadata->pagesForTag((string) $row['slug'])
                    as $tagPage
                ) {
                    if ($this->canViewPage($context, $tagPage)) {
                        $visible++;
                    }
                }

                if ($visible === 0) {
                    continue;
                }

                $results[] = [
                    'type' => 'tag',
                    'id' => (int) $row['id'],
                    'name' => $row['name'],
                    'slug' => $row['slug'],
                    'page_count' => $visible,
                    'updated_at' => $row['created_at'],
                    'relevance' => (int) $row['relevance'],
                ];
            }
        }

        usort(
            $results,
            static function (array $left, array $right): int {
                $relevance = (int) $right['relevance']
                    <=> (int) $left['relevance'];
                if ($relevance !== 0) {
                    return $relevance;
                }

                return strcmp(
                    (string) ($right['updated_at'] ?? ''),
                    (string) ($left['updated_at'] ?? '')
                );
            }
        );

        $total = count($results);

        return Response::json([
            'data' => array_slice(
                $results,
                $offset,
                $perPage
            ),
            'meta' => $this->paginationMeta(
                $page,
                $perPage,
                $total
            ) + ['type' => $type],
        ]);
    }

    public function users(Request $request): Response
    {
        [$context, $failure] = $this->apiAuth(
            $request,
            'users:read',
            'user.manage'
        );
        if ($failure !== null) {
            return $failure;
        }

        [$page, $perPage, $offset] = $this->pagination($request);
        $query = trim((string) $request->query('q', ''));
        $where = 'deleted_at IS NULL';
        $params = [];

        if ($query !== '') {
            $where .= ' AND (
                username LIKE ?
                OR email LIKE ?
                OR first_name LIKE ?
                OR last_name LIKE ?
            )';
            $like = '%' . $query . '%';
            $params = [$like, $like, $like, $like];
        }

        $count = $this->app->database()->fetchOne(
            'SELECT COUNT(*) AS total
             FROM users
             WHERE ' . $where,
            $params
        );
        $rows = $this->app->database()->fetchAll(
            'SELECT id, username, email, first_name, last_name,
                    status, auth_source, force_password_change,
                    last_login_at, created_at, updated_at
             FROM users
             WHERE ' . $where . '
             ORDER BY username ASC
             LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $params
        );

        return Response::json([
            'data' => array_map([$this, 'userResource'], $rows),
            'meta' => $this->paginationMeta(
                $page,
                $perPage,
                (int) ($count['total'] ?? 0)
            ),
        ]);
    }

    public function user(Request $request, string $id): Response
    {
        [$context, $failure] = $this->apiAuth(
            $request,
            'users:read',
            'user.manage'
        );
        if ($failure !== null) {
            return $failure;
        }

        $userId = $this->positiveId($id);
        $user = $userId === null
            ? null
            : (new DirectoryAdminService(
                $this->app->database()
            ))->user($userId);

        if ($user === null) {
            return $this->error('not_found', 'User not found.', 404);
        }

        return Response::json([
            'data' => $this->userResource($user),
        ]);
    }

    public function createUser(Request $request): Response
    {
        [$context, $failure] = $this->apiAuth(
            $request,
            'users:write',
            'user.manage'
        );
        if ($failure !== null) {
            return $failure;
        }

        try {
            $service = new DirectoryAdminService($this->app->database());
            $userId = $service->createUser((array) $request->input());
            $user = $service->user($userId);
            if ($user === null) {
                throw new \RuntimeException('Created user cannot be resolved.');
            }

            (new AuditLogger($this->app->database()))->log(
                'USER_CREATED',
                'user',
                $userId,
                (int) $context['user']['id'],
                $request,
                null,
                [
                    'username' => $user['username'],
                    'email' => $user['email'],
                    'source' => 'api',
                ]
            );

            return Response::json([
                'data' => $this->userResource($user),
            ], 201);
        } catch (\InvalidArgumentException $exception) {
            return $this->error(
                'validation_error',
                $exception->getMessage(),
                422
            );
        } catch (\Throwable $exception) {
            error_log('[OpenWiki API user create] ' . $exception->getMessage());
            return $this->error(
                'server_error',
                'Unable to create user.',
                500
            );
        }
    }

    public function updateUser(Request $request, string $id): Response
    {
        [$context, $failure] = $this->apiAuth(
            $request,
            'users:write',
            'user.manage'
        );
        if ($failure !== null) {
            return $failure;
        }

        $userId = $this->positiveId($id);
        $service = new DirectoryAdminService($this->app->database());
        $existing = $userId === null ? null : $service->user($userId);
        if ($existing === null) {
            return $this->error('not_found', 'User not found.', 404);
        }

        $input = (array) $request->input();
        $merged = [
            'username' => $input['username'] ?? $existing['username'],
            'email' => $input['email'] ?? $existing['email'],
            'first_name' => $input['first_name'] ?? $existing['first_name'],
            'last_name' => $input['last_name'] ?? $existing['last_name'],
            'status' => $input['status'] ?? $existing['status'],
            'force_password_change' => array_key_exists(
                'force_password_change',
                $input
            )
                ? $input['force_password_change']
                : (int) $existing['force_password_change'],
            'role_ids' => $input['role_ids'] ?? $existing['role_ids'],
            'group_ids' => $input['group_ids'] ?? $existing['group_ids'],
        ];

        if ($existing['auth_source'] === 'ldap') {
            $merged['username'] = $existing['username'];
            $merged['email'] = $existing['email'];
            $merged['first_name'] = $existing['first_name'];
            $merged['last_name'] = $existing['last_name'];
        }

        if (
            $userId === (int) $context['user']['id']
            && ($merged['status'] ?? 'active') !== 'active'
        ) {
            return $this->error(
                'validation_error',
                'The current API account cannot disable or lock itself.',
                422
            );
        }

        try {
            $service->updateUser($userId, $merged);
            $updated = $service->user($userId);
            if ($updated === null) {
                throw new \RuntimeException('Updated user cannot be resolved.');
            }

            (new AuditLogger($this->app->database()))->log(
                'USER_UPDATED',
                'user',
                $userId,
                (int) $context['user']['id'],
                $request,
                [
                    'username' => $existing['username'],
                    'email' => $existing['email'],
                    'status' => $existing['status'],
                ],
                [
                    'username' => $updated['username'],
                    'email' => $updated['email'],
                    'status' => $updated['status'],
                    'source' => 'api',
                ]
            );

            return Response::json([
                'data' => $this->userResource($updated),
            ]);
        } catch (\InvalidArgumentException $exception) {
            return $this->error(
                'validation_error',
                $exception->getMessage(),
                422
            );
        } catch (\Throwable $exception) {
            error_log('[OpenWiki API user update] ' . $exception->getMessage());
            return $this->error(
                'server_error',
                'Unable to update user.',
                500
            );
        }
    }

    public function deleteUser(Request $request, string $id): Response
    {
        [$context, $failure] = $this->apiAuth(
            $request,
            'users:write',
            'user.manage'
        );
        if ($failure !== null) {
            return $failure;
        }

        $userId = $this->positiveId($id);
        if ($userId === null) {
            return $this->error('not_found', 'User not found.', 404);
        }
        if ($userId === (int) $context['user']['id']) {
            return $this->error(
                'validation_error',
                'The current API account cannot delete itself.',
                422
            );
        }

        $service = new DirectoryAdminService($this->app->database());
        $existing = $service->user($userId);
        if ($existing === null) {
            return $this->error('not_found', 'User not found.', 404);
        }

        if (!$service->softDeleteUser($userId)) {
            return $this->error('not_found', 'User not found.', 404);
        }

        (new AuditLogger($this->app->database()))->log(
            'USER_DELETED',
            'user',
            $userId,
            (int) $context['user']['id'],
            $request,
            [
                'username' => $existing['username'],
                'email' => $existing['email'],
            ],
            ['deleted' => true, 'source' => 'api']
        );

        return new Response('', 204);
    }

    public function groups(Request $request): Response
    {
        [$context, $failure] = $this->apiAuth(
            $request,
            'groups:read',
            'group.manage'
        );
        if ($failure !== null) {
            return $failure;
        }

        [$page, $perPage, $offset] = $this->pagination($request);
        $count = $this->app->database()->fetchOne(
            'SELECT COUNT(*) AS total FROM user_groups'
        );
        $rows = $this->app->database()->fetchAll(
            'SELECT g.id, g.name, g.slug, g.description,
                    g.source, g.external_id, g.created_at, g.updated_at,
                    COUNT(gu.user_id) AS member_count
             FROM user_groups g
             LEFT JOIN group_users gu ON gu.group_id = g.id
             GROUP BY g.id
             ORDER BY g.name ASC
             LIMIT ' . $perPage . ' OFFSET ' . $offset
        );

        return Response::json([
            'data' => array_map([$this, 'groupResource'], $rows),
            'meta' => $this->paginationMeta(
                $page,
                $perPage,
                (int) ($count['total'] ?? 0)
            ),
        ]);
    }

    public function group(Request $request, string $id): Response
    {
        [$context, $failure] = $this->apiAuth(
            $request,
            'groups:read',
            'group.manage'
        );
        if ($failure !== null) {
            return $failure;
        }

        $groupId = $this->positiveId($id);
        $group = $groupId === null
            ? null
            : (new DirectoryAdminService(
                $this->app->database()
            ))->group($groupId);

        if ($group === null) {
            return $this->error('not_found', 'Group not found.', 404);
        }

        return Response::json([
            'data' => $this->groupResource($group),
        ]);
    }

    public function createGroup(Request $request): Response
    {
        [$context, $failure] = $this->apiAuth(
            $request,
            'groups:write',
            'group.manage'
        );
        if ($failure !== null) {
            return $failure;
        }

        try {
            $service = new DirectoryAdminService($this->app->database());
            $groupId = $service->saveGroup(
                null,
                (array) $request->input()
            );
            $group = $service->group($groupId);
            if ($group === null) {
                throw new \RuntimeException('Created group cannot be resolved.');
            }

            (new AuditLogger($this->app->database()))->log(
                'GROUP_CREATED',
                'group',
                $groupId,
                (int) $context['user']['id'],
                $request,
                null,
                [
                    'name' => $group['name'],
                    'slug' => $group['slug'],
                    'source' => 'api',
                ]
            );

            return Response::json([
                'data' => $this->groupResource($group),
            ], 201);
        } catch (\InvalidArgumentException $exception) {
            return $this->error(
                'validation_error',
                $exception->getMessage(),
                422
            );
        } catch (\Throwable $exception) {
            error_log('[OpenWiki API group create] ' . $exception->getMessage());
            return $this->error(
                'server_error',
                'Unable to create group.',
                500
            );
        }
    }

    public function updateGroup(Request $request, string $id): Response
    {
        [$context, $failure] = $this->apiAuth(
            $request,
            'groups:write',
            'group.manage'
        );
        if ($failure !== null) {
            return $failure;
        }

        $groupId = $this->positiveId($id);
        $service = new DirectoryAdminService($this->app->database());
        $existing = $groupId === null ? null : $service->group($groupId);
        if ($existing === null) {
            return $this->error('not_found', 'Group not found.', 404);
        }

        $input = (array) $request->input();
        $merged = [
            'name' => $input['name'] ?? $existing['name'],
            'slug' => $input['slug'] ?? $existing['slug'],
            'description' => $input['description'] ?? $existing['description'],
            'user_ids' => $input['user_ids'] ?? $existing['user_ids'],
        ];

        try {
            $service->saveGroup($groupId, $merged);
            $updated = $service->group($groupId);
            if ($updated === null) {
                throw new \RuntimeException('Updated group cannot be resolved.');
            }

            (new AuditLogger($this->app->database()))->log(
                'GROUP_UPDATED',
                'group',
                $groupId,
                (int) $context['user']['id'],
                $request,
                [
                    'name' => $existing['name'],
                    'slug' => $existing['slug'],
                    'source' => $existing['source'],
                ],
                [
                    'name' => $updated['name'],
                    'slug' => $updated['slug'],
                    'source' => $updated['source'],
                    'api' => true,
                ]
            );

            return Response::json([
                'data' => $this->groupResource($updated),
            ]);
        } catch (\InvalidArgumentException $exception) {
            return $this->error(
                'validation_error',
                $exception->getMessage(),
                422
            );
        } catch (\Throwable $exception) {
            error_log('[OpenWiki API group update] ' . $exception->getMessage());
            return $this->error(
                'server_error',
                'Unable to update group.',
                500
            );
        }
    }

    public function deleteGroup(Request $request, string $id): Response
    {
        [$context, $failure] = $this->apiAuth(
            $request,
            'groups:write',
            'group.manage'
        );
        if ($failure !== null) {
            return $failure;
        }

        $groupId = $this->positiveId($id);
        $service = new DirectoryAdminService($this->app->database());
        $existing = $groupId === null ? null : $service->group($groupId);
        if ($existing === null) {
            return $this->error('not_found', 'Group not found.', 404);
        }

        try {
            if (!$service->deleteGroup($groupId)) {
                return $this->error('not_found', 'Group not found.', 404);
            }

            (new AuditLogger($this->app->database()))->log(
                'GROUP_DELETED',
                'group',
                $groupId,
                (int) $context['user']['id'],
                $request,
                [
                    'name' => $existing['name'],
                    'slug' => $existing['slug'],
                    'source' => $existing['source'],
                ],
                ['deleted' => true, 'api' => true]
            );

            return new Response('', 204);
        } catch (\InvalidArgumentException $exception) {
            return $this->error(
                'validation_error',
                $exception->getMessage(),
                422
            );
        }
    }

    public function roles(Request $request): Response
    {
        [$context, $failure] = $this->apiAuth(
            $request,
            'roles:read',
            'role.manage'
        );
        if ($failure !== null) {
            return $failure;
        }

        [$page, $perPage, $offset] = $this->pagination($request);
        $rows = (new DirectoryAdminService(
            $this->app->database()
        ))->roles();

        $total = count($rows);
        return Response::json([
            'data' => array_map(
                [$this, 'roleResource'],
                array_slice($rows, $offset, $perPage)
            ),
            'meta' => $this->paginationMeta($page, $perPage, $total),
        ]);
    }

    public function role(Request $request, string $id): Response
    {
        [$context, $failure] = $this->apiAuth(
            $request,
            'roles:read',
            'role.manage'
        );
        if ($failure !== null) {
            return $failure;
        }

        $roleId = $this->positiveId($id);
        $role = $roleId === null
            ? null
            : (new DirectoryAdminService(
                $this->app->database()
            ))->role($roleId);

        if ($role === null) {
            return $this->error('not_found', 'Role not found.', 404);
        }

        return Response::json([
            'data' => $this->roleResource($role),
        ]);
    }

    public function createRole(Request $request): Response
    {
        [$context, $failure] = $this->apiAuth(
            $request,
            'roles:write',
            'role.manage'
        );
        if ($failure !== null) {
            return $failure;
        }

        try {
            $service = new DirectoryAdminService($this->app->database());
            $roleId = $service->saveRole(
                null,
                (array) $request->input()
            );
            $role = $service->role($roleId);
            if ($role === null) {
                throw new \RuntimeException('Created role cannot be resolved.');
            }

            (new AuditLogger($this->app->database()))->log(
                'ROLE_CREATED',
                'role',
                $roleId,
                (int) $context['user']['id'],
                $request,
                null,
                [
                    'name' => $role['name'],
                    'slug' => $role['slug'],
                    'source' => 'api',
                ]
            );

            return Response::json([
                'data' => $this->roleResource($role),
            ], 201);
        } catch (\InvalidArgumentException $exception) {
            return $this->error(
                'validation_error',
                $exception->getMessage(),
                422
            );
        } catch (\Throwable $exception) {
            error_log('[OpenWiki API role create] ' . $exception->getMessage());
            return $this->error(
                'server_error',
                'Unable to create role.',
                500
            );
        }
    }

    public function updateRole(Request $request, string $id): Response
    {
        [$context, $failure] = $this->apiAuth(
            $request,
            'roles:write',
            'role.manage'
        );
        if ($failure !== null) {
            return $failure;
        }

        $roleId = $this->positiveId($id);
        $service = new DirectoryAdminService($this->app->database());
        $existing = $roleId === null ? null : $service->role($roleId);
        if ($existing === null) {
            return $this->error('not_found', 'Role not found.', 404);
        }

        $input = (array) $request->input();
        $merged = [
            'name' => $input['name'] ?? $existing['name'],
            'slug' => $input['slug'] ?? $existing['slug'],
            'description' => $input['description'] ?? $existing['description'],
            'permission_ids' => $input['permission_ids'] ?? $existing['permission_ids'],
        ];

        try {
            $service->saveRole($roleId, $merged);
            $updated = $service->role($roleId);
            if ($updated === null) {
                throw new \RuntimeException('Updated role cannot be resolved.');
            }

            (new AuditLogger($this->app->database()))->log(
                'ROLE_UPDATED',
                'role',
                $roleId,
                (int) $context['user']['id'],
                $request,
                [
                    'name' => $existing['name'],
                    'slug' => $existing['slug'],
                    'permission_ids' => $existing['permission_ids'],
                ],
                [
                    'name' => $updated['name'],
                    'slug' => $updated['slug'],
                    'permission_ids' => $updated['permission_ids'],
                    'source' => 'api',
                ]
            );

            return Response::json([
                'data' => $this->roleResource($updated),
            ]);
        } catch (\InvalidArgumentException $exception) {
            return $this->error(
                'validation_error',
                $exception->getMessage(),
                422
            );
        } catch (\Throwable $exception) {
            error_log('[OpenWiki API role update] ' . $exception->getMessage());
            return $this->error(
                'server_error',
                'Unable to update role.',
                500
            );
        }
    }

    public function deleteRole(Request $request, string $id): Response
    {
        [$context, $failure] = $this->apiAuth(
            $request,
            'roles:write',
            'role.manage'
        );
        if ($failure !== null) {
            return $failure;
        }

        $roleId = $this->positiveId($id);
        $service = new DirectoryAdminService($this->app->database());
        $existing = $roleId === null ? null : $service->role($roleId);
        if ($existing === null) {
            return $this->error('not_found', 'Role not found.', 404);
        }

        try {
            if (!$service->deleteRole($roleId)) {
                return $this->error('not_found', 'Role not found.', 404);
            }

            (new AuditLogger($this->app->database()))->log(
                'ROLE_DELETED',
                'role',
                $roleId,
                (int) $context['user']['id'],
                $request,
                [
                    'name' => $existing['name'],
                    'slug' => $existing['slug'],
                    'is_system' => (bool) $existing['is_system'],
                ],
                ['deleted' => true, 'source' => 'api']
            );

            return new Response('', 204);
        } catch (\InvalidArgumentException $exception) {
            return $this->error(
                'validation_error',
                $exception->getMessage(),
                422
            );
        }
    }

    public function permissions(Request $request): Response
    {
        [$context, $failure] = $this->apiAuth(
            $request,
            'roles:read',
            'role.manage'
        );
        if ($failure !== null) {
            return $failure;
        }

        $rows = (new DirectoryAdminService(
            $this->app->database()
        ))->permissions();

        return Response::json([
            'data' => array_map(
                static fn (array $row): array => [
                    'id' => (int) $row['id'],
                    'name' => $row['name'],
                    'description' => $row['description'],
                ],
                $rows
            ),
        ]);
    }

    public function tags(Request $request): Response
    {
        [$context, $failure] = $this->apiAuth($request, 'tags:read', 'page.view');
        if ($failure !== null) {
            return $failure;
        }

        [$page, $perPage, $offset] = $this->pagination($request);
        $rows = $this->app->database()->fetchAll(
            'SELECT t.id AS tag_id, t.name AS tag_name, t.slug AS tag_slug,
                    p.id AS page_id, p.space_id, p.parent_id, p.inherit_acl,
                    p.status AS page_status, p.owner_id AS page_owner_id, p.author_id AS page_author_id,
                    s.space_key, s.name AS space_name, s.visibility AS space_visibility,
                    s.owner_id AS space_owner_id, s.status AS space_status, s.deleted_at AS space_deleted_at
             FROM tags t
             LEFT JOIN page_tags pt ON pt.tag_id = t.id
             LEFT JOIN pages p ON p.id = pt.page_id AND p.deleted_at IS NULL
             LEFT JOIN spaces s ON s.id = p.space_id AND s.deleted_at IS NULL
             ORDER BY t.name ASC, t.id ASC, p.id ASC'
        );

        $tags = [];
        foreach ($rows as $row) {
            $tagId = (int) $row['tag_id'];
            if (!isset($tags[$tagId])) {
                $tags[$tagId] = [
                    'id' => $tagId,
                    'name' => $row['tag_name'],
                    'slug' => $row['tag_slug'],
                    'page_count' => 0,
                ];
            }

            if ($row['page_id'] === null || $row['space_key'] === null) {
                continue;
            }

            $pageRow = [
                'id' => (int) $row['page_id'],
                'space_id' => (int) $row['space_id'],
                'parent_id' => $row['parent_id'] === null ? null : (int) $row['parent_id'],
                'inherit_acl' => (int) $row['inherit_acl'],
                'status' => $row['page_status'],
                'owner_id' => (int) $row['page_owner_id'],
                'author_id' => (int) $row['page_author_id'],
                'space_key' => $row['space_key'],
                'space_name' => $row['space_name'],
                'space_visibility' => $row['space_visibility'],
                'space_owner_id' => (int) $row['space_owner_id'],
                'space_status' => $row['space_status'],
                'space_deleted_at' => $row['space_deleted_at'],
            ];

            if ($this->canViewPage($context, $pageRow)) {
                $tags[$tagId]['page_count']++;
            }
        }

        $visibleTags = array_values(array_filter(
            $tags,
            static fn (array $tag): bool => $tag['page_count'] > 0
        ));
        $total = count($visibleTags);

        return Response::json([
            'data' => array_slice($visibleTags, $offset, $perPage),
            'meta' => $this->paginationMeta($page, $perPage, $total),
        ]);
    }

    public function tag(Request $request, string $id): Response
    {
        [$context, $failure] = $this->apiAuth(
            $request,
            'tags:read',
            'page.view'
        );
        if ($failure !== null) {
            return $failure;
        }

        $tagId = $this->positiveId($id);
        if ($tagId === null) {
            return $this->error('not_found', 'Tag not found.', 404);
        }

        $tag = $this->app->database()->fetchOne(
            'SELECT id, name, slug, created_at
             FROM tags
             WHERE id = :id
             LIMIT 1',
            ['id' => $tagId]
        );
        if ($tag === null) {
            return $this->error('not_found', 'Tag not found.', 404);
        }

        $metadata = new WikiMetadataService($this->app->database());
        $pages = [];
        foreach ($metadata->pagesForTag((string) $tag['slug']) as $row) {
            if (!$this->canViewPage($context, $row)) {
                continue;
            }
            $pages[] = $this->pageListResource($row);
        }

        return Response::json([
            'data' => [
                'id' => (int) $tag['id'],
                'name' => $tag['name'],
                'slug' => $tag['slug'],
                'page_count' => count($pages),
                'pages' => $pages,
                'created_at' => $tag['created_at'],
            ],
        ]);
    }

    public function attachments(Request $request): Response
    {
        [$context, $failure] = $this->apiAuth($request, 'attachments:read', 'page.view');
        if ($failure !== null) {
            return $failure;
        }

        $state = $this->spaceState($context, '');
        if ($state instanceof Response) {
            return $state;
        }
        [$page, $perPage, $offset] = $this->pagination($request);

        if ($state['visible_ids'] === []) {
            return Response::json(['data' => [], 'meta' => $this->paginationMeta($page, $perPage, 0)]);
        }

        $placeholders = implode(',', array_fill(0, count($state['visible_ids']), '?'));
        $rows = $this->app->database()->fetchAll(
            'SELECT a.id AS attachment_id, a.page_id, a.name, a.mime_type, a.size_bytes,
                    a.current_version, a.created_at AS attachment_created_at, a.updated_at AS attachment_updated_at,
                    p.space_id, p.parent_id, p.inherit_acl, p.status AS page_status,
                    p.owner_id AS page_owner_id, p.author_id AS page_author_id,
                    p.title AS page_title, p.slug AS page_slug,
                    s.space_key, s.name AS space_name, s.visibility AS space_visibility,
                    s.owner_id AS space_owner_id, s.status AS space_status, s.deleted_at AS space_deleted_at
             FROM attachments a
             INNER JOIN pages p ON p.id = a.page_id AND p.deleted_at IS NULL
             INNER JOIN spaces s ON s.id = p.space_id AND s.deleted_at IS NULL
             WHERE a.deleted_at IS NULL
               AND p.space_id IN (' . $placeholders . ')
             ORDER BY a.updated_at DESC, a.id DESC',
            $state['visible_ids']
        );

        $filtered = [];
        foreach ($rows as $row) {
            $pageRow = [
                'id' => (int) $row['page_id'],
                'space_id' => (int) $row['space_id'],
                'parent_id' => $row['parent_id'] === null ? null : (int) $row['parent_id'],
                'inherit_acl' => (int) $row['inherit_acl'],
                'status' => $row['page_status'],
                'owner_id' => (int) $row['page_owner_id'],
                'author_id' => (int) $row['page_author_id'],
                'space_key' => $row['space_key'],
                'space_name' => $row['space_name'],
                'space_visibility' => $row['space_visibility'],
                'space_owner_id' => (int) $row['space_owner_id'],
                'space_status' => $row['space_status'],
                'space_deleted_at' => $row['space_deleted_at'],
            ];

            if (!$this->canViewPage($context, $pageRow)) {
                continue;
            }

            $filtered[] = [
                'id' => (int) $row['attachment_id'],
                'page_id' => (int) $row['page_id'],
                'name' => $row['name'],
                'mime_type' => $row['mime_type'],
                'size_bytes' => (int) $row['size_bytes'],
                'current_version' => (int) $row['current_version'],
                'page' => [
                    'title' => $row['page_title'],
                    'slug' => $row['page_slug'],
                    'space_key' => $row['space_key'],
                ],
                'created_at' => $row['attachment_created_at'],
                'updated_at' => $row['attachment_updated_at'],
            ];
        }

        $total = count($filtered);
        return Response::json([
            'data' => array_slice($filtered, $offset, $perPage),
            'meta' => $this->paginationMeta($page, $perPage, $total),
        ]);
    }

    public function attachment(Request $request, string $id): Response
    {
        [$context, $failure] = $this->apiAuth(
            $request,
            'attachments:read',
            'page.view'
        );
        if ($failure !== null) {
            return $failure;
        }

        $attachmentId = $this->positiveId($id);
        $service = $this->attachmentService();
        $attachment = $attachmentId === null ? null : $service->find($attachmentId);

        if (
            $attachment === null
            || !$this->canViewAttachment($context, $attachment)
        ) {
            return $this->error('not_found', 'Attachment not found.', 404);
        }

        return Response::json([
            'data' => $this->attachmentResource(
                $attachment,
                $service->versions($attachmentId)
            ),
        ]);
    }

    public function uploadAttachment(Request $request): Response
    {
        [$context, $failure] = $this->apiAuth(
            $request,
            'attachments:write',
            'attachment.upload'
        );
        if ($failure !== null) {
            return $failure;
        }

        $pageId = $this->positiveId((string) $request->input('page_id', ''));
        if ($pageId === null) {
            return $this->error('validation_error', 'page_id is required.', 422);
        }

        $page = $this->pageRow($pageId);
        if ($page === null) {
            return $this->error('not_found', 'Page not found.', 404);
        }
        if (!$this->canEditPageForContext($context, $page)) {
            return $this->error('forbidden', 'You cannot upload attachments to this page.', 403);
        }

        try {
            $service = $this->attachmentService();
            $attachmentId = $service->upload(
                $pageId,
                (int) $context['user']['id'],
                $request->file('attachment') ?? []
            );
            $attachment = $service->find($attachmentId);
            if ($attachment === null) {
                throw new \RuntimeException('Uploaded attachment cannot be resolved.');
            }

            (new AuditLogger($this->app->database()))->log(
                'ATTACHMENT_UPLOADED',
                'attachment',
                $attachmentId,
                (int) $context['user']['id'],
                $request,
                null,
                [
                    'page_id' => $pageId,
                    'source' => 'api',
                ]
            );

            return Response::json([
                'data' => $this->attachmentResource(
                    $attachment,
                    $service->versions($attachmentId)
                ),
            ], 201);
        } catch (\InvalidArgumentException $exception) {
            return $this->error('validation_error', $exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            error_log('[OpenWiki API attachment upload] ' . $exception->getMessage());
            return $this->error('server_error', 'Unable to upload attachment.', 500);
        }
    }

    public function uploadAttachmentVersion(
        Request $request,
        string $id
    ): Response {
        [$context, $failure] = $this->apiAuth(
            $request,
            'attachments:write',
            'attachment.upload'
        );
        if ($failure !== null) {
            return $failure;
        }

        $attachmentId = $this->positiveId($id);
        $service = $this->attachmentService();
        $attachment = $attachmentId === null ? null : $service->find($attachmentId);
        if ($attachment === null) {
            return $this->error('not_found', 'Attachment not found.', 404);
        }
        if (!$this->canEditAttachmentPage($context, $attachment)) {
            return $this->error('forbidden', 'You cannot modify this attachment.', 403);
        }

        try {
            $newVersion = $service->addVersion(
                $attachmentId,
                (int) $context['user']['id'],
                $request->file('attachment') ?? []
            );
            $updated = $service->find($attachmentId);
            if ($updated === null) {
                throw new \RuntimeException('Updated attachment cannot be resolved.');
            }

            (new AuditLogger($this->app->database()))->log(
                'ATTACHMENT_VERSION_UPLOADED',
                'attachment',
                $attachmentId,
                (int) $context['user']['id'],
                $request,
                ['version' => (int) $attachment['current_version']],
                ['version' => $newVersion, 'source' => 'api']
            );

            return Response::json([
                'data' => $this->attachmentResource(
                    $updated,
                    $service->versions($attachmentId)
                ),
            ], 201);
        } catch (\InvalidArgumentException $exception) {
            return $this->error('validation_error', $exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            error_log('[OpenWiki API attachment version] ' . $exception->getMessage());
            return $this->error('server_error', 'Unable to upload attachment version.', 500);
        }
    }

    public function renameAttachment(Request $request, string $id): Response
    {
        [$context, $failure] = $this->apiAuth(
            $request,
            'attachments:write',
            'attachment.upload'
        );
        if ($failure !== null) {
            return $failure;
        }

        $attachmentId = $this->positiveId($id);
        $service = $this->attachmentService();
        $attachment = $attachmentId === null ? null : $service->find($attachmentId);
        if ($attachment === null) {
            return $this->error('not_found', 'Attachment not found.', 404);
        }
        if (!$this->canEditAttachmentPage($context, $attachment)) {
            return $this->error('forbidden', 'You cannot modify this attachment.', 403);
        }

        try {
            if (!$service->rename(
                $attachmentId,
                (string) $request->input('name', '')
            )) {
                return $this->error('not_found', 'Attachment not found.', 404);
            }

            $updated = $service->find($attachmentId);
            if ($updated === null) {
                throw new \RuntimeException('Renamed attachment cannot be resolved.');
            }

            (new AuditLogger($this->app->database()))->log(
                'ATTACHMENT_RENAMED',
                'attachment',
                $attachmentId,
                (int) $context['user']['id'],
                $request,
                ['name' => $attachment['name']],
                ['name' => $updated['name'], 'source' => 'api']
            );

            return Response::json([
                'data' => $this->attachmentResource(
                    $updated,
                    $service->versions($attachmentId)
                ),
            ]);
        } catch (\InvalidArgumentException $exception) {
            return $this->error('validation_error', $exception->getMessage(), 422);
        }
    }

    public function deleteAttachment(Request $request, string $id): Response
    {
        [$context, $failure] = $this->apiAuth(
            $request,
            'attachments:write',
            'attachment.delete'
        );
        if ($failure !== null) {
            return $failure;
        }

        $attachmentId = $this->positiveId($id);
        $service = $this->attachmentService();
        $attachment = $attachmentId === null ? null : $service->find($attachmentId);
        if ($attachment === null) {
            return $this->error('not_found', 'Attachment not found.', 404);
        }
        if (!$this->canEditAttachmentPage($context, $attachment)) {
            return $this->error('forbidden', 'You cannot delete this attachment.', 403);
        }

        if (!$service->delete($attachmentId)) {
            return $this->error('not_found', 'Attachment not found.', 404);
        }

        (new AuditLogger($this->app->database()))->log(
            'ATTACHMENT_DELETED',
            'attachment',
            $attachmentId,
            (int) $context['user']['id'],
            $request,
            [
                'name' => $attachment['name'],
                'version' => (int) $attachment['current_version'],
            ],
            ['deleted' => true, 'source' => 'api']
        );

        return new Response('', 204);
    }

    public function previewAttachment(Request $request, string $id): Response
    {
        [$context, $failure] = $this->apiAuth(
            $request,
            'attachments:read',
            'page.view'
        );
        if ($failure !== null) {
            return $failure;
        }

        $attachmentId = $this->positiveId($id);
        $service = $this->attachmentService();
        $attachment = $attachmentId === null ? null : $service->find($attachmentId);

        if (
            $attachment === null
            || !$this->canViewAttachment($context, $attachment)
        ) {
            return $this->error('not_found', 'Attachment not found.', 404);
        }

        if (!$service->mayPreview((string) $attachment['mime_type'])) {
            return $this->error(
                'unsupported_media_type',
                'Attachment cannot be previewed inline.',
                415
            );
        }

        try {
            return Response::file(
                $service->currentPath($attachment),
                (string) $attachment['mime_type'],
                (string) $attachment['name'],
                true
            );
        } catch (\Throwable $exception) {
            error_log('[OpenWiki API attachment preview] ' . $exception->getMessage());
            return $this->error('not_found', 'Attachment file not found.', 404);
        }
    }

    public function thumbnailAttachment(Request $request, string $id): Response
    {
        [$context, $failure] = $this->apiAuth(
            $request,
            'attachments:read',
            'page.view'
        );
        if ($failure !== null) {
            return $failure;
        }

        $attachmentId = $this->positiveId($id);
        $service = $this->attachmentService();
        $attachment = $attachmentId === null ? null : $service->find($attachmentId);

        if (
            $attachment === null
            || !$this->canViewAttachment($context, $attachment)
        ) {
            return $this->error('not_found', 'Attachment not found.', 404);
        }

        $width = filter_var(
            $request->query('width', 640),
            FILTER_VALIDATE_INT
        );
        $width = $width === false ? 640 : (int) $width;

        try {
            $thumbnail = (new ImageService(
                $service,
                $this->app->basePath()
            ))->thumbnail($attachment, $width);

            return Response::file(
                $thumbnail['path'],
                $thumbnail['mime_type'],
                'thumbnail-' . $attachmentId
                    . '-' . (int) $thumbnail['width'],
                true
            );
        } catch (\InvalidArgumentException $exception) {
            return $this->error(
                'unsupported_media_type',
                $exception->getMessage(),
                415
            );
        } catch (\Throwable $exception) {
            error_log('[OpenWiki API attachment thumbnail] ' . $exception->getMessage());
            return $this->error(
                'server_error',
                'Unable to generate thumbnail.',
                500
            );
        }
    }

    public function downloadAttachment(Request $request, string $id): Response
    {
        [$context, $failure] = $this->apiAuth(
            $request,
            'attachments:read',
            'page.view'
        );
        if ($failure !== null) {
            return $failure;
        }

        $attachmentId = $this->positiveId($id);
        $service = $this->attachmentService();
        $attachment = $attachmentId === null ? null : $service->find($attachmentId);
        if (
            $attachment === null
            || !$this->canViewAttachment($context, $attachment)
        ) {
            return $this->error('not_found', 'Attachment not found.', 404);
        }

        try {
            return Response::file(
                $service->currentPath($attachment),
                (string) $attachment['mime_type'],
                (string) $attachment['name'],
                false
            );
        } catch (\Throwable $exception) {
            error_log('[OpenWiki API attachment download] ' . $exception->getMessage());
            return $this->error('not_found', 'Attachment file not found.', 404);
        }
    }

    public function downloadAttachmentVersion(
        Request $request,
        string $id,
        string $version
    ): Response {
        [$context, $failure] = $this->apiAuth(
            $request,
            'attachments:read',
            'page.view'
        );
        if ($failure !== null) {
            return $failure;
        }

        $attachmentId = $this->positiveId($id);
        $versionNumber = $this->positiveId($version);
        $service = $this->attachmentService();
        $attachment = $attachmentId === null ? null : $service->find($attachmentId);

        if (
            $attachment === null
            || $versionNumber === null
            || !$this->canViewAttachment($context, $attachment)
        ) {
            return $this->error('not_found', 'Attachment version not found.', 404);
        }

        try {
            $file = $service->versionPath($attachmentId, $versionNumber);
            if ($file === null) {
                return $this->error('not_found', 'Attachment version not found.', 404);
            }

            return Response::file(
                (string) $file['path'],
                (string) $file['mime_type'],
                (string) $file['name'],
                false
            );
        } catch (\Throwable $exception) {
            error_log('[OpenWiki API attachment version download] ' . $exception->getMessage());
            return $this->error('not_found', 'Attachment version not found.', 404);
        }
    }

    private function attachmentService(): AttachmentService
    {
        return new AttachmentService(
            $this->app->database(),
            $this->app->basePath()
        );
    }

    private function attachmentPage(array $attachment): array
    {
        return [
            'id' => (int) $attachment['page_id'],
            'space_id' => (int) $attachment['space_id'],
            'parent_id' => $attachment['page_parent_id'] === null
                ? null
                : (int) $attachment['page_parent_id'],
            'inherit_acl' => (int) $attachment['page_inherit_acl'],
            'status' => $attachment['page_status'],
            'owner_id' => (int) $attachment['page_owner_id'],
            'author_id' => (int) $attachment['page_author_id'],
            'space_key' => $attachment['space_key'],
            'space_name' => $attachment['space_name'],
            'space_visibility' => $attachment['space_visibility'],
            'space_owner_id' => (int) $attachment['space_owner_id'],
            'space_status' => $attachment['space_status'],
            'space_deleted_at' => $attachment['space_deleted_at'],
        ];
    }

    private function canViewAttachment(array $context, array $attachment): bool
    {
        return $this->canViewPage(
            $context,
            $this->attachmentPage($attachment)
        );
    }

    private function canEditAttachmentPage(array $context, array $attachment): bool
    {
        return $this->canEditPageForContext(
            $context,
            $this->attachmentPage($attachment)
        );
    }

    private function canEditPageForContext(array $context, array $page): bool
    {
        return (new PageAclService($this->app))->canFor(
            $page,
            $this->spaceFromPage($page),
            'page.edit',
            (int) $context['user']['id'],
            $this->isSuperAdmin($context),
            $this->contextHasPermission($context, 'page.edit')
        );
    }

    private function attachmentResource(
        array $attachment,
        array $versions = []
    ): array {
        $resource = [
            'id' => (int) $attachment['id'],
            'page_id' => (int) $attachment['page_id'],
            'name' => $attachment['name'],
            'mime_type' => $attachment['mime_type'],
            'size_bytes' => (int) $attachment['size_bytes'],
            'sha256' => $attachment['sha256'] ?? null,
            'current_version' => (int) $attachment['current_version'],
            'created_at' => $attachment['created_at'],
            'updated_at' => $attachment['updated_at'],
            'urls' => [
                'download' => '/api/v1/attachments/' . (int) $attachment['id'] . '/download',
                'preview' => '/api/v1/attachments/' . (int) $attachment['id'] . '/preview',
                'thumbnail' => str_starts_with((string) $attachment['mime_type'], 'image/')
                    ? '/api/v1/attachments/' . (int) $attachment['id'] . '/thumbnail?width=640'
                    : null,
            ],
        ];

        if ($versions !== []) {
            $resource['versions'] = array_map(
                static fn (array $version): array => [
                    'id' => (int) $version['id'],
                    'version_number' => (int) $version['version_number'],
                    'mime_type' => $version['mime_type'],
                    'size_bytes' => (int) $version['size_bytes'],
                    'sha256' => $version['sha256'],
                    'uploader' => [
                        'id' => (int) $version['uploader_id'],
                        'username' => $version['uploader_username'],
                    ],
                    'created_at' => $version['created_at'],
                    'download_url' => '/api/v1/attachments/'
                        . (int) $attachment['id']
                        . '/versions/' . (int) $version['version_number']
                        . '/download',
                ],
                $versions
            );
        }

        return $resource;
    }

    private function apiAuth(Request $request, string $scope, ?string $permission = null): array
    {
        $tokenService = new ApiTokenService($this->app->database());
        $context = $tokenService->authenticate($request->header('Authorization'));

        if ($context === null) {
            $limiter = new RateLimiter($this->app->database());
            $key = $request->ip();
            if ($limiter->tooManyAttempts('api_unauthenticated', $key, 60, 60)) {
                return [null, $this->error('rate_limited', 'Too many API authentication attempts.', 429, ['Retry-After' => '60'])];
            }
            $limiter->hit('api_unauthenticated', $key);
            return [null, $this->error('unauthenticated', 'A valid Bearer token is required.', 401)];
        }

        $limiter = new RateLimiter($this->app->database());
        $rateKey = 'token:' . (int) $context['token_id'] . '|ip:' . $request->ip();
        if ($limiter->tooManyAttempts('api', $rateKey, 600, 60)) {
            return [null, $this->error('rate_limited', 'API rate limit exceeded.', 429, ['Retry-After' => '60'])];
        }
        $limiter->hit('api', $rateKey);

        if (!$tokenService->hasScope($context, $scope)) {
            return [null, $this->error('insufficient_scope', 'The token does not include the required scope: ' . $scope, 403)];
        }
        if ($permission !== null && !$tokenService->hasPermission($context, $permission)) {
            return [null, $this->error('forbidden', 'The token owner does not have the required permission: ' . $permission, 403)];
        }

        return [$context, null];
    }

    private function resolveSpace(Request $request): array|Response
    {
        $repository = new SpaceRepository($this->app->database());
        $key = trim((string) $request->input('space_key', ''));
        if ($key !== '') {
            return $repository->findByKey($key)
                ?? $this->error('validation_error', 'Unknown space_key.', 422);
        }

        $id = filter_var($request->input('space_id'), FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) {
            return $this->error('validation_error', 'space_key or space_id is required.', 422);
        }

        return $this->app->database()->fetchOne(
            'SELECT * FROM spaces WHERE id = :id AND deleted_at IS NULL LIMIT 1',
            ['id' => (int) $id]
        ) ?? $this->error('validation_error', 'Unknown space_id.', 422);
    }

    private function validatedPageData(Request $request, array $context, array $space, ?array $current): array
    {
        $title = trim((string) $request->input('title', $current['title'] ?? ''));
        if ($title === '' || mb_strlen($title) > 255) {
            throw new \InvalidArgumentException('Page title is required and may contain at most 255 characters.');
        }

        $slugInput = trim((string) $request->input('slug', $current['slug'] ?? ''));
        $slug = (new Slugger())->slug($slugInput === '' ? $title : $slugInput);

        $status = (string) $request->input('status', $current['status'] ?? 'draft');
        if (!in_array($status, ['draft', 'published', 'archived'], true)) {
            throw new \InvalidArgumentException('Invalid page status.');
        }

        $tokenService = new ApiTokenService($this->app->database());
        $pageAccess = new PageAclService($this->app);
        $userId = (int) $context['user']['id'];
        $superAdmin = $this->isSuperAdmin($context);

        if ($status === 'published') {
            $hasPublish = $tokenService->hasPermission($context, 'page.publish');
            if (!$hasPublish) {
                throw new \InvalidArgumentException('The token owner cannot publish pages.');
            }
            if (
                $current !== null
                && !$pageAccess->canFor($current, $space, 'page.publish', $userId, $superAdmin, $hasPublish)
            ) {
                throw new \InvalidArgumentException('Page ACL does not allow publishing this page.');
            }
        }
        if ($status === 'archived') {
            $hasArchive = $tokenService->hasPermission($context, 'page.archive');
            if (!$hasArchive) {
                throw new \InvalidArgumentException('The token owner cannot archive pages.');
            }
            if (
                $current !== null
                && !$pageAccess->canFor($current, $space, 'page.archive', $userId, $superAdmin, $hasArchive)
            ) {
                throw new \InvalidArgumentException('Page ACL does not allow archiving this page.');
            }
        }

        $parentValue = $request->input('parent_id', $current['parent_id'] ?? null);
        $parentId = ($parentValue === null || $parentValue === '') ? null : filter_var($parentValue, FILTER_VALIDATE_INT);
        if ($parentId === false || (is_int($parentId) && $parentId < 1)) {
            throw new \InvalidArgumentException('Invalid parent_id.');
        }

        $repository = new PageRepository($this->app->database());
        if ($parentId !== null) {
            $parent = $repository->findById((int) $parentId);
            if ($parent === null || (int) $parent['space_id'] !== (int) $space['id']) {
                throw new \InvalidArgumentException('Parent page must belong to the same space.');
            }

            if ($current === null) {
                if (!$pageAccess->canFor($parent, $space, 'page.create', $userId, $superAdmin, true)) {
                    throw new \InvalidArgumentException('Page ACL does not allow creating a child under this parent.');
                }
            } else {
                $parentChanged = (int) ($current['parent_id'] ?? 0) !== (int) $parentId;
                if ($parentChanged) {
                    $hasMove = $tokenService->hasPermission($context, 'page.move');
                    if (
                        !$pageAccess->canFor($current, $space, 'page.move', $userId, $superAdmin, $hasMove)
                        || !$pageAccess->canViewFor(
                            $parent,
                            $space,
                            $userId,
                            $superAdmin,
                            $tokenService->hasPermission($context, 'page.view')
                        )
                    ) {
                        throw new \InvalidArgumentException('The token owner cannot move this page to the selected parent.');
                    }
                }
            }

            if ($current !== null && $repository->wouldCreateCycle((int) $current['id'], (int) $parentId)) {
                throw new \InvalidArgumentException('The selected parent would create a cycle.');
            }
        }

        if (
            $current !== null
            && $parentId === null
            && $current['parent_id'] !== null
            && !$pageAccess->canFor(
                $current,
                $space,
                'page.move',
                $userId,
                $superAdmin,
                $tokenService->hasPermission($context, 'page.move')
            )
        ) {
            throw new \InvalidArgumentException('The token owner cannot move this page to the root level.');
        }

        $format = (string) $request->input('content_format', $current['content_format'] ?? 'visual');
        $content = (new ContentService())->normalize(
            $format,
            (string) $request->input('content_html', $current['content_html'] ?? ''),
            (string) $request->input('content_markdown', $current['content_markdown'] ?? '')
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

    private function commentRow(int $commentId): ?array
    {
        return $this->app->database()->fetchOne(
            'SELECT c.id, c.page_id, c.parent_id, c.author_id, c.body_html,
                    c.created_at, c.updated_at,
                    u.username, u.first_name, u.last_name
             FROM comments c
             INNER JOIN users u ON u.id = c.author_id
             WHERE c.id = :id AND c.deleted_at IS NULL
             LIMIT 1',
            ['id' => $commentId]
        );
    }

    private function pageRow(int $pageId): ?array
    {
        return $this->app->database()->fetchOne(
            'SELECT p.*, u.username AS author_username,
                    s.space_key, s.name AS space_name, s.visibility AS space_visibility,
                    s.owner_id AS space_owner_id, s.status AS space_status, s.deleted_at AS space_deleted_at
             FROM pages p
             INNER JOIN users u ON u.id = p.author_id
             INNER JOIN spaces s ON s.id = p.space_id
             WHERE p.id = :id AND p.deleted_at IS NULL
             LIMIT 1',
            ['id' => $pageId]
        );
    }

    private function canViewSearchRow(array $context, array $row): bool
    {
        $page = [
            'id' => (int) $row['id'],
            'space_id' => (int) $row['space_id'],
            'parent_id' => $row['parent_id'] === null
                ? null
                : (int) $row['parent_id'],
            'inherit_acl' => (int) $row['inherit_acl'],
            'status' => $row['status'],
            'owner_id' => (int) $row['owner_id'],
            'author_id' => (int) $row['author_id'],
        ];
        $space = [
            'id' => (int) $row['space_id'],
            'owner_id' => (int) $row['space_owner_id'],
            'visibility' => $row['visibility'],
            'status' => $row['space_status'],
            'deleted_at' => $row['space_deleted_at'],
        ];

        return (new PageAclService($this->app))->canViewFor(
            $page,
            $space,
            (int) $context['user']['id'],
            $this->isSuperAdmin($context),
            $this->contextHasPermission($context, 'page.view')
        );
    }

    private function canViewPage(array $context, array $page): bool
    {
        return (new PageAclService($this->app))->canViewFor(
            $page,
            $this->spaceFromPage($page),
            (int) $context['user']['id'],
            $this->isSuperAdmin($context),
            $this->contextHasPermission($context, 'page.view')
        );
    }

    private function spaceFromPage(array $page): array
    {
        return [
            'id' => (int) $page['space_id'],
            'space_key' => $page['space_key'],
            'name' => $page['space_name'],
            'visibility' => $page['space_visibility'],
            'owner_id' => (int) $page['space_owner_id'],
            'status' => $page['space_status'],
            'deleted_at' => $page['space_deleted_at'],
        ];
    }

    private function spaceState(array $context, string $spaceKey): array|Response
    {
        $spaces = (new SpaceRepository($this->app->database()))->visibleFor(
            (int) $context['user']['id'],
            $this->isSuperAdmin($context)
        );

        if ($spaceKey !== '') {
            $spaces = array_values(array_filter(
                $spaces,
                static fn (array $space): bool => strcasecmp((string) $space['space_key'], $spaceKey) === 0
            ));
            if ($spaces === []) {
                return $this->error('not_found', 'Space not found.', 404);
            }
        }

        $visibleIds = [];
        $editableIds = [];
        $access = new SpaceAccessService($this->app);
        foreach ($spaces as $space) {
            $visibleIds[] = (int) $space['id'];
            if ($access->canEditFor($space, (int) $context['user']['id'], $this->isSuperAdmin($context))) {
                $editableIds[] = (int) $space['id'];
            }
        }

        return ['spaces' => $spaces, 'visible_ids' => $visibleIds, 'editable_ids' => $editableIds];
    }

    private function contextHasPermission(array $context, string $permission): bool
    {
        $permissions = $context['permissions'] ?? [];
        return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
    }

    private function isSuperAdmin(array $context): bool
    {
        return in_array('*', $context['permissions'] ?? [], true);
    }

    private function apiSearchFilters(Request $request): array
    {
        $filters = [
            'space' => mb_substr(
                trim((string) $request->query('space', '')),
                0,
                191
            ),
            'author' => mb_substr(
                trim((string) $request->query('author', '')),
                0,
                100
            ),
            'tag' => mb_substr(
                trim((string) $request->query('tag', '')),
                0,
                100
            ),
        ];

        foreach ([
            'created_from' => false,
            'created_to' => true,
            'updated_from' => false,
            'updated_to' => true,
        ] as $field => $endOfDay) {
            $value = trim((string) $request->query($field, ''));
            if ($value === '') {
                $filters[$field] = '';
                continue;
            }

            if (
                preg_match(
                    '/^(\d{4})-(\d{2})-(\d{2})$/',
                    $value,
                    $match
                ) !== 1
                || !checkdate(
                    (int) $match[2],
                    (int) $match[3],
                    (int) $match[1]
                )
            ) {
                throw new \InvalidArgumentException(
                    'Invalid date filter: ' . $field . '.'
                );
            }

            $filters[$field] = $value
                . ($endOfDay ? ' 23:59:59' : ' 00:00:00');
        }

        return $filters;
    }

    private function pagination(Request $request): array
    {
        $page = filter_var($request->query('page', 1), FILTER_VALIDATE_INT);
        $perPage = filter_var($request->query('per_page', 25), FILTER_VALIDATE_INT);
        $page = $page === false ? 1 : max(1, (int) $page);
        $perPage = $perPage === false ? 25 : max(1, min(100, (int) $perPage));

        return [$page, $perPage, ($page - 1) * $perPage];
    }

    private function paginationMeta(int $page, int $perPage, int $total): array
    {
        return [
            'page' => $page,
            'per_page' => $perPage,
            'total' => $total,
            'total_pages' => $total === 0 ? 0 : (int) ceil($total / $perPage),
        ];
    }

    private function positiveId(string $value): ?int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT);
        return $id === false || $id < 1 ? null : (int) $id;
    }

    private function spaceResource(array $space): array
    {
        return [
            'id' => (int) $space['id'],
            'name' => $space['name'],
            'key' => $space['space_key'],
            'description' => $space['description'] ?? null,
            'visibility' => $space['visibility'],
            'status' => $space['status'],
            'created_at' => $space['created_at'] ?? null,
            'updated_at' => $space['updated_at'] ?? null,
        ];
    }

    private function roleResource(array $role): array
    {
        return [
            'id' => (int) $role['id'],
            'name' => $role['name'],
            'slug' => $role['slug'],
            'description' => $role['description'],
            'is_system' => (bool) $role['is_system'],
            'permission_ids' => array_values(array_map(
                'intval',
                $role['permission_ids'] ?? []
            )),
            'user_count' => isset($role['user_count'])
                ? (int) $role['user_count']
                : null,
            'permission_count' => isset($role['permission_count'])
                ? (int) $role['permission_count']
                : count($role['permission_ids'] ?? []),
            'created_at' => $role['created_at'],
            'updated_at' => $role['updated_at'],
        ];
    }

    private function userResource(array $user): array
    {
        return [
            'id' => (int) $user['id'],
            'username' => $user['username'],
            'email' => $user['email'],
            'first_name' => $user['first_name'],
            'last_name' => $user['last_name'],
            'status' => $user['status'],
            'auth_source' => $user['auth_source'],
            'force_password_change' => (bool) (
                $user['force_password_change'] ?? false
            ),
            'role_ids' => array_values(array_map(
                'intval',
                $user['role_ids'] ?? []
            )),
            'group_ids' => array_values(array_map(
                'intval',
                $user['group_ids'] ?? []
            )),
            'roles' => $user['role_names'] ?? null,
            'groups' => $user['group_names'] ?? null,
            'last_login_at' => $user['last_login_at'],
            'created_at' => $user['created_at'],
            'updated_at' => $user['updated_at'],
        ];
    }

    private function groupResource(array $group): array
    {
        return [
            'id' => (int) $group['id'],
            'name' => $group['name'],
            'slug' => $group['slug'],
            'description' => $group['description'],
            'source' => $group['source'],
            'external_id' => $group['external_id'],
            'user_ids' => array_values(array_map(
                'intval',
                $group['user_ids'] ?? []
            )),
            'member_count' => isset($group['member_count'])
                ? (int) $group['member_count']
                : count($group['user_ids'] ?? []),
            'created_at' => $group['created_at'],
            'updated_at' => $group['updated_at'],
        ];
    }

    private function commentResource(array $comment): array
    {
        return [
            'id' => (int) $comment['id'],
            'page_id' => (int) $comment['page_id'],
            'parent_id' => $comment['parent_id'] === null
                ? null
                : (int) $comment['parent_id'],
            'author' => [
                'id' => (int) $comment['author_id'],
                'username' => $comment['username'],
                'first_name' => $comment['first_name'],
                'last_name' => $comment['last_name'],
            ],
            'body_html' => $comment['body_html'],
            'created_at' => $comment['created_at'],
            'updated_at' => $comment['updated_at'],
        ];
    }

    private function pageListResource(array $page): array
    {
        return [
            'id' => (int) $page['id'],
            'space_id' => (int) $page['space_id'],
            'parent_id' => $page['parent_id'] === null ? null : (int) $page['parent_id'],
            'title' => $page['title'],
            'slug' => $page['slug'],
            'status' => $page['status'],
            'version' => (int) $page['version'],
            'author' => ['id' => (int) $page['author_id'], 'username' => $page['author_username']],
            'owner_id' => (int) $page['owner_id'],
            'space' => ['key' => $page['space_key'], 'name' => $page['space_name']],
            'published_at' => $page['published_at'],
            'created_at' => $page['created_at'],
            'updated_at' => $page['updated_at'],
        ];
    }

    private function pageResource(array $page): array
    {
        return $this->pageListResource($page) + [
            'content_format' => $page['content_format'],
            'content_html' => $page['content_html'],
            'content_markdown' => $page['content_markdown'],
            'tags' => (new WikiMetadataService($this->app->database()))->tagsForPage((int) $page['id']),
        ];
    }

    private function error(string $code, string $message, int $status, array $headers = []): Response
    {
        return Response::json(['error' => ['code' => $code, 'message' => $message]], $status, $headers);
    }
}
