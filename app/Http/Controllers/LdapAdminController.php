<?php

declare(strict_types=1);

namespace OpenWiki\Http\Controllers;

use OpenWiki\Audit\AuditLogger;
use OpenWiki\Auth\LdapService;
use OpenWiki\Core\Request;
use OpenWiki\Core\Response;
use OpenWiki\Core\Session;
use OpenWiki\Http\Controller;

final class LdapAdminController extends Controller
{
    public function index(Request $request): Response
    {
        if (($failure = $this->authorize($request, 'settings.manage')) !== null) {
            return $failure;
        }

        return $this->renderForm();
    }

    public function save(Request $request): Response
    {
        if (($failure = $this->authorize($request, 'settings.manage')) !== null) {
            return $failure;
        }
        if (($failure = $this->verifyCsrf($request)) !== null) {
            return $failure;
        }

        $service = new LdapService($this->app->database());
        $before = $service->displayConfig();
        $actor = $this->app->auth()->user();

        try {
            $service->saveConfig((array) $request->input(), (int) $actor['id']);
            $after = $service->displayConfig();

            (new AuditLogger($this->app->database()))->log(
                'LDAP_SETTINGS_CHANGED',
                'settings',
                null,
                (int) $actor['id'],
                $request,
                $before,
                $after
            );

            Session::flash('success', 'LDAP / Active Directory settings saved.');
            return Response::redirect('/admin/ldap');
        } catch (\InvalidArgumentException $exception) {
            return $this->renderForm((array) $request->input(), $exception->getMessage(), null, 422);
        } catch (\Throwable $exception) {
            error_log('[OpenWiki LDAP settings] ' . $exception->getMessage());
            return $this->renderForm(
                (array) $request->input(),
                'Unable to save LDAP settings.',
                null,
                500
            );
        }
    }

    public function test(Request $request): Response
    {
        if (($failure = $this->authorize($request, 'settings.manage')) !== null) {
            return $failure;
        }
        if (($failure = $this->verifyCsrf($request)) !== null) {
            return $failure;
        }

        try {
            $result = (new LdapService($this->app->database()))->testConfig((array) $request->input());
            return $this->renderForm(
                (array) $request->input(),
                null,
                (string) $result['message']
            );
        } catch (\InvalidArgumentException $exception) {
            return $this->renderForm((array) $request->input(), $exception->getMessage(), null, 422);
        } catch (\Throwable $exception) {
            error_log('[OpenWiki LDAP test] ' . $exception->getMessage());
            return $this->renderForm(
                (array) $request->input(),
                'LDAP connection test failed. Verify host, port, TLS mode, credentials and Base DN.',
                null,
                422
            );
        }
    }

    private function renderForm(
        array $old = [],
        ?string $formError = null,
        ?string $testSuccess = null,
        int $status = 200
    ): Response {
        unset($old['bind_password'], $old['_token']);

        return $this->render('admin/ldap/index', [
            'title' => 'LDAP / Active Directory',
            'config' => (new LdapService($this->app->database()))->displayConfig(),
            'roles' => $this->app->database()->fetchAll(
                'SELECT id, name, slug FROM roles ORDER BY is_system DESC, name ASC'
            ),
            'old' => $old,
            'formError' => $formError,
            'testSuccess' => $testSuccess,
        ], $status);
    }
}
