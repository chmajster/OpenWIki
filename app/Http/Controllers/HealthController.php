<?php

declare(strict_types=1);

namespace OpenWiki\Http\Controllers;

use OpenWiki\Core\Request;
use OpenWiki\Core\Response;
use OpenWiki\Http\Controller;

final class HealthController extends Controller
{
    public function show(Request $request): Response
    {
        if (!$this->app->installed()) {
            return Response::json([
                'status' => 'setup_required',
                'checks' => ['application' => false],
            ], 503);
        }

        $checks = [
            'application' => true,
            'database' => false,
            'storage' => false,
        ];

        try {
            $row = $this->app->database()->fetchOne('SELECT 1 AS ok');
            $checks['database'] = (int) ($row['ok'] ?? 0) === 1;
        } catch (\Throwable) {
            $checks['database'] = false;
        }

        $storagePaths = ['storage', 'storage/attachments', 'storage/cache', 'storage/logs', 'storage/temp'];
        $checks['storage'] = true;
        foreach ($storagePaths as $path) {
            if (!is_dir($this->app->basePath($path)) || !is_writable($this->app->basePath($path))) {
                $checks['storage'] = false;
                break;
            }
        }

        $healthy = !in_array(false, $checks, true);

        return Response::json([
            'status' => $healthy ? 'ok' : 'degraded',
            'checks' => $checks,
        ], $healthy ? 200 : 503);
    }
}
