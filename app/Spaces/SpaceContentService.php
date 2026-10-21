<?php

declare(strict_types=1);

namespace OpenWiki\Spaces;

use OpenWiki\Core\Database;
use OpenWiki\Permissions\PageAclService;
use OpenWiki\Permissions\SpaceAccessService;
use OpenWiki\Search\SearchInput;

final class SpaceContentService
{
    public function __construct(private readonly Database $database)
    {
    }

    public function browse(int $spaceId, array $filters, ?int $userId, array $permissions): array
    {
        return $this->database->transaction(function () use ($spaceId, $filters, $userId, $permissions): array {
            $space = $this->database->fetchOne('SELECT * FROM spaces WHERE id = ? AND deleted_at IS NULL', [$spaceId]);
            $super = in_array('*', $permissions, true);
            if ($space === null || !(new SpaceAccessService($this->database))->canViewFor($space, $userId, $super)) {
                throw new \OutOfBoundsException('Space not found');
            }
            $acl = new PageAclService($this->database);
            $acl->snapshot($space, $userId, $super);
            $where = 'p.space_id = ? AND p.deleted_at IS NULL';
            $params = [$spaceId];
            if ($filters['q'] !== '') {
                $where .= " AND p.title LIKE ? ESCAPE '!'";
                $params[] = SearchInput::like($filters['q']);
            }
            if ($filters['state'] !== 'all') { $where .= ' AND p.status = ?'; $params[] = $filters['state']; }
            $date = 'COALESCE(pub.created_at, p.updated_at)';
            $order = $filters['sort'] === 'updated' ? $date . ' DESC, p.title, p.id' : 'p.title, p.id';
            $from = ' FROM pages p LEFT JOIN page_revisions pub ON pub.page_id = p.id AND pub.revision_number = p.published_revision';
            // Hierarchy and authorization metadata only. Titles and bodies are not fetched for rejected candidates.
            $candidates = $this->database->fetchAll('SELECT p.id, p.space_id, p.parent_id, p.inherit_acl, p.status, p.owner_id, p.author_id' . $from . ' WHERE ' . $where . ' ORDER BY ' . $order, $params);
            $visible = [];
            foreach ($candidates as $page) {
                if ($acl->canViewFor($page, $space, $userId, $super, in_array('page.view', $permissions, true) || $super, $permissions)) $visible[] = (int) $page['id'];
            }
            $total = count($visible);
            $pages = max(1, (int) ceil($total / BrowseInput::PER_PAGE));
            $page = min($filters['page'], $pages);
            $selected = array_slice($visible, ($page - 1) * BrowseInput::PER_PAGE, BrowseInput::PER_PAGE);
            $items = $selected === [] ? [] : $this->database->fetchAll('SELECT p.id, p.title, p.slug, p.status, ' . $date . ' AS updated_at' . $from . ' WHERE p.id IN (' . implode(',', $selected) . ') ORDER BY ' . $order);
            foreach ($items as &$item) $item['url'] = DocumentationTreeService::url($space, $item);
            unset($item);
            return ['data' => $items, 'filters' => $filters, 'meta' => ['page' => $page, 'per_page' => BrowseInput::PER_PAGE, 'total' => $total, 'total_pages' => $pages]];
        });
    }
}
