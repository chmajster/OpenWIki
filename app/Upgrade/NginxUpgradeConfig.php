<?php
declare(strict_types=1);
namespace OpenWiki\Upgrade;

/** Change only OpenWiki-owned routing and maintenance directives, retaining TLS/custom settings. */
final class NginxUpgradeConfig
{
    public static function prepare(string $configuration, string $instance, string $code, bool $health): string
    {
        $lines = preg_split('/\r?\n/', $configuration);
        $lines = array_filter($lines, static fn (string $line): bool => !str_contains($line, '$openwiki_maintenance')
            && !(str_contains($line, $instance . '/.openwiki/maintenance') && str_contains($line, 'return 503')));
        $configuration = implode("\n", $lines);
        $pattern = '~^([ \t]*)root\s+["\']?' . preg_quote($instance, '~') . '/(?:current/|\.openwiki/releases/[^/]+/)?public["\']?\s*;[ \t]*$~m';
        $count = 0;
        $result = preg_replace_callback($pattern, static function (array $match) use ($instance, $code, $health): string {
            $indent = $match[1];
            $directives = ['set $openwiki_maintenance "";', 'if (-f ' . self::path($instance . '/.openwiki/maintenance') . ') { set $openwiki_maintenance "blocked"; }'];
            if ($health) $directives[] = 'if ($request_uri ~ "^/health(/live)?(\\?|$)") { set $openwiki_maintenance ""; }';
            $directives[] = 'if ($openwiki_maintenance = "blocked") { return 503; }';
            $directives[] = 'root ' . self::path($code . '/public') . ';';
            return $indent . implode("\n" . $indent, $directives);
        }, $configuration, -1, $count);
        if ($result === null || $count === 0) throw new \RuntimeException('Nginx configuration does not contain a recognized OpenWiki root. No configuration was replaced.');
        return rtrim(str_replace('$document_root$fastcgi_script_name', '$realpath_root$fastcgi_script_name', $result), "\n") . "\n";
    }

    private static function path(string $path): string
    {
        return '"' . str_replace(['\\', '"', '$'], ['\\\\', '\\"', '\\$'], $path) . '"';
    }
}
