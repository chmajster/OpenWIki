<?php
declare(strict_types=1);
namespace OpenWiki\Install;

/** Linux/WSL process identity: PID alone is insufficient because it can be reused. */
final class LocalProcess
{
    private readonly string $file;
    public function __construct(private readonly string $root)
    {
        $this->file = $root . '/.openwiki/local/state.json';
    }

    public function read(): array
    {
        if (!is_file($this->file)) return [];
        $state = json_decode((string) file_get_contents($this->file), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($state) || ($state['root'] ?? '') !== $this->root) throw new \RuntimeException('Stan lokalny pochodzi z innej kopii projektu. Żaden proces nie został zatrzymany.');
        LocalRuntime::validateAddress((string) ($state['host'] ?? ''), (string) ($state['port'] ?? ''));
        return $state;
    }

    public function ownedPid(): ?int
    {
        $state = $this->read(); $pid = (int) ($state['pid'] ?? 0);
        if ($pid < 2 || !isset($state['start'], $state['token']) || !hash_equals((string) $state['start'], self::start($pid) ?? '')) return null;
        $command = @file_get_contents('/proc/' . $pid . '/cmdline');
        $environment = @file_get_contents('/proc/' . $pid . '/environ');
        if ($command === false || $environment === false) return null;
        $args = explode("\0", rtrim($command, "\0"));
        if (end($args) !== $this->root . '/scripts/local-router.php' || !in_array($this->root . '/public', $args, true)) return null;
        $server = array_search('-S', $args, true); $documentRoot = array_search('-t', $args, true);
        $host = str_contains($state['host'], ':') ? '[' . $state['host'] . ']' : $state['host'];
        if ($server === false || $documentRoot === false || ($args[$server + 1] ?? '') !== $host . ':' . $state['port'] || ($args[$documentRoot + 1] ?? '') !== $this->root . '/public') return null;
        if (!in_array('OPENWIKI_LOCAL_PROBE_TOKEN=' . $state['token'], explode("\0", $environment), true)) return null;
        return $pid;
    }

    public function configure(string $host, string $port, ?int $pid = null, ?string $token = null): void
    {
        LocalRuntime::validateAddress($host, $port);
        if ($pid !== null && (self::start($pid) === null || preg_match('/^[a-f0-9]{32}$/D', $token ?? '') !== 1)) throw new \RuntimeException('Nie można ustalić tożsamości uruchomionego PHP.');
        $state = ['root' => $this->root, 'host' => $host, 'port' => (int) $port, 'pid' => $pid, 'start' => $pid === null ? null : self::start($pid), 'token' => $token];
        $temporary = $this->file . '.' . bin2hex(random_bytes(6)) . '.tmp';
        if (!is_dir(dirname($this->file)) && !mkdir(dirname($this->file), 0700, true)) throw new \RuntimeException('Brak zapisu stanu lokalnego.');
        if (file_put_contents($temporary, json_encode($state, JSON_THROW_ON_ERROR), LOCK_EX) === false) throw new \RuntimeException('Nie można zapisać stanu lokalnego.');
        chmod($temporary, 0600);
        if (!rename($temporary, $this->file)) { @unlink($temporary); throw new \RuntimeException('Nie można aktywować stanu lokalnego.'); }
    }

    public function clear(int $pid): void
    {
        $state = $this->read();
        if (($state['pid'] ?? null) === $pid) $this->configure($state['host'], (string) $state['port']);
    }

    private static function start(int $pid): ?string
    {
        $stat = @file_get_contents('/proc/' . $pid . '/stat');
        if ($stat === false) return null;
        $parts = preg_split('/\s+/', substr($stat, (int) strrpos($stat, ')') + 2));
        return ($parts[0] ?? '') === 'Z' ? null : ($parts[19] ?? null);
    }
}
