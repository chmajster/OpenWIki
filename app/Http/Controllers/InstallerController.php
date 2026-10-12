<?php

declare(strict_types=1);

namespace OpenWiki\Http\Controllers;

use OpenWiki\Core\Env;
use OpenWiki\Core\Request;
use OpenWiki\Core\Response;
use OpenWiki\Core\Session;
use OpenWiki\Http\Controller;
use OpenWiki\Install\InstallService;

final class InstallerController extends Controller
{
    public function index(Request $request): Response
    {
        if ($this->app->installed()) {
            return Response::redirect('/');
        }
        if (is_file($this->app->basePath('storage/installed.lock'))) {
            return Response::json(['error' => ['code' => 'configuration_error', 'message' => 'Instancja jest już zainstalowana. Przywróć jej konfigurację i APP_KEY; nie uruchamiaj kreatora ponownie.']], 503);
        }

        $service = new InstallService($this->app->basePath());

        return $this->render('install/index', [
            'title' => 'Install OpenWiki',
            'requirements' => $service->requirements(),
            'defaults' => [
                'app_name' => 'OpenWiki',
                'app_url' => $this->suggestedUrl($request),
                'timezone' => 'UTC',
                'db_host' => Env::get('DB_HOST', '127.0.0.1'),
                'db_port' => Env::get('DB_PORT', '3306'),
                'db_database' => Env::get('DB_DATABASE', 'openwiki'),
                'db_username' => Env::bool('OPENWIKI_MANAGED_DATABASE') ? Env::get('DB_USERNAME') : '',
                'create_database' => !Env::bool('OPENWIKI_MANAGED_DATABASE'),
            ],
            'managedDatabase' => Env::bool('OPENWIKI_MANAGED_DATABASE'),
        ]);
    }

    public function testDatabase(Request $request): Response
    {
        if ($this->app->installed()) {
            return Response::json([
                'error' => ['code' => 'already_installed', 'message' => 'OpenWiki is already installed.'],
            ], 409);
        }

        if (($failure = $this->verifyCsrf($request)) !== null) {
            return $failure;
        }

        try {
            $result = (new InstallService($this->app->basePath()))
                ->testDatabaseConnection($this->installationInput($request));

            return Response::json(['data' => $result]);
        } catch (\Throwable $exception) {
            error_log('[OpenWiki installer database test] ' . $exception->getMessage());

            return Response::json([
                'error' => [
                    'code' => 'database_connection_failed',
                    'message' => $exception->getMessage(),
                ],
            ], 422);
        }
    }

    public function store(Request $request): Response
    {
        if ($this->app->installed()) {
            return Response::redirect('/');
        }

        if (($failure = $this->verifyCsrf($request)) !== null) {
            return $failure;
        }

        try {
            (new InstallService($this->app->basePath()))->install($this->installationInput($request));
            Env::load($this->app->basePath('.env'));
            Session::flash(
                'success',
                'Installation completed. Sign in with admin/admin. You will be required to change the password.'
            );
            return Response::redirect('/login');
        } catch (\Throwable $exception) {
            error_log('[OpenWiki installer] ' . $exception->getMessage());
            return $this->render('install/index', [
                'title' => 'Install OpenWiki',
                'requirements' => (new InstallService($this->app->basePath()))->requirements(),
                'defaults' => array_diff_key($this->installationInput($request), ['db_password' => true, 'admin_password' => true]),
                'managedDatabase' => Env::bool('OPENWIKI_MANAGED_DATABASE'),
                'installError' => $exception->getMessage(),
            ], 422);
        }
    }

    private function suggestedUrl(Request $request): string
    {
        $host = $request->header('Host', 'localhost') ?? 'localhost';
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        return $scheme . '://' . $host;
    }

    private function installationInput(Request $request): array
    {
        $input = (array) $request->input();
        if (Env::bool('OPENWIKI_MANAGED_DATABASE')) {
            foreach (['host', 'port', 'database', 'username', 'password'] as $key) {
                $input['db_' . $key] = Env::get('DB_' . strtoupper($key), '') ?? '';
            }
            $input['create_database'] = false;
        }
        return $input;
    }
}
