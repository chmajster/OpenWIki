<?php

declare(strict_types=1);

namespace OpenWiki\Core;

final class Env
{
    private static array $values = [];

    public static function load(string $path): void
    {
        self::$values = [];
        if (!is_file($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES);
        if ($lines === false) {
            throw new \RuntimeException('Unable to read environment configuration.');
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $position = strpos($line, '=');
            if ($position === false) {
                continue;
            }

            $key = trim(substr($line, 0, $position));
            $value = trim(substr($line, $position + 1));
            if ($key === '' || !preg_match('/^[A-Z0-9_]+$/', $key)) {
                continue;
            }

            if (strlen($value) >= 2 && $value[0] === '"' && substr($value, -1) === '"') {
                try {
                    $decoded = json_decode($value, true, 512, JSON_THROW_ON_ERROR);
                } catch (\JsonException $exception) {
                    throw new \RuntimeException('Invalid quoted environment value for ' . $key . '.', 0, $exception);
                }
                if (!is_string($decoded)) {
                    throw new \RuntimeException('Environment value must decode to a string: ' . $key);
                }
                $value = $decoded;
            } elseif (strlen($value) >= 2 && $value[0] === "'" && substr($value, -1) === "'") {
                $value = substr($value, 1, -1);
            }

            self::$values[$key] = $value;
            if (getenv($key) === false) {
                putenv($key . '=' . $value);
                $_ENV[$key] = $value;
            }
        }
    }

    public static function get(string $key, ?string $default = null): ?string
    {
        $system = getenv($key);
        if ($system !== false) {
            return $system;
        }

        return self::$values[$key] ?? $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key);
        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE) ?? $default;
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key);
        return $value !== null && is_numeric($value) ? (int) $value : $default;
    }
}
