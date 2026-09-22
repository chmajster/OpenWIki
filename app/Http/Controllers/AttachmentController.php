<?php

declare(strict_types=1);

namespace OpenWiki\Http\Controllers;

use OpenWiki\Attachments\AttachmentService;
use OpenWiki\Audit\AuditLogger;
use OpenWiki\Core\Request;
use OpenWiki\Core\Response;
use OpenWiki\Core\Session;
use OpenWiki\Http\Controller;
use OpenWiki\Permissions\PageAclService;
use OpenWiki\Permissions\SpaceAccessService;
use OpenWiki\Repositories\PageRepository;
use OpenWiki\Repositories\SpaceRepository;

final class AttachmentController extends Controller
{
    public function upload(Request $request, string $spaceKey, string $slug): Response
    {
        if (($failure = $this->authorize($request, 'attachment.upload')) !== null) {
            return $failure;
        }
        if (($failure = $this->verifyCsrf($request)) !== null) {
            return $failure;
        }

        [$space, $page, $failure] = $this->editablePage($spaceKey, $slug);
        if ($failure !== null) {
            return $failure;
        }

        try {
            $user = $this->app->auth()->user();
            $service = $this->service();
            $id = $service->upload((int) $page['id'], (int) $user['id'], $request->file('attachment') ?? []);

            (new AuditLogger($this->app->database()))->log(
                'ATTACHMENT_UPLOADED',
                'attachment',
                $id,
                (int) $user['id'],
                $request,
                null,
                ['page_id' => (int) $page['id']]
            );

            Session::flash('success', 'Attachment uploaded.');
        } catch (\InvalidArgumentException $exception) {
            Session::flash('error', $exception->getMessage());
        } catch (\Throwable $exception) {
            error_log('[OpenWiki attachment upload] ' . $exception->getMessage());
            Session::flash('error', 'Unable to upload attachment.');
        }

        return Response::redirect($this->pageUrl($space, $page) . '#attachments');
    }

    public function version(Request $request, string $spaceKey, string $slug, string $id): Response
    {
        if (($failure = $this->authorize($request, 'attachment.upload')) !== null) {
            return $failure;
        }
        if (($failure = $this->verifyCsrf($request)) !== null) {
            return $failure;
        }

        [$space, $page, $failure] = $this->editablePage($spaceKey, $slug);
        if ($failure !== null) {
            return $failure;
        }

        $attachmentId = $this->positiveId($id);
        if ($attachmentId === null) {
            return $this->render('errors/404', ['title' => 'Attachment not found'], 404);
        }

        $service = $this->service();
        $attachment = $service->find($attachmentId);
        if ($attachment === null || (int) $attachment['page_id'] !== (int) $page['id']) {
            return $this->render('errors/404', ['title' => 'Attachment not found'], 404);
        }

        try {
            $user = $this->app->auth()->user();
            $newVersion = $service->addVersion(
                $attachmentId,
                (int) $user['id'],
                $request->file('attachment') ?? []
            );

            (new AuditLogger($this->app->database()))->log(
                'ATTACHMENT_VERSION_UPLOADED',
                'attachment',
                $attachmentId,
                (int) $user['id'],
                $request,
                ['version' => (int) $attachment['current_version']],
                ['version' => $newVersion]
            );

            Session::flash('success', 'New attachment version uploaded.');
        } catch (\InvalidArgumentException $exception) {
            Session::flash('error', $exception->getMessage());
        } catch (\Throwable $exception) {
            error_log('[OpenWiki attachment version] ' . $exception->getMessage());
            Session::flash('error', 'Unable to upload attachment version.');
        }

        return Response::redirect($this->pageUrl($space, $page) . '#attachment-' . $attachmentId);
    }

    public function rename(Request $request, string $spaceKey, string $slug, string $id): Response
    {
        if (($failure = $this->authorize($request, 'attachment.upload')) !== null) {
            return $failure;
        }
        if (($failure = $this->verifyCsrf($request)) !== null) {
            return $failure;
        }

        [$space, $page, $failure] = $this->editablePage($spaceKey, $slug);
        if ($failure !== null) {
            return $failure;
        }

        $attachmentId = $this->positiveId($id);
        $service = $this->service();
        $attachment = $attachmentId === null ? null : $service->find($attachmentId);
        if ($attachment === null || (int) $attachment['page_id'] !== (int) $page['id']) {
            return $this->render('errors/404', ['title' => 'Attachment not found'], 404);
        }

        try {
            if (!$service->rename($attachmentId, (string) $request->input('name', ''))) {
                return $this->render('errors/404', ['title' => 'Attachment not found'], 404);
            }

            $user = $this->app->auth()->user();
            (new AuditLogger($this->app->database()))->log(
                'ATTACHMENT_RENAMED',
                'attachment',
                $attachmentId,
                (int) $user['id'],
                $request,
                ['name' => $attachment['name']],
                ['name' => (string) $request->input('name', '')]
            );
            Session::flash('success', 'Attachment renamed.');
        } catch (\InvalidArgumentException $exception) {
            Session::flash('error', $exception->getMessage());
        }

        return Response::redirect($this->pageUrl($space, $page) . '#attachment-' . $attachmentId);
    }

