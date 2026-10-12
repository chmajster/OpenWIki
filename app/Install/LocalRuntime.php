<?php

declare(strict_types=1);

namespace OpenWiki\Install;

/** Read-only dependency checks and bounded startup checks for the CLI server. */
final class LocalRuntime
{
    public function __construct(private readonly string $root)
    {
    }

    public function phpDependenciesReady(): bool
    {
        try {
            if (!is_file($this->root . '/vendor/autoload.php')) return false;
            $lock = $this->json('composer.lock');
            $metadata = $this->json('vendor/composer/installed.json');
            $installed = array_column($metadata['packages'] ?? $metadata, null, 'name');
            foreach ($lock['packages'] as $package) {
                $actual = $installed[$package['name']] ?? null;
                if ($actual === null || $actual['version'] !== $package['version']) return false;
                foreach (['source', 'dist'] as $kind) {
                    if (isset($package[$kind]['reference']) && ($actual[$kind]['reference'] ?? null) !== $package[$kind]['reference']) return false;
                }
                $path = $actual['install-path'] ?? '../' . $package['name'];
                if (!is_dir($this->root . '/vendor/composer/' . $path)) return false;
                foreach ($package['autoload']['psr-4'] ?? [] as $paths) {
                    foreach ((array) $paths as $directory) if (!is_dir($this->root . '/vendor/composer/' . $path . '/' . $directory)) return false;
                }
            }
            return true;
        } catch (\Throwable) { return false; }
    }

    public function assetsReady(): bool
    {
        try {
            $manifest = $this->json('public/assets/editor-manifest.json');
            if (($manifest['version'] ?? null) !== 1 || ($manifest['input'] ?? null) !== $this->assetFingerprint()) return false;
            foreach (['wiki-editor.js', 'diagram.js', 'document-reader.js', 'diagram.html'] as $name) {
                $file = $this->root . '/public/assets/' . $name;
                if (!is_file($file) || filesize($file) === 0 || !hash_equals($manifest['outputs'][$name] ?? '', hash_file('sha256', $file))) return false;
            }
            return true;
        } catch (\Throwable) { return false; }
    }

    public function assetFingerprint(): string
    {
        $files = ['package.json', 'package-lock.json', 'scripts/build-assets.cjs', 'public/assets/diagram.html'];
        foreach (glob($this->root . '/resources/js/*.js') as $file) $files[] = 'resources/js/' . basename($file);
        sort($files, SORT_STRING);
        $hash = hash_init('sha256');
        foreach ($files as $file) {
            hash_update($hash, $file . "\0");
            if (!hash_update_file($hash, $this->root . '/' . $file)) throw new \RuntimeException('Cannot read asset source: ' . $file);
            hash_update($hash, "\0");
        }
        return hash_final($hash);
    }

    public function prepareStorage(): void
    {
        if (is_file($this->root . '/storage/installed.lock') && !is_readable($this->root . '/.env')) {
            throw new \RuntimeException('Istnieje storage/installed.lock bez czytelnego .env. Przywróć konfigurację istniejącej instancji; dane nie zostały zmienione.');
        }
        if (is_file($this->root . '/.env') && !is_readable($this->root . '/.env')) throw new \RuntimeException('Brak prawa odczytu istniejącego .env. Przywróć dostęp do konfiguracji; plik nie został zmieniony.');
        if (!is_file($this->root . '/storage/installed.lock') && !is_writable($this->root)) throw new \RuntimeException('Kreator potrzebuje prawa zapisu .env w katalogu projektu.');
        foreach (['storage', 'storage/attachments', 'storage/backups', 'storage/cache', 'storage/logs', 'storage/temp', 'storage/temp/local-sessions'] as $directory) {
            $path = $this->root . '/' . $directory;
            if (!is_dir($path) && !@mkdir($path, 0700, true) && !is_dir($path)) throw new \RuntimeException('Nie można utworzyć katalogu: ' . $path);
            if (!is_writable($path)) throw new \RuntimeException('Brak prawa zapisu do katalogu: ' . $path);
            $probe = $path . '/.local-write-' . bin2hex(random_bytes(8));
            $handle = @fopen($probe, 'xb');
            if ($handle === false) throw new \RuntimeException('Nie można zapisać pliku w katalogu: ' . $path);
            fclose($handle);
            unlink($probe);
        }
    }

