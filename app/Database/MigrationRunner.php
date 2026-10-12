<?php

declare(strict_types=1);

namespace OpenWiki\Database;

use OpenWiki\Core\Database;

final class MigrationRunner
{
    public function __construct(private readonly Database $database, private readonly string $migrationPath) {}

    public function migrate(): array
    {
        if ($this->database->pdo()->inTransaction()) {
            throw new \LogicException('Migrations cannot run inside a transaction: MySQL/MariaDB DDL implicitly commits it.');
        }
        $plans = $this->plans();
        $name = $this->lockName();
        if ((int) ($this->database->fetchOne('SELECT GET_LOCK(?, 0) AS acquired', [$name])['acquired'] ?? 0) !== 1) {
            throw new \RuntimeException('Another migration process holds the database migration lock.');
        }
        try {
            $this->ensureTables();
            $known = $this->appliedVersions();
            $applied = [];
            foreach ($plans as $version => $steps) {
                if (isset($known[$version])) continue;
                foreach ($steps as $index => $step) $this->executeStep($version, $index, $step);
                // A crash here only leaves the version receipt to insert on resume.
                $this->database->execute('INSERT INTO schema_migrations (version, applied_at) VALUES (?, UTC_TIMESTAMP())', [$version]);
                $applied[] = $version;
            }
            return $applied;
        } finally {
            try { $this->database->fetchOne('SELECT RELEASE_LOCK(?)', [$name]); }
            catch (\Throwable) { /* A lost connection releases its MySQL named locks. */ }
        }
    }

    /** Diagnostics never create or alter database tables. */
    public function status(): array
    {
        $known = $this->appliedVersions();
        $unfinished = array_fill_keys(array_column($this->unfinished(), 'version'), true);
        $status = [];
        foreach ($this->plans() as $version => $steps) {
            $status[] = ['version' => $version, 'applied' => isset($known[$version]) && !isset($unfinished[$version])];
        }
        return $status;
    }

    public function schemaState(array $compatibleExtraVersions = []): array
    {
        $items = $this->status();
        $pending = array_column(array_filter($items, static fn (array $item): bool => !$item['applied']), 'version');
        $unknown = array_values(array_diff(array_keys($this->appliedVersions()), array_column($items, 'version'), $compatibleExtraVersions));
        $unfinished = $this->unfinished();
        return ['ready' => $pending === [] && $unknown === [] && $unfinished === [],
            'pending' => $pending, 'unknown' => $unknown, 'unfinished' => $unfinished];
    }

    private function executeStep(string $version, int $index, array $step): void
    {
        $hash = hash('sha256', json_encode($step, JSON_THROW_ON_ERROR));
        $record = $this->database->fetchOne('SELECT sql_hash, state FROM schema_migration_steps WHERE version = ? AND step_index = ?', [$version, $index]);
        if ($record !== null && !hash_equals($record['sql_hash'], $hash)) {
            throw new \RuntimeException("Migration $version step $index changed after execution began. Restore the original plan or use controlled recovery.");
        }
        if (($record['state'] ?? null) === 'completed') return;
        if (isset($step['check']) && $this->check($step['check'])) {
            $this->record($version, $index, $hash, 'completed');
            return;
        }
        if (isset($step['exists']) && $this->check($step['exists'])) {
            throw new \RuntimeException("Migration $version step $index found an incompatible existing schema object. No destructive repair was attempted.");
        }
        $transactional = preg_match('/^\s*(?:INSERT|UPDATE|DELETE)\b/i', $step['sql']) === 1;
        if ($record !== null && !$transactional && !($step['repeatable'] ?? false) && !isset($step['check'])) {
            throw new \RuntimeException("Migration $version step $index has an uncertain DDL result. A recovery check or controlled restore is required.");
        }
        $this->record($version, $index, $hash, 'running');
        try {
            $run = function () use ($step, $version, $index, $hash): void {
                $this->database->pdo()->exec($step['sql']);
                if (isset($step['check']) && !$this->check($step['check'])) {
                    throw new \RuntimeException("Migration $version step $index did not satisfy its schema postcondition.");
                }
                $this->record($version, $index, $hash, 'completed');
            };
            // Only DML and its checkpoint can be committed atomically by MySQL/MariaDB.
            if ($transactional) $this->database->transaction($run);
            else $run();
        } catch (\Throwable $error) {
            try { $this->record($version, $index, $hash, 'failed'); }
            catch (\Throwable) { /* Retain the running checkpoint after a lost connection. */ }
            throw new \RuntimeException("Migration $version failed at step $index. Run migrate to resume the recorded plan after resolving the cause.", 0, $error);
        }
    }

