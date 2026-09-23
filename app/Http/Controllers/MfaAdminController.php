<?php

declare(strict_types=1);

namespace OpenWiki\Http\Controllers;

use OpenWiki\Audit\AuditLogger;
use OpenWiki\Auth\MfaService;
use OpenWiki\Core\Request;
use OpenWiki\Core\Response;
use OpenWiki\Core\Session;
use OpenWiki\Http\Controller;

final class MfaAdminController extends Controller
{
    public function index(Request $request): Response
    {
        if (($failure = $this->authorize($request, 'settings.manage')) !== null) {
            return $failure;
        }

        $service = new MfaService($this->app->database());

        return $this->render('admin/mfa/index', [
            'title' => 'MFA policy',
            'policy' => $service->policy(),
            'roles' => $this->app->database()->fetchAll(
                'SELECT id, name, slug FROM roles ORDER BY is_system DESC, name ASC'
            ),
        ]);
    }

    public function update(Request $request): Response
    {
        if (($failure = $this->authorize($request, 'settings.manage')) !== null) {
            return $failure;
        }
        if (($failure = $this->verifyCsrf($request)) !== null) {
            return $failure;
        }

        $actor = $this->app->auth()->user();

        try {
            $global = filter_var($request->input('enforce_global', false), FILTER_VALIDATE_BOOL);
            $roles = $request->input('role_ids', []);
            if (!is_array($roles)) {
                $roles = [$roles];
            }

            $service = new MfaService($this->app->database());
            $before = $service->policy();
            $service->updatePolicy($global, $roles, (int) $actor['id']);
            $after = $service->policy();

            (new AuditLogger($this->app->database()))->log(
                'MFA_POLICY_CHANGED',
                'settings',
                null,
                (int) $actor['id'],
                $request,
                $before,
                $after
            );

            Session::flash('success', 'MFA policy updated.');
        } catch (\InvalidArgumentException $exception) {
            Session::flash('error', $exception->getMessage());
        } catch (\Throwable $exception) {
            error_log('[OpenWiki MFA policy] ' . $exception->getMessage());
            Session::flash('error', 'Unable to update MFA policy.');
        }

        return Response::redirect('/admin/mfa');
    }
}
