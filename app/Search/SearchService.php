<?php
declare(strict_types=1);
namespace OpenWiki\Search;

use OpenWiki\Core\Database;
use OpenWiki\Repositories\SearchRepository;

final class SearchService
{
    public function __construct(private readonly Database $db)
    {
    }

    public function search(array $input, ?int $user, array $permissions): array
    {
        return $this->db->transaction(function () use ($input, $user, $permissions): array {
            $repository = new SearchRepository($this->db);
            $candidates = $repository->candidates($input['query'], $input['type'], $input['filters'], $user !== null);
            $spaceIds = array_filter(array_column($candidates, 'space_id'));
            $workingIds = array_column(array_filter($candidates, static fn ($row) => (bool) $row['working']), 'page_id');
            $access = new SearchAccess($this->db, $spaceIds, array_filter(array_column($candidates, 'page_id')), $workingIds, $user, $permissions);
            $visible = []; $tagPages = []; $tagWorking = []; $files = [];
            foreach ($candidates as $row) {
                $type = $row['type']; $pageId = (int) ($row['page_id'] ?? 0);
                if ($pageId !== 0 && !$access->visible($pageId, (bool) $row['working'])) continue;
                if ($type === 'comment' && (bool) $row['working'] && !$access->visibleWorking($pageId)) continue;
                if ($type === 'space' && !($access->spaces[(int) $row['space_id']]['search_visible'] ?? false)) continue;
                if ($type === 'user' && $user === null) continue;
                if ($type === 'attachment') {
                    $signature = $row['id'] . ':' . $row['attachment_version'] . ':' . $row['attachment_name'];
                    if (isset($files[$signature])) continue;
                    $files[$signature] = true;
                }
                $key = self::key($row);
                if ($type === 'tag') {
                    $tagPages[$key][$pageId] = true;
                    if ((bool) $row['working']) $tagWorking[$key][$pageId] = true;
                    $row['working'] = 0; $row['revision'] = 0; $row['branch'] = false;
                }
                $visible[$key] = $row;
            }
            unset($candidates);
            $counts = array_fill_keys(array_values(array_diff(SearchInput::TYPES, ['all'])), 0);
            foreach ($visible as $row) $counts[$row['type']]++;
            usort($visible, static fn (array $a, array $b): int => ((int) $b['relevance'] <=> (int) $a['relevance'])
                ?: strcmp((string) $b['updated_at'], (string) $a['updated_at'])
                ?: strcmp($a['type'], $b['type']) ?: ((int) $a['id'] <=> (int) $b['id'])
                ?: ((int) $a['working'] <=> (int) $b['working']) ?: ((int) $a['revision'] <=> (int) $b['revision']));
            $total = count($visible); $perPage = $input['per_page']; $page = $input['page'];
            $selected = array_slice($visible, ($page - 1) * $perPage, $perPage);
            unset($visible);
            $details = $repository->details($input['query'], $input['filters'], $selected);
            $data = [];
            foreach ($selected as $candidate) {
                $key = self::key($candidate); $row = $details[$key] ?? null;
                if ($row === null) throw new \RuntimeException('Search projection disappeared during a consistent read.');
                $type = $row['type']; $id = (int) $row['id']; $pageId = (int) ($row['page_id'] ?? 0);
                $space = $access->spaces[(int) ($row['space_id'] ?? 0)] ?? null;
                $snippet = trim(html_entity_decode(strip_tags((string) ($row['snippet'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                $result = ['type' => $type, 'id' => $id, 'key' => $key, 'result_key' => $key, 'title' => $row['title'], 'snippet' => $snippet,
                    'relevance' => (int) $row['relevance'], 'created_at' => $row['created_at'], 'updated_at' => $row['updated_at'],
                    'working' => (bool) $row['working'], 'revision' => (int) $row['revision'], 'url' => null, 'meta' => ''];
                if (in_array($type, ['page', 'comment', 'attachment'], true) && isset($row['author']) && (string) $row['author'] !== '') $result['author'] = (string) $row['author'];
                if ($space !== null) $result['space'] = ['id' => (int) $space['id'], 'key' => $space['space_key'], 'name' => $space['name']];
                if ($pageId !== 0) {
                    $result['page_id'] = $pageId; $result['path'] = $access->path($pageId);
                    if ($type === 'page' && (bool) $row['working'] && $result['path'] !== []) $result['path'][count($result['path']) - 1]['title'] = $row['title'];
                    $pageRow = $access->pages[$pageId]; $slug = $row['slug'] ?? $pageRow['slug'];
                    $privateRevision = $type === 'page' && (bool) $row['working'] && $pageRow['published_revision'] !== null;
                    $result['url'] = '/spaces/' . rawurlencode($space['space_key']) . '/pages/' . rawurlencode($privateRevision ? $pageRow['slug'] : $slug);
                    if ($privateRevision) $result['url'] .= '/revisions/' . $row['revision'];
                    $result['meta'] = $space['name'] . ' · ' . implode(' / ', array_column($result['path'], 'title'));
                    if ($type === 'page') { $result['slug'] = $slug; $result['status'] = $row['status']; }
                    if ($type === 'comment') { $result['url'] .= ((bool) $row['working'] ? '/revisions/' . $row['comment_revision'] : '') . ($row['comment_revision'] === null ? '#comment-' . $id : '#discussion-' . ($row['thread_id'] ?? $id)); $result['page_title'] = $row['title']; $result['author'] = $row['author']; }
                    if ($type === 'attachment') {
                        $legacy = !(bool) $space['review_workflow'] && $pageRow['status'] === 'published' && ($pageRow['published_revision'] === null || (int) $pageRow['published_revision'] === (int) $pageRow['version']);
                        $result['url'] = '/attachments/' . $id . '/download' . ((bool) $row['working'] || $legacy ? '' : '?revision=' . $row['revision']);
                        $result['page_title'] = $row['page_title']; $result['name'] = $row['name']; $result['mime_type'] = $row['mime_type']; $result['size_bytes'] = (int) $row['size_bytes'];
                        $result['snippet'] = $row['mime_type'] . ' · ' . number_format((int) $row['size_bytes'] / 1024, 1) . ' KiB';
                    }
                }
                if ($type === 'space') {
                    $result += ['name' => $row['title'], 'visibility' => $row['visibility'], 'status' => $row['status']];
                    $result['url'] = '/spaces/' . rawurlencode($row['key']); $result['meta'] = $row['key'];
                    $result['space_key'] = $row['key']; $result['key'] = $row['key']; $result['description'] = $snippet;
                }
                if ($type === 'user') {
                    foreach (['username', 'first_name', 'last_name', 'status', 'auth_source'] as $field) $result[$field] = $row[$field];
                    if ($access->can('user.manage')) $result['url'] = '/admin/users/' . $id . '/edit';
                }
                if ($type === 'tag') {
                    $result['name'] = $row['name']; $result['slug'] = $row['slug']; $result['page_count'] = count($tagPages[$key] ?? []);
                    $result['working_page_count'] = count($tagWorking[$key] ?? []);
                    $result['snippet'] = $result['page_count'] . ' dostępnych stron'; $result['url'] = '/tags/' . rawurlencode($row['slug']);
                    if ($result['working_page_count'] > 0) {
                        $result['url'] = '/search?' . http_build_query(['q'=>$row['name'],'type'=>'page','tag'=>$row['name'],'visibility'=>$input['filters']['visibility'] ?? 'all']);
                        $result['snippet'] .= ' · ' . $result['working_page_count'] . ' z dostępnymi wersjami roboczymi';
                    }
                    unset($result['space'], $result['path'], $result['page_id']); $result['meta'] = '';
                }
                $data[] = $result;
            }
            return ['data' => $data, 'meta' => ['page' => $page, 'per_page' => $perPage, 'total' => $total,
                'total_pages' => (int) ceil($total / $perPage), 'type' => $input['type'], 'counts' => $counts]];
        });
    }

    public static function key(array $row): string
    {
        // Tags are aggregated across accessible documents; page revisions remain distinct results.
        return $row['type'] . ':' . (int) $row['id'] . ':' . ($row['type'] === 'tag' ? '0:0' : (int) $row['working'] . ':' . (int) $row['revision']);
    }
}