    private function record(string $version, int $index, string $hash, string $state): void
    {
        $this->database->execute('INSERT INTO schema_migration_steps (version, step_index, sql_hash, state, updated_at)
            VALUES (?, ?, ?, ?, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE state = VALUES(state), updated_at = UTC_TIMESTAMP()', [$version, $index, $hash, $state]);
    }

    private function check(string $sql): bool
    {
        if (!preg_match('/^\s*SELECT\b/i', $sql)) throw new \RuntimeException('Migration recovery checks must be SELECT queries.');
        $row = $this->database->fetchOne($sql);
        return $row !== null && (int) reset($row) === 1;
    }

    private function ensureTables(): void
    {
        $this->database->pdo()->exec('CREATE TABLE IF NOT EXISTS schema_migrations (
            version VARCHAR(191) NOT NULL PRIMARY KEY, applied_at DATETIME NOT NULL
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
        $this->database->pdo()->exec('CREATE TABLE IF NOT EXISTS schema_migration_steps (
            version VARCHAR(191) NOT NULL, step_index INT UNSIGNED NOT NULL, sql_hash CHAR(64) NOT NULL,
            state VARCHAR(16) NOT NULL, updated_at DATETIME NOT NULL,
            PRIMARY KEY (version, step_index)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
    }

    private function exists(string $table): bool
    {
        return $this->database->fetchOne('SELECT 1 FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?', [$table]) !== null;
    }

    private function appliedVersions(): array
    {
        return $this->exists('schema_migrations') ? array_fill_keys(array_column($this->database->fetchAll('SELECT version FROM schema_migrations'), 'version'), true) : [];
    }

    private function unfinished(): array
    {
        return $this->exists('schema_migration_steps') ? $this->database->fetchAll('SELECT version, step_index, state FROM schema_migration_steps WHERE state <> "completed" ORDER BY version, step_index') : [];
    }

    private function lockName(): string
    {
        $row = $this->database->fetchOne('SELECT DATABASE() AS name');
        return 'openwiki:migrate:' . substr(hash('sha256', (string) ($row['name'] ?? '')), 0, 40);
    }

    private function plans(): array
    {
        $files = glob(rtrim($this->migrationPath, '/\\') . '/*.php');
        if ($files === false || $files === []) throw new \RuntimeException('No migration plan is available in this release.');
        sort($files, SORT_STRING);
        $plans = [];
        foreach ($files as $file) {
            $migration = require $file;
            if (!is_array($migration) || !is_string($migration['version'] ?? null)
                || !preg_match('/^[a-zA-Z0-9_]{1,191}$/D', $migration['version'])
                || !is_array($migration['up'] ?? null) || $migration['up'] === [] || isset($plans[$migration['version']])) {
                throw new \RuntimeException('Invalid or duplicate migration: ' . basename($file));
            }
            $steps = [];
            foreach ($migration['up'] as $step) {
                if (is_string($step)) $step = ['sql' => $step, 'repeatable' => preg_match('/^\s*CREATE TABLE IF NOT EXISTS\b/i', $step) === 1];
                if (!is_array($step) || !is_string($step['sql'] ?? null) || trim($step['sql']) === '') {
                    throw new \RuntimeException('Invalid migration step: ' . $migration['version']);
                }
                $steps[] = $step;
            }
            $plans[$migration['version']] = $steps;
        }
        return $plans;
    }
}
