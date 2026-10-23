<?php

declare(strict_types=1);

namespace OpenWiki\Http\Controllers;

use OpenWiki\Core\Request;
use OpenWiki\Core\Response;
use OpenWiki\Core\Session;
use OpenWiki\Http\Controller;
use OpenWiki\Permissions\PageAclService;
use OpenWiki\Permissions\SpaceAccessService;
use OpenWiki\Repositories\PageRepository;
use OpenWiki\Repositories\SpaceRepository;
use OpenWiki\Wiki\CommentService;
use OpenWiki\Wiki\ReactionService;

final class ReactionController extends Controller
{
    public function page(Request $request, string $spaceKey, string $slug): Response
    {
        if (($failure = $this->requireAuth($request)) !== null) return $failure;
        if (($failure = $this->verifyCsrf($request)) !== null) return $failure;
        [$space, $page, $failure] = $this->pageResource($spaceKey, $slug);
        if ($failure !== null) return $failure;
        $user = $this->app->auth()->user();
        try {
            (new ReactionService($this->app->database()))->togglePage(
                (int) $page['id'], (int) $user['id'], (int) $page['author_id'], (string) $user['username'],
                $this->emoji($request), (string) $page['title'], $this->pageUrl($space, $page)
            );
            Session::flash('success', 'Reaction saved.');
        } catch (\InvalidArgumentException $error) {
            Session::flash('error', $error->getMessage());
        }
        return Response::redirect($this->pageUrl($space, $page) . '#page-reactions');
    }

    public function comment(Request $request, string $spaceKey, string $slug, string $id): Response
    {
        if (($failure = $this->requireAuth($request)) !== null) return $failure;
        if (($failure = $this->verifyCsrf($request)) !== null) return $failure;
        [$space, $page, $failure] = $this->pageResource($spaceKey, $slug);
        if ($failure !== null) return $failure;
        $commentId = filter_var($id, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        if ($commentId === false) return $this->render('errors/404', ['title' => 'Comment not found'], 404);
        $user = $this->app->auth()->user();
        try {
            $comment = (new CommentService($this->app->database()))->read((int) $commentId, (int) $user['id'], null, (int) $page['id']);
        } catch (\OutOfBoundsException) {
            return $this->render('errors/404', ['title' => 'Comment not found'], 404);
        } catch (\DomainException) {
            return $this->render('errors/403', ['title' => 'Permission denied'], 403);
        }
        if ($comment['visibility'] !== 'page' || $comment['revision_number'] !== null || $comment['anchor_json'] !== null) {
            return $this->render('errors/404', ['title' => 'Comment not found'], 404);
        }
        try {
            (new ReactionService($this->app->database()))->toggleComment(
                (int) $comment['id'], (int) $page['id'], (int) $user['id'], (int) $comment['author_id'],
                (string) $user['username'], $this->emoji($request), (string) $page['title'],
                $this->pageUrl($space, $page) . '#comment-' . (int) $comment['id']
            );
            Session::flash('success', 'Reaction saved.');
        } catch (\InvalidArgumentException $error) {
            Session::flash('error', $error->getMessage());
        }
        return Response::redirect($this->pageUrl($space, $page) . '#comment-' . (int) $comment['id']);
    }

    private function pageResource(string $spaceKey, string $slug): array
    {
        $space = (new SpaceRepository($this->app->database()))->findByKey($spaceKey);
        if ($space === null) return [null, null, $this->render('errors/404', ['title' => 'Space not found'], 404)];
        $repository = new PageRepository($this->app->database());
        $page = $repository->findBySlug((int) $space['id'], $slug);
        if ($page === null) return [null, null, $this->render('errors/404', ['title' => 'Page not found'], 404)];
        if ($space['status'] !== 'active' || $page['status'] === 'archived' || $page['published_revision'] === null) {
            return [null, null, $this->render('errors/403', ['title' => 'Permission denied'], 403)];
        }
        $page = $repository->published($page);
        if (!(new SpaceAccessService($this->app))->canView($space) || !(new PageAclService($this->app))->canView($page, $space)) {
            return [null, null, $this->render('errors/403', ['title' => 'Permission denied'], 403)];
        }
        return [$space, $page, null];
    }

    private function emoji(Request $request): string
    {
        $emoji = $request->input('emoji');
        if (!is_string($emoji)) throw new \InvalidArgumentException('Wybierz jedną z dostępnych reakcji.');
        return $emoji;
    }

    private function pageUrl(array $space, array $page): string
    {
        return '/spaces/' . rawurlencode($space['space_key']) . '/pages/' . rawurlencode($page['slug']);
    }
}
