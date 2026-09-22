<?php

declare(strict_types=1);

namespace OpenWiki\Wiki;

use OpenWiki\Core\Application;
use OpenWiki\Permissions\PageAclService;

final class MacroService
{
    public function __construct(private readonly Application $app)
    {
    }

    public function renderPage(array $page, array $space): array
    {
        [$html, $headings] = $this->anchorHeadings((string) $page['content_html']);
        $toc = $this->tocHtml($headings);
        $registry = $this->registry($page, $space, $toc);

        $blockPattern = '/<p>\s*\{\{([a-z0-9-]+)(?::([^{}]*))?\}\}\s*<\/p>/isu';
        $html = preg_replace_callback(
            $blockPattern,
            fn (array $match): string => $this->renderMacro($registry, $match),
            $html
        ) ?? $html;

        $segments = preg_split(
            '/(<pre\b[^>]*>.*?<\/pre>|<code\b[^>]*>.*?<\/code>)/isu',
            $html,
            -1,
            PREG_SPLIT_DELIM_CAPTURE
        );

        if (is_array($segments)) {
            foreach ($segments as $index => $segment) {
                if (preg_match('/^<(?:pre|code)\b/iu', $segment) === 1) {
                    continue;
                }

                $segments[$index] = preg_replace_callback(
                    '/\{\{([a-z0-9-]+)(?::([^{}]*))?\}\}/isu',
                    fn (array $match): string => $this->renderMacro($registry, $match),
                    $segment
                ) ?? $segment;
            }
            $html = implode('', $segments);
        }

        return [
            'html' => $html,
            'toc' => $toc,
            'headings' => $headings,
        ];
    }

    private function registry(array $page, array $space, string $toc): array
    {
        return [
            'toc' => fn (?string $argument): string => $toc !== ''
                ? $toc
                : '<div class="macro-empty">No headings on this page.</div>',

            'child-pages' => fn (?string $argument): string => $this->childPages($page, $space),

            'page-properties' => fn (?string $argument): string => $this->pageProperties($page, $space),

            'attachments' => fn (?string $argument): string => $this->attachments((int) $page['id']),

            'recent-updates' => fn (?string $argument): string => $this->recentUpdates($space),

            'user-profile' => fn (?string $argument): string => $this->userProfile((string) $argument),

            'status' => fn (?string $argument): string => '<span class="macro-status">'
                . $this->escape($this->macroText($argument, 'Status'))
                . '</span>',

            'info' => fn (?string $argument): string => $this->callout(
                'info',
                $this->macroText($argument, 'Information')
            ),

            'warning' => fn (?string $argument): string => $this->callout(
                'warning',
                $this->macroText($argument, 'Warning')
            ),

            'note' => fn (?string $argument): string => $this->callout(
                'note',
                $this->macroText($argument, 'Note')
            ),

            'code' => fn (?string $argument): string => '<pre class="macro-code"><code>'
                . $this->escape((string) $argument)
                . '</code></pre>',
        ];
    }

