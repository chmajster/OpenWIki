<?php
declare(strict_types=1);
namespace OpenWiki\Wiki;

use OpenWiki\Core\Database;
use OpenWiki\Search\SearchAccess;
use OpenWiki\Spaces\SpaceActor;

/** Batch policy for persisted events, including events created before resource metadata existed. */
final class NotificationAccess
{
    public function __construct(private readonly Database $db) {}

    public function visible(array $rows, int $user): array
    {
        if ($rows === []) return [];
        try { $actor = new SpaceActor($this->db, $user, null, false); }
        catch (\DomainException) { return []; }
        $legacy = []; $params = [];
        foreach ($rows as $row) if ($row['page_id'] === null && preg_match('~^/spaces/([^/]+)/pages/([^/?#]+)(?:[/?#]|$)~', (string) $row['target_url'], $m)) {
            $legacy[$row['id']] = [rawurldecode($m[1]), rawurldecode($m[2])];
            $params = [...$params, ...$legacy[$row['id']]];
        }
        $resolved = [];
        if ($params !== []) foreach ($this->db->fetchAll('SELECT p.id,p.slug,s.space_key FROM pages p INNER JOIN spaces s ON s.id=p.space_id WHERE ' . implode(' OR ', array_fill(0, count($legacy), '(s.space_key=? AND p.slug=?)')), $params) as $page) $resolved[$page['space_key'] . '/' . $page['slug']] = $page['id'];
        foreach ($rows as &$row) if ($row['page_id'] === null && isset($legacy[$row['id']])) {
            $row['page_id'] = $resolved[implode('/', $legacy[$row['id']])] ?? null;
            $row['working'] = str_contains((string) $row['target_url'], '/review') || str_contains((string) $row['target_url'], '/revisions/');
        }
        unset($row);
        $ids = array_values(array_unique(array_filter(array_map('intval', array_column($rows, 'page_id')))));
        if ($ids === []) return [];
        $pages = $this->db->fetchAll('SELECT id,space_id FROM pages WHERE id IN (' . implode(',', $ids) . ')');
        $access = new SearchAccess($this->db, array_column($pages, 'space_id'), $ids, $ids, $user, $actor->permissions(), true);
        $comments = []; $tasks = []; $revisions = [];
        foreach ($rows as $row) {
            if ($row['resource_type'] === 'comment') $comments[] = (int) $row['resource_id'];
            if ($row['resource_type'] === 'task') $tasks[] = (int) $row['resource_id'];
            if ($row['revision_number'] !== null) $revisions[] = [(int) $row['page_id'], (int) $row['revision_number']];
        }
        $resources = [];
        if ($comments !== []) foreach ($this->db->fetchAll('SELECT c.id,c.page_id,c.visibility,c.deleted_at,root.deleted_at AS root_deleted FROM comments c LEFT JOIN comments root ON root.id=c.thread_id WHERE c.id IN (' . implode(',', $comments) . ')') as $row) $resources['comment:' . $row['id']] = $row;
        if ($tasks !== []) foreach ($this->db->fetchAll('SELECT id,page_id,visibility,NULL AS deleted_at,NULL AS root_deleted FROM page_tasks WHERE id IN (' . implode(',', $tasks) . ')') as $row) $resources['task:' . $row['id']] = $row;
        $published = [];
        if ($revisions !== []) foreach ($this->db->fetchAll('SELECT page_id,revision_number,was_published FROM page_revisions WHERE ' . implode(' OR ', array_fill(0, count($revisions), '(page_id=? AND revision_number=?)')), array_merge(...$revisions)) as $row) $published[$row['page_id'] . ':' . $row['revision_number']] = (bool) $row['was_published'];
        return array_values(array_filter($rows, static function (array $row) use ($access, $resources, $published): bool {
            $page = (int) $row['page_id'];
            if (!$access->visible($page, (bool) $row['working'])) return false;
            if ($row['revision_number'] !== null && !isset($published[$page . ':' . $row['revision_number']])) return false;
            if ($row['revision_number'] !== null && !$published[$page . ':' . $row['revision_number']] && !$access->visible($page, true)) return false;
            if ($row['resource_type'] !== null) {
                $resource = $resources[$row['resource_type'] . ':' . $row['resource_id']] ?? null;
                if ($resource === null || (int) $resource['page_id'] !== $page || $resource['deleted_at'] !== null || $resource['root_deleted'] !== null) return false;
                if ($resource['visibility'] === 'working' && !$access->visible($page, true)) return false;
            }
            return true;
        }));
    }
}
