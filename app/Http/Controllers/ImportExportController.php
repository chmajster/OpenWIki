<?php

declare(strict_types=1);

namespace OpenWiki\Http\Controllers;

use OpenWiki\Audit\AuditLogger;
use OpenWiki\Core\Request;
use OpenWiki\Core\Response;
use OpenWiki\Core\Session;
use OpenWiki\Export\DocumentExportService;
use OpenWiki\Http\Controller;
use OpenWiki\Import\DocumentImportService;
use OpenWiki\Permissions\PageAclService;
use OpenWiki\Permissions\SpaceAccessService;
use OpenWiki\Repositories\PageRepository;
use OpenWiki\Repositories\SpaceRepository;
use OpenWiki\Webhooks\WebhookService;

final class ImportExportController extends Controller
{
    public function exportPage(
        Request $request,
        string $spaceKey,
        string $slug,
        string $format
    ): Response {
        $space = $this->space($spaceKey);
        if ($space instanceof Response) {
            return $space;
        }

        $page = (new PageRepository($this->app->database()))
            ->findBySlug((int) $space['id'], $slug);
        if ($page === null) {
            return $this->render('errors/404', ['title' => 'Page not found'], 404);
        }

        if (!(new PageAclService($this->app))->canView($page, $space)) {
            return $this->render('errors/403', ['title' => 'Permission denied'], 403);
        }

        $service = new DocumentExportService($this->app);
        $format = strtolower($format);
        $response = match ($format) {
            'html' => Response::download(
                $service->pageHtml($page, $space),
                'text/html; charset=UTF-8',
                $page['slug'] . '.html'
            ),
            'markdown', 'md' => Response::download(
                $service->pageMarkdown($page),
                'text/markdown; charset=UTF-8',
                $page['slug'] . '.md'
            ),
            'pdf' => Response::download(
                $service->pagePdf($page, $space),
                'application/pdf',
                $page['slug'] . '.pdf'
            ),
            default => $this->render('errors/404', ['title' => 'Export format not found'], 404),
        };

        if ($response instanceof Response && in_array($format, ['html', 'markdown', 'md', 'pdf'], true)) {
            $this->audit(
                'PAGE_EXPORTED',
                'page',
                (int) $page['id'],
                $request,
                ['format' => $format]
            );
        }

        return $response;
    }

    public function exportSpace(Request $request, string $spaceKey): Response
    {
        $space = $this->space($spaceKey);
        if ($space instanceof Response) {
            return $space;
        }

        $spaceAccess = new SpaceAccessService($this->app);
        if (!$spaceAccess->canView($space)) {
            return $this->render('errors/403', ['title' => 'Permission denied'], 403);
        }

        $rows = $this->app->database()->fetchAll(
            'SELECT p.*, u.username AS author_username
             FROM pages p
             INNER JOIN users u ON u.id = p.author_id
             WHERE p.space_id = :space_id AND p.deleted_at IS NULL
             ORDER BY p.order_index ASC, p.id ASC',
            ['space_id' => (int) $space['id']]
        );

        $pageAccess = new PageAclService($this->app);
        $pages = array_values(array_filter(
            $rows,
            fn (array $page): bool => $pageAccess->canView($page, $space)
        ));

        $archive = (new DocumentExportService($this->app))->spaceArchive($space, $pages);
        $this->audit(
            'SPACE_EXPORTED',
            'space',
            (int) $space['id'],
            $request,
            ['page_count' => count($pages)]
        );

        return Response::file(
            $archive['path'],
            'application/zip',
            $archive['filename'],
            false,
            true
        );
    }

    public function importForm(Request $request, string $spaceKey): Response
    {
        if (($failure = $this->authorize($request, 'page.create')) !== null) {
            return $failure;
        }

        $space = $this->space($spaceKey);
        if ($space instanceof Response) {
            return $space;
        }

        if (!(new SpaceAccessService($this->app))->canEdit($space)) {
            return $this->render('errors/403', ['title' => 'Permission denied'], 403);
        }

        return $this->renderImport($space);
    }

