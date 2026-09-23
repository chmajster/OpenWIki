<?php

declare(strict_types=1);

namespace OpenWiki\Http\Controllers;

use OpenWiki\Audit\AuditLogger;
use OpenWiki\Backup\BackupService;
use OpenWiki\Core\Request;
use OpenWiki\Core\Response;
use OpenWiki\Core\Session;
use OpenWiki\Http\Controller;

final class BackupController extends Controller
{
    public function index(Request $request): Response
    {
        if (($failure = $this->authorize($request, 'settings.manage')) !== null) {
            return $failure;
        }

        return $this->render('admin/backups/index', [
            'title' => 'Backups',
            'backups' => $this->service()->list(),
        ]);
    }

    public function create(Request $request): Response
    {
        if (($failure = $this->authorize($request, 'settings.manage')) !== null) {
            return $failure;
        }
        if (($failure = $this->verifyCsrf($request)) !== null) {
            return $failure;
        }

        try {
            $backup = $this->service()->create();
            $actor = $this->app->auth()->user();

            (new AuditLogger($this->app->database()))->log(
                'BACKUP_CREATED',
                'backup',
                null,
                $actor === null ? null : (int) $actor['id'],
                $request,
                null,
                [
                    'name' => $backup['name'],
                    'size_bytes' => $backup['size_bytes'],
                ]
            );

            Session::flash('success', 'Backup created.');
        } catch (\Throwable $exception) {
            error_log('[OpenWiki backup] ' . $exception->getMessage());
            Session::flash('error', 'Unable to create backup.');
        }

        return Response::redirect('/admin/backups');
    }

    public function download(Request $request, string $name): Response
    {
        if (($failure = $this->authorize($request, 'settings.manage')) !== null) {
            return $failure;
        }

        $path = $this->service()->find($name);
        if ($path === null) {
            return $this->render('errors/404', ['title' => 'Backup not found'], 404);
        }

        $actor = $this->app->auth()->user();
        (new AuditLogger($this->app->database()))->log(
            'BACKUP_DOWNLOADED',
            'backup',
            null,
            $actor === null ? null : (int) $actor['id'],
            $request,
            null,
            ['name' => $name]
        );

        return Response::file($path, 'application/x-tar', $name);
    }

    private function service(): BackupService
    {
        return new BackupService($this->app->database(), $this->app->basePath());
    }
}
