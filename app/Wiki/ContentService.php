<?php

declare(strict_types=1);

namespace OpenWiki\Wiki;

use OpenWiki\Security\HtmlSanitizer;

final class ContentService
{
    public const SCHEMA_VERSION = 1;

    public static function storedSource(?array $row): ?array
    {
        if ($row === null || ($row['document_json'] ?? null) === null) return $row;
        $source = json_decode($row['document_json'], true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($source) || ($source['version'] ?? null) !== self::SCHEMA_VERSION
            || !in_array($source['format'] ?? null, ['visual', 'markdown'], true) || !is_string($source['source'] ?? null)) {
            throw new \RuntimeException('Unsupported stored document schema.');
        }
        $row['content_format'] = $source['format'];
        $row[$source['format'] === 'visual' ? 'content_html' : 'content_markdown'] = $source['source'];
        return $row;
    }

    public function document(array $input): array
    {
        if (($input['version'] ?? null) !== self::SCHEMA_VERSION || !is_string($input['format'] ?? null)
            || !is_string($input['source'] ?? null) || array_diff(array_keys($input), ['version', 'format', 'source'])) {
            throw new \InvalidArgumentException('Invalid document schema or unsupported version.');
        }
        return $this->normalize($input['format'], $input['format'] === 'visual' ? $input['source'] : '',
            $input['format'] === 'markdown' ? $input['source'] : '');
    }
    public function __construct(
        private readonly HtmlSanitizer $sanitizer = new HtmlSanitizer(),
        private readonly MarkdownRenderer $markdown = new MarkdownRenderer()
    ) {
    }

    public function normalize(string $format, string $html, string $markdown, bool $validate = true): array
    {
        if (!in_array($format, ['visual', 'markdown'], true)) {
            throw new \InvalidArgumentException('Unsupported content format.');
        }

        if ($format === 'markdown') {
            if ($validate && mb_strlen($markdown) > 2_000_000) {
                throw new \InvalidArgumentException('Page content is too large.');
            }
            $cleanHtml = $this->sanitizer->sanitize($this->markdown->render($markdown), $validate);
            $storedMarkdown = $markdown;
        } else {
            if ($validate && mb_strlen($html) > 2_000_000) {
                throw new \InvalidArgumentException('Page content is too large.');
            }
            $cleanHtml = $this->sanitizer->sanitize($html, $validate);
            $storedMarkdown = null;
        }

        $text = html_entity_decode(strip_tags($cleanHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;

        return [
            'html' => $cleanHtml,
            'markdown' => $storedMarkdown,
            'text' => trim($text),
            'format' => $format,
            'document' => ['version' => self::SCHEMA_VERSION, 'format' => $format,
                'source' => $format === 'visual' ? $cleanHtml : $storedMarkdown],
        ];
    }

    public function recoveryCopy(mixed $input): ?array
    {
        if ($input === null) return null;
        if (!is_array($input)) throw new \InvalidArgumentException('Invalid conversion recovery copy.');
        foreach (['format', 'html', 'markdown'] as $key) {
            if (array_key_exists($key, $input) && !is_string($input[$key])) {
                throw new \InvalidArgumentException('Conversion recovery fields must be strings.');
            }
        }
        $safe = $this->normalize($input['format'] ?? 'visual', $input['html'] ?? '', $input['markdown'] ?? '');
        return ['format' => $safe['format'], 'html' => $safe['format'] === 'visual' ? $safe['html'] : '', 'markdown' => $safe['markdown'] ?? ''];
    }

    public function convert(string $format, string $target, string $html, string $markdown): array
    {
        $source = $this->normalize($format, $html, $markdown);
        if (!in_array($target, ['visual', 'markdown'], true)) throw new \InvalidArgumentException('Unsupported target format.');
        if ($target === 'visual') return ['format' => 'visual', 'html' => $source['html'], 'markdown' => $source['markdown'], 'lossy' => false];
        if ($format === 'markdown') return $source + ['lossy' => false];
        return (new HtmlMarkdownConverter())->convert($source['html']) + ['format' => 'markdown', 'before_html' => $source['html']];
    }
}
