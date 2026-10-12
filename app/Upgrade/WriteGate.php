<?php

declare(strict_types=1);

namespace OpenWiki\Upgrade;

/** Held for the lifetime of each request/worker, including reads that touch sessions. */
final class WriteGate
{
    private mixed $handle = null;

    public static function instancePath(string $codePath): string
    {
        $storage = realpath(\OpenWiki\Core\RuntimePaths::storage($codePath));
        return $storage === false ? $codePath : dirname($storage);
    }

    public static function maintenance(string $codePath): bool
    {
        return is_file(self::instancePath($codePath) . '/.openwiki/maintenance');
    }

    public function acquire(string $codePath, bool $exclusive = false): void
    {
        $directory = self::instancePath($codePath) . '/.openwiki';
        $token = PHP_SAPI === 'cli' ? getenv('OPENWIKI_UPGRADE_OWNER') : false;
        if (is_string($token) && $token !== '' && is_readable($directory . '/owner')
            && hash_equals(trim((string) file_get_contents($directory . '/owner')), $token)) return;
        if (self::maintenance($codePath)) throw new \RuntimeException('OpenWiki is in maintenance mode. Resume the upgrade or perform controlled recovery.');
        if (!is_dir($directory)) return; // Unmanaged development copy; installer provisions the gate.
        // Runtime metadata alone does not provision the system-upgrade gate.
        if (!is_file($directory . '/requests.lock') && array_diff(scandir($directory) ?: [], ['.', '..', 'docker', 'local']) === []) return;
        $this->handle = @fopen($directory . '/requests.lock', 'rb');
        if ($this->handle === false || !flock($this->handle, ($exclusive ? LOCK_EX : LOCK_SH) | LOCK_NB)) {
            throw new \RuntimeException('OpenWiki writes are paused by an upgrade or migration.');
        }
        if (self::maintenance($codePath)) throw new \RuntimeException('OpenWiki entered maintenance mode.');
    }

    public function __destruct()
    {
        if (is_resource($this->handle)) { flock($this->handle, LOCK_UN); fclose($this->handle); }
    }
}
