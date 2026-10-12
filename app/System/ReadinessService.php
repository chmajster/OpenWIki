<?php

declare(strict_types=1);

namespace OpenWiki\System;

use OpenWiki\Core\Application;
use OpenWiki\Database\MigrationRunner;
use OpenWiki\Upgrade\WriteGate;

final class ReadinessService
{
    public function __construct(private readonly Application $app) {}

    public function check(bool $duringUpgrade = false): array
    {
        $checks = ['application' => $this->app->installed(), 'database' => false, 'storage' => true, 'schema' => false, 'maintenance' => $duringUpgrade || !WriteGate::maintenance($this->app->basePath())];
        $schema = ['pending' => [], 'unknown' => [], 'unfinished' => []];
        if ($checks['application']) {
            try {
                $checks['database'] = (int) ($this->app->database()->fetchOne('SELECT 1 AS ok')['ok'] ?? 0) === 1;
                $manifest = self::manifest($this->app->basePath());
                $schema = (new MigrationRunner($this->app->database(), $this->app->basePath('database/migrations')))->schemaState($manifest['compatible_extra_migrations'] ?? []);
                $checks['schema'] = $schema['ready'];
            } catch (\Throwable) { /* Public health must not disclose connection strings or SQL. */ }
        }
        foreach (['storage', 'storage/attachments', 'storage/cache', 'storage/logs', 'storage/temp'] as $path) {
            $checks['storage'] = $checks['storage'] && is_dir($this->app->basePath($path)) && is_writable($this->app->basePath($path));
        }
        $ready = !in_array(false, $checks, true);
        $status = !$checks['application'] ? 'setup_required' : (!$checks['maintenance'] ? 'maintenance' : ($ready ? 'ok' : 'degraded'));
        return ['status' => $status, 'checks' => $checks, 'schema' => $schema, 'ready' => $ready];
    }

    public static function manifest(string $codePath): array
    {
        $path = $codePath . '/release.json';
        $value = is_file($path) ? json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR) : [];
        if (!is_array($value) || !is_array($value['compatible_extra_migrations'] ?? [])) throw new \RuntimeException('Invalid release compatibility manifest.');
        if (isset($value['version']) && (!is_string($value['version']) || !preg_match('/^[a-zA-Z0-9.+_-]{1,128}$/D', $value['version']))) throw new \RuntimeException('Invalid release version.');
        foreach ($value['compatible_extra_migrations'] ?? [] as $version) {
            if (!is_string($version) || !preg_match('/^[a-zA-Z0-9_]{1,191}$/D', $version)) throw new \RuntimeException('Invalid compatible migration version.');
        }
        return $value;
    }
}
