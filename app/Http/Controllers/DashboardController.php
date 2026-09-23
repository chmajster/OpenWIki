<?php

declare(strict_types=1);

namespace OpenWiki\Http\Controllers;

use OpenWiki\Core\Request;
use OpenWiki\Core\Response;
use OpenWiki\Http\Controller;
use OpenWiki\Permissions\PageAclService;
use OpenWiki\Permissions\SpaceAccessService;
use OpenWiki\Repositories\PageRepository;
use OpenWiki\Repositories\SpaceRepository;
use OpenWiki\Wiki\PageEngagementService;

final class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $this->app->auth()->user();
        $spaceRepository = new SpaceRepository($this->app->database());
        $pageRepository = new PageRepository($this->app->database());
        $access = new SpaceAccessService($this->app);
        $pageAccess = new PageAclService($this->app);

        $spaces = $spaceRepository->visibleFor(
            $user === null ? null : (int) $user['id'],
            $this->app->auth()->can('*')
        );

        $recentUpdated = [];
        foreach ($pageRepository->recentUpdated(30) as $page) {
            $space = [
                'id' => (int) $page['space_id'],
                'owner_id' => (int) $page['space_owner_id'],
                'visibility' => $page['visibility'],
                'status' => $page['space_status'],
                'deleted_at' => $page['space_deleted_at'],
            ];

            if (!$pageAccess->canView($page, $space)) {
                continue;
            }

            $recentUpdated[] = $page;
            if (count($recentUpdated) >= 10) {
                break;
            }
        }

        $filterAccessible = function (array $pages) use ($pageAccess): array {
            return array_values(array_filter($pages, function (array $page) use ($pageAccess): bool {
                $space = [
                    'id' => (int) $page['space_id'],
                    'owner_id' => (int) $page['space_owner_id'],
                    'visibility' => $page['visibility'],
                    'status' => $page['space_status'],
                    'deleted_at' => $page['space_deleted_at'],
                ];
                return $pageAccess->canView($page, $space);
            }));
        };

        return $this->render('dashboard/index', [
            'title' => 'Dashboard',
            'spaces' => $spaces,
            'recentUpdated' => $recentUpdated,
            'recentViewed' => $user === null ? [] : $filterAccessible(
                $pageRepository->recentForUser((int) $user['id'], 20)
            ),
            'favorites' => $user === null ? [] : $filterAccessible(
                (new PageEngagementService($this->app->database()))->favoritesForUser((int) $user['id'], 20)
            ),
            'drafts' => $user === null ? [] : $filterAccessible(
                $pageRepository->draftsForUser((int) $user['id'], 20)
            ),
            'canCreateSpace' => $this->app->auth()->can('space.create'),
        ]);
    }
}
