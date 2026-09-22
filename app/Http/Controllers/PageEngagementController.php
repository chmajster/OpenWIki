<?php

declare(strict_types=1);

namespace OpenWiki\Http\Controllers;

use OpenWiki\Core\Request;
use OpenWiki\Core\Response;
use OpenWiki\Core\Session;
use OpenWiki\Http\Controller;
use OpenWiki\Permissions\SpaceAccessService;
use OpenWiki\Repositories\PageRepository;
use OpenWiki\Repositories\SpaceRepository;
use OpenWiki\Wiki\PageEngagementService;

final class PageEngagementController extends Controller
{
    public function favorite(Request $request, string $spaceKey, string $slug): Response
    {
        if (($failure = $this->requireAuth($request)) !== null) {
            return $failure;
        }
        if (($failure = $this->verifyCsrf($request)) !== null) {
            return $failure;
        }

        [$space, $page, $failure] = $this->page($spaceKey, $slug);
        if ($failure !== null) {
            return $failure;
        }

        $user = $this->app->auth()->user();
        $enabled = filter_var($request->input('enabled', true), FILTER_VALIDATE_BOOL);

        (new PageEngagementService($this->app->database()))->setFavorite(
            (int) $user['id'],
            (int) $page['id'],
            $enabled
        );

        Session::flash('success', $enabled ? 'Page added to favorites.' : 'Page removed from favorites.');
        return Response::redirect($this->pageUrl($space, $page));
    }

    public function watch(Request $request, string $spaceKey, string $slug): Response
    {
        if (($failure = $this->requireAuth($request)) !== null) {
            return $failure;
        }
        if (($failure = $this->verifyCsrf($request)) !== null) {
            return $failure;
        }

        [$space, $page, $failure] = $this->page($spaceKey, $slug);
        if ($failure !== null) {
            return $failure;
        }

        $user = $this->app->auth()->user();
        $enabled = filter_var($request->input('enabled', true), FILTER_VALIDATE_BOOL);

        (new PageEngagementService($this->app->database()))->setWatch(
            (int) $user['id'],
            'page',
            (int) $page['id'],
            $enabled
        );

        Session::flash('success', $enabled ? 'You are watching this page.' : 'Page watch disabled.');
        return Response::redirect($this->pageUrl($space, $page));
    }

    public function watchSpace(Request $request, string $spaceKey): Response
    {
        if (($failure = $this->requireAuth($request)) !== null) {
            return $failure;
        }
        if (($failure = $this->verifyCsrf($request)) !== null) {
            return $failure;
        }

        $space = (new SpaceRepository($this->app->database()))->findByKey($spaceKey);
        if ($space === null) {
            return $this->render('errors/404', ['title' => 'Space not found'], 404);
        }

        if (!(new SpaceAccessService($this->app))->canView($space)) {
            return $this->render('errors/403', ['title' => 'Permission denied'], 403);
        }

        $user = $this->app->auth()->user();
        $enabled = filter_var($request->input('enabled', true), FILTER_VALIDATE_BOOL);

        (new PageEngagementService($this->app->database()))->setWatch(
            (int) $user['id'],
            'space',
            (int) $space['id'],
            $enabled
        );

        Session::flash('success', $enabled ? 'You are watching this space.' : 'Space watch disabled.');
        return Response::redirect('/spaces/' . rawurlencode($space['space_key']));
    }

    private function page(string $spaceKey, string $slug): array
    {
        $space = (new SpaceRepository($this->app->database()))->findByKey($spaceKey);
        if ($space === null) {
            return [null, null, $this->render('errors/404', ['title' => 'Space not found'], 404)];
        }

        $access = new SpaceAccessService($this->app);
        if (!$access->canView($space)) {
            return [null, null, $this->render('errors/403', ['title' => 'Permission denied'], 403)];
        }

        $page = (new PageRepository($this->app->database()))->findBySlug((int) $space['id'], $slug);
        if ($page === null) {
            return [null, null, $this->render('errors/404', ['title' => 'Page not found'], 404)];
        }

        if ($page['status'] !== 'published') {
            $user = $this->app->auth()->user();
            $owns = $user !== null && (
                (int) $page['owner_id'] === (int) $user['id']
                || (int) $page['author_id'] === (int) $user['id']
            );

            if (!$owns && !$access->canEdit($space)) {
                return [null, null, $this->render('errors/403', ['title' => 'Permission denied'], 403)];
            }
        }

        return [$space, $page, null];
    }

    private function pageUrl(array $space, array $page): string
    {
        return '/spaces/' . rawurlencode($space['space_key'])
            . '/pages/' . rawurlencode($page['slug']);
    }
}
