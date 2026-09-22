<?php

declare(strict_types=1);

namespace OpenWiki\Http\Controllers;

use OpenWiki\Audit\AuditLogger;
use OpenWiki\Core\Request;
use OpenWiki\Core\Response;
use OpenWiki\Core\Session;
use OpenWiki\Http\Controller;
use OpenWiki\Permissions\PageAclService;
use OpenWiki\Repositories\PageRepository;
use OpenWiki\Repositories\SpaceRepository;

final class PageAclController extends Controller
{
    public function index(Request $request, string $spaceKey, string $slug): Response
    {
        [$space, $page, $failure] = $this->editablePage($request, $spaceKey, $slug);
        if ($failure !== null) {
            return $failure;
        }

        $service = new PageAclService($this->app);

        return $this->render('pages/permissions', [
            'title' => 'Permissions: ' . $page['title'],
            'space' => $space,
            'page' => $page,
            'rules' => $service->entries((int) $page['id']),
            'permissions' => $service->pagePermissions(),
            'users' => $this->app->database()->fetchAll(
                'SELECT id, username, email FROM users
                 WHERE deleted_at IS NULL AND status = "active"
                 ORDER BY username ASC'
            ),
            'groups' => $this->app->database()->fetchAll(
                'SELECT id, name, source FROM user_groups ORDER BY name ASC'
            ),
            'roles' => $this->app->database()->fetchAll(
                'SELECT id, name, slug FROM roles ORDER BY is_system DESC, name ASC'
            ),
        ]);
    }

    public function inheritance(Request $request, string $spaceKey, string $slug): Response
    {
        [$space, $page, $failure] = $this->editablePage($request, $spaceKey, $slug, true);
        if ($failure !== null) {
            return $failure;
        }

        $inherit = filter_var($request->input('inherit_acl', false), FILTER_VALIDATE_BOOL);
        $service = new PageAclService($this->app);
        $service->setInheritance((int) $page['id'], $inherit);

        $this->audit(
            'PAGE_ACL_INHERITANCE_CHANGED',
            (int) $page['id'],
            $request,
            ['inherit_acl' => (bool) ($page['inherit_acl'] ?? true)],
            ['inherit_acl' => $inherit]
        );

        Session::flash('success', $inherit
            ? 'Parent page ACL inheritance enabled.'
            : 'Parent page ACL inheritance disabled.');

        return Response::redirect($this->permissionsUrl($space, $page));
    }

    public function addRule(Request $request, string $spaceKey, string $slug): Response
    {
        [$space, $page, $failure] = $this->editablePage($request, $spaceKey, $slug, true);
        if ($failure !== null) {
            return $failure;
        }

        $principal = explode(':', (string) $request->input('principal', ''), 2);
        $principalType = $principal[0] ?? '';
        $principalId = isset($principal[1]) ? filter_var($principal[1], FILTER_VALIDATE_INT) : false;

        if ($principalId === false || $principalId < 1) {
            Session::flash('error', 'Select a valid user, group or role.');
            return Response::redirect($this->permissionsUrl($space, $page));
        }

        try {
            $service = new PageAclService($this->app);
            $ruleId = $service->addRule(
                (int) $page['id'],
                $principalType,
                (int) $principalId,
                (string) $request->input('permission', ''),
                (string) $request->input('effect', 'allow'),
                filter_var($request->input('inherit_to_children', false), FILTER_VALIDATE_BOOL)
            );

            $this->audit(
                'PAGE_ACL_RULE_SAVED',
                (int) $page['id'],
                $request,
                null,
                ['rule_id' => $ruleId]
            );
            Session::flash('success', 'Page ACL rule saved.');
        } catch (\InvalidArgumentException $exception) {
            Session::flash('error', $exception->getMessage());
        } catch (\Throwable $exception) {
            error_log('[OpenWiki page ACL] ' . $exception->getMessage());
            Session::flash('error', 'Unable to save page ACL rule.');
        }

        return Response::redirect($this->permissionsUrl($space, $page));
    }

    public function deleteRule(Request $request, string $spaceKey, string $slug, string $id): Response
    {
        [$space, $page, $failure] = $this->editablePage($request, $spaceKey, $slug, true);
        if ($failure !== null) {
            return $failure;
        }

        $ruleId = filter_var($id, FILTER_VALIDATE_INT);
        if ($ruleId === false || $ruleId < 1) {
            return $this->render('errors/404', ['title' => 'ACL rule not found'], 404);
        }

        $service = new PageAclService($this->app);
        if (!$service->deleteRule((int) $page['id'], (int) $ruleId)) {
            return $this->render('errors/404', ['title' => 'ACL rule not found'], 404);
        }

        $this->audit(
            'PAGE_ACL_RULE_DELETED',
            (int) $page['id'],
            $request,
            ['rule_id' => (int) $ruleId],
            ['deleted' => true]
        );
        Session::flash('success', 'Page ACL rule deleted.');

        return Response::redirect($this->permissionsUrl($space, $page));
    }

    private function editablePage(
        Request $request,
        string $spaceKey,
        string $slug,
        bool $mutation = false
    ): array {
        if (($failure = $this->authorize($request, 'page.edit')) !== null) {
            return [null, null, $failure];
        }
        if ($mutation && ($failure = $this->verifyCsrf($request)) !== null) {
            return [null, null, $failure];
        }

        $space = (new SpaceRepository($this->app->database()))->findByKey($spaceKey);
        if ($space === null) {
            return [null, null, $this->render('errors/404', ['title' => 'Space not found'], 404)];
        }

        $page = (new PageRepository($this->app->database()))->findBySlug((int) $space['id'], $slug);
        if ($page === null) {
            return [null, null, $this->render('errors/404', ['title' => 'Page not found'], 404)];
        }

        if (!(new PageAclService($this->app))->canEdit($page, $space)) {
            return [null, null, $this->render('errors/403', ['title' => 'Permission denied'], 403)];
        }

        return [$space, $page, null];
    }

    private function permissionsUrl(array $space, array $page): string
    {
        return '/spaces/' . rawurlencode((string) $space['space_key'])
            . '/pages/' . rawurlencode((string) $page['slug'])
            . '/permissions';
    }

    private function audit(
        string $action,
        int $pageId,
        Request $request,
        ?array $before,
        ?array $after
    ): void {
        $actor = $this->app->auth()->user();
        (new AuditLogger($this->app->database()))->log(
            $action,
            'page',
            $pageId,
            $actor === null ? null : (int) $actor['id'],
            $request,
            $before,
            $after
        );
    }
}
