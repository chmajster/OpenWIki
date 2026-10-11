<?php

declare(strict_types=1);

namespace OpenWiki\Wiki;

use DOMDocument;
use DOMElement;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;

final class MarkdownRenderer
{
    private readonly MarkdownConverter $converter;

    public function __construct()
    {
        $environment = new Environment(['html_input' => 'escape', 'allow_unsafe_links' => false,
            'max_nesting_level' => 50, 'max_delimiters_per_line' => 1000]);
        $environment->addExtension(new CommonMarkCoreExtension());
        $environment->addExtension(new GithubFlavoredMarkdownExtension());
        $this->converter = new MarkdownConverter($environment);
    }

    public function render(string $markdown): string
    {
        $html = trim((string) $this->converter->convert($markdown));
        if ($html === '') return '';
        $document = new DOMDocument('1.0', 'UTF-8');
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML('<!doctype html><html><head><meta charset="utf-8"></head><body><div id="markdown-root">' . $html . '</div></body></html>');
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $root = $document->getElementById('markdown-root');
        foreach ($root->getElementsByTagName('code') as $code) {
            if (preg_match('/(?:^|\s)language-([A-Za-z0-9_+#.-]+)/', $code->getAttribute('class'), $match)) {
                $code->setAttribute('data-language', $match[1]);
                $code->removeAttribute('class');
            }
        }
        // Store task state as passive HTML; checkbox controls belong to the editor model.
        foreach (iterator_to_array($root->getElementsByTagName('input')) as $input) {
            $item = $input->parentNode;
            while ($item instanceof DOMElement && $item->tagName !== 'li' && $item !== $root) $item = $item->parentNode;
            if ($item instanceof DOMElement && $item->tagName === 'li') {
                $item->setAttribute('data-type', 'taskItem');
                $item->setAttribute('data-checked', $input->hasAttribute('checked') ? 'true' : 'false');
                if ($item->parentNode instanceof DOMElement && $item->parentNode->tagName === 'ul') $item->parentNode->setAttribute('data-type', 'taskList');
            }
            $input->parentNode?->removeChild($input);
        }
        // GFM allows ordinary bullets and tasks in one list. Split contiguous runs so
        // both remain representable without silently turning bullets into checkboxes.
        foreach (iterator_to_array($root->getElementsByTagName('ul')) as $list) {
            $items = array_values(array_filter(iterator_to_array($list->childNodes),
                static fn ($node): bool => $node instanceof DOMElement && $node->tagName === 'li'));
            $kinds = array_unique(array_map(static fn (DOMElement $item): bool => $item->getAttribute('data-type') === 'taskItem', $items));
            if (count($kinds) < 2) continue;
            $previousKind = null;
            foreach ($items as $item) {
                $kind = $item->getAttribute('data-type') === 'taskItem';
                if ($kind !== $previousKind) {
                    $run = $document->createElement('ul');
                    if ($kind) $run->setAttribute('data-type', 'taskList');
                    $list->parentNode->insertBefore($run, $list);
                    $previousKind = $kind;
                }
                $run->appendChild($item);
            }
            $list->parentNode->removeChild($list);
        }
        $result = '';
        foreach ($root->childNodes as $node) $result .= $document->saveHTML($node);
        return trim($result);
    }
}
