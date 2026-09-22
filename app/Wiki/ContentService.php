<?php

declare(strict_types=1);

namespace OpenWiki\Wiki;

use OpenWiki\Security\HtmlSanitizer;

final class ContentService
{
    public function __construct(
        private readonly HtmlSanitizer $sanitizer = new HtmlSanitizer(),
        private readonly MarkdownRenderer $markdown = new MarkdownRenderer()
    ) {
    }

    public function normalize(string $format, string $html, string $markdown): array
    {
        if (!in_array($format, ['visual', 'markdown'], true)) {
            throw new \InvalidArgumentException('Unsupported content format.');
        }

        if ($format === 'markdown') {
            if (mb_strlen($markdown) > 2_000_000) {
                throw new \InvalidArgumentException('Page content is too large.');
            }
            $cleanHtml = $this->sanitizer->sanitize($this->markdown->render($markdown));
            $storedMarkdown = $markdown;
        } else {
            if (mb_strlen($html) > 2_000_000) {
                throw new \InvalidArgumentException('Page content is too large.');
            }
            $cleanHtml = $this->sanitizer->sanitize($html);
            $storedMarkdown = null;
        }

        $text = html_entity_decode(strip_tags($cleanHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return [
            'html' => $cleanHtml,
            'markdown' => $storedMarkdown,
            'text' => trim($text),
            'format' => $format,
        ];
    }
}
