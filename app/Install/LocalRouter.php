<?php

declare(strict_types=1);

namespace OpenWiki\Install;

final class LocalRouter
{
    /** null = application; false = forbidden; string = a public static file. */
    public static function resolve(string $publicRoot, string $uri): string|false|null
    {
        $path = parse_url($uri, PHP_URL_PATH);
        if (!is_string($path) || !str_starts_with($path, '/')) return false;
        $path = rawurldecode($path);
        if (preg_match('~[\x00-\x1f\x7f\\\\]~', $path)) return false;
        $segments = explode('/', $path);
        foreach ($segments as $segment) if (str_starts_with($segment, '.')) return false;
        if (in_array($segments[1] ?? '', ['storage', 'vendor', 'node_modules', 'app', 'bootstrap', 'database', 'resources', 'scripts', 'tests', 'docs', 'bin'], true)) return false;
        $root = realpath($publicRoot);
        $file = realpath($publicRoot . $path);
        if ($root === false) return false;
        if ($file === false || !is_file($file)) return null;
        if (!str_starts_with($file, $root . DIRECTORY_SEPARATOR)) return false;
        if ($path === '/index.php') return null;
        $extension = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        if (!in_array($extension, ['css', 'js', 'json', 'html', 'txt', 'xml', 'ico', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'svg', 'woff', 'woff2', 'ttf', 'eot', 'webmanifest'], true)) return false;
        return $file;
    }
}
