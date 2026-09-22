<?php

declare(strict_types=1);

namespace OpenWiki\Export;

use OpenWiki\Attachments\AttachmentService;
use OpenWiki\Core\Application;
use OpenWiki\Wiki\MacroService;
use ZipArchive;

final class DocumentExportService
{
    public function __construct(private readonly Application $app)
    {
    }

    public function pageHtml(array $page, array $space): string
    {
        $rendered = (new MacroService($this->app))->renderPage($page, $space);
        $title = $this->escape((string) $page['title']);
        $spaceName = $this->escape((string) $space['name']);

        return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . $title . '</title>'
            . '<style>body{font-family:system-ui,sans-serif;max-width:980px;margin:40px auto;padding:0 24px;line-height:1.6}'
            . 'pre{white-space:pre-wrap;background:#f4f4f4;padding:12px}table{border-collapse:collapse;width:100%}'
            . 'td,th{border:1px solid #ccc;padding:6px;text-align:left}small{color:#666}</style>'
            . '</head><body><header><p><small>' . $spaceName . '</small></p><h1>'
            . $title . '</h1></header><main>' . $rendered['html']
            . '</main></body></html>';
    }

    public function pageMarkdown(array $page): string
    {
        $markdown = trim((string) ($page['content_markdown'] ?? ''));
        if ($markdown === '') {
            $markdown = $this->htmlToMarkdown((string) $page['content_html']);
        }

        return '# ' . (string) $page['title'] . "\n\n" . trim($markdown) . "\n";
    }

    public function pagePdf(array $page, array $space): string
    {
        $rendered = (new MacroService($this->app))->renderPage($page, $space);
        $text = html_entity_decode(
            strip_tags(
                preg_replace('/<\/(?:p|div|h[1-6]|li|tr)>/iu', "\n", $rendered['html']) ?? $rendered['html']
            ),
            ENT_QUOTES | ENT_HTML5,
            'UTF-8'
        );
        $text = preg_replace('/[ \t]+/u', ' ', $text) ?? $text;
        $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;

        return (new PdfTextExporter())->build(
            (string) $page['title'],
            trim($text)
        );
    }

    public function spaceArchive(array $space, array $pages): array
    {
        if (!class_exists(ZipArchive::class)) {
            throw new \RuntimeException('PHP ZIP extension is not available.');
        }

        $tempRoot = $this->app->basePath('storage/temp');
        if (!is_dir($tempRoot) && !mkdir($tempRoot, 0770, true) && !is_dir($tempRoot)) {
            throw new \RuntimeException('Unable to create temporary export directory.');
        }

        $filename = 'space-' . $this->safeFileName((string) $space['space_key'])
            . '-' . gmdate('Ymd-His') . '.zip';
        $path = $tempRoot . '/' . bin2hex(random_bytes(8)) . '-' . $filename;

        $zip = new ZipArchive();
        $opened = $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
        if ($opened !== true) {
            throw new \RuntimeException('Unable to create Space export archive.');
        }

        try {
            $manifest = [
                'format' => 1,
                'space' => [
                    'id' => (int) $space['id'],
                    'key' => $space['space_key'],
                    'name' => $space['name'],
                    'exported_at' => gmdate(DATE_ATOM),
                ],
                'pages' => [],
            ];

            $indexItems = [];
            $attachments = new AttachmentService($this->app->database(), $this->app->basePath());

            foreach ($pages as $page) {
                $baseName = (int) $page['id'] . '-' . $this->safeFileName((string) $page['slug']);
                $htmlPath = 'pages/' . $baseName . '.html';
                $markdownPath = 'pages/' . $baseName . '.md';

                if (!$zip->addFromString($htmlPath, $this->pageHtml($page, $space))) {
                    throw new \RuntimeException('Unable to add HTML page to Space export.');
                }
                if (!$zip->addFromString($markdownPath, $this->pageMarkdown($page))) {
                    throw new \RuntimeException('Unable to add Markdown page to Space export.');
                }

                $manifest['pages'][] = [
                    'id' => (int) $page['id'],
                    'parent_id' => $page['parent_id'] === null ? null : (int) $page['parent_id'],
                    'title' => $page['title'],
                    'slug' => $page['slug'],
                    'status' => $page['status'],
                    'html' => $htmlPath,
                    'markdown' => $markdownPath,
                ];
                $indexItems[] = '<li><a href="' . $this->escape($htmlPath) . '">'
                    . $this->escape((string) $page['title']) . '</a></li>';

                foreach ($attachments->listForPage((int) $page['id']) as $attachment) {
                    try {
                        $source = $attachments->currentPath($attachment);
                        $attachmentName = 'attachments/' . $baseName . '/'
                            . (int) $attachment['id'] . '-'
                            . $this->safeFileName((string) $attachment['name']);
                        if (!$zip->addFile($source, $attachmentName)) {
                            throw new \RuntimeException('Unable to add attachment to Space export.');
                        }
                    } catch (\Throwable $exception) {
                        error_log('[OpenWiki space export attachment] ' . $exception->getMessage());
                    }
                }
            }

            $index = '<!doctype html><html><head><meta charset="utf-8"><title>'
                . $this->escape((string) $space['name'])
                . '</title></head><body><h1>' . $this->escape((string) $space['name'])
                . '</h1><ul>' . implode('', $indexItems) . '</ul></body></html>';

            $zip->addFromString('index.html', $index);
            $zip->addFromString(
                'manifest.json',
                json_encode(
                    $manifest,
                    JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
                ) . PHP_EOL
            );
        } finally {
            $zip->close();
        }

        if (!is_file($path) || filesize($path) === 0) {
            @unlink($path);
            throw new \RuntimeException('Space export archive is empty.');
        }

        @chmod($path, 0640);
        return ['path' => $path, 'filename' => $filename];
    }

