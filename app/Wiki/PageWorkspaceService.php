<?php
declare(strict_types=1);
namespace OpenWiki\Wiki;

use OpenWiki\Core\Application;
use OpenWiki\Permissions\PageAclService;
use OpenWiki\Permissions\SpaceAccessService;
use OpenWiki\Repositories\PageRepository;
use OpenWiki\Repositories\SpaceRepository;

/** Read-only publication context. Names are loaded only after visibility checks. */
final class PageWorkspaceService
{
    public function __construct(private readonly Application $app) {}

    public function spaces(?array $page = null): array
    {
        $auth = $this->app->auth(); $user = $auth->user();
        if ($user === null) return [];
        $access = new SpaceAccessService($this->app);
        return array_values(array_map(static fn (array $space): array => array_intersect_key($space, array_flip(['id', 'name', 'space_key'])),
            array_filter((new SpaceRepository($this->app->database()))->visibleFor((int) $user['id'], $auth->can('*')),
                fn (array $space): bool => $access->canEdit($space) && ($page === null ? $auth->can('page.create') : (int) $space['id'] === (int) $page['space_id']))));
    }

    public function context(int $spaceId, ?array $page = null, ?int $parentId = null): array
    {
        $auth = $this->app->auth(); $user = $auth->user();
        $space = $this->app->database()->fetchOne('SELECT * FROM spaces WHERE id = ? AND deleted_at IS NULL', [$spaceId]);
        if ($user === null || $space === null || !(new SpaceAccessService($this->app))->canEdit($space)
            || ($page === null ? !$auth->can('page.create') : (int) $page['space_id'] !== $spaceId)) {
            throw new \DomainException('Nie masz dostępu do wybranej lokalizacji. Szkic pozostaje zachowany.');
        }
        return $this->app->database()->transaction(function () use ($space, $page, $parentId, $auth, $user): array {
            $acl = new PageAclService($this->app); $super = $auth->can('*'); $id = (int) $user['id'];
            $acl->snapshot($space, $id, $super);
            if ($page !== null && !$acl->canFor($page, $space, 'page.edit', $id, $super, $auth->can('page.edit'))) throw new \DomainException('Nie masz prawa edytować tej strony.');
            $canMove = $page === null || $acl->canFor($page, $space, 'page.move', $id, $super, $auth->can('page.move'));
            $repository = new PageRepository($this->app->database());
            $rows = $repository->tree((int) $space['id']); $byId = array_column($rows, null, 'id');
            $children = []; $blocked = [];
            foreach ($rows as $row) $children[(int) ($row['parent_id'] ?? 0)][] = (int) $row['id'];
            $pending = $page === null ? [] : [(int) $page['id']];
            while ($pending !== []) {
                $child = array_pop($pending); if (isset($blocked[$child])) continue;
                $blocked[$child] = true; array_push($pending, ...($children[$child] ?? []));
            }
            $parents = [];
            foreach ($rows as $row) {
                if (!$acl->canViewFor($row, $space, $id, $super, $auth->can('page.view'))) continue;
                if (isset($blocked[(int) $row['id']])) continue;
                if (!$canMove && (int) $row['id'] !== (int) ($page['parent_id'] ?? 0)) continue;
                if ($page === null && !$acl->canFor($row, $space, 'page.create', $id, $super, $auth->can('page.create'))) continue;
                $parents[] = ['id' => (int) $row['id'], 'title' => $row['title'], 'parent_id' => isset($byId[(int) ($row['parent_id'] ?? 0)])
                    && $acl->canViewFor($byId[(int) $row['parent_id']], $space, $id, $super, $auth->can('page.view')) ? (int) $row['parent_id'] : null];
            }
            $parent = $parentId === null ? null : ($byId[$parentId] ?? null);
            if ($parentId !== null && !in_array($parentId, array_column($parents, 'id'), true)) {
                // An unchanged restricted parent can be retained without exposing its metadata.
                if ($page === null || (int) ($page['parent_id'] ?? 0) !== $parentId) throw new \DomainException('Wybrany rodzic jest niedostępny. Szkic pozostaje zachowany.');
                $parent = null;
            }
            $candidate = array_replace($page ?? ['id' => 0, 'space_id' => (int) $space['id'], 'owner_id' => $id, 'author_id' => $id, 'inherit_acl' => 1, 'status' => 'draft'], ['parent_id' => $parentId]);
            $publish = $acl->explainFor($candidate, $space, 'page.publish', $id, $super, $auth->can('page.publish'));
            $view = $acl->explainFor($candidate, $space, 'page.view', $id, $super, $auth->can('page.view'));
            return ['space' => ['id' => (int) $space['id'], 'name' => $space['name'], 'space_key' => $space['space_key'], 'visibility' => $space['visibility']],
                'parents' => $parents, 'parent' => $parent === null ? null : ['id' => (int) $parent['id'], 'title' => $parent['title']],
                'can_publish' => !(bool) $space['review_workflow'] && $publish['allowed'], 'can_archive' => $acl->canFor($candidate, $space, 'page.archive', $id, $super, $auth->can('page.archive')),
                'can_change_parent' => $canMove, 'review_workflow' => (bool) $space['review_workflow'], 'publish_reason' => (bool) $space['review_workflow'] ? 'Przestrzeń wymaga osobnej recenzji konkretnej rewizji przed publikacją.' : $publish['reason'],
                'access' => ($parentId === null ? 'Dostęp według przestrzeni. ' : 'Dostęp dziedziczony po rodzicu; ograniczenia ACL pozostają w mocy. ') . $view['reason'],
                'user_id' => $id];
        });
    }
}
