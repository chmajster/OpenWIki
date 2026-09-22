<?php

declare(strict_types=1);

namespace OpenWiki\Http\Controllers;

use OpenWiki\Audit\AuditLogger;
use OpenWiki\Core\Request;
use OpenWiki\Core\Response;
use OpenWiki\Core\Session;
use OpenWiki\Http\Controller;
use OpenWiki\Wiki\PageTemplateService;

final class TemplateAdminController extends Controller
{
    public function index(Request $request): Response
    {
        if (($failure = $this->authorize($request, 'template.manage')) !== null) {
            return $failure;
        }

        return $this->render('admin/templates/index', [
            'title' => 'Page templates',
            'templates' => $this->service()->all(),
        ]);
    }

    public function create(Request $request): Response
    {
        if (($failure = $this->authorize($request, 'template.manage')) !== null) {
            return $failure;
        }

        return $this->form(null);
    }

    public function edit(Request $request, string $id): Response
    {
        if (($failure = $this->authorize($request, 'template.manage')) !== null) {
            return $failure;
        }

        $templateId = $this->id($id);
        $template = $templateId === null ? null : $this->service()->find($templateId);
        if ($template === null) {
            return $this->render('errors/404', ['title' => 'Template not found'], 404);
        }

        return $this->form($template);
    }

    public function save(Request $request, ?string $id = null): Response
    {
        if (($failure = $this->authorize($request, 'template.manage')) !== null) {
            return $failure;
        }
        if (($failure = $this->verifyCsrf($request)) !== null) {
            return $failure;
        }

        $templateId = $id === null ? null : $this->id($id);
        if ($id !== null && $templateId === null) {
            return $this->render('errors/404', ['title' => 'Template not found'], 404);
        }

        $before = $templateId === null ? null : $this->service()->find($templateId);
        $actor = $this->app->auth()->user();

        try {
            $savedId = $this->service()->save(
                $templateId,
                (array) $request->input(),
                (int) $actor['id']
            );
            $after = $this->service()->find($savedId);

            (new AuditLogger($this->app->database()))->log(
                $before === null ? 'TEMPLATE_CREATED' : 'TEMPLATE_UPDATED',
                'page_template',
                $savedId,
                (int) $actor['id'],
                $request,
                $this->safeAudit($before),
                $this->safeAudit($after)
            );

            Session::flash('success', $before === null ? 'Template created.' : 'Template updated.');
            return Response::redirect('/admin/templates/' . $savedId . '/edit');
        } catch (\InvalidArgumentException $exception) {
            return $this->formError($before, $request, $exception->getMessage());
        } catch (\Throwable $exception) {
            error_log('[OpenWiki template] ' . $exception->getMessage());
            return $this->formError($before, $request, 'Unable to save template.', 500);
        }
    }

    public function delete(Request $request, string $id): Response
    {
        if (($failure = $this->authorize($request, 'template.manage')) !== null) {
            return $failure;
        }
        if (($failure = $this->verifyCsrf($request)) !== null) {
            return $failure;
        }

        $templateId = $this->id($id);
        if ($templateId === null) {
            return $this->render('errors/404', ['title' => 'Template not found'], 404);
        }

        try {
            $before = $this->service()->find($templateId);
            if ($before === null || !$this->service()->delete($templateId)) {
                return $this->render('errors/404', ['title' => 'Template not found'], 404);
            }

            $actor = $this->app->auth()->user();
            (new AuditLogger($this->app->database()))->log(
                'TEMPLATE_DELETED',
                'page_template',
                $templateId,
                (int) $actor['id'],
                $request,
                $this->safeAudit($before),
                ['deleted' => true]
            );
            Session::flash('success', 'Template deleted.');
        } catch (\InvalidArgumentException $exception) {
            Session::flash('error', $exception->getMessage());
        }

        return Response::redirect('/admin/templates');
    }

    private function form(?array $template, array $old = [], ?string $error = null, int $status = 200): Response
    {
        return $this->render('admin/templates/edit', [
            'title' => $template === null ? 'Create template' : 'Edit template',
            'templateRecord' => $template,
            'old' => $old,
            'formError' => $error,
            'formAction' => $template === null
                ? '/admin/templates'
                : '/admin/templates/' . (int) $template['id'],
        ], $status);
    }

    private function formError(
        ?array $template,
        Request $request,
        string $error,
        int $status = 422
    ): Response {
        $old = (array) $request->input();
        unset($old['_token']);
        return $this->form($template, $old, $error, $status);
    }

    private function service(): PageTemplateService
    {
        return new PageTemplateService($this->app->database());
    }

    private function id(string $value): ?int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT);
        return $id === false || $id < 1 ? null : (int) $id;
    }

    private function safeAudit(?array $template): ?array
    {
        if ($template === null) {
            return null;
        }

        return [
            'id' => (int) $template['id'],
            'name' => $template['name'],
            'description' => $template['description'],
            'is_system' => (bool) $template['is_system'],
        ];
    }
}
