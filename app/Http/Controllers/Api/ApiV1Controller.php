<?php

declare(strict_types=1);

namespace OpenWiki\Http\Controllers\Api;

use OpenWiki\Audit\AuditLogger;
use OpenWiki\Auth\ApiTokenService;
use OpenWiki\Core\Request;
use OpenWiki\Core\Response;
use OpenWiki\Http\Controller;
use OpenWiki\Permissions\PageAclService;
use OpenWiki\Permissions\SpaceAccessService;
use OpenWiki\Repositories\PageRepository;
use OpenWiki\Repositories\SearchRepository;
use OpenWiki\Repositories\SpaceRepository;
use OpenWiki\Security\RateLimiter;
use OpenWiki\Wiki\ContentService;
use OpenWiki\Wiki\Slugger;
use OpenWiki\Wiki\WikiMetadataService;

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

        [$visibilitySql, $params] = $this->pageVisibilitySql($context, $state);
        $where = 'p.deleted_at IS NULL AND p.space_id IN (' . implode(',', array_fill(0, count($state['visible_ids']), '?')) . ') AND ' . $visibilitySql;
        $allParams = array_merge($state['visible_ids'], $params);

        $query = trim((string) $request->query('q', ''));
        if ($query !== '') {
            $where .= ' AND (p.title LIKE ? OR p.content_text LIKE ?)';
            $like = '%' . $query . '%';
            $allParams[] = $like;
            $allParams[] = $like;
        }

        $count = $this->app->database()->fetchOne(
            'SELECT COUNT(*) AS total FROM pages p WHERE ' . $where,
            $allParams
        );
        $total = (int) ($count['total'] ?? 0);

        $rows = $this->app->database()->fetchAll(
            'SELECT p.id, p.space_id, p.parent_id, p.title, p.slug, p.status, p.version,
                    p.author_id, p.owner_id, p.created_at, p.updated_at, p.published_at,
                    s.space_key, s.name AS space_name, u.username AS author_username
             FROM pages p
             INNER JOIN spaces s ON s.id = p.space_id
             INNER JOIN users u ON u.id = p.author_id
             WHERE ' . $where . '
             ORDER BY ' . $sortMap[$sort] . ' ' . strtoupper($order) . '
             LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $allParams
        );

        return Response::json([
            'data' => array_map([$this, 'pageListResource'], $rows),
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

            $id = $this->app->database()->transaction(
                function () use ($data, $metadata, $tagInput, $decorated, $space): int {
                    $pageId = (new PageRepository($this->app->database()))->create($data);
                    $metadata->syncTags($pageId, $tagInput);
                    $metadata->syncLinks($pageId, (int) $space['id'], $decorated['references']);
                    $metadata->refreshSpaceLinks((int) $space['id']);
                    return $pageId;
                }
            );
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

            $before = ['title' => $row['title'], 'slug' => $row['slug'], 'status' => $row['status'], 'version' => (int) $row['version']];
            $result = $this->app->database()->transaction(
                function () use ($row, $data, $metadata, $tagInput, $decorated, $space, $pageId): array {
                    $updatedResult = (new PageRepository($this->app->database()))->update($row, $data);
                    $metadata->syncTags($pageId, $tagInput);
                    $metadata->syncLinks($pageId, (int) $space['id'], $decorated['references']);
                    $metadata->refreshSpaceLinks((int) $space['id']);
                    return $updatedResult;
                }
            );
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

    public function search(Request $request): Response
    {
        [$context, $failure] = $this->apiAuth($request, 'search:read', 'page.view');
        if ($failure !== null) {
            return $failure;
        }

        $query = trim((string) $request->query('q', ''));
        if ($query === '') {
            return $this->error('validation_error', 'q is required.', 422);
        }
        if (mb_strlen($query) > 200) {
            return $this->error('validation_error', 'q may contain at most 200 characters.', 422);
        }

        [$page, $perPage, $offset] = $this->pagination($request);
        $raw = (new SearchRepository($this->app->database()))->searchPages($query, 100);
        $filtered = [];

        foreach ($raw as $row) {
            $pageRow = $this->pageRow((int) $row['id']);
            if ($pageRow === null || !$this->canViewPage($context, $pageRow)) {
                continue;
            }

            $filtered[] = [
                'id' => (int) $row['id'],
                'title' => $row['title'],
                'slug' => $row['slug'],
                'space' => ['id' => (int) $row['space_id'], 'key' => $row['space_key'], 'name' => $row['space_name']],
                'status' => $row['status'],
                'updated_at' => $row['updated_at'],
                'relevance' => (int) $row['relevance'],
                'snippet' => mb_substr(trim((string) $row['content_text']), 0, 240),
            ];
        }

        $total = count($filtered);
        return Response::json([
            'data' => array_slice($filtered, $offset, $perPage),
            'meta' => $this->paginationMeta($page, $perPage, $total),
        ]);
    }

    public function users(Request $request): Response
    {
        [$context, $failure] = $this->apiAuth($request, 'users:read', 'user.manage');
        if ($failure !== null) {
            return $failure;
        }

        [$page, $perPage, $offset] = $this->pagination($request);
        $query = trim((string) $request->query('q', ''));
        $where = 'deleted_at IS NULL';
        $params = [];
        if ($query !== '') {
            $where .= ' AND (username LIKE ? OR email LIKE ? OR first_name LIKE ? OR last_name LIKE ?)';
            $like = '%' . $query . '%';
            $params = [$like, $like, $like, $like];
        }

        $count = $this->app->database()->fetchOne('SELECT COUNT(*) AS total FROM users WHERE ' . $where, $params);
        $rows = $this->app->database()->fetchAll(
            'SELECT id, username, email, first_name, last_name, status, auth_source, last_login_at, created_at, updated_at
             FROM users WHERE ' . $where . '
             ORDER BY username ASC LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $params
        );

        return Response::json([
            'data' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'username' => $row['username'],
                'email' => $row['email'],
                'first_name' => $row['first_name'],
                'last_name' => $row['last_name'],
                'status' => $row['status'],
                'auth_source' => $row['auth_source'],
                'last_login_at' => $row['last_login_at'],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
            ], $rows),
            'meta' => $this->paginationMeta($page, $perPage, (int) ($count['total'] ?? 0)),
        ]);
    }

    public function groups(Request $request): Response
    {
        [$context, $failure] = $this->apiAuth($request, 'groups:read', 'group.manage');
        if ($failure !== null) {
            return $failure;
        }

        [$page, $perPage, $offset] = $this->pagination($request);
        $count = $this->app->database()->fetchOne('SELECT COUNT(*) AS total FROM user_groups');
        $rows = $this->app->database()->fetchAll(
            'SELECT g.id, g.name, g.slug, g.description, g.source, g.external_id, g.created_at, g.updated_at,
                    COUNT(gu.user_id) AS member_count
             FROM user_groups g
             LEFT JOIN group_users gu ON gu.group_id = g.id
             GROUP BY g.id
             ORDER BY g.name ASC
             LIMIT ' . $perPage . ' OFFSET ' . $offset
        );

        return Response::json([
            'data' => array_map(static function (array $row): array {
                $row['id'] = (int) $row['id'];
                $row['member_count'] = (int) $row['member_count'];
                return $row;
            }, $rows),
            'meta' => $this->paginationMeta($page, $perPage, (int) ($count['total'] ?? 0)),
        ]);
    }

    public function tags(Request $request): Response
    {
        [$context, $failure] = $this->apiAuth($request, 'tags:read', 'page.view');
        if ($failure !== null) {
            return $failure;
        }

        [$page, $perPage, $offset] = $this->pagination($request);
        $count = $this->app->database()->fetchOne('SELECT COUNT(*) AS total FROM tags');
        $rows = $this->app->database()->fetchAll(
            'SELECT t.id, t.name, t.slug, COUNT(pt.page_id) AS page_count
             FROM tags t
             LEFT JOIN page_tags pt ON pt.tag_id = t.id
             GROUP BY t.id
             ORDER BY t.name ASC
             LIMIT ' . $perPage . ' OFFSET ' . $offset
        );

        return Response::json([
            'data' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'name' => $row['name'],
                'slug' => $row['slug'],
                'page_count' => (int) $row['page_count'],
            ], $rows),
            'meta' => $this->paginationMeta($page, $perPage, (int) ($count['total'] ?? 0)),
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

        [$visibilitySql, $visibilityParams] = $this->pageVisibilitySql($context, $state);
        $spacePlaceholders = implode(',', array_fill(0, count($state['visible_ids']), '?'));
        $where = 'a.deleted_at IS NULL AND p.deleted_at IS NULL AND p.space_id IN (' . $spacePlaceholders . ') AND ' . $visibilitySql;
        $params = array_merge($state['visible_ids'], $visibilityParams);

        $count = $this->app->database()->fetchOne(
            'SELECT COUNT(*) AS total
             FROM attachments a
             INNER JOIN pages p ON p.id = a.page_id
             WHERE ' . $where,
            $params
        );
        $rows = $this->app->database()->fetchAll(
            'SELECT a.id, a.page_id, a.name, a.mime_type, a.size_bytes, a.current_version, a.created_at, a.updated_at,
                    p.title AS page_title, p.slug AS page_slug, s.space_key
             FROM attachments a
             INNER JOIN pages p ON p.id = a.page_id
             INNER JOIN spaces s ON s.id = p.space_id
             WHERE ' . $where . '
             ORDER BY a.updated_at DESC
             LIMIT ' . $perPage . ' OFFSET ' . $offset,
            $params
        );

        return Response::json([
            'data' => array_map(static fn (array $row): array => [
                'id' => (int) $row['id'],
                'page_id' => (int) $row['page_id'],
                'name' => $row['name'],
                'mime_type' => $row['mime_type'],
                'size_bytes' => (int) $row['size_bytes'],
                'current_version' => (int) $row['current_version'],
                'page' => ['title' => $row['page_title'], 'slug' => $row['page_slug'], 'space_key' => $row['space_key']],
                'created_at' => $row['created_at'],
                'updated_at' => $row['updated_at'],
            ], $rows),
            'meta' => $this->paginationMeta($page, $perPage, (int) ($count['total'] ?? 0)),
        ]);
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
        if ($status === 'published' && !$tokenService->hasPermission($context, 'page.publish')) {
            throw new \InvalidArgumentException('The token owner cannot publish pages.');
        }
        if ($status === 'archived' && !$tokenService->hasPermission($context, 'page.archive')) {
            throw new \InvalidArgumentException('The token owner cannot archive pages.');
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
            if ($current !== null && $repository->wouldCreateCycle((int) $current['id'], (int) $parentId)) {
                throw new \InvalidArgumentException('The selected parent would create a cycle.');
            }
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

    private function pageVisibilitySql(array $context, array $state): array
    {
        $userId = (int) $context['user']['id'];
        if ($this->isSuperAdmin($context)) {
            return ['1=1', []];
        }

        $parts = ['p.status = "published"', 'p.owner_id = ?', 'p.author_id = ?'];
        $params = [$userId, $userId];
        if ($state['editable_ids'] !== []) {
            $parts[] = 'p.space_id IN (' . implode(',', array_fill(0, count($state['editable_ids']), '?')) . ')';
            $params = array_merge($params, $state['editable_ids']);
        }

        return ['(' . implode(' OR ', $parts) . ')', $params];
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
