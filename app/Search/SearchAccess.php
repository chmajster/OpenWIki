<?php
declare(strict_types=1);
namespace OpenWiki\Search;

use OpenWiki\Core\Database;
use OpenWiki\Permissions\PageAclService;
use OpenWiki\Permissions\SpaceAccessService;

/** Request-local ACL snapshot. It contains metadata only and is never shared between users or requests. */
final class SearchAccess
{
    public array $spaces = [];
    public array $pages = [];
    private array $visible = [];
    private array $working = [];

    public function __construct(Database $db, array $spaceIds, array $pageIds, array $workingIds, private readonly ?int $user, private readonly array $permissions)
    {
        if ($spaceIds === []) return;
        $spaceIds = array_values(array_unique(array_map('intval', $spaceIds)));
        // Integer IDs originate in database rows, not user input; avoid the native statement parameter limit.
        $ids = implode(',', $spaceIds);
        $this->spaces = array_column($db->fetchAll('SELECT * FROM spaces WHERE id IN (' . $ids . ') AND deleted_at IS NULL'), null, 'id');
        $principals = $user === null ? [] : ['user:' . $user => true];
        if ($user !== null) {
            foreach (['role' => ['user_roles', 'role_id'], 'group' => ['group_users', 'group_id']] as $kind => [$table, $column]) {
                foreach ($db->fetchAll('SELECT ' . $column . ' AS id FROM ' . $table . ' WHERE user_id = ?', [$user]) as $row) $principals[$kind . ':' . $row['id']] = true;
            }
        }
        $spaceRules = [];
        foreach ($db->fetchAll('SELECT space_id,principal_type,principal_id,permission,effect FROM space_acl WHERE space_id IN (' . $ids . ')') as $row) {
            if (isset($principals[$row['principal_type'] . ':' . $row['principal_id']])) $spaceRules[$row['space_id']][$row['permission']][] = $row;
        }
        $grouped = [];
        $pending = array_values(array_unique(array_map('intval', $pageIds)));
        // Fetch candidate metadata and ancestors in breadth-first batches, never every unrelated page in a space.
        while ($pending !== []) {
            $parents = [];
            foreach ($db->fetchAll('SELECT id,space_id,parent_id,title,slug,inherit_acl,owner_id,author_id,status,deleted_at,version,published_revision FROM pages WHERE id IN (' . implode(',', $pending) . ') AND space_id IN (' . $ids . ')') as $row) {
                $this->pages[(int) $row['id']] = $row;
                $grouped[$row['space_id']][] = $row;
                if ($row['parent_id'] !== null) $parents[(int) $row['parent_id']] = true;
            }
            $pending = array_keys(array_diff_key($parents, $this->pages));
        }
        $pageRules = [];
        if ($this->pages !== []) foreach ($db->fetchAll('SELECT a.page_id,a.principal_type,a.principal_id,a.permission,a.effect,a.inherit_to_children,p.space_id FROM page_acl a INNER JOIN pages p ON p.id = a.page_id WHERE a.page_id IN (' . implode(',', array_keys($this->pages)) . ')') as $row) $pageRules[$row['space_id']][] = $row;
        $acl = new PageAclService($db);
        $workingNeeded = array_fill_keys(array_map('intval', $workingIds), true);
        foreach ($this->spaces as $id => &$space) {
            $view = SpaceAccessService::evaluate($space, $user, 'space.view', $this->can('*'), true, $spaceRules[$id]['space.view'] ?? [])['allowed'];
            $edit = $user !== null && SpaceAccessService::evaluate($space, $user, 'space.edit', $this->can('*'), true, $spaceRules[$id]['space.edit'] ?? [])['allowed'];
            $space['search_visible'] = $view;
            $acl->simulate($space, $grouped[$id] ?? [], $pageRules[$id] ?? [], $user, $principals, $view, $edit);
            foreach ($grouped[$id] ?? [] as $page) {
                if ($page['deleted_at'] !== null) continue;
                $this->visible[(int) $page['id']] = $acl->canViewFor($page, $space, $user, $this->can('*'), $this->can('page.view'), $permissions);
                // Working content is a separate resource even if the public projection remains readable.
                if ($this->visible[(int) $page['id']] && isset($workingNeeded[(int) $page['id']])) {
                    $this->working[(int) $page['id']] = $acl->canReadWorkingFor($page, $space, $user, $this->can('*'), $permissions)
                        || (!(bool) $space['review_workflow'] && $page['published_revision'] === null && $page['status'] !== 'published');
                }
            }
        }
        unset($space);
    }

    public function can(string $permission): bool { return in_array('*', $this->permissions, true) || in_array($permission, $this->permissions, true); }
    public function visible(int $id, bool $working = false): bool { return $working ? ($this->working[$id] ?? false) : ($this->visible[$id] ?? false); }

    public function path(int $id): array
    {
        $path = []; $seen = [];
        while (isset($this->pages[$id]) && !isset($seen[$id])) {
            $seen[$id] = true; $page = $this->pages[$id];
            if ($this->visible($id)) array_unshift($path, ['id' => $id, 'title' => $page['title'], 'slug' => $page['slug']]);
            $id = (int) ($page['parent_id'] ?? 0);
        }
        return $path;
    }
}
