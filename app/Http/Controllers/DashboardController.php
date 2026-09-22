<?php

declare(strict_types=1);

namespace OpenWiki\Http\Controllers;

use OpenWiki\Core\Request;
use OpenWiki\Core\Response;
use OpenWiki\Http\Controller;
use OpenWiki\Permissions\SpaceAccessService;
use OpenWiki\Repositories\PageRepository;
use OpenWiki\Repositories\SpaceRepository;

final class DashboardController extends Controller
{
    public function index(Request $request): Response
    {
        $user = $this->app->auth()->user();
        $spaceRepository = new SpaceRepository($this->app->database());
        $pageRepository = new PageRepository($this->app->database());
        $access = new SpaceAccessService($this->app);

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

            if (!$access->canView($space)) {
                continue;
            }

            if ($page['status'] !== 'published') {
                if ($user === null) {
                    continue;
                }

                $ownsPage = (int) $page['owner_id'] === (int) $user['id']
                    || (int) $page['author_id'] === (int) $user['id'];
                if (!$ownsPage && !$access->canEdit($space)) {
                    continue;
                }
            }

            $recentUpdated[] = $page;
            if (count($recentUpdated) >= 10) {
                break;
            }
        }

        return $this->render('dashboard/index', [
            'title' => 'Dashboard',
            'spaces' => $spaces,
            'recentUpdated' => $recentUpdated,
            'recentViewed' => $user === null ? [] : $pageRepository->recentForUser((int) $user['id'], 10),
            'drafts' => $user === null ? [] : $pageRepository->draftsForUser((int) $user['id'], 10),
            'canCreateSpace' => $this->app->auth()->can('space.create'),
        ]);
    }
}