    private function renderMacro(array $registry, array $match): string
    {
        $name = strtolower((string) ($match[1] ?? ''));
        if (!isset($registry[$name])) {
            return (string) $match[0];
        }

        $argument = isset($match[2])
            ? html_entity_decode(trim((string) $match[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8')
            : null;

        try {
            return (string) $registry[$name]($argument);
        } catch (\Throwable $exception) {
            error_log('[OpenWiki macro ' . $name . '] ' . $exception->getMessage());
            return '<span class="macro-error">Macro unavailable</span>';
        }
    }

    private function anchorHeadings(string $html): array
    {
        $headings = [];
        $used = [];

        $rendered = preg_replace_callback(
            '/<h([1-6])([^>]*)>(.*?)<\/h\1>/isu',
            function (array $match) use (&$headings, &$used): string {
                $level = (int) $match[1];
                $attributes = (string) $match[2];
                $inner = (string) $match[3];
                $title = trim(html_entity_decode(
                    strip_tags($inner),
                    ENT_QUOTES | ENT_HTML5,
                    'UTF-8'
                ));

                if ($title === '') {
                    return (string) $match[0];
                }

                $anchor = null;
                if (preg_match('/\sid=(["\'])([^"\']+)\1/iu', $attributes, $idMatch) === 1) {
                    $anchor = (string) $idMatch[2];
                }

                if ($anchor === null || $anchor === '') {
                    $base = $this->slug($title);
                    $anchor = $base;
                    $counter = 2;
                    while (isset($used[$anchor])) {
                        $anchor = $base . '-' . $counter;
                        $counter++;
                    }
                    $attributes .= ' id="' . $this->escape($anchor) . '"';
                }

                $used[$anchor] = true;
                $headings[] = [
                    'level' => $level,
                    'title' => $title,
                    'anchor' => $anchor,
                ];

                return '<h' . $level . $attributes . '>' . $inner . '</h' . $level . '>';
            },
            $html
        );

        return [is_string($rendered) ? $rendered : $html, $headings];
    }

    private function tocHtml(array $headings): string
    {
        if (count($headings) < 2) {
            return '';
        }

        $items = [];
        foreach ($headings as $heading) {
            $items[] = '<li class="toc-level-' . (int) $heading['level'] . '">'
                . '<a href="#' . $this->escape((string) $heading['anchor']) . '">'
                . $this->escape((string) $heading['title'])
                . '</a></li>';
        }

        return '<nav class="page-toc-nav" aria-label="Table of contents">'
            . '<strong>Table of contents</strong>'
            . '<ol>' . implode('', $items) . '</ol>'
            . '</nav>';
    }

    private function childPages(array $page, array $space): string
    {
        $rows = $this->app->database()->fetchAll(
            'SELECT id, space_id, parent_id, inherit_acl, title, slug, status, owner_id, author_id
             FROM pages
             WHERE parent_id = :parent_id AND deleted_at IS NULL
             ORDER BY order_index ASC, title ASC',
            ['parent_id' => (int) $page['id']]
        );

        $access = new PageAclService($this->app);
        $items = [];
        foreach ($rows as $child) {
            if (!$access->canView($child, $space)) {
                continue;
            }

            $items[] = '<li><a href="/spaces/'
                . rawurlencode((string) $space['space_key'])
                . '/pages/' . rawurlencode((string) $child['slug']) . '">'
                . $this->escape((string) $child['title'])
                . '</a></li>';
        }

        return $items === []
            ? '<div class="macro-empty">No accessible child pages.</div>'
            : '<ul class="macro-list">' . implode('', $items) . '</ul>';
    }

    private function pageProperties(array $page, array $space): string
    {
        $rows = [
            'Space' => (string) $space['name'],
            'Status' => (string) $page['status'],
            'Version' => (string) $page['version'],
            'Author' => (string) ($page['author_username'] ?? $page['author_id']),
            'Updated' => (string) $page['updated_at'],
        ];

        $html = '<table class="macro-properties"><tbody>';
        foreach ($rows as $label => $value) {
            $html .= '<tr><th>' . $this->escape($label) . '</th><td>'
                . $this->escape($value) . '</td></tr>';
        }

        return $html . '</tbody></table>';
    }

    private function attachments(int $pageId): string
    {
        $rows = $this->app->database()->fetchAll(
            'SELECT id, name, mime_type, size_bytes
             FROM attachments
             WHERE page_id = :page_id AND deleted_at IS NULL
             ORDER BY name ASC',
            ['page_id' => $pageId]
        );

        if ($rows === []) {
            return '<div class="macro-empty">No attachments.</div>';
        }

        $items = [];
        foreach ($rows as $row) {
            $items[] = '<li><a href="/attachments/' . (int) $row['id'] . '/download">'
                . $this->escape((string) $row['name'])
                . '</a> <small>' . $this->escape((string) $row['mime_type']) . '</small></li>';
        }

        return '<ul class="macro-list">' . implode('', $items) . '</ul>';
    }

    private function recentUpdates(array $space): string
    {
        $rows = $this->app->database()->fetchAll(
            'SELECT id, space_id, parent_id, inherit_acl, title, slug, status,
                    owner_id, author_id, updated_at
             FROM pages
             WHERE space_id = :space_id AND deleted_at IS NULL
             ORDER BY updated_at DESC
             LIMIT 20',
            ['space_id' => (int) $space['id']]
        );

        $access = new PageAclService($this->app);
        $items = [];
        foreach ($rows as $row) {
            if (!$access->canView($row, $space)) {
                continue;
            }

            $items[] = '<li><a href="/spaces/'
                . rawurlencode((string) $space['space_key'])
                . '/pages/' . rawurlencode((string) $row['slug']) . '">'
                . $this->escape((string) $row['title'])
                . '</a> <small>' . $this->escape((string) $row['updated_at']) . '</small></li>';

            if (count($items) >= 10) {
                break;
            }
        }

        return $items === []
            ? '<div class="macro-empty">No accessible recent updates.</div>'
            : '<ul class="macro-list">' . implode('', $items) . '</ul>';
    }

    private function userProfile(string $username): string
    {
        $username = trim($username);
        if ($username === '') {
            return '<span class="macro-error">Username required</span>';
        }

        $user = $this->app->database()->fetchOne(
            'SELECT username, first_name, last_name
             FROM users
             WHERE LOWER(username) = LOWER(:username)
               AND status = "active"
               AND deleted_at IS NULL
             LIMIT 1',
            ['username' => $username]
        );

        if ($user === null) {
            return '<span class="macro-error">User not found</span>';
        }

        $name = trim((string) ($user['first_name'] ?? '') . ' ' . (string) ($user['last_name'] ?? ''));
        return '<span class="macro-user"><strong>'
            . $this->escape((string) $user['username'])
            . '</strong>'
            . ($name === '' ? '' : ' · ' . $this->escape($name))
            . '</span>';
    }

    private function callout(string $type, string $text): string
    {
        return '<div class="macro-callout macro-callout--' . $this->escape($type) . '">'
            . $this->escape($text)
            . '</div>';
    }

    private function macroText(?string $argument, string $fallback): string
    {
        $text = trim((string) $argument);
        return $text === '' ? $fallback : $text;
    }

    private function slug(string $value): string
    {
        $value = mb_strtolower(trim($value));
        $value = preg_replace('/[^\p{L}\p{N}]+/u', '-', $value) ?? '';
        $value = trim($value, '-');

        return $value === '' ? 'section' : mb_substr($value, 0, 120);
    }

    private function escape(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
