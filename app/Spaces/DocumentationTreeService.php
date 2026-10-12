<?php
declare(strict_types=1);
namespace OpenWiki\Spaces;

use OpenWiki\Core\Application;
use OpenWiki\Permissions\PageAclService;
use OpenWiki\Permissions\SpaceAccessService;

final class DocumentationTreeService
{
    public function __construct(private readonly Application $app) {}

    public function branch(array $space, ?int $userId, bool $super, bool $viewPermission, bool $movePermission, ?int $parent = null, string $query = '', int $offset = 0, bool $copyPermission = false, bool $siblingsOnly = false): array
    {
        return $this->app->database()->transaction(function () use ($space, $userId, $super, $viewPermission, $movePermission, $parent, $query, $offset, $copyPermission, $siblingsOnly): array {
            if (!(new SpaceAccessService($this->app))->canViewFor($space, $userId, $super)) throw new \OutOfBoundsException('Nie znaleziono przestrzeni.');
            [$acl, $rows, $visible] = $this->visible($space, $userId, $super, $viewPermission);
            if ($parent !== null && !isset($visible[$parent])) throw new \OutOfBoundsException('Nie znaleziono gałęzi.');
            $children = [];
            foreach ($visible as $row) $children[isset($visible[(int) ($row['parent_id'] ?? 0)]) ? (int) $row['parent_id'] : 0][] = (int) $row['id'];
            $ids = $children[$parent ?? 0] ?? [];
            $query = mb_substr(trim($query), 0, 100);
            if ($query !== '') {
                $allowed = $parent === null && !$siblingsOnly ? $visible : array_fill_keys($ids, true);
                $matches = $this->app->database()->fetchAll('SELECT id FROM pages WHERE space_id = ? AND deleted_at IS NULL AND title LIKE ? ORDER BY order_index, id', [$space['id'], '%' . $query . '%']);
                $ids = array_values(array_filter(array_map(static fn (array $row): int => (int) $row['id'], $matches), static fn (int $id): bool => isset($allowed[$id])));
            }
            if ($ids === []) return ['items' => [], 'next_offset' => null];
            $offset = max(0, $offset); $selected = array_slice($ids, $offset, 51);
            $more = count($selected) > 50; $selected = array_slice($selected, 0, 50);
            if ($selected === []) return ['items' => [], 'next_offset' => null];
            $placeholders = implode(',', array_fill(0, count($selected), '?'));
            $items = $this->app->database()->fetchAll('SELECT id, parent_id, title, slug, status, version, order_index FROM pages WHERE id IN (' . $placeholders . ') ORDER BY order_index, id', $selected);
            foreach ($items as &$item) {
                $id = (int) $item['id'];
                $item['id'] = $id;
                $item['space_id'] = (int) $space['id'];
                $item['parent_id'] = isset($visible[(int) ($item['parent_id'] ?? 0)]) ? (int) $item['parent_id'] : null;
                $item['has_children'] = isset($children[$id]);
                $item['url'] = self::url($space, $item);
                $item['can_move'] = $userId !== null && $acl->canFor($rows[$id], $space, 'page.move', $userId, $super, $movePermission);
                $item['can_copy'] = $userId !== null && $copyPermission;
            }
            unset($item);
            return ['items' => $items, 'next_offset' => $more ? $offset + 50 : null];
        });
    }

    public function breadcrumbs(array $space, array $page, ?int $userId, bool $super, bool $viewPermission): array
    {
        return $this->app->database()->transaction(function () use ($space, $page, $userId, $super, $viewPermission): array {
            [, $rows, $visible] = $this->visible($space, $userId, $super, $viewPermission);
            $path = []; $visited = []; $id = (int) ($page['parent_id'] ?? 0);
            while ($id > 0 && isset($rows[$id])) {
                if (isset($visited[$id])) throw new \RuntimeException('Wykryto cykl drzewa.');
                $visited[$id] = true;
                $path[] = isset($visible[$id]) ? $id : null;
                $id = (int) ($rows[$id]['parent_id'] ?? 0);
            }
            $ids = array_values(array_filter($path)); $titles = [];
            if ($ids !== []) $titles = array_column($this->app->database()->fetchAll('SELECT id, title, slug FROM pages WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', $ids), null, 'id');
            return array_map(static fn (?int $id): array => $id === null ? ['title' => 'Niedostępny poziom', 'url' => null] : ['title' => $titles[$id]['title'], 'url' => self::url($space, $titles[$id])], array_reverse($path));
        });
    }

    private function visible(array $space, ?int $userId, bool $super, bool $viewPermission): array
    {
        $acl = new PageAclService($this->app);
        $acl->snapshot($space, $userId, $super);
        // No document bodies or hidden names are loaded for branch authorization.
        $rows = array_column($this->app->database()->fetchAll('SELECT id, space_id, parent_id, inherit_acl, status, owner_id, author_id FROM pages WHERE space_id = ? AND deleted_at IS NULL ORDER BY order_index, id', [$space['id']]), null, 'id');
        $visible = array_filter($rows, fn (array $row): bool => $acl->canViewFor($row, $space, $userId, $super, $viewPermission));
        return [$acl, $rows, $visible];
    }

    public static function url(array $space, array $page): string
    {
        return '/spaces/' . rawurlencode($space['space_key']) . '/pages/' . rawurlencode($page['slug']);
    }
}
