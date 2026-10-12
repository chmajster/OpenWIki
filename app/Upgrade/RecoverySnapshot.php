<?php

declare(strict_types=1);

namespace OpenWiki\Upgrade;

use OpenWiki\Core\Database;
use PharData;

/** Private recovery archives include secrets and must never use the UI backup directory. */
final class RecoverySnapshot
{
    public function __construct(private readonly Database $database, private readonly string $instance) {}

    public function create(string $destination): array
    {
        if (file_exists($destination)) throw new \RuntimeException('Recovery snapshot already exists; it will not be overwritten.');
        $writing = dirname($destination) . '/.working-' . bin2hex(random_bytes(8)) . '.tar';
        $temporary = dirname($destination) . '/snapshot-' . bin2hex(random_bytes(8));
        if (!mkdir($temporary, 0700)) throw new \RuntimeException('Cannot prepare private recovery directory.');
        $lock = $this->lock();
        try {
            $dump = $temporary . '/database.ndjson';
            $tables = $this->dump($dump);
            $sources = ['database.ndjson' => $dump, '.env' => $this->instance . '/.env'];
            $directories = ['storage'];
            $root = realpath($this->instance . '/storage');
            if ($root === false) throw new \RuntimeException('Missing instance storage.');
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
            foreach ($iterator as $file) {
                $relative = 'storage/' . str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root) + 1));
                // Ordinary backups are retained separately, preventing recursive archives.
                if ($relative === 'storage/backups' || str_starts_with($relative, 'storage/backups/')) continue;
                if ($file->isLink()) throw new \RuntimeException('Storage contains a symlink; resolve it before taking a recovery snapshot.');
                if ($file->isDir()) $directories[] = $relative;
                elseif ($file->isFile()) $sources[$relative] = $file->getPathname();
                else throw new \RuntimeException('Storage contains an unsupported file type.');
            }
            $archive = new PharData($writing);
            $entries = [];
            foreach ($sources as $name => $source) {
                if (!is_file($source) || !is_readable($source)) throw new \RuntimeException('A recovery source is not readable.');
                $entries[$name] = ['sha256' => hash_file('sha256', $source), 'size' => filesize($source)];
                $archive->addFile($source, $name);
            }
            $manifest = ['format' => 1, 'created_at' => gmdate(DATE_ATOM), 'database' => $this->database->fetchOne('SELECT DATABASE() AS name')['name'],
                'tables' => $tables, 'entries' => $entries, 'directories' => $directories];
            $archive->addFromString('manifest.json', json_encode($manifest, JSON_THROW_ON_ERROR));
            unset($archive);
            chmod($writing, 0600);
            $this->verify($writing);
            if (!rename($writing, $destination)) throw new \RuntimeException('Cannot commit the verified recovery archive.');
            return ['sha256' => hash_file('sha256', $destination), 'tables' => count($tables), 'files' => count($entries)];
        } catch (\Throwable $error) {
            if (is_file($writing)) unlink($writing);
            throw $error;
        } finally {
            try { $this->database->fetchOne('SELECT RELEASE_LOCK(?)', [$lock]); } catch (\Throwable) { /* Connection loss releases its named locks. */ }
            if (is_file($temporary . '/database.ndjson')) unlink($temporary . '/database.ndjson');
            rmdir($temporary);
        }
    }

    public function verify(string $path, ?string $expectedHash = null): array
    {
        if (!is_file($path) || ($expectedHash !== null && !hash_equals($expectedHash, (string) hash_file('sha256', $path)))) {
            throw new \RuntimeException('Recovery archive checksum does not match the upgrade journal.');
        }
        $archive = new PharData($path);
        if (!isset($archive['manifest.json'])) throw new \RuntimeException('Recovery manifest is missing.');
        $manifest = json_decode($archive['manifest.json']->getContent(), true, 512, JSON_THROW_ON_ERROR);
        if (($manifest['format'] ?? null) !== 1 || !isset($manifest['entries']['.env'], $manifest['entries']['database.ndjson'], $manifest['entries']['storage/installed.lock'])) {
            throw new \RuntimeException('Incomplete recovery snapshot.');
        }
        foreach ($manifest['directories'] as $name) self::safeName($name);
        foreach ($manifest['entries'] as $name => $expected) {
            self::safeName($name);
            if (!isset($archive[$name]) || !$archive[$name]->isFile() || $archive[$name]->isLink() || $archive[$name]->getSize() !== $expected['size']
                || !hash_equals($expected['sha256'], (string) hash_file('sha256', $archive[$name]->getPathname()))) {
                throw new \RuntimeException('Recovery archive entry failed verification: ' . $name);
            }
        }
        $count = 0;
        foreach (new \RecursiveIteratorIterator($archive) as $file) {
            if ($file->isLink() || !$file->isFile()) throw new \RuntimeException('Invalid recovery archive entry.');
            $count++;
        }
        if ($count !== count($manifest['entries']) + 1) throw new \RuntimeException('Unexpected files in recovery archive.');
        return $manifest;
    }

    /** The caller must hold the exclusive write gate and first preserve the failed state. */
    public function restore(string $path, string $expectedHash, string $confirmedDatabase): void
    {
        $manifest = $this->verify($path, $expectedHash);
        $name = (string) ($this->database->fetchOne('SELECT DATABASE() AS name')['name'] ?? '');
        if ($name === '' || $confirmedDatabase !== $name || $manifest['database'] !== $name) throw new \RuntimeException('Restore requires explicit confirmation of the snapshot database name.');
        $lock = $this->lock();
        try {
            $archive = new PharData($path);
            $this->database->pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');
            foreach ($this->database->fetchAll('SHOW FULL TABLES') as $row) {
                if (array_values($row)[1] !== 'BASE TABLE') throw new \RuntimeException('Restore of views is not supported.');
                $this->database->pdo()->exec('DROP TABLE ' . self::quote((string) array_values($row)[0]));
            }
            $handle = fopen($archive['database.ndjson']->getPathname(), 'rb');
            if ($handle === false) throw new \RuntimeException('Cannot read verified database dump.');
            $statement = null;
            $counts = [];
            try {
                while (($line = fgets($handle)) !== false) {
                    $record = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
                    if (isset($record['table'])) {
                        $table = $record['table'];
                        if (!array_key_exists($table, $manifest['tables'])) throw new \RuntimeException('Unexpected table in recovery dump.');
                        $this->database->pdo()->exec($record['schema']);
                        $columns = implode(', ', array_map(self::quote(...), $record['columns']));
                        $statement = $this->database->pdo()->prepare('INSERT INTO ' . self::quote($table) . ' (' . $columns . ') VALUES (' . implode(',', array_fill(0, count($record['columns']), '?')) . ')');
                        $counts[$table] = 0;
                    } else {
                        if ($statement === null) throw new \RuntimeException('Invalid row in recovery dump.');
                        $values = array_map(static fn ($value) => $value === null ? null : base64_decode($value, true), $record['row']);
                        if (in_array(false, $values, true)) throw new \RuntimeException('Invalid encoding in recovery dump.');
                        $statement->execute($values);
                        $counts[$table]++;
                    }
                }
            } finally { fclose($handle); }
            if ($counts !== $manifest['tables']) throw new \RuntimeException('Restored row counts do not match the snapshot.');
            // Files are copied individually; archive paths can never choose a destination outside the instance.
            foreach ($manifest['directories'] as $directory) {
                self::safeDestination($this->instance, $directory);
                if (!is_dir($this->instance . '/' . $directory) && !mkdir($this->instance . '/' . $directory, 0770, true)) throw new \RuntimeException('Cannot restore a storage directory.');
            }
            foreach ($manifest['entries'] as $entry => $metadata) {
                if ($entry === 'database.ndjson') continue;
                $destination = $this->instance . '/' . $entry;
                self::safeDestination($this->instance, $entry);
                if (is_link($destination)) throw new \RuntimeException('Refusing to restore over a symlink.');
                $temporary = $destination . '.restore-' . bin2hex(random_bytes(6));
                if (!copy($archive[$entry]->getPathname(), $temporary) || !hash_equals($metadata['sha256'], (string) hash_file('sha256', $temporary))) throw new \RuntimeException('Cannot restore a verified file.');
                chmod($temporary, $entry === '.env' ? 0640 : 0660);
                if (!rename($temporary, $destination)) throw new \RuntimeException('Cannot replace a restored file.');
            }
        } finally {
            $this->database->pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');
            $this->database->fetchOne('SELECT RELEASE_LOCK(?)', [$lock]);
        }
    }

    private function dump(string $path): array
    {
        $handle = fopen($path, 'xb');
        if ($handle === false) throw new \RuntimeException('Cannot create database recovery dump.');
        $tables = $this->database->fetchAll('SHOW FULL TABLES');
        $counts = [];
        $this->database->pdo()->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
        $this->database->pdo()->beginTransaction();
        try {
            foreach ($tables as $row) {
                $name = (string) array_values($row)[0];
                if (array_values($row)[1] !== 'BASE TABLE') throw new \RuntimeException('Recovery snapshot requires a database without views.');
                $create = $this->database->fetchOne('SHOW CREATE TABLE ' . self::quote($name));
                $columns = array_column($this->database->fetchAll('SHOW COLUMNS FROM ' . self::quote($name)), 'Field');
                self::line($handle, ['table' => $name, 'schema' => array_values($create)[1], 'columns' => $columns]);
                $statement = $this->database->pdo()->prepare('SELECT * FROM ' . self::quote($name), [\PDO::MYSQL_ATTR_USE_BUFFERED_QUERY => false]);
                $statement->execute();
                $counts[$name] = 0;
                while (($data = $statement->fetch(\PDO::FETCH_NUM)) !== false) {
                    self::line($handle, ['row' => array_map(static fn ($value) => $value === null ? null : base64_encode((string) $value), $data)]);
                    $counts[$name]++;
                }
                $statement->closeCursor();
            }
            $this->database->pdo()->commit();
            return $counts;
        } finally {
            if ($this->database->pdo()->inTransaction()) $this->database->pdo()->rollBack();
            fclose($handle);
        }
    }

    private static function line(mixed $handle, array $record): void
    {
        $line = json_encode($record, JSON_THROW_ON_ERROR) . "\n";
        if (fwrite($handle, $line) !== strlen($line)) throw new \RuntimeException('Database recovery dump is incomplete.');
    }

    private function lock(): string
    {
        $name = 'openwiki:migrate:' . substr(hash('sha256', (string) $this->database->fetchOne('SELECT DATABASE() AS name')['name']), 0, 40);
        if ((int) ($this->database->fetchOne('SELECT GET_LOCK(?, 0) AS acquired', [$name])['acquired'] ?? 0) !== 1) throw new \RuntimeException('Database is locked by another migration or recovery operation.');
        return $name;
    }

    private static function safeName(string $name): void
    {
        if (!in_array($name, ['.env', 'database.ndjson'], true) && !str_starts_with($name, 'storage/') && $name !== 'storage') throw new \RuntimeException('Invalid recovery archive path.');
        if (preg_match('~(?:^|/)\.\.?(?:/|$)|[\\\\\x00-\x1f]~', $name)) throw new \RuntimeException('Unsafe recovery archive path.');
    }

    private static function quote(string $name): string
    {
        if (!preg_match('/^[a-zA-Z0-9_]+$/D', $name)) throw new \RuntimeException('Unsupported database identifier in recovery snapshot.');
        return '`' . $name . '`';
    }

    private static function safeDestination(string $root, string $relative): void
    {
        $parts = explode('/', $relative);
        $path = $root;
        foreach ($parts as $part) {
            $path .= '/' . $part;
            if (is_link($path)) throw new \RuntimeException('Refusing to restore through a symlink.');
        }
    }
}