    public function import(Request $request, string $spaceKey): Response
    {
        if (($failure = $this->authorize($request, 'page.create')) !== null) {
            return $failure;
        }
        if (($failure = $this->verifyCsrf($request)) !== null) {
            return $failure;
        }

        $space = $this->space($spaceKey);
        if ($space instanceof Response) {
            return $space;
        }
        if (!(new SpaceAccessService($this->app))->canEdit($space)) {
            return $this->render('errors/403', ['title' => 'Permission denied'], 403);
        }

        try {
            $parentId = $this->validatedParent($space, $request->input('parent_id'));
            $user = $this->app->auth()->user();

            $result = (new DocumentImportService(
                $this->app->database(),
                $this->app->basePath(),
                $this->app->auth()->can('attachment.upload')
            ))->importUploaded(
                $space,
                (int) $user['id'],
                $request->file('document') ?? [],
                $parentId
            );

            foreach ($result['page_ids'] as $pageId) {
                try {
                    $created = (new PageRepository($this->app->database()))->findById((int) $pageId);
                    if ($created !== null) {
                        (new WebhookService($this->app->database()))->queue('page.created', [
                            'id' => (int) $created['id'],
                            'space_id' => (int) $space['id'],
                            'title' => $created['title'],
                            'slug' => $created['slug'],
                            'status' => $created['status'],
                            'author_id' => (int) $user['id'],
                            'source' => 'import',
                        ]);
                    }
                } catch (\Throwable $webhookError) {
                    error_log('[OpenWiki import webhook] ' . $webhookError->getMessage());
                }
            }

            $this->audit(
                'DOCUMENTATION_IMPORTED',
                'space',
                (int) $space['id'],
                $request,
                [
                    'imported_pages' => $result['imported_pages'],
                    'section_pages' => $result['section_pages'],
                    'skipped_files' => $result['skipped_files'],
                ]
            );

            Session::flash(
                'success',
                'Import completed: ' . $result['imported_pages'] . ' document page(s), '
                . $result['section_pages'] . ' section page(s), '
                . $result['skipped_files'] . ' skipped file(s).'
            );

            return Response::redirect('/spaces/' . rawurlencode((string) $space['space_key']));
        } catch (\InvalidArgumentException $exception) {
            return $this->renderImport($space, $exception->getMessage(), 422);
        } catch (\Throwable $exception) {
            error_log('[OpenWiki import] ' . $exception->getMessage());
            return $this->renderImport($space, 'Unable to import documentation.', 500);
        }
    }

    private function renderImport(array $space, ?string $error = null, int $status = 200): Response
    {
        $access = new PageAclService($this->app);
        $parents = array_values(array_filter(
            (new PageRepository($this->app->database()))->tree((int) $space['id']),
            fn (array $page): bool => $access->can($page, $space, 'page.create')
        ));

        return $this->render('import/index', [
            'title' => 'Import documentation',
            'space' => $space,
            'parents' => $parents,
            'formError' => $error,
        ], $status);
    }

    private function validatedParent(array $space, mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        $parentId = filter_var($value, FILTER_VALIDATE_INT);
        if ($parentId === false || $parentId < 1) {
            throw new \InvalidArgumentException('Invalid parent page.');
        }

        $parent = (new PageRepository($this->app->database()))->findById((int) $parentId);
        if ($parent === null || (int) $parent['space_id'] !== (int) $space['id']) {
            throw new \InvalidArgumentException('Parent page must belong to this Space.');
        }

        if (!(new PageAclService($this->app))->can($parent, $space, 'page.create')) {
            throw new \InvalidArgumentException('You cannot import pages under the selected parent.');
        }

        return (int) $parentId;
    }

    private function space(string $spaceKey): array|Response
    {
        $space = (new SpaceRepository($this->app->database()))->findByKey($spaceKey);
        return $space ?? $this->render('errors/404', ['title' => 'Space not found'], 404);
    }

    private function audit(
        string $action,
        string $objectType,
        ?int $objectId,
        Request $request,
        array $after
    ): void {
        $user = $this->app->auth()->user();
        (new AuditLogger($this->app->database()))->log(
            $action,
            $objectType,
            $objectId,
            $user === null ? null : (int) $user['id'],
            $request,
            null,
            $after
        );
    }
}
