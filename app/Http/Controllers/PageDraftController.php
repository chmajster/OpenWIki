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

    public function workspace(Request $request, string $spaceKey): Response
    {
        $context = $this->draftContext($request, $spaceKey, (string) $request->query('document_key', ''));
        if ($context instanceof Response) return $context;
        try {
            $space = filter_var($request->query('space_id', $context['space']['id']), FILTER_VALIDATE_INT);
            $parentValue = $request->query('parent_id', '');
            $parent = $parentValue === '' ? null : filter_var($parentValue, FILTER_VALIDATE_INT);
            if ($space === false || $space < 1 || $parent === false || ($parent !== null && $parent < 1)) throw new \InvalidArgumentException('Nieprawidłowa lokalizacja.');
            return Response::json(['data' => (new \OpenWiki\Wiki\PageWorkspaceService($this->app))->context($space, $context['page'], $parent)]);
        } catch (\DomainException $error) {
            return Response::json(['error' => ['message' => $error->getMessage()]], 403);
        } catch (\InvalidArgumentException $error) {
            return Response::json(['error' => ['message' => $error->getMessage()]], 422);
        }
    }

    public function drafts(Request $request, string $spaceKey): Response
    {
        $context = $this->draftContext($request, $spaceKey, (string) $request->query('document_key', ''));
        if ($context instanceof Response) return $context;
        $sessions = new EditSessionService($this->app->database());
        $userId = (int) $this->app->auth()->user()['id'];
        if ($request->query('checkpoint') !== null) {
            $point = $sessions->recoveryPoint((int) $context['space']['id'], $userId, $context['document_key'], (int) $request->query('checkpoint'));
            return $point === null ? Response::json(['error' => ['message' => 'Recovery point not found.']], 404)
                : Response::json(['data' => ['draft' => $point, 'user_id' => $userId]]);
        }
        return Response::json(['data' => [
            'drafts' => (new EditSessionService($this->app->database()))->editorDrafts(
                (int) $context['space']['id'], (int) $this->app->auth()->user()['id'], $context['document_key']
            ), 'checkpoints' => $sessions->recoveryPoints((int) $context['space']['id'], $userId, $context['document_key']),
            'current_version' => (int) ($context['page']['version'] ?? 0), 'user_id' => $userId, 'csrf_token' => \OpenWiki\Security\Csrf::token(),
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
            if ($request->input('document') !== null) {
                if (!is_array($request->input('document'))) throw new \InvalidArgumentException('Invalid document source.');
                $content = (new ContentService())->document($request->input('document'));
            } else $content = (new ContentService())->normalize(
                (string) $request->input('content_format', 'visual'),
                (string) $request->input('content_html', ''), (string) $request->input('content_markdown', '')
            );
            (new \OpenWiki\Attachments\DraftFileService($this->app->database(), $this->app->basePath()))->touch($content['html'],
                (int) $context['space']['id'], (int) $this->app->auth()->user()['id'], $context['document_key']);
            $payload = ['format' => $content['format'], 'html' => $content['html'],
                'markdown' => $content['markdown'] ?? '', 'document' => $content['document'], 'baseVersion' => $base];
            foreach (['title' => 255, 'slug' => 255, 'parent_id' => 20, 'status' => 20, 'tags' => 4000, 'change_summary' => 500, 'content_owner_id' => 20, 'review_due_at' => 10, 'publication_space_id' => 20, 'publication_parent_id' => 20] as $field => $limit) {
                $value = $request->input($field, '');
                if (!is_string($value) || mb_strlen($value) > $limit) throw new \InvalidArgumentException('Invalid draft field: ' . $field);
                $key = ['parent_id' => 'parentId', 'change_summary' => 'changeSummary'][$field] ?? $field;
                $payload[$key] = $value;
            }
            $payload['conversionBackup'] = (new ContentService())->recoveryCopy($request->input('conversion_backup'));
            if ($payload['conversionBackup'] !== null) {
                $backup = $payload['conversionBackup'];
                $backupHtml = (new ContentService())->normalize($backup['format'], $backup['html'], $backup['markdown'])['html'];
                (new \OpenWiki\Attachments\DraftFileService($this->app->database(), $this->app->basePath()))->touch($backupHtml,
                    (int) $context['space']['id'], (int) $this->app->auth()->user()['id'], $context['document_key']);
            }
            $sequence = filter_var($request->input('draft_sequence'), FILTER_VALIDATE_INT);
            if ($sequence === false || $sequence < 1) throw new \InvalidArgumentException('Invalid draft sequence.');
            $expected = $request->input('draft_version') === null ? null : filter_var($request->input('draft_version'), FILTER_VALIDATE_INT);
            if ($expected === false || ($expected !== null && $expected < 0)) throw new \InvalidArgumentException('Invalid draft version.');
            if ($request->input('operation_id') !== null && !is_string($request->input('operation_id'))) throw new \InvalidArgumentException('Invalid operation identity.');
            $result = (new EditSessionService($this->app->database()))->saveEditorDraft(
                (int) $context['space']['id'], (int) $this->app->auth()->user()['id'],
                $context['document_key'], (string) $request->input('editor_id', ''), $sequence, $base, $payload,
                $expected, $request->input('operation_id')
            );
            $result['stale'] = $context['page'] !== null && (int) $context['page']['version'] !== $base;
            $result['current_version'] = (int) ($context['page']['version'] ?? 0);
            $result['user_id'] = (int) $this->app->auth()->user()['id'];
            return Response::json(['data' => $result]);
        } catch (\OpenWiki\Wiki\DraftConflict $exception) {
            return Response::json(['error' => ['message' => $exception->getMessage(), 'draft_conflict' => $exception->current]], 409);
        } catch (\DomainException $exception) {
            return Response::json(['error' => ['message' => $exception->getMessage()]], 403);
        } catch (\InvalidArgumentException $exception) {
            return Response::json(['error' => ['message' => $exception->getMessage()]], 422);
        }
    }

    public function uploadFile(Request $request, string $spaceKey): Response
    {
        $context = $this->draftContext($request, $spaceKey, (string) $request->input('document_key', ''));
        if ($context instanceof Response) return $context;
        if (($failure = $this->authorize($request, 'attachment.upload')) !== null) return $failure;
        if (($failure = $this->verifyCsrf($request)) !== null) return $failure;
        try {
            $operation = $request->input('operation_id');
            if (!is_string($operation)) throw new \InvalidArgumentException('Invalid upload operation.');
            $file = (new \OpenWiki\Attachments\DraftFileService($this->app->database(), $this->app->basePath()))->upload(
                (int) $context['space']['id'], (int) $this->app->auth()->user()['id'], $context['document_key'], $operation, $request->file('file') ?? []);
            return Response::json(['data' => ['id' => (int) $file['id'], 'name' => $file['name'], 'mime_type' => $file['mime_type'],
                'size_bytes' => (int) $file['size_bytes'], 'width' => $file['width'] === null ? null : (int) $file['width'], 'height' => $file['height'] === null ? null : (int) $file['height'],
                'preview_url' => '/editor-files/' . $file['id'] . '/preview', 'thumbnail_url' => '/editor-files/' . $file['id'] . '/thumbnail?width=640',
                'download_url' => '/editor-files/' . $file['id'] . '/download', 'operation_id' => $operation, 'user_id' => (int) $this->app->auth()->user()['id']]], 201);
        } catch (\InvalidArgumentException $exception) {
            return Response::json(['error' => ['message' => $exception->getMessage()]], 422);
        } catch (\Throwable $exception) {
            error_log('[OpenWiki private upload] ' . $exception->getMessage());
            return Response::json(['error' => ['message' => 'Upload failed. Your document is retained; retry the file.']], 500);
        }
    }

    public function file(Request $request, string $id, string $mode): Response
    {
        $user = $this->app->auth()->user();
        $file = $user === null ? null : $this->app->database()->fetchOne('SELECT * FROM editor_draft_files WHERE id = :id AND user_id = :user', ['id' => (int) $id, 'user' => $user['id']]);
        if ($file === null || !in_array($mode, ['download', 'preview', 'thumbnail'], true)) return Response::html('File not found.', 404);
        if ($file['attachment_id'] !== null) return Response::redirect('/attachments/' . (int) $file['attachment_id'] . '/' . $mode . ($mode === 'thumbnail' ? '?width=' . (int) $request->query('width', 640) : ''));
        $space = $this->app->database()->fetchOne('SELECT space_key FROM spaces WHERE id = :id AND deleted_at IS NULL', ['id' => $file['space_id']]);
        if ($space === null || $this->draftContext($request, $space['space_key'], $file['document_key']) instanceof Response) return Response::html('File not found.', 404);
        try {
            $attachments = new \OpenWiki\Attachments\AttachmentService($this->app->database(), $this->app->basePath());
            if ($mode === 'thumbnail') {
                $thumbnail = (new \OpenWiki\Images\ImageService($attachments, $this->app->basePath()))->thumbnail($file, (int) $request->query('width', 640));
                return Response::file($thumbnail['path'], $thumbnail['mime_type'], 'thumbnail', true);
            }
            if ($mode === 'preview' && $file['width'] === null) return Response::html('Image not found.', 404);
            return Response::file($attachments->stagedPath($file['storage_key']), $file['mime_type'], $file['name'], $mode === 'preview');
        } catch (\Throwable) { return Response::html('File not found.', 404); }
    }

    public function preview(Request $request, string $spaceKey): Response
    {
        $context = $this->draftContext($request, $spaceKey, (string) $request->input('document_key', ''));
        if ($context instanceof Response) return $context;
        if (($failure = $this->verifyCsrf($request)) !== null) return $failure;
        try {
            $this->validateContentFields($request);
            $content = (new ContentService())->normalize($request->input('document_preview') === true ? (string) $request->input('content_format', 'markdown') : 'markdown', (string) $request->input('content_html', ''), (string) $request->input('content_markdown', ''));
            if ($request->input('document_preview') === true) {
                $page = array_replace($context['page'] ?? ['id' => 0, 'author_id' => $this->app->auth()->user()['id'], 'version' => 0, 'updated_at' => '', 'status' => 'draft'],
                    ['content_format' => $content['format'], 'content_html' => $content['html'], 'content_markdown' => $content['markdown'] ?? '', 'document_json' => json_encode($content['document'], JSON_THROW_ON_ERROR)]);
                unset($page['published_revision']);
                $content['html'] = (new \OpenWiki\Wiki\MacroService($this->app))->renderPage($page, $context['space'])['html'];
            }
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
        $acl = new PageAclService($this->app);
        $rows = $acl->visiblePages($context['space'], fn (): array => $this->app->database()->fetchAll(
            'SELECT id, space_id, parent_id, inherit_acl, owner_id, author_id, title, slug, status FROM pages WHERE space_id = :space AND deleted_at IS NULL
             AND (title LIKE :title OR slug LIKE :slug) ORDER BY title, id LIMIT 100',
            ['space' => $context['space']['id'], 'title' => '%' . $query . '%', 'slug' => '%' . $query . '%']
        ));
        $results = [];
        foreach ($rows as $row) {
            $results[] = ['id' => (int) $row['id'], 'title' => $row['title'], 'url' => '/spaces/' . rawurlencode($spaceKey) . '/pages/' . rawurlencode($row['slug'])];
            if (count($results) === 20) break;
        }
        return Response::json(['data' => ['pages' => $results, 'user_id' => (int) $this->app->auth()->user()['id']]]);
    }

    public function users(Request $request, string $spaceKey): Response
    {
        $context = $this->draftContext($request, $spaceKey, (string) $request->query('document_key', ''));
        if ($context instanceof Response) return $context;
        $query = $request->query('q', '');
        if (!is_string($query) || mb_strlen($query) > 100) return Response::json(['error' => ['message' => 'Search is limited to 100 characters.']], 422);
        $user = $this->app->auth()->user();
        // Directory access is a server permission; ordinary editors may mention themselves.
        $rows = $this->app->auth()->can('user.view')
            ? $this->app->database()->fetchAll('SELECT id, username FROM users WHERE status = "active" AND deleted_at IS NULL AND username LIKE :query ORDER BY username LIMIT 20', ['query' => '%' . $query . '%'])
            : (stripos($user['username'], $query) !== false ? [['id' => $user['id'], 'username' => $user['username']]] : []);
        return Response::json(['data' => ['users' => $rows, 'user_id' => (int) $user['id']]]);
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
