<?php
declare(strict_types=1);
namespace OpenWiki\Spaces;

use OpenWiki\Core\Env;
use OpenWiki\Wiki\ContentService;
use OpenWiki\Wiki\MarkdownRenderer;
use OpenWiki\Wiki\Slugger;

final class StructureContent
{
    private array $local = [];
    private array $titles = [];
    private array $slugs = [];
    private array $aliases = [];
    private array $targets = [];
    public function __construct(private readonly array $source, array $destination, array $rows, array $slugs, array $mapping, private readonly array $assets, array $redirects = [])
    {
        foreach ($rows as $row) {
            if ((int) $row['space_id'] !== (int) $source['id'] || $row['deleted_at'] !== null) continue;
            $this->titles[mb_strtolower($row['title'])] ??= (int) $row['id']; $this->slugs[$row['slug']] ??= (int) $row['id'];
            if (!isset($mapping[$row['id']])) continue;
            $url = '/spaces/' . rawurlencode($destination['space_key']) . '/pages/' . rawurlencode($slugs[$row['id']]);
            $this->targets[(int) $row['id']] = $url;
            $this->local['/spaces/' . rawurlencode($source['space_key']) . '/pages/' . rawurlencode($row['slug'])] = $url;
        }
        foreach ($redirects as $redirect) if ((int) $redirect['space_id'] === (int) $source['id']) {
            $this->aliases[$redirect['old_slug']] = (int) $redirect['page_id'];
            if (isset($mapping[$redirect['page_id']]) && !isset($this->slugs[$redirect['old_slug']])) $this->local['/spaces/' . rawurlencode($source['space_key']) . '/pages/' . rawurlencode($redirect['old_slug'])] = $this->targets[(int) $redirect['page_id']];
        }
    }

    public function transform(array $page): array
    {
        $local = $this->local; $assets = $this->assets; $source = $this->source;
        $referenceUrl = fn (string $reference): ?string => $this->referenceUrl($reference);
        $rewriteUrl = static function (string $url) use ($local, $assets, $referenceUrl, $source): string {
            $host = parse_url($url, PHP_URL_HOST); $appHost = parse_url((string) Env::get('APP_URL', ''), PHP_URL_HOST);
            if ($host !== null && (!is_string($appHost) || strcasecmp($host, $appHost) !== 0)) return $url;
            $path = parse_url($url, PHP_URL_PATH); if (!is_string($path)) return $url;
            $suffix = (parse_url($url, PHP_URL_QUERY) === null ? '' : '?' . parse_url($url, PHP_URL_QUERY)) . (parse_url($url, PHP_URL_FRAGMENT) === null ? '' : '#' . parse_url($url, PHP_URL_FRAGMENT));
            foreach ($assets as $old => $new) if ($path === $old || str_starts_with($path, $old . '/')) return $new . substr($path, strlen($old)) . $suffix;
            if (isset($local[$path])) return $local[$path] . $suffix;
            $prefix = '/spaces/' . rawurlencode($source['space_key']) . '/wiki/';
            if (str_starts_with($path, $prefix)) return ($referenceUrl(rawurldecode(substr($path, strlen($prefix)))) ?? $path) . $suffix;
            return $url;
        };
        $wiki = static function (string $text, bool $markdown) use ($referenceUrl, $source): string {
            return preg_replace_callback('/\[\[([^\]\r\n]{1,255})\]\]/u', static function (array $match) use ($referenceUrl, $source, $markdown): string {
                $reference = trim($markdown ? $match[1] : html_entity_decode($match[1], ENT_QUOTES | ENT_HTML5, 'UTF-8')); $url = $referenceUrl($reference) ?? '/spaces/' . rawurlencode($source['space_key']) . '/wiki/' . rawurlencode($reference);
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
        $walk = function (\DOMNode $node) use (&$walk, $rewriteUrl, $wiki, $dom, $assets): void {
            if ($node instanceof \DOMElement) {
                if (in_array(strtolower($node->tagName), ['code', 'pre'], true)) return;
                foreach (['href', 'src'] as $attribute) if ($node->hasAttribute($attribute)) $node->setAttribute($attribute, $rewriteUrl($node->getAttribute($attribute)));
                $attachment = $assets['/attachments/' . $node->getAttribute('data-attachment-id')] ?? null;
                if ($attachment !== null && preg_match('~^/attachments/([1-9][0-9]*)$~D', $attachment, $match)) $node->setAttribute('data-attachment-id', $match[1]);
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

    private function referenceUrl(string $reference): ?string
    {
        try { $slug = (new Slugger())->slug($reference); } catch (\InvalidArgumentException) { return null; }
        $target = $this->titles[mb_strtolower($reference)] ?? $this->slugs[$slug] ?? $this->aliases[$slug] ?? null;
        // External/hidden targets keep the reference supplied by the author, never their private slug.
        return $target === null ? null : ($this->targets[$target] ?? null);
    }
}
