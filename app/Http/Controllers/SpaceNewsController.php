<?php

declare(strict_types=1);

namespace OpenWiki\Http\Controllers;

use OpenWiki\Core\Request;
use OpenWiki\Core\Response;
use OpenWiki\Core\Session;
use OpenWiki\Http\Controller;
use OpenWiki\Permissions\SpaceAccessService;
use OpenWiki\Repositories\SpaceRepository;
use OpenWiki\Wiki\SpaceNewsService;

final class SpaceNewsController extends Controller
{
    public function index(Request $request, string $spaceKey): Response
    {
        $space = $this->space($spaceKey);
        if ($space instanceof Response) return $space;
        $user = $this->app->auth()->user();
        $canCreateNews = $user !== null && $this->app->auth()->can('page.create')
            && (new SpaceAccessService($this->app))->canEdit($space) && $space['status'] === 'active';
        return $this->render('spaces/news', [
            'title' => 'Space news', 'space' => $space, 'spaceTab' => 'news',
            'news' => (new SpaceNewsService($this->app->database()))->listForSpace((int) $space['id']),
            'canCreateNews' => $canCreateNews, 'canManageNews' => $this->spaceSettingsAllowed($space),
            'dateFormat' => (string) ($user['date_format'] ?? 'locale'),
        ] + $this->spaceNavigation($space));
    }

    public function create(Request $request, string $spaceKey): Response
    {
        if (($failure = $this->requireAuth($request)) !== null) return $failure;
        $space = $this->space($spaceKey);
        if ($space instanceof Response) return $space;
        if (!$this->canCreateNews($space)) return $this->render('errors/403', ['title' => 'Permission denied'], 403);
        return $this->form($space);
    }

    public function store(Request $request, string $spaceKey): Response
    {
        if (($failure = $this->requireAuth($request)) !== null) return $failure;
        if (($failure = $this->verifyCsrf($request)) !== null) return $failure;
        $space = $this->space($spaceKey);
        if ($space instanceof Response) return $space;
        if (!$this->canCreateNews($space)) return $this->render('errors/403', ['title' => 'Permission denied'], 403);
        try {
            $user = $this->app->auth()->user();
            $id = (new SpaceNewsService($this->app->database()))->create((int) $space['id'], (int) $user['id'], $request->input('title'), $request->input('body'));
            Session::flash('success', 'News post published.');
            return Response::redirect('/spaces/' . rawurlencode($space['space_key']) . '/news#news-' . $id);
        } catch (\InvalidArgumentException $error) {
            return $this->form($space, 422, $error->getMessage(), (array) $request->input());
        } catch (\Throwable $error) {
            error_log('[OpenWiki space news create] ' . $error->getMessage());
            return $this->form($space, 500, 'Unable to publish this update. Try again.', (array) $request->input());
        }
    }

    public function edit(Request $request, string $spaceKey, string $id): Response
    {
        if (($failure = $this->requireAuth($request)) !== null) return $failure;
        $space = $this->space($spaceKey);
        if ($space instanceof Response) return $space;
        $post = $this->post($space, $id);
        if ($post instanceof Response) return $post;
        if (!$this->canManagePost($space, $post) || $space['status'] !== 'active') return $this->render('errors/403', ['title' => 'Permission denied'], 403);
        return $this->form($space, 200, null, $post, (int) $post['id']);
    }

    public function update(Request $request, string $spaceKey, string $id): Response
    {
        if (($failure = $this->requireAuth($request)) !== null) return $failure;
        if (($failure = $this->verifyCsrf($request)) !== null) return $failure;
        $space = $this->space($spaceKey);
        if ($space instanceof Response) return $space;
        $post = $this->post($space, $id);
        if ($post instanceof Response) return $post;
        if (!$this->canManagePost($space, $post) || $space['status'] !== 'active') return $this->render('errors/403', ['title' => 'Permission denied'], 403);
        try {
            (new SpaceNewsService($this->app->database()))->update((int) $post['id'], (int) $space['id'], $request->input('title'), $request->input('body'));
            Session::flash('success', 'News post updated.');
            return Response::redirect('/spaces/' . rawurlencode($space['space_key']) . '/news#news-' . (int) $post['id']);
        } catch (\InvalidArgumentException $error) {
            return $this->form($space, 422, $error->getMessage(), (array) $request->input(), (int) $post['id']);
        } catch (\Throwable $error) {
            error_log('[OpenWiki space news update] ' . $error->getMessage());
            return $this->form($space, 500, 'Unable to save this update. Try again.', (array) $request->input(), (int) $post['id']);
        }
    }

    public function delete(Request $request, string $spaceKey, string $id): Response
    {
        if (($failure = $this->requireAuth($request)) !== null) return $failure;
        if (($failure = $this->verifyCsrf($request)) !== null) return $failure;
        $space = $this->space($spaceKey);
        if ($space instanceof Response) return $space;
        $post = $this->post($space, $id);
        if ($post instanceof Response) return $post;
        if (!$this->canManagePost($space, $post) || $space['status'] !== 'active') return $this->render('errors/403', ['title' => 'Permission denied'], 403);
        (new SpaceNewsService($this->app->database()))->delete((int) $post['id'], (int) $space['id']);
        Session::flash('success', 'News post deleted.');
        return Response::redirect('/spaces/' . rawurlencode($space['space_key']) . '/news');
    }

    private function space(string $key): array|Response
    {
        $space = (new SpaceRepository($this->app->database()))->findByKey($key);
        if ($space === null) return $this->render('errors/404', ['title' => 'Space not found'], 404);
        if (!(new SpaceAccessService($this->app))->canView($space)) return $this->render('errors/403', ['title' => 'Permission denied'], 403);
        return $space;
    }

    private function post(array $space, string $value): array|Response
    {
        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $post = $id === false ? null : (new SpaceNewsService($this->app->database()))->find((int) $space['id'], (int) $id);
        return $post ?? $this->render('errors/404', ['title' => 'News post not found'], 404);
    }

    private function canCreateNews(array $space): bool
    {
        return $space['status'] === 'active' && $this->app->auth()->can('page.create')
            && (new SpaceAccessService($this->app))->canEdit($space);
    }

    private function canManagePost(array $space, array $post): bool
    {
        $user = $this->app->auth()->user();
        return $user !== null && ((int) $post['author_id'] === (int) $user['id'] || $this->spaceSettingsAllowed($space));
    }

    private function form(array $space, int $status = 200, ?string $error = null, array $old = [], ?int $postId = null): Response
    {
        $user = $this->app->auth()->user();
        return $this->render('spaces/news-form', [
            'title' => $postId === null ? 'Write a space update' : 'Edit space update',
            'space' => $space, 'spaceTab' => 'news', 'postId' => $postId,
            'old' => $old, 'formError' => $error, 'dateFormat' => (string) ($user['date_format'] ?? 'locale'),
        ] + $this->spaceNavigation($space), $status);
    }
}
