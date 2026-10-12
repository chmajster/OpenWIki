<?php
declare(strict_types=1);
namespace OpenWiki\Wiki;

final class RevisionDiff
{
    /** Bounded LCS; large changed regions remain a complete, escaped delete/insert diff. */
    public static function lines(string $before, string $after): array
    {
        $before = str_replace("\r\n", "\n", $before); $after = str_replace("\r\n", "\n", $after);
        // Keep pathological newline-heavy documents from allocating millions of row arrays.
        if (substr_count($before, "\n") + substr_count($after, "\n") > 20000) return $before === $after ? [['same', $before]] : [['removed', $before], ['added', $after]];
        $a = explode("\n", $before); $b = explode("\n", $after);
        $prefix = []; $suffix = [];
        $start = 0; $endA = count($a); $endB = count($b);
        while ($start < $endA && $start < $endB && $a[$start] === $b[$start]) $prefix[] = ['same', $a[$start++]];
        while ($endA > $start && $endB > $start && $a[$endA - 1] === $b[$endB - 1]) { $suffix[] = ['same', $a[--$endA]]; --$endB; }
        $a = array_slice($a, $start, $endA - $start); $b = array_slice($b, $start, $endB - $start);
        $n = count($a); $m = count($b); $middle = [];
        if ($n * $m > 250000) {
            foreach ($a as $line) $middle[] = ['removed', $line];
            foreach ($b as $line) $middle[] = ['added', $line];
        } else {
            $matrix = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
            for ($i = $n - 1; $i >= 0; --$i) for ($j = $m - 1; $j >= 0; --$j) $matrix[$i][$j] = $a[$i] === $b[$j] ? 1 + $matrix[$i + 1][$j + 1] : max($matrix[$i + 1][$j], $matrix[$i][$j + 1]);
            $i = $j = 0;
            while ($i < $n || $j < $m) {
                if ($i < $n && $j < $m && $a[$i] === $b[$j]) { $middle[] = ['same', $a[$i++]]; ++$j; }
                elseif ($j < $m && ($i === $n || $matrix[$i][$j + 1] > $matrix[$i + 1][$j])) $middle[] = ['added', $b[$j++]];
                else $middle[] = ['removed', $a[$i++]];
            }
        }
        return [...$prefix, ...$middle, ...array_reverse($suffix)];
    }

    public static function compare(array $before, array $after): array
    {
        $source = static fn (array $r): string => $r['content_format'] === 'markdown' ? (string) $r['content_markdown'] : (string) $r['content_html'];
        $metadata = static fn (array $r): array => ['title' => $r['title'], 'slug' => $r['slug'], 'parent_id' => $r['parent_id'], 'status' => $r['status'], 'format' => $r['content_format'], 'tags' => json_decode($r['tags_json'] ?? '[]', true)] + json_decode($r['knowledge_json'] ?? '{}', true);
        $old = $metadata($before); $new = $metadata($after); $changes = [];
        foreach (array_unique([...array_keys($old), ...array_keys($new)]) as $key) if (($old[$key] ?? null) !== ($new[$key] ?? null)) $changes[$key] = ['before' => $old[$key] ?? null, 'after' => $new[$key] ?? null];
        return ['metadata' => $changes, 'document' => self::lines($source($before), $source($after))];
    }
}
