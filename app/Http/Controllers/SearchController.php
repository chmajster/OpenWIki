<?php

declare(strict_types=1);

namespace OpenWiki\Http\Controllers;

use OpenWiki\Core\Request;
use OpenWiki\Core\Response;
use OpenWiki\Http\Controller;
use OpenWiki\Permissions\PageAclService;
use OpenWiki\Permissions\SpaceAccessService;
use OpenWiki\Repositories\SearchRepository;
use OpenWiki\Security\RateLimiter;
use OpenWiki\Wiki\WikiMetadataService;

final class SearchController extends Controller
{
    private const TYPES = ['all', 'page', 'space', 'user', 'comment', 'attachment', 'tag'];

    public function index(Request $request): Response
    {
        $query = trim((string) $request->query('q', ''));
        $type = strtolower(trim((string) $request->query('type', 'all')));
        $rawFilters = [
            'space' => trim((string) $request->query('space', '')),
            'author' => trim((string) $request->query('author', '')),
            'tag' => trim((string) $request->query('tag', '')),
            'created_from' => trim((string) $request->query('created_from', '')),
            'created_to' => trim((string) $request->query('created_to', '')),
            'updated_from' => trim((string) $request->query('updated_from', '')),
            'updated_to' => trim((string) $request->query('updated_to', '')),
        ];

        if (!in_array($type, self::TYPES, true)) {
            return $this->renderSearch(
                $query,
                'all',
                $rawFilters,
                [],
                'Invalid search result type.',
                422
            );
        }

        try {
            $filters = $this->normalizedFilters($rawFilters);
        } catch (\InvalidArgumentException $exception) {
            return $this->renderSearch(
                $query,
                $type,
                $rawFilters,
                [],
                $exception->getMessage(),
                422
            );
        }

        if ($query === '') {
            return $this->renderSearch($query, $type, $rawFilters, []);
        }
        if (mb_strlen($query) > 200) {
            return $this->renderSearch(
                $query,
                $type,
                $rawFilters,
                [],
                'Search query is too long.',
                422
            );
        }

        $user = $this->app->auth()->user();
        $key = $user === null ? 'ip:' . $request->ip() : 'user:' . $user['id'];
        $limiter = new RateLimiter($this->app->database());

        if ($limiter->tooManyAttempts('search', $key, 60, 60)) {
            return $this->renderSearch(
                $query,
                $type,
                $rawFilters,
                [],
                'Search rate limit exceeded. Try again shortly.',
                429
            );
        }
        $limiter->hit('search', $key);

        $repository = new SearchRepository($this->app->database());
        $pageAccess = new PageAclService($this->app);
        $spaceAccess = new SpaceAccessService($this->app);
        $results = [];

        if ($type === 'all' || $type === 'page') {
            foreach ($repository->searchPages($query, $filters, 150) as $row) {
                $space = $this->spaceFromPageRow($row);
                if (!$pageAccess->canView($row, $space)) {
                    continue;
                }

                $text = trim((string) $row['content_text']);
                $results[] = $this->result(
                    'page',
                    (string) $row['title'],
                    '/spaces/' . rawurlencode((string) $row['space_key'])
                        . '/pages/' . rawurlencode((string) $row['slug']),
                    $this->snippet($text),
                    'Page · ' . $row['space_name'] . ' · ' . $row['author_username'],
                    (int) $row['relevance'],
                    (string) $row['created_at'],
                    (string) $row['updated_at']
                );
            }
        }

        if (($type === 'all' || $type === 'space') && $rawFilters['author'] === '' && $rawFilters['tag'] === '') {
            foreach ($repository->searchSpaces($query, $filters, 100) as $row) {
                if (!$spaceAccess->canView($row)) {
                    continue;
                }

                $results[] = $this->result(
                    'space',
                    (string) $row['name'],
                    '/spaces/' . rawurlencode((string) $row['space_key']),
                    $this->snippet(trim((string) ($row['description'] ?? ''))),
                    'Space · ' . $row['space_key'] . ' · ' . $row['visibility'],
                    (int) $row['relevance'],
                    (string) $row['created_at'],
                    (string) $row['updated_at']
                );
            }
        }

        if (
            $user !== null
            && ($type === 'all' || $type === 'user')
            && $rawFilters['space'] === ''
            && $rawFilters['tag'] === ''
        ) {
            foreach ($repository->searchUsers($query, $filters, 100) as $row) {
                $displayName = trim(
                    (string) ($row['first_name'] ?? '') . ' ' . (string) ($row['last_name'] ?? '')
                );

                $url = $this->app->auth()->can('user.manage')
                    ? '/admin/users/' . (int) $row['id'] . '/edit'
                    : null;

                $results[] = $this->result(
                    'user',
                    (string) $row['username'],
                    $url,
                    $displayName,
                    'User · ' . $row['auth_source'] . ' · ' . $row['status'],
                    (int) $row['relevance'],
                    (string) $row['created_at'],
                    (string) $row['updated_at']
                );
            }
        }

        if ($type === 'all' || $type === 'comment') {
            foreach ($repository->searchComments($query, $filters, 150) as $row) {
                $space = $this->spaceFromPageRow($row);
                if (!$pageAccess->canView($row, $space)) {
                    continue;
                }

                $plain = html_entity_decode(
                    strip_tags((string) $row['body_html']),
                    ENT_QUOTES | ENT_HTML5,
                    'UTF-8'
                );
                $results[] = $this->result(
                    'comment',
                    'Comment on ' . (string) $row['title'],
                    '/spaces/' . rawurlencode((string) $row['space_key'])
                        . '/pages/' . rawurlencode((string) $row['slug'])
                        . '#comment-' . (int) $row['comment_id'],
                    $this->snippet(trim($plain)),
                    'Comment · ' . $row['space_name'] . ' · ' . $row['comment_author_username'],
                    (int) $row['relevance'],
                    (string) $row['created_at'],
                    (string) $row['updated_at']
                );
            }
        }

        if ($type === 'all' || $type === 'attachment') {
            foreach ($repository->searchAttachments($query, $filters, 150) as $row) {
                $space = $this->spaceFromPageRow($row);
                if (!$pageAccess->canView($row, $space)) {
                    continue;
                }

                $results[] = $this->result(
                    'attachment',
                    (string) $row['attachment_name'],
                    '/attachments/' . (int) $row['attachment_id'] . '/download',
                    (string) $row['mime_type'] . ' · ' . $this->formatBytes((int) $row['size_bytes']),
                    'Attachment · ' . $row['space_name'] . ' · ' . $row['title'],
                    (int) $row['relevance'],
                    (string) $row['created_at'],
                    (string) $row['updated_at']
                );
            }
        }

        if ($type === 'all' || $type === 'tag') {
            $metadata = new WikiMetadataService($this->app->database());
            foreach ($repository->searchTags($query, 100) as $row) {
                if ($rawFilters['tag'] !== '') {
                    $requested = mb_strtolower($rawFilters['tag']);
                    if (
                        mb_strtolower((string) $row['name']) !== $requested
                        && mb_strtolower((string) $row['slug']) !== $requested
                    ) {
                        continue;
                    }
                }

                $visiblePages = 0;
                foreach ($metadata->pagesForTag((string) $row['slug']) as $tagPage) {
                    $space = $this->spaceFromPageRow($tagPage);
                    if (
                        $pageAccess->canView($tagPage, $space)
                        && $this->pageMatchesRawFilters($tagPage, $rawFilters)
                    ) {
                        $visiblePages++;
                    }
                }

                if ($visiblePages === 0) {
                    continue;
                }

                $results[] = $this->result(
                    'tag',
                    (string) $row['name'],
                    '/tags/' . rawurlencode((string) $row['slug']),
                    $visiblePages . ' accessible page' . ($visiblePages === 1 ? '' : 's'),
                    'Tag',
                    (int) $row['relevance'],
                    (string) $row['created_at'],
                    (string) $row['created_at']
                );
            }
        }

        usort($results, static function (array $left, array $right): int {
            $relevance = $right['relevance'] <=> $left['relevance'];
            if ($relevance !== 0) {
                return $relevance;
            }

            return strcmp((string) $right['sort_date'], (string) $left['sort_date']);
        });

        $results = array_slice($results, 0, 100);

        return $this->renderSearch($query, $type, $rawFilters, $results);
    }

