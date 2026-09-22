<?php

declare(strict_types=1);

namespace OpenWiki\Wiki;

final class MarkdownRenderer
{
    public function render(string $markdown): string
    {
        $markdown = str_replace(["\r\n", "\r"], "\n", $markdown);
        $tokens = [];

        $markdown = preg_replace_callback('/\x60{3}([A-Za-z0-9_+#.-]*)\n(.*?)\x60{3}/s', static function (array $match) use (&$tokens): string {
            $language = preg_replace('/[^A-Za-z0-9_+#.-]/', '', $match[1]) ?? '';
            $code = htmlspecialchars($match[2], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $token = '@@OPENWIKI_CODE_' . count($tokens) . '@@';
            $tokens[$token] = '<pre><code' . ($language !== '' ? ' data-language="' . htmlspecialchars($language, ENT_QUOTES, 'UTF-8') . '"' : '') . '>' . $code . '</code></pre>';
            return "\n" . $token . "\n";
        }, $markdown) ?? $markdown;

        $lines = explode("\n", $markdown);
        $html = [];
        $listType = null;

        foreach ($lines as $line) {
            if (isset($tokens[trim($line)])) {
                if ($listType !== null) {
                    $html[] = '</' . $listType . '>';
                    $listType = null;
                }
                $html[] = $tokens[trim($line)];
                continue;
            }

            if (preg_match('/^(#{1,6})\s+(.+)$/u', $line, $match)) {
                if ($listType !== null) {
                    $html[] = '</' . $listType . '>';
                    $listType = null;
                }
                $level = strlen($match[1]);
                $html[] = '<h' . $level . '>' . $this->inline($match[2]) . '</h' . $level . '>';
                continue;
            }

            if (preg_match('/^\s*[-*]\s+(.+)$/u', $line, $match)) {
                if ($listType !== 'ul') {
                    if ($listType !== null) {
                        $html[] = '</' . $listType . '>';
                    }
                    $html[] = '<ul>';
                    $listType = 'ul';
                }
                $html[] = '<li>' . $this->inline($match[1]) . '</li>';
                continue;
            }

            if (preg_match('/^\s*\d+[.)]\s+(.+)$/u', $line, $match)) {
                if ($listType !== 'ol') {
                    if ($listType !== null) {
                        $html[] = '</' . $listType . '>';
                    }
                    $html[] = '<ol>';
                    $listType = 'ol';
                }
                $html[] = '<li>' . $this->inline($match[1]) . '</li>';
                continue;
            }

            if ($listType !== null) {
                $html[] = '</' . $listType . '>';
                $listType = null;
            }

            if (preg_match('/^>\s?(.*)$/u', $line, $match)) {
                $html[] = '<blockquote>' . $this->inline($match[1]) . '</blockquote>';
                continue;
            }

            if (trim($line) === '---') {
                $html[] = '<hr>';
                continue;
            }

            if (trim($line) === '') {
                continue;
            }

            $html[] = '<p>' . $this->inline($line) . '</p>';
        }

        if ($listType !== null) {
            $html[] = '</' . $listType . '>';
        }

        return implode("\n", $html);
    }

    private function inline(string $value): string
    {
        $escaped = htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        $escaped = preg_replace_callback('/\[([^\]]+)\]\(([^\s)]+)\)/', function (array $match): string {
            $label = $match[1];
            $url = html_entity_decode($match[2], ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (!$this->safeUrl($url)) {
                return $label;
            }
            return '<a href="' . htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">' . $label . '</a>';
        }, $escaped) ?? $escaped;

        $escaped = preg_replace('/\*\*(.+?)\*\*/u', '<strong>$1</strong>', $escaped) ?? $escaped;
        $escaped = preg_replace('/~~(.+?)~~/u', '<del>$1</del>', $escaped) ?? $escaped;
        $escaped = preg_replace('/(?<!\*)\*([^*]+)\*(?!\*)/u', '<em>$1</em>', $escaped) ?? $escaped;
        $escaped = preg_replace('/\x60([^\x60]+)\x60/u', '<code>$1</code>', $escaped) ?? $escaped;

        return $escaped;
    }

    private function safeUrl(string $url): bool
    {
        if (str_starts_with($url, '/') || str_starts_with($url, '#')) {
            return true;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        return in_array($scheme, ['http', 'https', 'mailto'], true);
    }
}
