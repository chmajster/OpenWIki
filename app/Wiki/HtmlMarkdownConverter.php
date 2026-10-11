<?php

declare(strict_types=1);

namespace OpenWiki\Wiki;

use DOMDocument;
use DOMElement;
use DOMNode;
use DOMText;

final class HtmlMarkdownConverter
{
    public function convert(string $safeHtml): array
    {
        $root = $this->root($safeHtml);
        $markdown = trim($this->children($root));
        $rendered = (new ContentService())->normalize('markdown', '', $markdown)['html'];
        return ['markdown' => $markdown, 'html' => $rendered,
            'lossy' => $this->signature($root) !== $this->signature($this->root($rendered))];
    }

    private function root(string $html): DOMElement
    {
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<!doctype html><html><head><meta charset="utf-8"></head><body><div id="conversion-root">' . $html . '</div></body></html>');
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        return $document->getElementById('conversion-root');
    }

    private function children(DOMNode $node): string
    {
        $result = '';
        foreach ($node->childNodes as $child) $result .= $this->node($child);
        return $result;
    }

    private function node(DOMNode $node): string
    {
        if ($node instanceof DOMText) {
            return preg_replace('/([\\\\*`~\[\]])/u', '\\\\$1', $node->nodeValue ?? '') ?? '';
        }
        if (!$node instanceof DOMElement) return '';
        $tag = strtolower($node->tagName);
        $text = $this->children($node);
        if (preg_match('/^h([1-6])$/', $tag, $match)) return str_repeat('#', (int) $match[1]) . ' ' . trim($text) . "\n\n";
        return match ($tag) {
            'p', 'div', 'section', 'aside', 'figure', 'figcaption', 'table', 'tr' => trim($text) . "\n\n",
            'strong', 'b' => '**' . $text . '**',
            'em', 'i' => '*' . $text . '*',
            'del', 's' => '~~' . $text . '~~',
            'pre' => "```" . ($node->firstChild instanceof DOMElement ? $node->firstChild->getAttribute('data-language') : '') . "\n" . rtrim($node->textContent, "\n") . "\n```\n\n",
            'code' => '`' . $node->textContent . '`',
            'blockquote' => '> ' . str_replace("\n", "\n> ", trim($text)) . "\n\n",
            'ul', 'ol' => $this->list($node) . "\n",
            'a' => $node->hasAttribute('href') ? '[' . $text . '](' . str_replace([' ', ')'], ['%20', '%29'], $node->getAttribute('href')) . ')' : $text,
            'img' => $node->getAttribute('alt'),
            'br' => "\n",
            'hr' => "---\n\n",
            default => $text,
        };
    }

    private function list(DOMElement $node): string
    {
        $result = '';
        $index = 1;
        foreach ($node->childNodes as $child) {
            if (!$child instanceof DOMElement) continue;
            $result .= ($node->tagName === 'ol' ? $index++ . '. ' : '- ') . trim($this->children($child)) . "\n";
        }
        return $result;
    }

    private function signature(DOMNode $node): string
    {
        if ($node instanceof DOMText) {
            for ($ancestor = $node->parentNode; $ancestor instanceof DOMElement; $ancestor = $ancestor->parentNode) {
                if (in_array(strtolower($ancestor->tagName), ['pre', 'code'], true)) return $node->nodeValue ?? '';
            }
            return preg_replace('/\s+/u', ' ', $node->nodeValue ?? '') ?? '';
        }
        if (!$node instanceof DOMElement) return '';
        $tag = strtolower($node->tagName);
        $isRoot = $node->getAttribute('id') === 'conversion-root';
        $hasBlock = false;
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMElement
                && !(in_array(strtolower($child->tagName), ['p', 'div'], true) && trim($child->textContent) === '' && $child->getElementsByTagName('img')->length === 0)
                && in_array(strtolower($child->tagName), ['p', 'div', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6', 'ul', 'ol', 'pre', 'blockquote', 'table', 'figure', 'hr', 'section', 'aside'], true)) $hasBlock = true;
        }
        if (!$isRoot && in_array($tag, ['p', 'div'], true) && trim($node->textContent) === '' && $node->getElementsByTagName('img')->length === 0) return '';
        if (!$isRoot && $tag === 'div' && !$hasBlock) $tag = 'p';
        $tag = ['b' => 'strong', 'i' => 'em', 's' => 'del'][$tag] ?? $tag;
        $attributes = [];
        foreach ($node->attributes as $attribute) {
            if ($attribute->name === 'class' && in_array($attribute->value, ['wiki-link', 'wiki-link wiki-link--broken'], true)) continue;
            if ($attribute->name === 'id' && $attribute->value === 'conversion-root') continue;
            $attributes[$attribute->name] = $attribute->value;
        }
        ksort($attributes);
        $children = '';
        foreach ($node->childNodes as $child) {
            if ($child instanceof DOMText && trim($child->nodeValue ?? '') === '' && in_array($tag, ['div', 'ul', 'ol'], true)) continue;
            $children .= $this->signature($child);
        }
        if ($isRoot && !$hasBlock && $children !== '') $children = '<p[]>' . $children . '</p>';
        return '<' . $tag . json_encode($attributes) . '>' . $children . '</' . $tag . '>';
    }
}
