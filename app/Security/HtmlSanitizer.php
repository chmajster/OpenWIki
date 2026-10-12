<?php

declare(strict_types=1);

namespace OpenWiki\Security;

use DOMDocument;
use DOMElement;
use DOMNode;

final class HtmlSanitizer
{
    private const ALLOWED_TAGS = [
        'p', 'br', 'strong', 'b', 'em', 'i', 'u', 's', 'del', 'sup', 'sub', 'details', 'summary',
        'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
        'ul', 'ol', 'li', 'blockquote', 'pre', 'code',
        'a', 'img', 'figure', 'figcaption', 'table', 'thead', 'tbody', 'tr', 'th', 'td',
        'hr', 'div', 'span', 'section', 'aside',
    ];

    private const GLOBAL_ATTRIBUTES = ['id', 'class', 'title', 'aria-label', 'data-callout', 'data-align', 'data-text-color', 'data-highlight',
        'data-columns', 'data-column', 'data-disclosure', 'data-disclosure-content'];
    private const VALUES = [
        'data-align' => ['left', 'center', 'right', 'justify'],
        'data-text-color' => ['red', 'blue', 'green', 'orange', 'purple', 'gray'],
        'data-highlight' => ['yellow', 'green', 'blue', 'pink'],
        'data-cell-color' => ['yellow', 'green', 'blue', 'pink', 'gray'],
        'data-columns' => ['2', '3'], 'data-column' => ['true'], 'data-disclosure' => ['true'], 'data-disclosure-content' => ['true'],
        'data-diagram' => ['mermaid'], 'data-line-numbers' => ['true', 'false'], 'data-code-wrap' => ['true', 'false'],
        'data-callout' => ['info', 'note', 'warning', 'success'],
    ];
    private const TAG_ATTRIBUTES = [
        'ul' => ['data-type'],
        'ol' => ['start'],
        'li' => ['data-type', 'data-checked'],
        'a' => ['href', 'target', 'rel', 'data-draft-file-id', 'data-attachment-id', 'data-file-size', 'data-file-type'],
        'img' => [
            'src', 'alt', 'width', 'height', 'loading',
            'data-attachment-id', 'data-original-width', 'data-original-height',
            'data-draft-file-id',
        ],
        'td' => ['colspan', 'rowspan', 'colwidth', 'data-cell-color'],
        'th' => ['colspan', 'rowspan', 'scope', 'colwidth', 'data-cell-color'],
        'pre' => ['data-diagram', 'data-line-numbers', 'data-code-wrap'],
        'code' => ['data-language'],
    ];

    public static function policy(): array
    {
        return ['tags' => self::ALLOWED_TAGS, 'global' => self::GLOBAL_ATTRIBUTES, 'attributes' => self::TAG_ATTRIBUTES, 'values' => self::VALUES];
    }

    public function sanitize(string $html, bool $validateStructure = false): string
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
            '<!doctype html><html><head><meta charset="utf-8"></head><body><div id="openwiki-root">' . $html . '</div></body></html>',
            LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
        );
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $root = $document->getElementById('openwiki-root');
        if (!$root) {
            return '';
        }

        if ($validateStructure) {
            $stack = [[$root, 0]]; $count = 0;
            while ($stack !== []) {
                [$element, $depth] = array_pop($stack);
                if ($depth > 100 || ++$count > 50000) throw new \InvalidArgumentException('Document structure exceeds the supported depth or element count.');
                foreach ($element->childNodes as $child) if ($child instanceof DOMElement) $stack[] = [$child, $depth + 1];
            }
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
                    if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math'], true)) {
                        $node->removeChild($child);
                        $child = $next;
                        continue;
                    }
                    $this->cleanChildren($child);
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
                    if (isset(self::VALUES[$name]) && !in_array($attribute->value, self::VALUES[$name], true)) $toRemove[] = $attribute->name;
                    if ($name === 'colwidth' && (!preg_match('/^(?:0|[1-9][0-9]{0,3})(?:,(?:0|[1-9][0-9]{0,3})){0,29}$/D', $attribute->value)
                        || max(array_map('intval', explode(',', $attribute->value))) > 1600)) $toRemove[] = $attribute->name;
                    if (in_array($name, ['colspan', 'rowspan'], true) && (!ctype_digit($attribute->value) || (int) $attribute->value < 1 || (int) $attribute->value > 100)) $toRemove[] = $attribute->name;
                    if (in_array($name, ['width', 'height', 'data-original-width', 'data-original-height'], true)
                        && (!ctype_digit($attribute->value) || (int) $attribute->value < 1 || (int) $attribute->value > 20000)) $toRemove[] = $attribute->name;
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
