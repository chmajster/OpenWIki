<?php

declare (strict_types=1);
namespace OpenWiki\Http\Controllers;

use OpenWiki\Core\Request;
use OpenWiki\Core\Response;
use OpenWiki\Http\Controller;
use OpenWiki\Http\CollaborationResponse;
use OpenWiki\Wiki\CollaborationActions;
use OpenWiki\Wiki\CollaborationAccess;
final class CollaborationController extends Controller
{
    public function read(Request $request, string $id, string $action): Response
    {
        if (!in_array($action, ['discussions', 'discussion-replies', 'comment', 'tasks', 'task', 'task-history', 'assignees'], true)) {
            return Response::json(['error' => ['message' => 'Nie znaleziono operacji.']], 404);
        }
        return $this->dispatch($request, $id, $action, false);
    }
    public function write(Request $request, string $id, string $action): Response
    {
        if (!in_array($action, ['comment-create', 'comment-update', 'comment-delete', 'discussion-status', 'task-create', 'task-update'], true)) {
            return Response::json(['error' => ['message' => 'Nie znaleziono operacji.']], 404);
        }
        return $this->dispatch($request, $id, $action, true);
    }
    private function dispatch(Request $request, string $id, string $action, bool $write): Response
    {
        if (($failure = $this->requireAuth($request)) !== null) {
            return $failure;
        }
        if ($write && ($failure = $this->verifyCsrf($request)) !== null) {
            return $failure;
        }
        try {
            return Response::json((new CollaborationActions($this->app->database()))->run($action, CollaborationAccess::positive($id), (int) $this->app->auth()->user()['id'], (array) ($write ? $request->input() : $request->query()), null, $request));
        } catch (\Throwable $error) {
            return CollaborationResponse::failure($error);
        }
    }
}
