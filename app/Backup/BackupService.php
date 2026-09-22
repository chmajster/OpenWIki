<?php

declare(strict_types=1);

namespace OpenWiki\Backup;

use OpenWiki\Core\Database;
use OpenWiki\Core\Env;
use PDO;
use PharData;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;

final class BackupService
{
    private readonly string $backupRoot;
    private readonly string $tempRoot;

    public function __construct(
        private readonly Database $database,
        private readonly string $basePath
    ) {
        $this->backupRoot = rtrim($basePath, '/\\') . '/storage/backups';
        $this->tempRoot = rtrim($basePath, '/\\') . '/storage/temp';
    }

    public function create(): array
    {
        $this->ensureDirectory($this->backupRoot);
        $this->ensureDirectory($this->tempRoot);

        $stamp = gmdate('Ymd\\THis\\Z');
        $suffix = bin2hex(random_bytes(4));
        $filename = 'openwiki-backup-' . $stamp . '-' . $suffix . '.tar';
        $archivePath = $this->backupRoot . '/' . $filename;
        $workDir = $this->tempRoot . '/backup-' . bin2hex(random_bytes(8));
        $this->ensureDirectory($workDir);

        try {
            $databasePath = $workDir . '/database.sql';
            $configPath = $workDir . '/config.json';
            $manifestPath = $workDir . '/manifest.json';

            $this->dumpDatabase($databasePath);
            $this->writeJson($configPath, $this->safeConfiguration());

            $attachments = $this->attachmentFiles();
            $this->writeJson($manifestPath, [
                'format' => 1,
                'application' => 'OpenWiki',
                'application_version' => '0.1.0',
                'created_at' => gmdate(DATE_ATOM),
                'database_file' => 'database.sql',
                'configuration_file' => 'config.json',
                'attachment_files' => count($attachments),
            ]);

            $archive = new PharData($archivePath);
            $archive->addFile($databasePath, 'database.sql');
            $archive->addFile($configPath, 'config.json');
            $archive->addFile($manifestPath, 'manifest.json');

            foreach ($attachments as $relative => $path) {
                $archive->addFile($path, 'attachments/' . $relative);
            }

            unset($archive);
            @chmod($archivePath, 0640);

            $size = filesize($archivePath);
            if ($size === false || $size < 1) {
                throw new \RuntimeException('Backup archive was not created correctly.');
            }

            return [
                'name' => $filename,
                'path' => $archivePath,
                'size_bytes' => (int) $size,
                'created_at' => gmdate('Y-m-d H:i:s'),
            ];
        } catch (\Throwable $exception) {
            @unlink($archivePath);
            throw $exception;
        } finally {
            $this->removeDirectory($workDir);
        }
    }

    public function list(): array
    {
        if (!is_dir($this->backupRoot)) {
            return [];
        }

        $items = [];
        foreach (new FilesystemIterator($this->backupRoot, FilesystemIterator::SKIP_DOTS) as $file) {
            if (!$file->isFile() || !$this->validName($file->getFilename())) {
                continue;
            }

            $items[] = [
                'name' => $file->getFilename(),
                'size_bytes' => $file->getSize(),
                'created_at' => gmdate('Y-m-d H:i:s', $file->getMTime()),
            ];
        }

        usort($items, static fn (array $a, array $b): int => strcmp($b['name'], $a['name']));
        return $items;
    }

    public function find(string $name): ?string
    {
        if (!$this->validName($name)) {
            return null;
        }

        $root = realpath($this->backupRoot);
        $path = realpath($this->backupRoot . '/' . $name);
        if ($root === false || $path === false || !is_file($path)) {
            return null;
        }

        if (!str_starts_with($path, $root . DIRECTORY_SEPARATOR)) {
            return null;
        }

        return $path;
    }

    private function dumpDatabase(string $path): void
    {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new \RuntimeException('Unable to create database dump.');
        }

