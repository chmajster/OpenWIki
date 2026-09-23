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
use OpenWiki\Wiki\ContentService;
use OpenWiki\Wiki\EditSessionService;

final class PageDraftController extends Controller
{
    public function lock(Request $request, string $spaceKey, string $slug): Response
    {
        $context = $this->editablePage($request, $spaceKey, $slug);
        if ($context instanceof Response) {
            return $context;
        }

        if (($failure = $this->verifyCsrf($request)) !== null) {
            return $failure;
        }

        $user = $this->app->auth()->user();
        $result = (new EditSessionService($this->app->database()))->acquire(
            (int) $context['page']['id'],
            (int) $user['id']
        );

        return Response::json(['data' => $result]);
    }

    public function unlock(Request $request, string $spaceKey, string $slug): Response
    {
        $context = $this->editablePage($request, $spaceKey, $slug);
        if ($context instanceof Response) {
            return $context;
        }

        if (($failure = $this->verifyCsrf($request)) !== null) {
            return $failure;
        }

        $user = $this->app->auth()->user();
        $released = (new EditSessionService($this->app->database()))->release(
            (int) $context['page']['id'],
            (int) $user['id'],
            (string) $request->input('lock_token', '')
        );

        return Response::json(['data' => ['released' => $released]]);
    }

    public function autosave(Request $request, string $spaceKey, string $slug): Response
    {
        $context = $this->editablePage($request, $spaceKey, $slug);
        if ($context instanceof Response) {
            return $context;
        }

        if (($failure = $this->verifyCsrf($request)) !== null) {
            return $failure;
        }

        $baseVersion = filter_var($request->input('base_version'), FILTER_VALIDATE_INT);
        if ($baseVersion === false || $baseVersion < 1) {
            return Response::json([
                'error' => ['code' => 'invalid_version', 'message' => 'Invalid base version.'],
            ], 422);
        }

        try {
            $content = (new ContentService())->normalize(
                (string) $request->input('content_format', 'visual'),
                (string) $request->input('content_html', ''),
                (string) $request->input('content_markdown', '')
            );

            $user = $this->app->auth()->user();
            $result = (new EditSessionService($this->app->database()))->saveDraft(
                (int) $context['page']['id'],
                (int) $user['id'],
                (int) $baseVersion,
                $content
            );

            return Response::json(['data' => $result]);
        } catch (\InvalidArgumentException $exception) {
            return Response::json([
                'error' => ['code' => 'invalid_content', 'message' => $exception->getMessage()],
            ], 422);
        }
    }

    private function editablePage(Request $request, string $spaceKey, string $slug): array|Response
    {
        if (($failure = $this->authorize($request, 'page.edit')) !== null) {
            return $failure;
        }

        $space = (new SpaceRepository($this->app->database()))->findByKey($spaceKey);
        if ($space === null) {
            return Response::json([
                'error' => ['code' => 'space_not_found', 'message' => 'Space not found.'],
            ], 404);
        }

        $page = (new PageRepository($this->app->database()))->findBySlug((int) $space['id'], $slug);
        if ($page === null) {
            return Response::json([
                'error' => ['code' => 'page_not_found', 'message' => 'Page not found.'],
            ], 404);
        }

        if (!(new PageAclService($this->app))->canEdit($page, $space)) {
            return Response::json([
                'error' => ['code' => 'forbidden', 'message' => 'Permission denied.'],
            ], 403);
        }

        return ['space' => $space, 'page' => $page];
    }
}
