<?php
declare(strict_types=1);
namespace OpenWiki\Wiki;

use OpenWiki\Core\Database;
use OpenWiki\Permissions\PageAclService;
use OpenWiki\Permissions\SpaceAccessService;
use OpenWiki\Search\SearchInput;

final class CollaborationAudience
{
    public function __construct(private readonly Database $db) {}

    public function eligible(array $context, ?int $revision, bool $working, string $query = '', ?array $ids = null): array
    {
        $params = []; $where = 'status="active" AND deleted_at IS NULL';
        if ($ids !== null) {
            $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
            if ($ids === []) return [];
            $where .= ' AND id IN (' . implode(',', $ids) . ')';
        } else { $where .= " AND username LIKE ? ESCAPE '!'"; $params[] = '%' . SearchInput::like(mb_substr($query,0,100)) . '%'; }
        $users = $this->db->fetchAll('SELECT id,username,first_name,last_name FROM users WHERE ' . $where . ' ORDER BY username' . ($ids === null ? ' LIMIT 100' : ''), $params);
        if ($users === []) return [];
        $userIds = implode(',', array_map('intval', array_column($users,'id'))); $principals = []; $permissions = [];
        foreach ($users as $user) $principals[$user['id']] = ['user:' . $user['id'] => true];
        foreach ($this->db->fetchAll('SELECT user_id,role_id AS id FROM user_roles WHERE user_id IN (' . $userIds . ')') as $row) $principals[$row['user_id']]['role:' . $row['id']] = true;
        foreach ($this->db->fetchAll('SELECT user_id,group_id AS id FROM group_users WHERE user_id IN (' . $userIds . ')') as $row) $principals[$row['user_id']]['group:' . $row['id']] = true;
        foreach ($this->db->fetchAll('SELECT ur.user_id,p.name FROM user_roles ur INNER JOIN role_permissions rp ON rp.role_id=ur.role_id INNER JOIN permissions p ON p.id=rp.permission_id WHERE ur.user_id IN (' . $userIds . ')') as $row) $permissions[$row['user_id']][$row['name']] = true;
        $space = $context['space']; $page = $context['page']; $pages = [$page['id'] => $page]; $current = $page;
        while ($current['parent_id'] !== null && !isset($pages[$current['parent_id']])) {
            $current = $this->db->fetchOne('SELECT id,space_id,parent_id,inherit_acl,owner_id,author_id,status,deleted_at,published_revision,version FROM pages WHERE id=? AND space_id=?', [$current['parent_id'],$space['id']]);
            if ($current === null) break;
            $pages[$current['id']] = $current;
        }
        $spaceRules = $this->db->fetchAll('SELECT * FROM space_acl WHERE space_id=?', [$space['id']]);
        $pageRules = $this->db->fetchAll('SELECT * FROM page_acl WHERE page_id IN (' . implode(',', array_map('intval', array_keys($pages))) . ')');
        $result = [];
        foreach ($users as $user) {
            $id = (int) $user['id']; $set = $permissions[$id] ?? []; $super = isset($set['*']); $ps = $principals[$id];
            $rules = array_filter($spaceRules, static fn ($rule) => isset($ps[$rule['principal_type'] . ':' . $rule['principal_id']]));
            $view = SpaceAccessService::evaluate($space,$id,'space.view',$super,true,array_values(array_filter($rules,static fn ($r) => $r['permission']==='space.view')))['allowed'];
            $edit = SpaceAccessService::evaluate($space,$id,'space.edit',$super,true,array_values(array_filter($rules,static fn ($r) => $r['permission']==='space.edit')))['allowed'];
            $acl = new PageAclService($this->db); $acl->simulate($space,array_values($pages),$pageRules,$id,$ps,$view,$edit);
            if (!$acl->canViewFor($page,$space,$id,$super,isset($set['page.view']),array_keys($set))) continue;
            if ($working && !$acl->canReadWorkingFor($page,$space,$id,$super,array_keys($set))) continue;
            if ($revision !== null && !(bool) $context['source']['was_published'] && !$acl->canReadWorkingFor($page,$space,$id,$super,array_keys($set))) continue;
            $result[] = $user;
        }
        return $result;
    }
}
