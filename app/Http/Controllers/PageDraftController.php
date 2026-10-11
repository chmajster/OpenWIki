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
        return $this->saveEditorDraft($request, $spaceKey);
    }

    public function drafts(Request $request, string $spaceKey): Response
    {
        $context = $this->draftContext($request, $spaceKey, (string) $request->query('document_key', ''));
        if ($context instanceof Response) return $context;
        return Response::json(['data' => [
            'drafts' => (new EditSessionService($this->app->database()))->editorDrafts(
                (int) $context['space']['id'], (int) $this->app->auth()->user()['id'], $context['document_key']
            ), 'user_id' => (int) $this->app->auth()->user()['id'],
        ]]);
    }

    public function convert(Request $request, string $spaceKey): Response
    {
        $context = $this->draftContext($request, $spaceKey, (string) $request->input('document_key', ''));
        if ($context instanceof Response) return $context;
        if (($failure = $this->verifyCsrf($request)) !== null) return $failure;
        try {
            $this->validateContentFields($request);
            $result = (new ContentService())->convert(
                (string) $request->input('content_format', 'visual'), (string) $request->input('target_format', 'visual'),
                (string) $request->input('content_html', ''), (string) $request->input('content_markdown', '')
            );
            return Response::json(['data' => $result]);
        } catch (\InvalidArgumentException $exception) {
            return Response::json(['error' => ['message' => $exception->getMessage()]], 422);
        }
    }

    public function saveEditorDraft(Request $request, string $spaceKey): Response
    {
        $context = $this->draftContext($request, $spaceKey, (string) $request->input('document_key', ''));
        if ($context instanceof Response) return $context;
        if (($failure = $this->verifyCsrf($request)) !== null) return $failure;
        try {
            $this->validateContentFields($request);
            $base = filter_var($request->input('base_version'), FILTER_VALIDATE_INT);
            if ($base === false || ($context['page'] !== null ? $base < 1 : $base !== 0)) {
                throw new \InvalidArgumentException('Invalid base version.');
            }
            $content = (new ContentService())->normalize(
                (string) $request->input('content_format', 'visual'),
                (string) $request->input('content_html', ''), (string) $request->input('content_markdown', '')
            );
            $payload = ['format' => $content['format'], 'html' => $content['html'],
                'markdown' => $content['markdown'] ?? '', 'baseVersion' => $base];
            foreach (['title' => 255, 'slug' => 255, 'parent_id' => 20, 'status' => 20, 'tags' => 4000, 'change_summary' => 500] as $field => $limit) {
                $value = $request->input($field, '');
                if (!is_string($value) || mb_strlen($value) > $limit) throw new \InvalidArgumentException('Invalid draft field: ' . $field);
                $key = ['parent_id' => 'parentId', 'change_summary' => 'changeSummary'][$field] ?? $field;
                $payload[$key] = $value;
            }
            $payload['conversionBackup'] = (new ContentService())->recoveryCopy($request->input('conversion_backup'));
            $sequence = filter_var($request->input('draft_sequence'), FILTER_VALIDATE_INT);
            if ($sequence === false || $sequence < 1) throw new \InvalidArgumentException('Invalid draft sequence.');
            $result = (new EditSessionService($this->app->database()))->saveEditorDraft(
                (int) $context['space']['id'], (int) $this->app->auth()->user()['id'],
                $context['document_key'], (string) $request->input('editor_id', ''), $sequence, $base, $payload
            );
            $result['stale'] = $context['page'] !== null && (int) $context['page']['version'] !== $base;
            $result['current_version'] = (int) ($context['page']['version'] ?? 0);
            $result['user_id'] = (int) $this->app->auth()->user()['id'];
            return Response::json(['data' => $result]);
        } catch (\InvalidArgumentException $exception) {
            return Response::json(['error' => ['message' => $exception->getMessage()]], 422);
        }
    }

    public function preview(Request $request, string $spaceKey): Response
    {
        $context = $this->draftContext($request, $spaceKey, (string) $request->input('document_key', ''));
        if ($context instanceof Response) return $context;
        if (($failure = $this->verifyCsrf($request)) !== null) return $failure;
        try {
            $this->validateContentFields($request);
            $content = (new ContentService())->normalize('markdown', '', (string) $request->input('content_markdown', ''));
            return Response::json(['data' => ['html' => $content['html'], 'renderer' => 'commonmark-2', 'user_id' => (int) $this->app->auth()->user()['id']]]);
        } catch (\InvalidArgumentException $exception) {
            return Response::json(['error' => ['message' => $exception->getMessage()]], 422);
        }
    }

    public function pages(Request $request, string $spaceKey): Response
    {
        $context = $this->draftContext($request, $spaceKey, (string) $request->query('document_key', ''));
        if ($context instanceof Response) return $context;
        $query = $request->query('q', '');
        if (!is_string($query) || mb_strlen($query) > 100) return Response::json(['error' => ['message' => 'Search is limited to 100 characters.']], 422);
        $rows = $this->app->database()->fetchAll(
            'SELECT id, space_id, parent_id, inherit_acl, owner_id, author_id, title, slug, status FROM pages WHERE space_id = :space AND deleted_at IS NULL
             AND (title LIKE :title OR slug LIKE :slug) ORDER BY title, id LIMIT 100',
            ['space' => $context['space']['id'], 'title' => '%' . $query . '%', 'slug' => '%' . $query . '%']
        );
        $acl = new PageAclService($this->app);
        $results = [];
        foreach ($rows as $row) {
            if (!$acl->canView($row, $context['space'])) continue;
            $results[] = ['id' => (int) $row['id'], 'title' => $row['title'], 'url' => '/spaces/' . rawurlencode($spaceKey) . '/pages/' . rawurlencode($row['slug'])];
            if (count($results) === 20) break;
        }
        return Response::json(['data' => ['pages' => $results, 'user_id' => (int) $this->app->auth()->user()['id']]]);
    }

    private function validateContentFields(Request $request): void
    {
        foreach (['content_format', 'target_format', 'content_html', 'content_markdown'] as $field) {
            if ($request->input($field) !== null && !is_string($request->input($field))) {
                throw new \InvalidArgumentException($field . ' must be a string.');
            }
        }
    }

    private function draftContext(Request $request, string $spaceKey, string $documentKey): array|Response
    {
        if (($failure = $this->requireAuth($request)) !== null) return $failure;
        $user = $this->app->auth()->user();
        if ($request->input('editor_user_id') !== null && (int) $request->input('editor_user_id') !== (int) $user['id']) {
            return Response::json(['error' => ['message' => 'The signed-in account changed. Reopen the editor.']], 403);
        }
        $space = (new SpaceRepository($this->app->database()))->findByKey($spaceKey);
        if ($space === null) return Response::json(['error' => ['message' => 'Space not found.']], 404);
        $page = null;
        if (preg_match('/^page:([1-9][0-9]*)$/D', $documentKey, $match)) {
            $page = (new PageRepository($this->app->database()))->findById((int) $match[1]);
            if ($page === null || (int) $page['space_id'] !== (int) $space['id']) {
                return Response::json(['error' => ['message' => 'Page not found.']], 404);
            }
            if (!(new PageAclService($this->app))->canEdit($page, $space)) {
                return Response::json(['error' => ['message' => 'Permission denied.']], 403);
            }
        } elseif (preg_match('/^new:[a-f0-9-]{36}$/D', $documentKey)) {
            if (!$this->app->auth()->can('page.create') || !(new SpaceAccessService($this->app))->canEdit($space)) {
                return Response::json(['error' => ['message' => 'Permission denied.']], 403);
            }
        } else {
            return Response::json(['error' => ['message' => 'Invalid document identity.']], 422);
        }
        return ['space' => $space, 'page' => $page, 'document_key' => $documentKey];
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
