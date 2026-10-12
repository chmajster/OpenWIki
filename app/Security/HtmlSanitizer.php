<?php

declare(strict_types=1);

namespace OpenWiki\Security;

use DOMElement;
use DOMNode;
use Masterminds\HTML5;

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
        'target' => ['_blank', '_self'], 'loading' => ['lazy', 'eager'],
        'scope' => ['row', 'col', 'rowgroup', 'colgroup'],
        'data-type' => ['taskList', 'taskItem'], 'data-checked' => ['true', 'false'],
    ];
    private const PATTERNS = [
        'id' => '^[A-Za-z0-9_:.\\-]{1,200}$',
        'class' => '^[A-Za-z0-9_:.+ \\-]{0,500}$',
        'rel' => '^(?:(?:noopener|noreferrer|nofollow|ugc|sponsored) ?)*$',
        'data-language' => '^[A-Za-z0-9_+.\\-]{0,50}$',
        'data-file-type' => '^[A-Za-z0-9.+\\-]+/[A-Za-z0-9.+\\-]+$',
        'data-draft-file-id' => '^[A-Za-z0-9_\\-]{1,100}$',
    ];
    private const RANGES = [
        'colspan' => [1, 100], 'rowspan' => [1, 100],
        'width' => [1, 20000], 'height' => [1, 20000],
        'data-original-width' => [1, 20000], 'data-original-height' => [1, 20000],
        'data-attachment-id' => [1, PHP_INT_MAX], 'data-file-size' => [0, PHP_INT_MAX],
        'start' => [-2147483648, 2147483647],
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
        return ['tags' => self::ALLOWED_TAGS, 'global' => self::GLOBAL_ATTRIBUTES, 'attributes' => self::TAG_ATTRIBUTES,
            'values' => self::VALUES, 'patterns' => self::PATTERNS, 'ranges' => self::RANGES];
    }

    public function sanitize(string $html, bool $validateStructure = false): string
    {
        if (trim($html) === '') {
            return '';
        }

        // Parse fragments with HTML5 rules. A user-supplied closing wrapper or duplicate
        // id must never select a different root or escape the sanitization boundary.
        $parser = new HTML5();
        $root = $parser->loadHTMLFragment($html);
        $stack = [[$root, 0, false]];
        $count = 0;
        while ($stack !== []) {
            [$node, $depth, $visited] = array_pop($stack);
            if (!$visited) {
                if ($node instanceof DOMElement && $validateStructure && ($depth > 100 || ++$count > 50000)) {
                    throw new \InvalidArgumentException('Document structure exceeds the supported depth or element count.');
                }
                $stack[] = [$node, $depth, true];
                foreach ($node->childNodes as $child) $stack[] = [$child, $depth + 1, false];
            } elseif ($node !== $root) {
                $this->cleanNode($node);
            }
        }
        return $parser->saveHTML($root);
    }

    private function cleanNode(DOMNode $child): void
    {
            if ($child instanceof DOMElement) {
                $node = $child->parentNode;
                $tag = strtolower($child->tagName);
                if (!in_array($tag, self::ALLOWED_TAGS, true) || !in_array($child->namespaceURI, [null, 'http://www.w3.org/1999/xhtml'], true)) {
                    if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed', 'svg', 'math', 'template', 'noscript', 'xmp', 'plaintext'], true)
                        || !in_array($child->namespaceURI, [null, 'http://www.w3.org/1999/xhtml'], true)) {
                        $node->removeChild($child);
                        return;
                    }
                    // All descendants have already been checked in postorder.
                    while ($child->firstChild) {
                        $node->insertBefore($child->firstChild, $child);
                    }
                    $node->removeChild($child);
                    return;
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
                    if (isset(self::PATTERNS[$name]) && !preg_match('~' . self::PATTERNS[$name] . '~D', $attribute->value)) $toRemove[] = $attribute->name;
                    if (isset(self::RANGES[$name]) && (!preg_match('/^-?[0-9]{1,19}$/D', $attribute->value)
                        || (float) $attribute->value < self::RANGES[$name][0] || (float) $attribute->value > self::RANGES[$name][1])) $toRemove[] = $attribute->name;
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

            } elseif ($child->nodeType !== XML_TEXT_NODE) {
                $child->parentNode?->removeChild($child);
            }
    }

    private function safeUrl(string $url, bool $allowDataImage): bool
    {
        $url = trim($url);
        if (preg_match('/[\\x00-\\x20\\x7f\\\\\\\\]/', $url)) return false;
        if ($url === '' || (str_starts_with($url, '/') && !str_starts_with($url, '//')) || str_starts_with($url, '#')) {
            return true;
        }

        if ($allowDataImage && preg_match('#^data:image/(png|jpeg|gif|webp);base64,[A-Za-z0-9+/]+={0,2}$#iD', $url)) {
            return true;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        return in_array($scheme, $allowDataImage ? ['http', 'https'] : ['http', 'https', 'mailto'], true);
    }
}
