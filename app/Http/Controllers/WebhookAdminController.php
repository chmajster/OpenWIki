<?php

declare(strict_types=1);

namespace OpenWiki\Http\Controllers;

use OpenWiki\Audit\AuditLogger;
use OpenWiki\Core\Request;
use OpenWiki\Core\Response;
use OpenWiki\Core\Session;
use OpenWiki\Http\Controller;
use OpenWiki\Webhooks\WebhookService;

final class WebhookAdminController extends Controller
{
    public function index(Request $request): Response
    {
        if (($failure = $this->authorize($request, 'webhook.manage')) !== null) {
            return $failure;
        }

        return $this->render('admin/webhooks/index', [
            'title' => 'Webhooks',
            'webhooks' => $this->service()->webhooks(),
            'deliveries' => $this->service()->deliveries(),
            'events' => $this->service()->events(),
            'newSecret' => Session::pullFlash('webhook_new_secret'),
        ]);
    }

    public function create(Request $request): Response
    {
        if (($failure = $this->authorize($request, 'webhook.manage')) !== null) {
            return $failure;
        }
        if (($failure = $this->verifyCsrf($request)) !== null) {
            return $failure;
        }

        try {
            $actor = $this->app->auth()->user();
            $created = $this->service()->create((array) $request->input(), (int) $actor['id']);

            Session::flash('webhook_new_secret', [
                'id' => $created['id'],
                'secret' => $created['secret'],
            ]);
            Session::flash('success', 'Webhook created. Copy its signing secret now.');

            (new AuditLogger($this->app->database()))->log(
                'WEBHOOK_CREATED',
                'webhook',
                (int) $created['id'],
                (int) $actor['id'],
                $request,
                null,
                [
                    'name' => (string) $request->input('name', ''),
                    'target_url' => (string) $request->input('target_url', ''),
                    'events' => (array) $request->input('events', []),
                ]
            );
        } catch (\InvalidArgumentException $exception) {
            Session::flash('error', $exception->getMessage());
        } catch (\Throwable $exception) {
            error_log('[OpenWiki webhook create] ' . $exception->getMessage());
            Session::flash('error', 'Unable to create webhook.');
        }

        return Response::redirect('/admin/webhooks');
    }

    public function status(Request $request, string $id): Response
    {
        if (($failure = $this->authorize($request, 'webhook.manage')) !== null) {
            return $failure;
        }
        if (($failure = $this->verifyCsrf($request)) !== null) {
            return $failure;
        }

        $webhookId = $this->id($id);
        if ($webhookId === null) {
            return $this->render('errors/404', ['title' => 'Webhook not found'], 404);
        }

        $status = (string) $request->input('status', '');
        try {
            if (!$this->service()->setStatus($webhookId, $status)) {
                return $this->render('errors/404', ['title' => 'Webhook not found'], 404);
            }

            $actor = $this->app->auth()->user();
            (new AuditLogger($this->app->database()))->log(
                'WEBHOOK_STATUS_CHANGED',
                'webhook',
                $webhookId,
                (int) $actor['id'],
                $request,
                null,
                ['status' => $status]
            );
            Session::flash('success', 'Webhook status updated.');
        } catch (\InvalidArgumentException $exception) {
            Session::flash('error', $exception->getMessage());
        }

        return Response::redirect('/admin/webhooks');
    }

    public function delete(Request $request, string $id): Response
    {
        if (($failure = $this->authorize($request, 'webhook.manage')) !== null) {
            return $failure;
        }
        if (($failure = $this->verifyCsrf($request)) !== null) {
            return $failure;
        }

        $webhookId = $this->id($id);
        if ($webhookId === null || !$this->service()->delete($webhookId)) {
            return $this->render('errors/404', ['title' => 'Webhook not found'], 404);
        }

        $actor = $this->app->auth()->user();
        (new AuditLogger($this->app->database()))->log(
            'WEBHOOK_DELETED',
            'webhook',
            $webhookId,
            (int) $actor['id'],
            $request
        );
        Session::flash('success', 'Webhook deleted.');
        return Response::redirect('/admin/webhooks');
    }

    private function service(): WebhookService
    {
        return new WebhookService($this->app->database());
    }

    private function id(string $id): ?int
    {
        $value = filter_var($id, FILTER_VALIDATE_INT);
        return $value === false || $value < 1 ? null : (int) $value;
    }
}