    public static function validateAddress(string $host, string $port): void
    {
        $validHost = filter_var($host, FILTER_VALIDATE_IP) !== false || $host === 'localhost';
        if (!$validHost) throw new \InvalidArgumentException('Host musi być adresem IPv4, IPv6 lub localhost (np. 127.0.0.1 albo 0.0.0.0).');
        if (!preg_match('/^[0-9]{1,5}$/D', $port) || (int) $port < 1024 || (int) $port > 65535) {
            throw new \InvalidArgumentException('Port lokalny musi mieścić się w zakresie 1024–65535.');
        }
    }

    public static function checkPort(string $host, string $port): void
    {
        self::validateAddress($host, $port);
        $address = str_contains($host, ':') ? '[' . $host . ']' : $host;
        $connectHost = match ($host) { '0.0.0.0' => '127.0.0.1', '::' => '[::1]', default => $address };
        $existing = @stream_socket_client('tcp://' . $connectHost . ':' . (int) $port, $connectCode, $connectError, 0.25);
        if ($existing !== false) {
            fclose($existing);
            throw new \RuntimeException('Port ' . (int) $port . ' jest zajęty na ' . $host . '. Wybierz inny --port; żaden proces nie został zatrzymany.');
        }
        $socket = @stream_socket_server('tcp://' . $address . ':' . (int) $port, $errorCode, $error);
        if ($socket === false) throw new \RuntimeException('Nie można nasłuchiwać na ' . $address . ':' . (int) $port . '. Port może być zajęty lub host nie jest lokalnym interfejsem (' . $error . '). Wybierz --port lub --host; żaden proces nie został zatrzymany.');
        fclose($socket);
    }

    public static function probe(string $url, string $token): string
    {
        $get = static function (string $path) use ($url, $token): ?array {
            $handle = curl_init($url . $path);
            $identity = false;
            curl_setopt_array($handle, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT_MS => 250,
                CURLOPT_TIMEOUT_MS => 1000, CURLOPT_PROXY => '', CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_HEADERFUNCTION => static function ($curl, string $header) use ($token, &$identity): int {
                    $prefix = 'X-OpenWiki-Local-Token:';
                    if (strncasecmp($header, $prefix, strlen($prefix)) === 0) $identity = hash_equals($token, trim(substr($header, strlen($prefix))));
                    return strlen($header);
                }]);
            $body = curl_exec($handle); $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE); curl_close($handle);
            return $body === false ? null : ['body' => $body, 'status' => $status, 'identity' => $identity];
        };
        $health = $get('/health');
        if ($health === null) return 'pending';
        if (!$health['identity']) return 'wrong_server';
        $data = json_decode($health['body'], true);
        if ($health['status'] === 503 && ($data['status'] ?? null) === 'setup_required') {
            $wizard = $get('/install');
            return $wizard !== null && $wizard['identity'] && $wizard['status'] === 200 && str_contains($wizard['body'], 'name="db_host"') ? 'setup' : 'installer_error';
        }
        if ($health['status'] === 200 && ($data['status'] ?? null) === 'ok'
            && ($data['checks']['database'] ?? null) === true && ($data['checks']['storage'] ?? null) === true) return 'ready';
        if (($data['status'] ?? null) === 'degraded') {
            if (($data['checks']['database'] ?? null) !== true) return 'database_error';
            if (($data['checks']['storage'] ?? null) !== true) return 'storage_error';
        }
        return 'application_error';
    }

    private function json(string $file): array
    {
        $data = json_decode(file_get_contents($this->root . '/' . $file), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($data)) throw new \RuntimeException('Invalid dependency metadata: ' . $file);
        return $data;
    }
}