    private function normalizedFilters(array $raw): array
    {
        $filters = [
            'space' => mb_substr((string) $raw['space'], 0, 191),
            'author' => mb_substr((string) $raw['author'], 0, 100),
            'tag' => mb_substr((string) $raw['tag'], 0, 100),
        ];

        foreach ([
            'created_from' => false,
            'created_to' => true,
            'updated_from' => false,
            'updated_to' => true,
        ] as $key => $endOfDay) {
            $filters[$key] = $this->normalizeDate((string) $raw[$key], $endOfDay, $key);
        }

        return $filters;
    }

    private function normalizeDate(string $value, bool $endOfDay, string $field): string
    {
        if ($value === '') {
            return '';
        }

        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $value, $match) !== 1) {
            throw new \InvalidArgumentException('Invalid date filter: ' . $field . '.');
        }
        if (!checkdate((int) $match[2], (int) $match[3], (int) $match[1])) {
            throw new \InvalidArgumentException('Invalid date filter: ' . $field . '.');
        }

        return $value . ($endOfDay ? ' 23:59:59' : ' 00:00:00');
    }

    private function pageMatchesRawFilters(array $page, array $filters): bool
    {
        if ($filters['space'] !== '') {
            $needle = mb_strtolower($filters['space']);
            $haystack = mb_strtolower(
                (string) ($page['space_key'] ?? '') . ' ' . (string) ($page['space_name'] ?? '')
            );
            if (!str_contains($haystack, $needle)) {
                return false;
            }
        }

        if ($filters['author'] !== '') {
            $author = mb_strtolower((string) ($page['author_username'] ?? ''));
            if (!str_contains($author, mb_strtolower($filters['author']))) {
                return false;
            }
        }

        foreach ([
            'created_from' => ['created_at', '>='],
            'created_to' => ['created_at', '<='],
            'updated_from' => ['updated_at', '>='],
            'updated_to' => ['updated_at', '<='],
        ] as $filterKey => [$pageKey, $operator]) {
            if ($filters[$filterKey] === '' || empty($page[$pageKey])) {
                continue;
            }
            $boundary = $filters[$filterKey] . (
                str_ends_with($filterKey, '_to') ? ' 23:59:59' : ' 00:00:00'
            );
            if ($operator === '>=' && (string) $page[$pageKey] < $boundary) {
                return false;
            }
            if ($operator === '<=' && (string) $page[$pageKey] > $boundary) {
                return false;
            }
        }

        return true;
    }

    private function spaceFromPageRow(array $row): array
    {
        return [
            'id' => (int) $row['space_id'],
            'owner_id' => (int) $row['space_owner_id'],
            'visibility' => $row['visibility'] ?? $row['space_visibility'] ?? 'private',
            'status' => $row['space_status'],
            'deleted_at' => $row['space_deleted_at'],
        ];
    }

    private function result(
        string $type,
        string $title,
        ?string $url,
        string $snippet,
        string $meta,
        int $relevance,
        string $createdAt,
        string $updatedAt
    ): array {
        return [
            'type' => $type,
            'title' => $title,
            'url' => $url,
            'snippet' => $snippet,
            'meta' => $meta,
            'relevance' => $relevance,
            'created_at' => $createdAt,
            'updated_at' => $updatedAt,
            'sort_date' => $updatedAt !== '' ? $updatedAt : $createdAt,
        ];
    }

    private function snippet(string $text): string
    {
        $text = preg_replace('/\s+/u', ' ', trim($text)) ?? trim($text);
        return mb_strlen($text) > 260 ? mb_substr($text, 0, 257) . '...' : $text;
    }

    private function formatBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1024 * 1024) {
            return number_format($bytes / 1024, 1) . ' KiB';
        }

        return number_format($bytes / 1024 / 1024, 1) . ' MiB';
    }

    private function renderSearch(
        string $query,
        string $type,
        array $filters,
        array $results,
        ?string $error = null,
        int $status = 200
    ): Response {
        return $this->render('search/index', [
            'title' => 'Search',
            'query' => $query,
            'type' => $type,
            'filters' => $filters,
            'results' => $results,
            'searchError' => $error,
            'types' => self::TYPES,
        ], $status);
    }
}
