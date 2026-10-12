<?php
declare(strict_types=1);
namespace OpenWiki\Core;

/** Runtime locations are process settings, separate from the mounted application code. */
final class RuntimePaths
{
    public static function configuration(string $code): string
    {
        return self::directory('OPENWIKI_CONFIG_DIR', $code) . '/.env';
    }

    public static function storage(string $code, string $suffix = ''): string
    {
        return self::directory('OPENWIKI_STORAGE_DIR', rtrim($code, '/\\') . '/storage')
            . ($suffix === '' ? '' : '/' . ltrim($suffix, '/\\'));
    }

    private static function directory(string $variable, string $default): string
    {
        $value = getenv($variable);
        if ($value === false || $value === '') return rtrim($default, '/\\');
        if (str_contains($value, "\0") || (!str_starts_with($value, '/') && preg_match('~^[A-Za-z]:[/\\\\]~D', $value) !== 1)) {
            throw new \RuntimeException($variable . ' must be an absolute directory path.');
        }
        return rtrim($value, '/\\');
    }
}
