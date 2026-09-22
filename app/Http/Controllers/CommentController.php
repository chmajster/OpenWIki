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
use OpenWiki\Wiki\CommentService;

final class CommentController extends Controller
{
    public function create(Request $request, string $spaceKey, string $slug): Response
    {
        if (($failure = $this->authorize($request, 'comment.create')) !== null) {
            return $failure;
        }
        if (($failure = $this->verifyCsrf($request)) !== null) {
            return $failure;
        }

        [$space, $page, $failure] = $this->resource($spaceKey, $slug);
        if ($failure !== null) {
            return $failure;
        }

        $parentValue = $request->input('parent_id');
        $parentId = ($parentValue === null || $parentValue === '')
            ? null
            : filter_var($parentValue, FILTER_VALIDATE_INT);
        if ($parentId === false || (is_int($parentId) && $parentId < 1)) {
            Session::flash('error', 'Invalid reply target.');
            return Response::redirect($this->pageUrl($space, $page) . '#comments');
        }

        try {
            $user = $this->app->auth()->user();
            (new CommentService($this->app->database()))->create(
                (int) $page['id'],
                (int) $space['id'],
                (int) $user['id'],
                (string) $request->input('body', ''),
                $parentId === null ? null : (int) $parentId,
                (string) $page['title'],
                $this->pageUrl($space, $page)
            );

            Session::flash('success', $parentId === null ? 'Comment added.' : 'Reply added.');
        } catch (\InvalidArgumentException $exception) {
            Session::flash('error', $exception->getMessage());
        } catch (\Throwable $exception) {
            error_log('[OpenWiki comment create] ' . $exception->getMessage());
            Session::flash('error', 'Unable to add the comment.');
        }

        return Response::redirect($this->pageUrl($space, $page) . '#comments');
    }

    public function update(Request $request, string $spaceKey, string $slug, string $id): Response
    {
        if (($failure = $this->requireAuth($request)) !== null) {
            return $failure;
        }
        if (($failure = $this->verifyCsrf($request)) !== null) {
            return $failure;
        }

        [$space, $page, $failure] = $this->resource($spaceKey, $slug);
        if ($failure !== null) {
            return $failure;
        }

        $commentId = filter_var($id, FILTER_VALIDATE_INT);
        if ($commentId === false || $commentId < 1) {
            return $this->render('errors/404', ['title' => 'Comment not found'], 404);
        }

        try {
            $user = $this->app->auth()->user();
            if (!(new CommentService($this->app->database()))->update(
                (int) $commentId,
                (int) $user['id'],
                (string) $request->input('body', '')
            )) {
                return $this->render('errors/404', ['title' => 'Comment not found'], 404);
            }

            Session::flash('success', 'Comment updated.');
        } catch (\InvalidArgumentException $exception) {
            Session::flash('error', $exception->getMessage());
        } catch (\RuntimeException $exception) {
            if ($exception->getMessage() === 'COMMENT_EDIT_FORBIDDEN') {
                return $this->render('errors/403', ['title' => 'Permission denied'], 403);
            }
            throw $exception;
        }

        return Response::redirect($this->pageUrl($space, $page) . '#comment-' . (int) $commentId);
    }

    public function delete(Request $request, string $spaceKey, string $slug, string $id): Response
    {
        if (($failure = $this->requireAuth($request)) !== null) {
            return $failure;
        }
        if (($failure = $this->verifyCsrf($request)) !== null) {
            return $failure;
        }

        [$space, $page, $failure] = $this->resource($spaceKey, $slug);
        if ($failure !== null) {
            return $failure;
        }

        $commentId = filter_var($id, FILTER_VALIDATE_INT);
        if ($commentId === false || $commentId < 1) {
            return $this->render('errors/404', ['title' => 'Comment not found'], 404);
        }

        try {
            $user = $this->app->auth()->user();
            $deleted = (new CommentService($this->app->database()))->delete(
                (int) $commentId,
                (int) $user['id'],
                $this->app->auth()->can('comment.delete')
            );

            if (!$deleted) {
                return $this->render('errors/404', ['title' => 'Comment not found'], 404);
            }

            Session::flash('success', 'Comment deleted.');
        } catch (\RuntimeException $exception) {
            if ($exception->getMessage() === 'COMMENT_DELETE_FORBIDDEN') {
                return $this->render('errors/403', ['title' => 'Permission denied'], 403);
            }
            throw $exception;
        }

        return Response::redirect($this->pageUrl($space, $page) . '#comments');
    }

    private function resource(string $spaceKey, string $slug): array
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
