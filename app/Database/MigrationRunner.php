<?php

declare(strict_types=1);

namespace OpenWiki\Database;

use OpenWiki\Core\Database;

final class MigrationRunner
{
    public function __construct(
        private readonly Database $database,
        private readonly string $migrationPath
    ) {
    }

    public function migrate(): array
    {
        $this->ensureMigrationTable();
        $applied = [];
        $known = $this->appliedVersions();

        foreach ($this->migrationFiles() as $file) {
            $migration = require $file;
            if (!is_array($migration) || !isset($migration['version'], $migration['up']) || !is_array($migration['up'])) {
                throw new \RuntimeException('Invalid migration file: ' . basename($file));
            }

            $version = (string) $migration['version'];
            if (isset($known[$version])) {
                continue;
            }

            foreach ($migration['up'] as $sql) {
                if (!is_string($sql) || trim($sql) === '') {
                    throw new \RuntimeException('Migration contains an invalid SQL statement: ' . $version);
                }
                $this->database->pdo()->exec($sql);
            }

            $this->database->execute(
                'INSERT INTO schema_migrations (version, applied_at) VALUES (:version, UTC_TIMESTAMP())',
                ['version' => $version]
            );
            $applied[] = $version;
        }

        return $applied;
    }

    public function status(): array
    {
        $this->ensureMigrationTable();
        $known = $this->appliedVersions();
        $status = [];

        foreach ($this->migrationFiles() as $file) {
            $migration = require $file;
            $version = (string) ($migration['version'] ?? basename($file));
            $status[] = [
                'version' => $version,
                'applied' => isset($known[$version]),
            ];
        }

        return $status;
    }

    private function ensureMigrationTable(): void
    {
        $this->database->pdo()->exec(
            'CREATE TABLE IF NOT EXISTS schema_migrations (
                version VARCHAR(191) NOT NULL PRIMARY KEY,
                applied_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci'
        );
    }

    private function appliedVersions(): array
    {
        $rows = $this->database->fetchAll('SELECT version FROM schema_migrations');
        $result = [];
        foreach ($rows as $row) {
            $result[(string) $row['version']] = true;
        }
        return $result;
    }

    private function migrationFiles(): array
    {
        $files = glob(rtrim($this->migrationPath, '/') . '/*.php');
        if ($files === false) {
            return [];
        }

        sort($files, SORT_STRING);
        return $files;
    }
}
