<?php
declare(strict_types=1);
namespace OpenWiki\Spaces;

use OpenWiki\Core\Application;
use OpenWiki\Core\Env;
use OpenWiki\Wiki\ContentService;
use OpenWiki\Wiki\MarkdownRenderer;

final class StructureContent
{
    public function __construct(private readonly Application $app) {}

    public function transform(array $page, array $source, array $destination, array $rows, array $slugs, array $mapping, array $assets): array
    {
        $references = []; $local = []; $cross = (int) $source['id'] !== (int) $destination['id'];
        foreach ($rows as $row) {
            if ((int) $row['space_id'] !== (int) $source['id'] || $row['deleted_at'] !== null) continue;
            $url = isset($mapping[$row['id']]) ? '/spaces/' . rawurlencode($destination['space_key']) . '/pages/' . rawurlencode($slugs[$row['id']]) : DocumentationTreeService::url($source, $row);
            foreach ([$row['title'], $row['slug']] as $ref) $references[mb_strtolower($ref)] ??= $url;
            foreach (['pages' => $row['slug'], 'wiki' => $row['slug']] as $route => $ref) $local['/spaces/' . rawurlencode($source['space_key']) . '/' . $route . '/' . rawurlencode($ref)] = $url;
        }
        $rewriteUrl = static function (string $url) use ($local, $assets, $references, $source, $destination, $cross): string {
            $host = parse_url($url, PHP_URL_HOST); $appHost = parse_url((string) Env::get('APP_URL', ''), PHP_URL_HOST);
            if ($host !== null && (!is_string($appHost) || strcasecmp($host, $appHost) !== 0)) return $url;
            $path = parse_url($url, PHP_URL_PATH); if (!is_string($path)) return $url;
            $suffix = (parse_url($url, PHP_URL_QUERY) === null ? '' : '?' . parse_url($url, PHP_URL_QUERY)) . (parse_url($url, PHP_URL_FRAGMENT) === null ? '' : '#' . parse_url($url, PHP_URL_FRAGMENT));
            foreach ($assets as $old => $new) if ($path === $old || str_starts_with($path, $old . '/')) return $new . substr($path, strlen($old)) . $suffix;
            if (isset($local[$path])) return $local[$path] . $suffix;
            $prefix = '/spaces/' . rawurlencode($source['space_key']) . '/wiki/';
            if (str_starts_with($path, $prefix)) return ($references[mb_strtolower(rawurldecode(substr($path, strlen($prefix))))] ?? $path) . $suffix;
            return $url;
        };
        $wiki = static function (string $text, bool $markdown) use ($references, $source, $cross): string {
            return preg_replace_callback('/\[\[([^\]\r\n]{1,255})\]\]/u', static function (array $match) use ($references, $source, $markdown, $cross): string {
                $reference = trim($match[1]); $url = $references[mb_strtolower($reference)] ?? '/spaces/' . rawurlencode($source['space_key']) . '/wiki/' . rawurlencode($reference);
                $label = $markdown ? str_replace(['\\', '[', ']'], ['\\\\', '\\[', '\\]'], $reference) : htmlspecialchars($reference, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                return $markdown ? '[' . $label . '](' . $url . ')' : '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8') . '">' . $label . '</a>';
            }, $text) ?? $text;
        };
        if ($page['content_format'] === 'markdown') {
            $renderer = new MarkdownRenderer();
            $markdown = $renderer->transformProse($page['content_markdown'] ?? '', static fn (string $text): string => $wiki($text, true));
            // Replace only parsed destination positions; text and code examples remain literal.
            $urls = $local;
            if (preg_match_all('~\]\(\s*<?([^\s)>]+)|^[ \t]{0,3}\[[^\]\r\n]+\]:[ \t]*<?([^\s>]+)~m', $markdown, $matches, PREG_SET_ORDER)) foreach ($matches as $match) {
                $url = $match[1] !== '' ? $match[1] : ($match[2] ?? ''); $replacement = $rewriteUrl($url); if ($replacement !== $url) $urls[$url] = $replacement;
            }
            return (new ContentService())->normalize('markdown', '', $renderer->replaceAssetUrls($markdown, $urls));
        }
        $dom = new \DOMDocument('1.0', 'UTF-8'); $previous = libxml_use_internal_errors(true);
        $dom->loadHTML('<!doctype html><meta charset="utf-8"><div id="structure-content-root">' . $page['content_html'] . '</div>'); libxml_clear_errors(); libxml_use_internal_errors($previous);
        $root = $dom->getElementById('structure-content-root');
        $walk = function (\DOMNode $node) use (&$walk, $rewriteUrl, $wiki, $dom): void {
            if ($node instanceof \DOMElement) {
                if (in_array(strtolower($node->tagName), ['code', 'pre'], true)) return;
                foreach (['href', 'src'] as $attribute) if ($node->hasAttribute($attribute)) $node->setAttribute($attribute, $rewriteUrl($node->getAttribute($attribute)));
                if (strtolower($node->tagName) === 'a') return;
            }
            foreach (iterator_to_array($node->childNodes) as $child) {
                if ($child instanceof \DOMText && str_contains($child->nodeValue ?? '', '[[')) {
                    $html = $wiki(htmlspecialchars($child->nodeValue, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false); $temporary = new \DOMDocument('1.0', 'UTF-8'); $temporary->loadHTML('<meta charset="utf-8"><div id="refs">' . $html . '</div>');
                    foreach (iterator_to_array($temporary->getElementById('refs')->childNodes) as $replacement) $child->parentNode->insertBefore($dom->importNode($replacement, true), $child);
                    $child->parentNode->removeChild($child);
                } else $walk($child);
            }
        };
        $walk($root); $html = ''; foreach ($root->childNodes as $child) $html .= $dom->saveHTML($child);
        return (new ContentService())->normalize('visual', $html, '');
    }
}
