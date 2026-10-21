<?php

declare(strict_types=1);

namespace OpenWiki\Http\Controllers;

use OpenWiki\Core\Env;
use OpenWiki\Core\Request;
use OpenWiki\Core\Response;
use OpenWiki\Core\Session;
use OpenWiki\Core\RuntimePaths;
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
                'create_database' => false,
                'admin_username' => 'admin',
            ],
            'managedDatabase' => Env::bool('OPENWIKI_MANAGED_DATABASE'),
        ]);
    }

    public function testDatabase(Request $request): Response
    {
        if (is_file($this->app->basePath('storage/installed.lock'))) {
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
            error_log('[OpenWiki installer database test] ' . $exception::class);

            return Response::json([
                'error' => [
                    'code' => 'database_connection_failed',
                    'message' => $this->failureMessage($exception),
                ],
            ], 422);
        }
    }

    public function store(Request $request): Response
    {
        if (is_file($this->app->basePath('storage/installed.lock'))) {
            return Response::redirect('/');
        }

        if (($failure = $this->verifyCsrf($request)) !== null) {
            return $failure;
        }

        try {
            if (!is_string($request->input('admin_password')) || $request->input('admin_password') !== $request->input('admin_password_confirmation')) {
                throw new \InvalidArgumentException('Administrator passwords do not match.');
            }
            (new InstallService($this->app->basePath()))->install($this->installationInput($request));
            Env::load(RuntimePaths::configuration($this->app->basePath()));
            Session::flash(
                'success',
                'Installation completed. Sign in with the administrator account you created.'
            );
            return Response::redirect('/login');
        } catch (\Throwable $exception) {
            error_log('[OpenWiki installer] ' . $exception::class);
            return $this->render('install/index', [
                'title' => 'Install OpenWiki',
                'requirements' => (new InstallService($this->app->basePath()))->requirements(),
                'defaults' => array_filter(array_intersect_key($this->installationInput($request), array_flip(['app_name', 'app_url', 'timezone', 'db_host', 'db_port', 'db_database', 'db_username', 'create_database', 'admin_username', 'admin_email'])), 'is_scalar'),
                'managedDatabase' => Env::bool('OPENWIKI_MANAGED_DATABASE'),
                'installError' => $this->failureMessage($exception),
            ], 422);
        }
    }

    private function suggestedUrl(Request $request): string
    {
        $host = $request->header('Host', 'localhost') ?? 'localhost';
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $url = $scheme . '://' . $host;
        return filter_var($url, FILTER_VALIDATE_URL) && parse_url($url, PHP_URL_PATH) === null ? $url : $scheme . '://localhost';
    }

    private function failureMessage(\Throwable $exception): string
    {
        for ($cause = $exception; $cause !== null; $cause = $cause->getPrevious()) {
            if ($cause instanceof \PDOException) {
                return match ((int) ($cause->errorInfo[1] ?? 0)) {
                    1045 => 'Database authentication failed. Check the username and password.',
                    1044, 1142, 1227 => 'The database account lacks the required permissions. Grant access to this database in your hosting panel.',
                    1049 => 'Database not found. Create it in your hosting panel or enable automatic creation.',
                    2002, 2003, 2005 => 'Cannot reach the database server. Check the host, port and server availability.',
                    default => 'The database operation failed. Check database permissions and availability, then retry with the same settings.',
                };
            }
        }
        return $exception instanceof \InvalidArgumentException || $exception instanceof \RuntimeException
            ? $exception->getMessage() : 'Installation could not finish. Check server permissions and available disk space, then retry.';
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