        try {
            $this->write($handle, "-- OpenWiki database backup\n");
            $this->write($handle, '-- Created at ' . gmdate(DATE_ATOM) . "\n");
            $this->write($handle, "SET NAMES utf8mb4;\nSET FOREIGN_KEY_CHECKS=0;\n\n");

            $tables = $this->database->fetchAll("SHOW FULL TABLES WHERE Table_type = 'BASE TABLE'");
            foreach ($tables as $row) {
                $table = (string) reset($row);
                if (!preg_match('/^[A-Za-z0-9_]+$/', $table)) {
                    throw new \RuntimeException('Unsafe table name returned by database.');
                }

                $quotedTable = '`' . $table . '`';
                $create = $this->database->fetchOne('SHOW CREATE TABLE ' . $quotedTable);
                if ($create === null) {
                    throw new \RuntimeException('Unable to read schema for table ' . $table . '.');
                }

                $createSql = (string) (array_values($create)[1] ?? '');
                if ($createSql === '') {
                    throw new \RuntimeException('Database returned an empty schema for table ' . $table . '.');
                }

                $this->write($handle, 'DROP TABLE IF EXISTS ' . $quotedTable . ";\n");
                $this->write($handle, $createSql . ";\n\n");

                $statement = $this->database->pdo()->query('SELECT * FROM ' . $quotedTable);
                if ($statement === false) {
                    throw new \RuntimeException('Unable to read data from table ' . $table . '.');
                }

                while (($record = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                    $columns = [];
                    $values = [];
                    foreach ($record as $column => $value) {
                        $columns[] = '`' . str_replace('`', '``', (string) $column) . '`';
                        $values[] = $this->sqlLiteral($value);
                    }

                    $this->write(
                        $handle,
                        'INSERT INTO ' . $quotedTable
                        . ' (' . implode(', ', $columns) . ') VALUES ('
                        . implode(', ', $values) . ");\n"
                    );
                }

                $this->write($handle, "\n");
            }

            $this->write($handle, "SET FOREIGN_KEY_CHECKS=1;\n");
        } finally {
            fclose($handle);
        }
    }

    private function sqlLiteral(mixed $value): string
    {
        if ($value === null) {
            return 'NULL';
        }

        $quoted = $this->database->pdo()->quote((string) $value);
        if ($quoted === false) {
            throw new \RuntimeException('Unable to encode database value for backup.');
        }

        return $quoted;
    }

    private function safeConfiguration(): array
    {
        return [
            'APP_NAME' => Env::get('APP_NAME', 'OpenWiki'),
            'APP_ENV' => Env::get('APP_ENV', 'production'),
            'APP_URL' => Env::get('APP_URL', ''),
            'APP_TIMEZONE' => Env::get('APP_TIMEZONE', 'UTC'),
            'SESSION_SECURE_COOKIE' => Env::get('SESSION_SECURE_COOKIE', 'false'),
            'SESSION_SAME_SITE' => Env::get('SESSION_SAME_SITE', 'Lax'),
            'SESSION_LIFETIME' => Env::get('SESSION_LIFETIME', '7200'),
            'DB_HOST' => Env::get('DB_HOST', '127.0.0.1'),
            'DB_PORT' => Env::get('DB_PORT', '3306'),
            'DB_DATABASE' => Env::get('DB_DATABASE', 'openwiki'),
            'DB_CHARSET' => Env::get('DB_CHARSET', 'utf8mb4'),
            'secrets_excluded' => ['APP_KEY', 'DB_USERNAME', 'DB_PASSWORD'],
        ];
    }

    private function attachmentFiles(): array
    {
        $root = rtrim($this->basePath, '/\\') . '/storage/attachments';
        if (!is_dir($root)) {
            return [];
        }

        $realRoot = realpath($root);
        if ($realRoot === false) {
            return [];
        }

        $files = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($realRoot, FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if (!$file->isFile() || $file->getFilename() === '.gitkeep') {
                continue;
            }

            $path = $file->getRealPath();
            if ($path === false || !str_starts_with($path, $realRoot . DIRECTORY_SEPARATOR)) {
                continue;
            }

            $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($path, strlen($realRoot) + 1));
            if ($relative === '' || str_contains($relative, '..')) {
                continue;
            }
            $files[$relative] = $path;
        }

        ksort($files);
        return $files;
    }

    private function writeJson(string $path, array $data): void
    {
        $json = json_encode(
            $data,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
        if (file_put_contents($path, $json . PHP_EOL, LOCK_EX) === false) {
            throw new \RuntimeException('Unable to write backup metadata.');
        }
    }

    private function write($handle, string $content): void
    {
        if (fwrite($handle, $content) === false) {
            throw new \RuntimeException('Unable to write database dump.');
        }
    }

    private function ensureDirectory(string $path): void
    {
        if (!is_dir($path) && !mkdir($path, 0770, true) && !is_dir($path)) {
            throw new \RuntimeException('Unable to create backup directory.');
        }
        if (!is_writable($path)) {
            throw new \RuntimeException('Backup directory is not writable.');
        }
    }

    private function validName(string $name): bool
    {
        return preg_match('/^openwiki-backup-\\d{8}T\\d{6}Z-[a-f0-9]{8}\\.tar$/', $name) === 1;
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($iterator as $entry) {
            if ($entry->isDir()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }
        @rmdir($path);
    }
}
