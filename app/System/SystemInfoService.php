<?php

declare(strict_types=1);

namespace OpenWiki\System;

use FilesystemIterator;
use OpenWiki\Core\Application;
use OpenWiki\Database\MigrationRunner;
use OpenWiki\Install\InstallService;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class SystemInfoService
{
    public function __construct(private readonly Application $app)
    {
    }

    public function snapshot(): array
    {
        $database = $this->app->database();
        $basePath = $this->app->basePath();

        $db = $database->fetchOne(
            'SELECT VERSION() AS version, DATABASE() AS database_name'
        ) ?? [];
        $dbSize = $database->fetchOne(
            'SELECT COALESCE(SUM(data_length + index_length), 0) AS size_bytes
             FROM information_schema.tables
             WHERE table_schema = DATABASE()'
        );

        $requirements = (new InstallService($basePath))->requirements();
        $migrations = (new MigrationRunner(
            $database,
            $basePath . '/database/migrations'
        ))->status();

        $cron = $database->fetchOne(
            'SELECT setting_value
             FROM settings
             WHERE setting_key = "system.cron_last_run"
             LIMIT 1'
        );
        $cronLastRun = $cron === null ? null : (string) $cron['setting_value'];
        $cronTimestamp = $cronLastRun === null ? false : strtotime($cronLastRun);
        $cronStale = $cronTimestamp === false || (time() - $cronTimestamp) > 600;

        $diskTotal = @disk_total_space($basePath);
        $diskFree = @disk_free_space($basePath);

        return [
            'application' => [
                'name' => $this->app->name(),
                'version' => $this->app->version(),
                'installed' => $this->app->installed(),
            ],
            'runtime' => [
                'php_version' => PHP_VERSION,
                'sapi' => PHP_SAPI,
                'memory_limit' => (string) ini_get('memory_limit'),
                'upload_max_filesize' => (string) ini_get('upload_max_filesize'),
                'post_max_size' => (string) ini_get('post_max_size'),
                'extensions' => $requirements['extensions'],
            ],
            'database' => [
                'name' => $db['database_name'] ?? null,
                'version' => $db['version'] ?? null,
                'size_bytes' => (int) ($dbSize['size_bytes'] ?? 0),
            ],
            'storage' => [
                'attachments_bytes' => $this->directorySize($basePath . '/storage/attachments'),
                'backups_bytes' => $this->directorySize($basePath . '/storage/backups'),
                'disk_total_bytes' => $diskTotal === false ? null : (int) $diskTotal,
                'disk_free_bytes' => $diskFree === false ? null : (int) $diskFree,
                'writable' => $requirements['writable'],
            ],
            'scheduler' => [
                'last_run' => $cronLastRun,
                'stale' => $cronStale,
            ],
            'migrations' => [
                'total' => count($migrations),
                'pending' => count(array_filter(
                    $migrations,
                    static fn (array $migration): bool => !$migration['applied']
                )),
                'items' => $migrations,
            ],
        ];
    }

    private function directorySize(string $path): int
    {
        if (!is_dir($path)) {
            return 0;
        }

        $realRoot = realpath($path);
        if ($realRoot === false) {
            return 0;
        }

        $size = 0;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator(
                $realRoot,
                FilesystemIterator::SKIP_DOTS
            )
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->isLink()) {
                continue;
            }

            $realPath = $file->getRealPath();
            if (
                $realPath === false
                || !str_starts_with($realPath, $realRoot . DIRECTORY_SEPARATOR)
            ) {
                continue;
            }

            $fileSize = $file->getSize();
            if ($fileSize > 0) {
                $size += $fileSize;
            }
        }

        return $size;
    }
}
