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

        $service = new InstallService($this->app->basePath());

        return $this->render('install/index', [
            'title' => 'Install OpenWiki',
            'requirements' => $service->requirements(),
            'defaults' => [
                'app_url' => $this->suggestedUrl($request),
                'timezone' => 'UTC',
                'db_host' => '127.0.0.1',
                'db_port' => 3306,
                'db_database' => 'openwiki',
            ],
        ]);
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
            (new InstallService($this->app->basePath()))->install((array) $request->input());
            Env::load($this->app->basePath('.env'));
            Session::flash('success', 'Installation completed. Sign in with the administrator account.');
            return Response::redirect('/login');
        } catch (\Throwable $exception) {
            error_log('[OpenWiki installer] ' . $exception->getMessage());
            return $this->render('install/index', [
                'title' => 'Install OpenWiki',
                'requirements' => (new InstallService($this->app->basePath()))->requirements(),
                'defaults' => (array) $request->input(),
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
}
