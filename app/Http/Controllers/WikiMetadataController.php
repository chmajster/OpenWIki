<?php

declare(strict_types=1);

namespace OpenWiki\Http\Controllers;

use OpenWiki\Core\Request;
use OpenWiki\Core\Response;
use OpenWiki\Http\Controller;
use OpenWiki\Permissions\SpaceAccessService;
use OpenWiki\Repositories\SpaceRepository;
use OpenWiki\Wiki\WikiMetadataService;

final class WikiMetadataController extends Controller
{
    public function resolve(Request $request, string $spaceKey, string $reference): Response
    {
        $space = (new SpaceRepository($this->app->database()))->findByKey($spaceKey);
        if ($space === null) {
            return $this->render('errors/404', ['title' => 'Space not found'], 404);
        }

        $access = new SpaceAccessService($this->app);
        if (!$access->canView($space)) {
            return $this->render('errors/404', ['title' => 'Page not found'], 404);
        }

        $target = (new WikiMetadataService($this->app->database()))->resolveTarget(
            (int) $space['id'],
            $reference
        );
        if ($target === null) {
            return Response::redirect('/search?q=' . rawurlencode($reference));
        }

        $page = $this->app->database()->fetchOne(
            'SELECT id, status, owner_id, author_id
             FROM pages
             WHERE id = :id AND deleted_at IS NULL
             LIMIT 1',
            ['id' => (int) $target['id']]
        );
        if ($page === null || !$this->canViewPage($page, $space, $access)) {
            return $this->render('errors/404', ['title' => 'Page not found'], 404);
        }

        return Response::redirect(
            '/spaces/' . rawurlencode((string) $space['space_key'])
            . '/pages/' . rawurlencode((string) $target['slug'])
        );
    }

    public function tag(Request $request, string $slug): Response
    {
        $metadata = new WikiMetadataService($this->app->database());
        $tag = $metadata->tagBySlug($slug);
        if ($tag === null) {
            return $this->render('errors/404', ['title' => 'Tag not found'], 404);
        }

        $access = new SpaceAccessService($this->app);
        $pages = [];

        foreach ($metadata->pagesForTag($slug) as $page) {
            $space = [
                'id' => (int) $page['space_id'],
                'owner_id' => (int) $page['space_owner_id'],
                'visibility' => $page['space_visibility'],
                'status' => $page['space_status'],
                'deleted_at' => $page['space_deleted_at'],
            ];

            if ($access->canView($space) && $this->canViewPage($page, $space, $access)) {
                $pages[] = $page;
            }
        }

        return $this->render('tags/show', [
            'title' => 'Tag: ' . $tag['name'],
            'tag' => $tag,
            'pages' => $pages,
        ]);
    }

    public function brokenLinks(Request $request): Response
    {
        if (($failure = $this->authorize($request, 'settings.manage')) !== null) {
            return $failure;
        }

        $metadata = new WikiMetadataService($this->app->database());
        $spaceIds = $this->app->database()->fetchAll(
            'SELECT DISTINCT space_id FROM pages WHERE deleted_at IS NULL'
        );
        foreach ($spaceIds as $row) {
            $metadata->refreshSpaceLinks((int) $row['space_id']);
        }

        return $this->render('admin/broken-links', [
            'title' => 'Broken links',
            'links' => $metadata->brokenLinks(),
        ]);
    }

    private function canViewPage(array $page, array $space, SpaceAccessService $access): bool
    {
        if (($page['status'] ?? null) === 'published') {
            return true;
        }

        $user = $this->app->auth()->user();
        if ($user === null) {
            return false;
        }

        return (int) $page['owner_id'] === (int) $user['id']
            || (int) $page['author_id'] === (int) $user['id']
            || $access->canEdit($space);
    }
}
