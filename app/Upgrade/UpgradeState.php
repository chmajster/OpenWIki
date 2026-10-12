<?php

declare(strict_types=1);

namespace OpenWiki\Upgrade;

final class UpgradeState
{
    public function __construct(private readonly string $directory) {}

    public function read(): array
    {
        $path = $this->directory . '/status.json';
        if (!is_file($path)) return ['status' => 'idle', 'completed' => []];
        $value = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($value)) throw new \RuntimeException('Invalid upgrade journal.');
        return $value;
    }

    public function update(array $changes): array
    {
        $state = array_replace($this->read(), $changes, ['updated_at' => gmdate(DATE_ATOM)]);
        $temporary = $this->directory . '/status-' . bin2hex(random_bytes(8)) . '.tmp';
        if (file_put_contents($temporary, json_encode($state, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n") === false) {
            throw new \RuntimeException('Cannot persist upgrade journal.');
        }
        chmod($temporary, 0644);
        if (!rename($temporary, $this->directory . '/status.json')) throw new \RuntimeException('Cannot commit upgrade journal.');
        return $state;
    }

    public function complete(string $stage): void
    {
        $state = $this->read();
        $completed = array_values(array_unique([...$state['completed'], $stage]));
        $this->update(['completed' => $completed, 'stage' => $stage, 'status' => 'running', 'error' => null]);
    }
}
