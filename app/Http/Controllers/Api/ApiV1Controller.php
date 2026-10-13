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
use OpenWiki\Permissions\PageAclService;
use OpenWiki\Permissions\SpaceAccessService;
use OpenWiki\Repositories\PageRepository;
use OpenWiki\Repositories\SpaceRepository;
use OpenWiki\Security\RateLimiter;
use OpenWiki\Wiki\CommentService;
use OpenWiki\Wiki\ContentService;
use OpenWiki\Wiki\PageTemplateService;
use OpenWiki\Wiki\Slugger;
use OpenWiki\Wiki\WikiMetadataService;
use OpenWiki\Webhooks\WebhookService;

final class ApiV1Controller extends Controller
{
    private array $requestSpaces = [];
    private ?PageAclService $requestAcl = null;
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
        return $this->spaceMutation($request, null, 'create');
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
        return $this->spaceMutation($request, $id, 'update');
    }

    public function deleteSpace(Request $request, string $id): Response
    {
        return $this->spaceMutation($request, $id, 'delete');
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
            'SELECT p.id, p.space_id, p.parent_id, p.inherit_acl, p.owner_id, p.author_id, p.title, p.slug, p.status, p.version, p.published_revision, p.workflow_state, p.content_owner_id, p.review_due_at, p.verified_at, p.verified_revision, p.published_at, p.created_at, p.updated_at, u.username AS author_username,
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
        $parents = $this->visibleParents($slice, $context);

        return Response::json([
            'data' => array_map(fn (array $row): array => $this->pageListResource($row, $context, $parents), $slice),
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

        if ($request->query('view') === 'working') {
            if (!(new PageAclService($this->app))->canReadWorkingFor($row, $this->spaceFromPage($row), (int) $context['user']['id'], $this->isSuperAdmin($context), $context['permissions'])) return $this->error('not_found', 'Page not found.', 404);
            $row = (new PageRepository($this->app->database()))->working($row);
            $row['is_working'] = true;
        }
        return Response::json(['data' => $this->pageResource($row, $context)]);
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
            $tokens = new ApiTokenService($this->app->database());
            $saved = (new \OpenWiki\Wiki\PageWriteService($this->app->database(), $this->app->basePath()))->save(
                $space, null, (array) $request->input(), (int) $context['user']['id'], $request,
                $this->pageWritePolicy($space, $context),
                $tokens->hasScope($context, 'attachments:write') && $tokens->hasPermission($context, 'attachment.upload')
            );
            return Response::json(['data' => $this->pageResource($saved, $context)], 201);
        } catch (\DomainException $exception) {
            return $this->error('forbidden', $exception->getMessage(), 403);
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
            $tokens = new ApiTokenService($this->app->database());
            $saved = (new \OpenWiki\Wiki\PageWriteService($this->app->database(), $this->app->basePath()))->save(
                $space, $row, (array) $request->input(), (int) $context['user']['id'], $request,
                $this->pageWritePolicy($space, $context),
                $tokens->hasScope($context, 'attachments:write') && $tokens->hasPermission($context, 'attachment.upload')
            );
            return Response::json(['data' => $this->pageResource($saved, $context)], 200);
        } catch (\DomainException $exception) {
            return $this->error('forbidden', $exception->getMessage(), 403);
        } catch (\InvalidArgumentException $exception) {
            return $this->error('validation_error', $exception->getMessage(), 422);
        } catch (\RuntimeException $exception) {
            if ($exception->getMessage() === 'EDIT_CONFLICT') {
                $repository = new PageRepository($this->app->database());
                $server = $repository->findById($pageId);
                if ($server !== null && !$this->pageWritePolicy($space, $context)('page.view', $server)) {
                    return $this->error('forbidden', 'You no longer have permission to access the current page.', 403);
                }
                $content = new \OpenWiki\Wiki\ContentService();
                return Response::json(['error' => ['code' => 'edit_conflict', 'message' => 'The page has changed. Resolve the conflict before changing base_version.',
                    'conflict' => ['base_version' => (int) $baseVersion, 'base' => $content->safeProjection($repository->revision($pageId, (int) $baseVersion)),
                        'local' => (array) $request->input(), 'server' => $content->safeProjection($server)]]], 409);
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

        try {
            (new \OpenWiki\Wiki\PageLifecycleService($this->app->database(), $this->app->basePath()))
                ->trash($space, $pageId, (int) $context['user']['id'], $this->pageWritePolicy($space, $context, subtreeSnapshot: true), $request);
        } catch (\DomainException $exception) {
            return $this->error('forbidden', $exception->getMessage(), 403);
        } catch (\InvalidArgumentException $exception) {
            return $this->error('not_found', $exception->getMessage(), 404);
        } catch (\Throwable $exception) {
            error_log('[OpenWiki API page lifecycle] ' . $exception->getMessage());
            return $this->error('server_error', 'Unable to move the page subtree to trash.', 500);
        }

        return new Response('', 204);
    }

    public function comments(Request $request): Response
    {
        [$context,$failure]=$this->apiAuth($request,'comments:read','page.view');
        if ($failure!==null) return $failure;
        try {
            $id=\OpenWiki\Wiki\CollaborationAccess::positive($request->query('page_id'));
            (new \OpenWiki\Wiki\CollaborationAccess($this->app->database()))->context($id,(int)$context['user']['id'],$context['permissions']);
            [$page,$per,$offset]=$this->pagination($request);
            $where='c.page_id=? AND c.deleted_at IS NULL AND c.visibility="page" AND c.revision_number IS NULL';
            $total=(int)$this->app->database()->fetchOne('SELECT COUNT(*) n FROM comments c WHERE '.$where,[$id])['n'];
            $rows=$this->app->database()->fetchAll('SELECT c.*,u.username,u.first_name,u.last_name FROM comments c INNER JOIN users u ON u.id=c.author_id WHERE '.$where.' ORDER BY c.created_at,c.id LIMIT '.$per.' OFFSET '.$offset,[$id]);
            return Response::json(['data'=>array_map([$this,'commentResource'],$rows),'meta'=>$this->paginationMeta($page,$per,$total)]);
        } catch (\Throwable $error) { return \OpenWiki\Http\CollaborationResponse::failure($error); }
    }

    public function createComment(Request $request): Response { return $this->collaborationApi($request,'comment-create','comments:write',(string)$request->input('page_id','')); }
    public function workCenter(Request $request): Response
    {
        [$context,$failure]=$this->apiAuth($request,'work:read');
        if($failure!==null)return $failure;
        try{return Response::json((new \OpenWiki\Wiki\WorkCenterService($this->app->database()))->items((array)$request->query(),(int)$context['user']['id'],$context['permissions']));}
        catch(\Throwable $error){return \OpenWiki\Http\CollaborationResponse::failure($error);}
    }
    public function getComment(Request $request,string $id): Response { return $this->collaborationApi($request,'comment','comments:read',null,$id,'comment'); }
    public function discussionReplies(Request $request,string $id): Response { return $this->collaborationApi($request,'discussion-replies','comments:read',null,$id,'comment'); }
    public function updateComment(Request $request,string $id): Response { return $this->collaborationApi($request,'comment-update','comments:write',null,$id,'comment'); }
    public function deleteComment(Request $request,string $id): Response
    {
        $response=$this->collaborationApi($request,'comment-delete','comments:write',null,$id,'comment');
        return $response->status()===200 ? new Response('',204) : $response;
    }
    public function discussionStatus(Request $request,string $id): Response { return $this->collaborationApi($request,'discussion-status','comments:write',null,$id,'comment'); }
    public function discussions(Request $request,string $id): Response { return $this->collaborationApi($request,'discussions','comments:read',$id); }
    public function pageTasks(Request $request,string $id): Response { return $this->collaborationApi($request,'tasks','tasks:read',$id); }
    public function createTask(Request $request,string $id): Response { return $this->collaborationApi($request,'task-create','tasks:write',$id); }
    public function getTask(Request $request,string $id): Response { return $this->collaborationApi($request,'task','tasks:read',null,$id,'task'); }
    public function updateTask(Request $request,string $id): Response { return $this->collaborationApi($request,'task-update','tasks:write',null,$id,'task'); }
    public function taskHistory(Request $request,string $id): Response { return $this->collaborationApi($request,'task-history','tasks:read',null,$id,'task'); }
    public function taskAssignees(Request $request,string $id): Response { return $this->collaborationApi($request,'assignees','tasks:write',$id); }

    private function collaborationApi(Request $request,string $action,string $scope,?string $page=null,?string $resource=null,string $kind=''): Response
    {
        [$context,$failure]=$this->apiAuth($request,$scope);
        if ($failure!==null) return $failure;
        try {
            $input=(array)($request->method()==='GET'?$request->query():$request->input());
            if ($resource!==null) {
                $id=\OpenWiki\Wiki\CollaborationAccess::positive($resource);
                $table=$kind==='task'?'page_tasks':'comments';
                $row=$this->app->database()->fetchOne('SELECT page_id FROM '.$table.' WHERE id=?',[$id]);
                if ($row===null) throw new \OutOfBoundsException('Nie znaleziono zasobu.');
                $page=(string)$row['page_id']; $input['id']=$id;
            }
            $result=(new \OpenWiki\Wiki\CollaborationActions($this->app->database()))->run($action,\OpenWiki\Wiki\CollaborationAccess::positive($page),(int)$context['user']['id'],$input,$context['permissions'],$request);
            if ($action==='comment-create' || $action==='comment-update' || $action==='comment') $result['data']=$this->commentResource($result['data']);
            return Response::json($result,in_array($action,['task-create','comment-create'],true)?201:200);
        } catch (\Throwable $error) { return \OpenWiki\Http\CollaborationResponse::failure($error); }
    }

    public function search(Request $request): Response
    {
        [$context, $failure] = $this->apiAuth($request, 'search:read', 'page.view');
        if ($failure !== null) return $failure;
        try {
            $input = \OpenWiki\Search\SearchInput::fromRequest($request, 'page');
            if ($input['query'] === '') throw new \InvalidArgumentException('q is required.');
        } catch (\InvalidArgumentException $error) {
            return $this->error('validation_error', $error->getMessage(), 422);
        }
        return Response::json((new \OpenWiki\Search\SearchService($this->app->database()))->search(
            $input, (int) $context['user']['id'], $context['permissions']
        ));
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
            $service = new DirectoryAdminService($this->app->database(), \OpenWiki\Admin\AdminActor::user((int) $context['user']['id']));
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
        } catch (\DomainException $exception) {
            return $this->error('forbidden', $exception->getMessage(), 403);
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
        $service = new DirectoryAdminService($this->app->database(), \OpenWiki\Admin\AdminActor::user((int) $context['user']['id']));
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
        } catch (\DomainException $exception) {
            return $this->error('forbidden', $exception->getMessage(), 403);
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

        $service = new DirectoryAdminService($this->app->database(), \OpenWiki\Admin\AdminActor::user((int) $context['user']['id']));
        $existing = $service->user($userId);
        if ($existing === null) {
            return $this->error('not_found', 'User not found.', 404);
        }

        try {
            $deleted = $service->softDeleteUser($userId);
        } catch (\DomainException $exception) {
            return $this->error('forbidden', $exception->getMessage(), 403);
        }
        if (!$deleted) {
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
            $service = new DirectoryAdminService($this->app->database(), \OpenWiki\Admin\AdminActor::user((int) $context['user']['id']));
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
        } catch (\DomainException $exception) {
            return $this->error('forbidden', $exception->getMessage(), 403);
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
        $service = new DirectoryAdminService($this->app->database(), \OpenWiki\Admin\AdminActor::user((int) $context['user']['id']));
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
        } catch (\DomainException $exception) {
            return $this->error('forbidden', $exception->getMessage(), 403);
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
        $service = new DirectoryAdminService($this->app->database(), \OpenWiki\Admin\AdminActor::user((int) $context['user']['id']));
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
        } catch (\DomainException $exception) {
            return $this->error('forbidden', $exception->getMessage(), 403);
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
            $service = new DirectoryAdminService($this->app->database(), \OpenWiki\Admin\AdminActor::user((int) $context['user']['id']));
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
        } catch (\DomainException $exception) {
            return $this->error('forbidden', $exception->getMessage(), 403);
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
        $service = new DirectoryAdminService($this->app->database(), \OpenWiki\Admin\AdminActor::user((int) $context['user']['id']));
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
        } catch (\DomainException $exception) {
            return $this->error('forbidden', $exception->getMessage(), 403);
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
        $service = new DirectoryAdminService($this->app->database(), \OpenWiki\Admin\AdminActor::user((int) $context['user']['id']));
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
        } catch (\DomainException $exception) {
            return $this->error('forbidden', $exception->getMessage(), 403);
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
        $tagRows = $metadata->pagesForTag((string) $tag['slug']); $parents = $this->visibleParents($tagRows, $context);
        foreach ($tagRows as $row) {
            if (!$this->canViewPage($context, $row)) {
                continue;
            }
            $pages[] = $this->pageListResource($row, $context, $parents);
        }

        if ($pages === []) return $this->error('not_found', 'Tag not found.', 404);

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
                    a.current_version, a.deleted_at, a.created_at AS attachment_created_at, a.updated_at AS attachment_updated_at,
                    p.space_id, p.parent_id, p.inherit_acl, p.status AS page_status,
                    p.owner_id AS page_owner_id, p.author_id AS page_author_id,
                    p.title AS page_title, p.slug AS page_slug,
                    s.space_key, s.name AS space_name, s.visibility AS space_visibility,
                    s.owner_id AS space_owner_id, s.status AS space_status, s.deleted_at AS space_deleted_at
             FROM attachments a
             INNER JOIN pages p ON p.id = a.page_id AND p.deleted_at IS NULL
             INNER JOIN spaces s ON s.id = p.space_id AND s.deleted_at IS NULL
             WHERE ' . $this->attachmentService()->readableFilesCondition() . '
               AND p.space_id IN (' . $placeholders . ')
             ORDER BY a.updated_at DESC, a.id DESC',
            $state['visible_ids']
        );

        $filtered = [];
        $visibility = new \OpenWiki\Attachments\AttachmentVisibility($this->app->database());
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

            $row['id'] = (int) $row['attachment_id'];
            $row = $visibility->project($row, (int) $context['user']['id'], $this->isSuperAdmin($context), $context['permissions']);
            if ($row === null) continue;

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
                'updated_at' => $row['updated_at'] ?? $row['attachment_updated_at'],
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
        $attachment = $attachmentId === null ? null : $service->find($attachmentId, true);

        if (
            $attachment === null
            || !$this->canViewAttachment($context, $attachment, $request->query('revision') === null ? null : (int) $request->query('revision'))
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
        $attachment = $attachmentId === null ? null : $service->find($attachmentId, true);

        if (
            $attachment === null
            || !$this->canViewAttachment($context, $attachment, $request->query('revision') === null ? null : (int) $request->query('revision'))
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
        $attachment = $attachmentId === null ? null : $service->find($attachmentId, true);

        if (
            $attachment === null
            || !$this->canViewAttachment($context, $attachment, $request->query('revision') === null ? null : (int) $request->query('revision'))
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
        $attachment = $attachmentId === null ? null : $service->find($attachmentId, true);
        if (
            $attachment === null
            || !$this->canViewAttachment($context, $attachment, $request->query('revision') === null ? null : (int) $request->query('revision'))
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
        $attachment = $attachmentId === null ? null : $service->find($attachmentId, true);

        if (
            $attachment === null
            || $versionNumber === null
            || !$this->canViewAttachment($context, $attachment, $request->query('revision') === null ? null : (int) $request->query('revision'))
        ) {
            return $this->error('not_found', 'Attachment version not found.', 404);
        }

        if (isset($attachment['_allowed_versions']) && !in_array($versionNumber, $attachment['_allowed_versions'], true)) return $this->error('not_found', 'Attachment version not found.', 404);

        try {
            $file = $service->versionPath($attachmentId, $versionNumber);
            if ($file === null) {
                return $this->error('not_found', 'Attachment version not found.', 404);
            }

            return Response::file(
                (string) $file['path'],
                (string) $file['mime_type'],
                (string) $attachment['name'],
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

    private function canViewAttachment(array $context, array &$attachment, ?int $revision = null): bool
    {
        if (!$this->canViewPage($context, $this->attachmentPage($attachment))) return false;
        $visible = (new \OpenWiki\Attachments\AttachmentVisibility($this->app->database()))->project($attachment, (int) $context['user']['id'], $this->isSuperAdmin($context), $context['permissions'], false, $revision);
        if ($visible === null) return false;
        $attachment = $visible;
        return true;
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
        if (isset($attachment['_allowed_versions'])) $versions = array_values(array_filter($versions, static fn (array $v): bool => in_array((int) $v['version_number'], $attachment['_allowed_versions'], true)));
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

    public function templates(Request $request): Response
    {
        [$context, $failure] = $this->apiAuth($request, 'templates:read', 'template.manage');
        if ($failure !== null) {
            return $failure;
        }

        [$page, $perPage, $offset] = $this->pagination($request);
        $rows = (new PageTemplateService($this->app->database()))->all();
        $total = count($rows);

        return Response::json([
            'data' => array_map([$this, 'templateResource'], array_slice($rows, $offset, $perPage)),
            'meta' => $this->paginationMeta($page, $perPage, $total),
        ]);
    }

    public function template(Request $request, string $id): Response
    {
        [$context, $failure] = $this->apiAuth($request, 'templates:read', 'template.manage');
        if ($failure !== null) {
            return $failure;
        }

        $templateId = $this->positiveId($id);
        $row = $templateId === null
            ? null
            : (new PageTemplateService($this->app->database()))->find($templateId);

        if ($row === null) {
            return $this->error('not_found', 'Template not found.', 404);
        }

        return Response::json(['data' => $this->templateResource($row, true)]);
    }

    public function createTemplate(Request $request): Response
    {
        [$context, $failure] = $this->apiAuth($request, 'templates:write', 'template.manage');
        if ($failure !== null) {
            return $failure;
        }

        try {
            $service = new PageTemplateService($this->app->database());
            $id = $service->save(null, (array) $request->input(), (int) $context['user']['id']);
            $row = $service->find($id);

            (new AuditLogger($this->app->database()))->log(
                'TEMPLATE_CREATED',
                'page_template',
                $id,
                (int) $context['user']['id'],
                $request,
                null,
                ['name' => $row['name'] ?? null]
            );

            return Response::json(['data' => $this->templateResource($row, true)], 201);
        } catch (\InvalidArgumentException $exception) {
            return $this->error('validation_error', $exception->getMessage(), 422);
        }
    }

    public function updateTemplate(Request $request, string $id): Response
    {
        [$context, $failure] = $this->apiAuth($request, 'templates:write', 'template.manage');
        if ($failure !== null) {
            return $failure;
        }

        $templateId = $this->positiveId($id);
        if ($templateId === null) {
            return $this->error('not_found', 'Template not found.', 404);
        }

        $service = new PageTemplateService($this->app->database());
        $before = $service->find($templateId);
        if ($before === null) {
            return $this->error('not_found', 'Template not found.', 404);
        }

        try {
            $service->save($templateId, (array) $request->input(), (int) $context['user']['id']);
            $after = $service->find($templateId);

            (new AuditLogger($this->app->database()))->log(
                'TEMPLATE_UPDATED',
                'page_template',
                $templateId,
                (int) $context['user']['id'],
                $request,
                ['name' => $before['name'], 'is_system' => (bool) $before['is_system']],
                ['name' => $after['name'] ?? null, 'is_system' => (bool) ($after['is_system'] ?? false)]
            );

            return Response::json(['data' => $this->templateResource($after, true)]);
        } catch (\InvalidArgumentException $exception) {
            return $this->error('validation_error', $exception->getMessage(), 422);
        }
    }

    public function deleteTemplate(Request $request, string $id): Response
    {
        [$context, $failure] = $this->apiAuth($request, 'templates:write', 'template.manage');
        if ($failure !== null) {
            return $failure;
        }

        $templateId = $this->positiveId($id);
        if ($templateId === null) {
            return $this->error('not_found', 'Template not found.', 404);
        }

        $service = new PageTemplateService($this->app->database());
        $before = $service->find($templateId);
        if ($before === null) {
            return $this->error('not_found', 'Template not found.', 404);
        }

        try {
            if (!$service->delete($templateId)) {
                return $this->error('not_found', 'Template not found.', 404);
            }

            (new AuditLogger($this->app->database()))->log(
                'TEMPLATE_DELETED',
                'page_template',
                $templateId,
                (int) $context['user']['id'],
                $request,
                ['name' => $before['name'], 'is_system' => (bool) $before['is_system']],
                ['deleted' => true]
            );

            return new Response('', 204);
        } catch (\InvalidArgumentException $exception) {
            return $this->error('validation_error', $exception->getMessage(), 422);
        }
    }

    public function webhooks(Request $request): Response
    {
        [$context, $failure] = $this->apiAuth($request, 'webhooks:read', 'webhook.manage');
        if ($failure !== null) {
            return $failure;
        }

        [$page, $perPage, $offset] = $this->pagination($request);
        $service = new WebhookService($this->app->database());
        $rows = $service->webhooks();
        $total = count($rows);

        return Response::json([
            'data' => array_map([$this, 'webhookResource'], array_slice($rows, $offset, $perPage)),
            'meta' => $this->paginationMeta($page, $perPage, $total),
        ]);
    }

    public function webhook(Request $request, string $id): Response
    {
        [$context, $failure] = $this->apiAuth($request, 'webhooks:read', 'webhook.manage');
        if ($failure !== null) {
            return $failure;
        }

        $webhookId = $this->positiveId($id);
        if ($webhookId === null) {
            return $this->error('not_found', 'Webhook not found.', 404);
        }

        foreach ((new WebhookService($this->app->database()))->webhooks() as $row) {
            if ((int) $row['id'] === $webhookId) {
                return Response::json(['data' => $this->webhookResource($row)]);
            }
        }

        return $this->error('not_found', 'Webhook not found.', 404);
    }

    public function createWebhook(Request $request): Response
    {
        [$context, $failure] = $this->apiAuth($request, 'webhooks:write', 'webhook.manage');
        if ($failure !== null) {
            return $failure;
        }

        try {
            $service = new WebhookService($this->app->database());
            $created = $service->create((array) $request->input(), (int) $context['user']['id']);
            $row = null;
            foreach ($service->webhooks() as $candidate) {
                if ((int) $candidate['id'] === (int) $created['id']) {
                    $row = $candidate;
                    break;
                }
            }

            (new AuditLogger($this->app->database()))->log(
                'WEBHOOK_CREATED',
                'webhook',
                (int) $created['id'],
                (int) $context['user']['id'],
                $request,
                null,
                ['name' => $row['name'] ?? null]
            );

            return Response::json([
                'data' => $this->webhookResource($row) + [
                    'signing_secret' => $created['secret'],
                ],
            ], 201);
        } catch (\InvalidArgumentException $exception) {
            return $this->error('validation_error', $exception->getMessage(), 422);
        }
    }

    public function updateWebhook(Request $request, string $id): Response
    {
        [$context, $failure] = $this->apiAuth($request, 'webhooks:write', 'webhook.manage');
        if ($failure !== null) {
            return $failure;
        }

        $webhookId = $this->positiveId($id);
        if ($webhookId === null) {
            return $this->error('not_found', 'Webhook not found.', 404);
        }

        $status = trim((string) $request->input('status', ''));
        if ($status === '') {
            return $this->error('validation_error', 'status is required.', 422);
        }

        try {
            $service = new WebhookService($this->app->database());
            if (!$service->setStatus($webhookId, $status)) {
                return $this->error('not_found', 'Webhook not found.', 404);
            }

            (new AuditLogger($this->app->database()))->log(
                'WEBHOOK_STATUS_CHANGED',
                'webhook',
                $webhookId,
                (int) $context['user']['id'],
                $request,
                null,
                ['status' => $status]
            );

            foreach ($service->webhooks() as $row) {
                if ((int) $row['id'] === $webhookId) {
                    return Response::json(['data' => $this->webhookResource($row)]);
                }
            }

            return $this->error('not_found', 'Webhook not found.', 404);
        } catch (\InvalidArgumentException $exception) {
            return $this->error('validation_error', $exception->getMessage(), 422);
        }
    }

    public function deleteWebhook(Request $request, string $id): Response
    {
        [$context, $failure] = $this->apiAuth($request, 'webhooks:write', 'webhook.manage');
        if ($failure !== null) {
            return $failure;
        }

        $webhookId = $this->positiveId($id);
        if ($webhookId === null) {
            return $this->error('not_found', 'Webhook not found.', 404);
        }

        if (!(new WebhookService($this->app->database()))->delete($webhookId)) {
            return $this->error('not_found', 'Webhook not found.', 404);
        }

        (new AuditLogger($this->app->database()))->log(
            'WEBHOOK_DELETED',
            'webhook',
            $webhookId,
            (int) $context['user']['id'],
            $request
        );

        return new Response('', 204);
    }

    public function webhookDeliveries(Request $request, string $id): Response
    {
        [$context, $failure] = $this->apiAuth($request, 'webhooks:read', 'webhook.manage');
        if ($failure !== null) {
            return $failure;
        }

        $webhookId = $this->positiveId($id);
        if ($webhookId === null) {
            return $this->error('not_found', 'Webhook not found.', 404);
        }

        [$page, $perPage, $offset] = $this->pagination($request);
        $rows = array_values(array_filter(
            (new WebhookService($this->app->database()))->deliveries(500),
            static fn (array $row): bool => (int) $row['webhook_id'] === $webhookId
        ));

        if ($rows === []) {
            $exists = $this->app->database()->fetchOne(
                'SELECT id FROM webhooks WHERE id = :id LIMIT 1',
                ['id' => $webhookId]
            );
            if ($exists === null) {
                return $this->error('not_found', 'Webhook not found.', 404);
            }
        }

        $total = count($rows);
        return Response::json([
            'data' => array_slice($rows, $offset, $perPage),
            'meta' => $this->paginationMeta($page, $perPage, $total),
        ]);
    }

    private function templateResource(?array $row, bool $includeContent = false): array
    {
        if ($row === null) {
            return [];
        }

        $resource = [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'description' => $row['description'],
            'is_system' => (bool) $row['is_system'],
            'created_by' => $row['created_by'] === null ? null : (int) $row['created_by'],
            'creator_username' => $row['creator_username'] ?? null,
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];

        if ($includeContent) {
            $resource['content_html'] = $row['content_html'];
            $resource['content_markdown'] = $row['content_markdown'];
        }

        return $resource;
    }

    private function webhookResource(?array $row): array
    {
        if ($row === null) {
            return [];
        }

        $events = json_decode((string) $row['events_json'], true);
        return [
            'id' => (int) $row['id'],
            'name' => $row['name'],
            'target_url' => $row['target_url'],
            'events' => is_array($events) ? array_values($events) : [],
            'status' => $row['status'],
            'created_by' => $row['created_by'] === null ? null : (int) $row['created_by'],
            'created_at' => $row['created_at'],
            'updated_at' => $row['updated_at'],
        ];
    }

    private function apiAuth(Request $request, string $scope, ?string $permission = null): array
    {
        $this->requestSpaces = [];
        $this->requestAcl = new PageAclService($this->app);
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

    private function spaceMutation(Request $request, ?string $id, string $action): Response
    {
        [$context, $failure] = $this->apiAuth($request, 'spaces:write');
        if ($failure !== null) return $failure;
        try {
            $service = new \OpenWiki\Spaces\SpaceService($this->app);
            $actor = (int) $context['user']['id'];
            if ($action === 'create') $space = $service->create((array) $request->input(), $actor, $request, $context['permissions']);
            else {
                $spaceId = $this->positiveId((string) $id) ?? throw new \OutOfBoundsException('Nie znaleziono przestrzeni.');
                if ($action === 'delete') { $service->delete($spaceId, $actor, $request, $context['permissions']); return new Response('', 204); }
                $space = $service->update($spaceId, (array) $request->input(), $actor, $request, $context['permissions']);
            }
            return Response::json(['data' => $this->spaceResource($space)], $action === 'create' ? 201 : 200);
        } catch (\Throwable $error) { return $this->spaceError($error); }
    }

    private function spaceError(\Throwable $error): Response
    {
        if ($error instanceof \OpenWiki\Spaces\SpaceConflict) return $this->error('conflict', $error->getMessage(), 409);
        if ($error instanceof \OutOfBoundsException) return $this->error('not_found', $error->getMessage(), 404);
        if ($error instanceof \DomainException) return $this->error('forbidden', $error->getMessage(), 403);
        if ($error instanceof \InvalidArgumentException) return $this->error('validation_error', $error->getMessage(), 422);
        error_log('[OpenWiki space operation] ' . $error->getMessage());
        return $this->error('server_error', 'Nie udało się wykonać operacji przestrzeni.', 500);
    }

    public function spaceAccess(Request $request, string $id): Response
    {
        [$context, $failure] = $this->apiAuth($request, $request->method() === 'GET' ? 'spaces:read' : 'spaces:write');
        if ($failure !== null) return $failure;
        try {
            $service = new \OpenWiki\Spaces\SpaceService($this->app);
            $space = $service->find($this->positiveId($id) ?? throw new \OutOfBoundsException('Nie znaleziono przestrzeni.'));
            $actor = new \OpenWiki\Spaces\SpaceActor($this->app->database(), (int) $context['user']['id'], $context['permissions']);
            $service->assertAllowed($space, $actor, 'space.manage_access');
            if ($request->method() !== 'GET') $space = $service->rule((int) $space['id'], (array) $request->input(), $actor->id, $request,
                $request->input('delete_id') === null ? null : ($this->positiveId((string) $request->input('delete_id')) ?? throw new \InvalidArgumentException('Nieprawidłowa reguła.')), $context['permissions']);
            $userId = $request->query('user_id');
            return Response::json(['data' => ['space' => $this->spaceResource($space), 'rules' => $service->entries((int) $space['id']),
                'effective' => $service->effective($space, $userId === null || $userId === '' ? null : ($this->positiveId((string) $userId) ?? throw new \InvalidArgumentException('Nieprawidłowy użytkownik.')), $request->query('page_id') === null ? null : ($this->positiveId((string) $request->query('page_id')) ?? throw new \InvalidArgumentException('Nieprawidłowa strona.')), $actor)]]);
        } catch (\Throwable $error) { return $this->spaceError($error); }
    }

    public function spacePrincipals(Request $request, string $id): Response
    {
        [$context, $failure] = $this->apiAuth($request, 'spaces:read');
        if ($failure !== null) return $failure;
        try {
            $service = new \OpenWiki\Spaces\SpaceService($this->app);
            $space = $service->find($this->positiveId($id) ?? throw new \OutOfBoundsException('Nie znaleziono przestrzeni.'));
            $actor = new \OpenWiki\Spaces\SpaceActor($this->app->database(), (int) $context['user']['id'], $context['permissions']);
            $type = $request->query('type', 'user'); $query = $request->query('q', '');
            if (!is_string($type) || !is_string($query)) throw new \InvalidArgumentException('Nieprawidłowe wyszukiwanie.');
            if (!$service->allowed($space, $actor, 'space.manage_access') && !($type === 'user' && $service->allowed($space, $actor, 'space.manage_owner'))) throw new \DomainException('Brak uprawnienia do wyszukiwania adresatów.');
            return Response::json(['data' => $service->principals($type, $query)]);
        } catch (\Throwable $error) { return $this->spaceError($error); }
    }

    public function spaceTree(Request $request, string $id): Response
    {
        [$context, $failure] = $this->apiAuth($request, 'spaces:read', 'space.view');
        if ($failure !== null) return $failure;
        try {
            $space = (new \OpenWiki\Spaces\SpaceService($this->app))->find($this->positiveId($id) ?? throw new \OutOfBoundsException('Nie znaleziono przestrzeni.'));
            $parent = $request->query('parent_id');
            $parentId = $parent === null ? null : ($this->positiveId((string) $parent) ?? throw new \OutOfBoundsException('Nie znaleziono gałęzi.'));
            return Response::json(['data' => (new \OpenWiki\Spaces\DocumentationTreeService($this->app))->branch($space, (int) $context['user']['id'],
                $this->isSuperAdmin($context), $this->contextHasPermission($context, 'page.view'), $this->contextHasPermission($context, 'page.move'),
                $parentId, (string) $request->query('q', ''), max(0, (int) $request->query('offset', 0)), $this->contextHasPermission($context, 'page.create'), $request->query('siblings_only') === '1')]);
        } catch (\Throwable $error) { return $this->spaceError($error); }
    }

    public function structurePreview(Request $request, string $id): Response { return $this->structureOperation($request, $id, true); }
    public function structureCommit(Request $request, string $id): Response { return $this->structureOperation($request, $id, false); }

    private function structureOperation(Request $request, string $id, bool $preview): Response
    {
        [$context, $failure] = $this->apiAuth($request, 'pages:write');
        if ($failure !== null) return $failure;
        try {
            $pageId = $this->positiveId($id) ?? throw new \OutOfBoundsException('Nie znaleziono strony.');
            $service = new \OpenWiki\Spaces\StructureService($this->app); $input = (array) $request->input();
            $tokens = new \OpenWiki\Auth\ApiTokenService($this->app->database());
            if (($input['action'] ?? 'move') === 'copy' && (!$tokens->hasScope($context, 'attachments:read') || !$tokens->hasScope($context, 'attachments:write'))) {
                // This ceiling is checked against the selected subtree inside the locked plan.
                $input['copy_attachments_allowed'] = false;
            } else $input['copy_attachments_allowed'] = true;
            return Response::json(['data' => $preview ? $service->preview($pageId, $input, (int) $context['user']['id'], $context['permissions'])
                : $service->execute($pageId, $input, (int) $context['user']['id'], $request, $context['permissions'])]);
        } catch (\Throwable $error) { return $this->spaceError($error); }
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
        return $this->requestAcl->canViewFor(
            $page,
            $this->spaceFromPage($page),
            (int) $context['user']['id'],
            $this->isSuperAdmin($context),
            $this->contextHasPermission($context, 'page.view'),
            $context['permissions']
        );
    }

    private function spaceFromPage(array $page): array
    {
        return $this->requestSpaces[(int) $page['space_id']] ??= $this->app->database()->fetchOne('SELECT * FROM spaces WHERE id = ?', [(int) $page['space_id']])
            ?? throw new \OutOfBoundsException('Space not found.');
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
            $this->requestSpaces[(int) $space['id']] = $space;
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
            'icon' => $space['icon'] ?? null,
            'owner_id' => (int) $space['owner_id'],
            'home_page_id' => isset($space['home_page_id']) ? (int) $space['home_page_id'] : null,
            'version' => (int) ($space['management_version'] ?? 1),
            'review_workflow' => (bool) ($space['review_workflow'] ?? false),
            'allow_self_review' => (bool) ($space['allow_self_review'] ?? false),
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
            'thread_id' => isset($comment['thread_id']) ? (int) $comment['thread_id'] : null,
            'revision_number' => isset($comment['revision_number']) ? (int) $comment['revision_number'] : null,
            'visibility' => $comment['visibility'] ?? 'page',
            'thread_status' => $comment['thread_status'] ?? 'open',
            'version' => (int) ($comment['version'] ?? 1),
            'anchor' => isset($comment['anchor_json']) ? json_decode($comment['anchor_json'], true, 512, JSON_THROW_ON_ERROR) : null,
            'created_at' => $comment['created_at'],
            'updated_at' => $comment['updated_at'],
        ];
    }

    private function pageListResource(array $page, array $context, ?array $parents = null): array
    {
        $parentId = $page['parent_id'] === null ? null : (int) $page['parent_id'];
        if ($parentId !== null) {
            $parents ??= $this->visibleParents([$page], $context);
            if (!isset($parents[$parentId])) $parentId = null;
        }
        return [
            'id' => (int) $page['id'],
            'space_id' => (int) $page['space_id'],
            'parent_id' => $parentId,
            'title' => $page['title'],
            'slug' => $page['slug'],
            'status' => $page['status'],
            'version' => !empty($page['is_working']) ? (int) $page['version'] : (int) ($page['published_revision'] ?? $page['version']),
            'published_revision' => isset($page['published_revision']) ? (int) $page['published_revision'] : null,
            'content_owner_id' => isset($page['content_owner_id']) ? (int) $page['content_owner_id'] : null,
            'review_due_at' => $page['review_due_at'] ?? null,
            'verified_at' => $page['verified_at'] ?? null,
            'verified_revision' => $page['verified_revision'] ?? null,
            'author' => ['id' => (int) $page['author_id'], 'username' => $page['author_username']],
            'owner_id' => (int) $page['owner_id'],
            'space' => ['key' => $page['space_key'], 'name' => $page['space_name']],
            'published_at' => $page['published_at'],
            'created_at' => $page['created_at'],
            'updated_at' => $page['updated_at'],
        ];
    }

    private function visibleParents(array $rows, array $context): array
    {
        $ids = array_values(array_unique(array_filter(array_column($rows, 'parent_id'))));
        if ($ids === []) return [];
        return $this->app->database()->transaction(function () use ($ids, $context): array {
            $parents = $this->app->database()->fetchAll('SELECT p.id, p.space_id, p.parent_id, p.inherit_acl, p.status, p.owner_id, p.author_id, s.space_key, s.name AS space_name, s.visibility AS space_visibility, s.owner_id AS space_owner_id, s.status AS space_status, s.deleted_at AS space_deleted_at FROM pages p INNER JOIN spaces s ON s.id = p.space_id WHERE p.deleted_at IS NULL AND p.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids);
            $services = []; $visible = [];
            foreach ($parents as $parent) {
                $space = $this->spaceFromPage($parent); $spaceId = (int) $space['id'];
                if (!isset($services[$spaceId])) { $services[$spaceId] = new PageAclService($this->app); $services[$spaceId]->snapshot($space, (int) $context['user']['id'], $this->isSuperAdmin($context)); }
                if ($services[$spaceId]->canViewFor($parent, $space, (int) $context['user']['id'], $this->isSuperAdmin($context), $this->contextHasPermission($context, 'page.view'))) $visible[(int) $parent['id']] = true;
            }
            return $visible;
        });
    }

    private function pageResource(array $page, array $context): array
    {
        $page = (new \OpenWiki\Wiki\ContentService())->safeProjection($page);
        if (isset($page['tags'][0]) && is_string($page['tags'][0])) $page['tags'] = (new WikiMetadataService($this->app->database()))->tagsForNames($page['tags']);
        $canWork = (new PageAclService($this->app))->canReadWorkingFor($page, $this->spaceFromPage($page), (int) $context['user']['id'], $this->isSuperAdmin($context), $context['permissions']);
        return $this->pageListResource($page, $context) + [
            'workflow_state' => $canWork ? ($page['workflow_state'] ?? $page['status']) : $page['status'],
            'working_version' => $canWork ? (int) $page['version'] : null,
            'content_format' => $page['content_format'],
            'content_html' => $page['content_html'],
            'document' => ['version' => \OpenWiki\Wiki\ContentService::SCHEMA_VERSION, 'format' => $page['content_format'],
                'source' => $page['content_format'] === 'visual' ? $page['content_html'] : $page['content_markdown']],
            'content_markdown' => $page['content_markdown'],
            'tags' => $page['tags'] ?? (new WikiMetadataService($this->app->database()))->tagsForPage((int) $page['id']),
        ];
    }

    public function reviewPage(Request $request, string $id): Response
    {
        [$context, $failure] = $this->apiAuth($request, 'pages:write');
        if ($failure !== null) return $failure;
        try {
            $row = (new \OpenWiki\Wiki\PageReviewService($this->app->database()))->transition($this->positiveId($id) ?? 0, (array) $request->input(), (int) $context['user']['id'], $request, $context['permissions']);
            return Response::json(['data' => $this->pageResource($row, $context)]);
        } catch (\Throwable $error) { return $this->reviewFailure($error); }
    }

    public function pageReview(Request $request, string $id): Response
    {
        [$context, $failure] = $this->apiAuth($request, 'pages:read', 'page.view');
        if ($failure !== null) return $failure;
        $page = $this->pageRow($this->positiveId($id) ?? 0);
        if ($page === null || !(new PageAclService($this->app))->canReadWorkingFor($page, $this->spaceFromPage($page), (int) $context['user']['id'], $this->isSuperAdmin($context), $context['permissions'])) return $this->error('not_found', 'Page not found.', 404);
        [$number, $perPage, $offset] = $this->pagination($request);
        $service = new \OpenWiki\Wiki\PageReviewService($this->app->database());
        return Response::json(['data' => $service->decisions((int) $page['id'], $perPage, $offset), 'meta' => $this->paginationMeta($number, $perPage, $service->decisionCount((int) $page['id']))]);
    }

    public function pageRevisions(Request $request, string $id): Response { return $this->revisionRead($request, $id, null, false); }
    public function pageRevision(Request $request, string $id, string $number): Response { return $this->revisionRead($request, $id, $number, false); }
    public function pageDiff(Request $request, string $id): Response { return $this->revisionRead($request, $id, null, true); }

    private function revisionRead(Request $request, string $id, ?string $number, bool $diff): Response
    {
        [$context, $failure] = $this->apiAuth($request, 'pages:read', 'page.view');
        if ($failure !== null) return $failure;
        $row = $this->pageRow($this->positiveId($id) ?? 0);
        if ($row === null || !$this->canViewPage($context, $row)) return $this->error('not_found', 'Page not found.', 404);
        $space = $this->spaceFromPage($row); $user = (int) $context['user']['id']; $super = $this->isSuperAdmin($context);
        $service = new \OpenWiki\Wiki\RevisionAccessService($this->app->database());
        $get = fn (int $n): array => $service->resource($service->visible($row, $space, $n, $user, $super, $context['permissions']), $space, $user, $super, $context['permissions']);
        try {
            if ($diff) return Response::json(['data' => \OpenWiki\Wiki\RevisionDiff::compare($get((int) $request->query('from')), $get((int) $request->query('to')))]);
            if ($number !== null) return Response::json(['data' => $get($this->positiveId($number) ?? 0)]);
            $working = (new PageAclService($this->app))->canReadWorkingFor($row, $space, $user, $super, $context['permissions']);
            $revisions = (new PageRepository($this->app->database()))->revisionSummaries((int) $row['id']);
            $data = array_values(array_filter($revisions, static fn (array $revision): bool => $working || (bool) $revision['was_published']));
            return Response::json(['data' => array_map(fn (array $revision): array => $service->metadata($revision, $space, $user, $super), $data)]);
        } catch (\Throwable $error) { return $this->reviewFailure($error); }
    }

    public function restorePage(Request $request, string $id): Response
    {
        [$context, $failure] = $this->apiAuth($request, 'pages:write', 'page.edit');
        if ($failure !== null) return $failure;
        $row = $this->pageRow($this->positiveId($id) ?? 0);
        if ($row === null || !$this->canEditPageForContext($context, $row)) return $this->error('not_found', 'Page not found.', 404);
        try {
            $space = $this->spaceFromPage($row); $service = new \OpenWiki\Wiki\RevisionAccessService($this->app->database());
            $revision = $service->visible($row, $space, (int) $request->input('revision'), (int) $context['user']['id'], $this->isSuperAdmin($context), $context['permissions']);
            $input = $service->restoreInput($row, $revision, $request->input('base_version'), (string) $request->input('restore_conversion', '') === '1');
            if ($request->input('operation_id') !== null) $input['operation_id'] = $request->input('operation_id');
            $tokens = new ApiTokenService($this->app->database());
            $saved = (new \OpenWiki\Wiki\PageWriteService($this->app->database(), $this->app->basePath()))->save($space, $row, $input, (int) $context['user']['id'], $request, $this->pageWritePolicy($space, $context), $tokens->hasScope($context, 'attachments:write') && $tokens->hasPermission($context, 'attachment.upload'), (int) $revision['revision_number']);
            return Response::json(['data' => $this->pageResource($saved, $context)]);
        } catch (\Throwable $error) { return $this->reviewFailure($error); }
    }

    private function reviewFailure(\Throwable $error): Response
    {
        return match (true) {
            $error instanceof \DomainException => $this->error('forbidden', $error->getMessage(), 403),
            $error instanceof \InvalidArgumentException => $this->error('validation_error', $error->getMessage(), 422),
            $error instanceof \OutOfBoundsException => $this->error('not_found', $error->getMessage(), 404),
            $error->getMessage() === 'EDIT_CONFLICT' => $this->error('edit_conflict', 'Rewizja zmieniła się. Decyzja nie została zastosowana.', 409),
            default => $this->error('server_error', 'Nie wykonano operacji dokumentu.', 500),
        };
    }

    private function error(string $code, string $message, int $status, array $headers = []): Response
    {
        return Response::json(['error' => ['code' => $code, 'message' => $message]], $status, $headers);
    }
}
