<?php

declare(strict_types=1);

namespace OpenWiki\Http\Controllers;

use OpenWiki\Core\Request;
use OpenWiki\Core\Response;
use OpenWiki\Http\Controller;
use OpenWiki\Permissions\PageAclService;
use OpenWiki\Repositories\PageRepository;
use OpenWiki\Repositories\SpaceRepository;
use OpenWiki\Wiki\PageEngagementService;

final class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $this->app->auth()->user();
        $view = $request->query('view', array_intersect(array_keys((array) $request->query()), ['attention', 'type', 'status', 'deadline', 'sort', 'space', 'page']) !== [] ? 'work' : 'home');
        if (!is_string($view) || !in_array($view, ['home', 'recent', 'favorites', 'work', 'spaces'], true)) {
            return $this->render('errors/404', ['title' => 'Page not found'], 404);
        }
        if ($user === null && in_array($view, ['recent', 'favorites', 'work'], true)) return Response::redirect('/login');
        try { $workCenter = $user === null || !in_array($view, ['home', 'work'], true) ? null : (new \OpenWiki\Wiki\WorkCenterService($this->app->database()))->items((array)$request->query(),(int)$user['id']); }
        catch (\InvalidArgumentException $error) {
            return $this->render('errors/422', ['title' => 'Invalid filters', 'message' => $error->getMessage(), 'dashboardView' => 'work'], 422);
        }
        $spaceRepository = new SpaceRepository($this->app->database());
        $pageRepository = new PageRepository($this->app->database());
        $pageAccess = new PageAclService($this->app);

        $spaces = $spaceRepository->visibleFor(
            $user === null ? null : (int) $user['id'],
            $this->app->auth()->can('*')
        );

        $recentUpdated = [];
        foreach ($view === 'home' ? $pageRepository->recentUpdated(30) : [] as $page) {
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
            'title' => ['home' => 'Home', 'recent' => 'Recently viewed', 'favorites' => 'Favorites', 'work' => 'Your work', 'spaces' => 'Spaces'][$view],
            'dashboardView' => $view,
            'workCenter' => $workCenter,
            'spaces' => $spaces,
            'recentUpdated' => $recentUpdated,
            'recentViewed' => $user === null || !in_array($view, ['home', 'recent'], true) ? [] : $filterAccessible(
                $pageRepository->recentForUser((int) $user['id'], 20)
            ),
            'favorites' => $user === null || $view !== 'favorites' ? [] : $filterAccessible(
                (new PageEngagementService($this->app->database()))->favoritesForUser((int) $user['id'], 20)
            ),
            'canCreateSpace' => $this->app->auth()->can('space.create'),
        ]);
    }
}