    public function delete(Request $request, string $spaceKey, string $slug, string $id): Response
    {
        if (($failure = $this->authorize($request, 'attachment.delete')) !== null) {
            return $failure;
        }
        if (($failure = $this->verifyCsrf($request)) !== null) {
            return $failure;
        }

        [$space, $page, $failure] = $this->editablePage($spaceKey, $slug);
        if ($failure !== null) {
            return $failure;
        }

        $attachmentId = $this->positiveId($id);
        $service = $this->service();
        $attachment = $attachmentId === null ? null : $service->find($attachmentId);
        if ($attachment === null || (int) $attachment['page_id'] !== (int) $page['id']) {
            return $this->render('errors/404', ['title' => 'Attachment not found'], 404);
        }

        if (!$service->delete($attachmentId)) {
            return $this->render('errors/404', ['title' => 'Attachment not found'], 404);
        }

        $user = $this->app->auth()->user();
        (new AuditLogger($this->app->database()))->log(
            'ATTACHMENT_DELETED',
            'attachment',
            $attachmentId,
            (int) $user['id'],
            $request,
            ['name' => $attachment['name'], 'version' => (int) $attachment['current_version']],
            ['deleted' => true]
        );

        Session::flash('success', 'Attachment deleted.');
        return Response::redirect($this->pageUrl($space, $page) . '#attachments');
    }

    public function download(Request $request, string $id): Response
    {
        $attachment = $this->accessibleAttachment($id);
        if ($attachment instanceof Response) {
            return $attachment;
        }

        try {
            return Response::file(
                $this->service()->currentPath($attachment),
                (string) $attachment['mime_type'],
                (string) $attachment['name'],
                false
            );
        } catch (\Throwable $exception) {
            error_log('[OpenWiki attachment download] ' . $exception->getMessage());
            return $this->render('errors/404', ['title' => 'Attachment not found'], 404);
        }
    }

    public function preview(Request $request, string $id): Response
    {
        $attachment = $this->accessibleAttachment($id);
        if ($attachment instanceof Response) {
            return $attachment;
        }

        $service = $this->service();
        if (!$service->mayPreview((string) $attachment['mime_type'])) {
            return Response::redirect('/attachments/' . (int) $attachment['id'] . '/download');
        }

        try {
            return Response::file(
                $service->currentPath($attachment),
                (string) $attachment['mime_type'],
                (string) $attachment['name'],
                true
            );
        } catch (\Throwable $exception) {
            error_log('[OpenWiki attachment preview] ' . $exception->getMessage());
            return $this->render('errors/404', ['title' => 'Attachment not found'], 404);
        }
    }

    public function downloadVersion(Request $request, string $id, string $version): Response
    {
        $attachment = $this->accessibleAttachment($id);
        if ($attachment instanceof Response) {
            return $attachment;
        }

        $versionNumber = $this->positiveId($version);
        if ($versionNumber === null) {
            return $this->render('errors/404', ['title' => 'Attachment version not found'], 404);
        }

        try {
            $file = $this->service()->versionPath((int) $attachment['id'], $versionNumber);
            if ($file === null) {
                return $this->render('errors/404', ['title' => 'Attachment version not found'], 404);
            }

            return Response::file(
                (string) $file['path'],
                (string) $file['mime_type'],
                (string) $file['name'],
                false
            );
        } catch (\Throwable $exception) {
            error_log('[OpenWiki attachment version download] ' . $exception->getMessage());
            return $this->render('errors/404', ['title' => 'Attachment version not found'], 404);
        }
    }

    private function editablePage(string $spaceKey, string $slug): array
    {
        $space = (new SpaceRepository($this->app->database()))->findByKey($spaceKey);
        if ($space === null) {
            return [null, null, $this->render('errors/404', ['title' => 'Space not found'], 404)];
        }

        $page = (new PageRepository($this->app->database()))->findBySlug((int) $space['id'], $slug);
        if ($page === null) {
            return [null, null, $this->render('errors/404', ['title' => 'Page not found'], 404)];
        }

        if (!(new PageAclService($this->app))->canEdit($page, $space)) {
            return [null, null, $this->render('errors/403', ['title' => 'Permission denied'], 403)];
        }

        return [$space, $page, null];
    }

    private function accessibleAttachment(string $id): array|Response
    {
        $attachmentId = $this->positiveId($id);
        if ($attachmentId === null) {
            return $this->render('errors/404', ['title' => 'Attachment not found'], 404);
        }

        $attachment = $this->service()->find($attachmentId);
        if ($attachment === null) {
            return $this->render('errors/404', ['title' => 'Attachment not found'], 404);
        }

        $space = [
            'id' => (int) $attachment['space_id'],
            'owner_id' => (int) $attachment['space_owner_id'],
            'visibility' => $attachment['space_visibility'],
            'status' => $attachment['space_status'],
            'deleted_at' => $attachment['space_deleted_at'],
        ];
        $page = [
            'id' => (int) $attachment['page_id'],
            'space_id' => (int) $attachment['space_id'],
            'parent_id' => $attachment['page_parent_id'] === null ? null : (int) $attachment['page_parent_id'],
            'inherit_acl' => (int) $attachment['page_inherit_acl'],
            'status' => $attachment['page_status'],
            'owner_id' => (int) $attachment['page_owner_id'],
            'author_id' => (int) $attachment['page_author_id'],
        ];

        if (!(new PageAclService($this->app))->canView($page, $space)) {
            return $this->render('errors/404', ['title' => 'Attachment not found'], 404);
        }

        return $attachment;
    }

    private function service(): AttachmentService
    {
        return new AttachmentService($this->app->database(), $this->app->basePath());
    }

    private function positiveId(string $value): ?int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT);
        return $id === false || $id < 1 ? null : (int) $id;
    }

    private function pageUrl(array $space, array $page): string
    {
        return '/spaces/' . rawurlencode($space['space_key'])
            . '/pages/' . rawurlencode($page['slug']);
    }
}
