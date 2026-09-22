<?php

declare(strict_types=1);

namespace OpenWiki\Security;

use DOMDocument;
use DOMElement;
use DOMNode;

final class HtmlSanitizer
{
    private const ALLOWED_TAGS = [
        'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'del',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'ul', 'ol', 'li', 'blockquote', 'pre', 'code',
        'a', 'img', 'table', 'thead', 'tbody', 'tr', 'th', 'td',
        'hr', 'div', 'span', 'section', 'aside',
    ];

    private const GLOBAL_ATTRIBUTES = ['id', 'class', 'title', 'aria-label', 'data-callout'];
    private const TAG_ATTRIBUTES = [
        'a' => ['href', 'target', 'rel'],
        'img' => ['src', 'alt', 'width', 'height', 'loading'],
        'td' => ['colspan', 'rowspan'],
        'th' => ['colspan', 'rowspan', 'scope'],
        'code' => ['data-language'],
    ];

    public function sanitize(string $html): string
    {
        if (trim($html) === '') {
            return '';
        }

        if (!class_exists(DOMDocument::class)) {
            throw new \RuntimeException('The DOM PHP extension is required for safe rich-text processing.');
        }

        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML(
            '<!doctype html><html><body><div id="openwiki-root">' . $html . '</div></body></html>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementById('openwiki-root');
        if (!$root) {
            return '';
        }

        $this->cleanChildren($root);

        $result = '';
        foreach ($root->childNodes as $child) {
            $result .= $document->saveHTML($child);
        }

        return $result;
    }

    private function cleanChildren(DOMNode $node): void
    {
        for ($child = $node->firstChild; $child !== null;) {
            $next = $child->nextSibling;

            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);
                if (!in_array($tag, self::ALLOWED_TAGS, true)) {
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);
                    $child = $next;
                    continue;
                }

                $allowed = array_merge(self::GLOBAL_ATTRIBUTES, self::TAG_ATTRIBUTES[$tag] ?? []);
                $toRemove = [];
                foreach ($child->attributes as $attribute) {
                    $name = strtolower($attribute->name);
                    if (!in_array($name, $allowed, true) || str_starts_with($name, 'on')) {
                        $toRemove[] = $attribute->name;
                        continue;
                    }

                    if (in_array($name, ['href', 'src'], true) && !$this->safeUrl($attribute->value, $tag === 'img')) {
                        $toRemove[] = $attribute->name;
                    }
                }

                foreach ($toRemove as $attributeName) {
                    $child->removeAttribute($attributeName);
                }

                if ($tag === 'a' && $child->getAttribute('target') === '_blank') {
                    $child->setAttribute('rel', 'noopener noreferrer');
                }

                $this->cleanChildren($child);
            }

            $child = $next;
        }
    }

    private function safeUrl(string $url, bool $allowDataImage): bool
    {
        $url = trim($url);
        if ($url === '' || str_starts_with($url, '/') || str_starts_with($url, '#')) {
            return true;
        }

        if ($allowDataImage && preg_match('#^data:image/(png|jpeg|gif|webp);base64,#i', $url)) {
            return true;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https', 'mailto'], true);
    }
}