    private function htmlToMarkdown(string $html): string
    {
        $markdown = $html;
        $markdown = preg_replace_callback(
            '/<pre[^>]*>\s*<code[^>]*>(.*?)<\/code>\s*<\/pre>/isu',
            static fn (array $match): string => "\n~~~\n"
                . html_entity_decode(strip_tags($match[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8')
                . "\n~~~\n",
            $markdown
        ) ?? $markdown;

        for ($level = 6; $level >= 1; $level--) {
            $markdown = preg_replace(
                '/<h' . $level . '[^>]*>(.*?)<\/h' . $level . '>/isu',
                "\n" . str_repeat('#', $level) . ' $1' . "\n",
                $markdown
            ) ?? $markdown;
        }

        $markdown = preg_replace('/<br\s*\/?>/iu', "\n", $markdown) ?? $markdown;
        $markdown = preg_replace('/<\/(?:p|div|blockquote|ul|ol|table)>/iu', "\n\n", $markdown) ?? $markdown;
        $markdown = preg_replace('/<li[^>]*>(.*?)<\/li>/isu', "- $1\n", $markdown) ?? $markdown;
        $markdown = preg_replace('/<(?:strong|b)[^>]*>(.*?)<\/(?:strong|b)>/isu', '**$1**', $markdown) ?? $markdown;
        $markdown = preg_replace('/<(?:em|i)[^>]*>(.*?)<\/(?:em|i)>/isu', '*$1*', $markdown) ?? $markdown;
        $markdown = preg_replace_callback(
            '/<code[^>]*>(.*?)<\/code>/isu',
            static fn (array $match): string => chr(96) . $match[1] . chr(96),
            $markdown
        ) ?? $markdown;
        $markdown = preg_replace_callback(
            '/<a[^>]+href=["\']([^"\']+)["\'][^>]*>(.*?)<\/a>/isu',
            static fn (array $match): string => '[' . strip_tags($match[2]) . '](' . $match[1] . ')',
            $markdown
        ) ?? $markdown;

        $markdown = html_entity_decode(strip_tags($markdown), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $markdown = preg_replace('/[ \t]+\n/u', "\n", $markdown) ?? $markdown;
        $markdown = preg_replace('/\n{3,}/u', "\n\n", $markdown) ?? $markdown;

        return trim($markdown);
    }

    private function safeFileName(string $value): string
    {
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', $value) ?? '';
        $safe = trim($safe, '.-_');

        return $safe === '' ? 'document' : substr($safe, 0, 120);
    }

    private function escape(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
