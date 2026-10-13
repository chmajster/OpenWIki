<?php

declare(strict_types=1);

namespace OpenWiki\Http\Controllers;

use OpenWiki\Core\Request;
use OpenWiki\Core\Response;
use OpenWiki\Core\Session;
use OpenWiki\Http\Controller;
use OpenWiki\Wiki\PageEngagementService;

final class NotificationController extends Controller
{
    public function index(Request $request): Response
    {
        if (($failure = $this->requireAuth($request)) !== null) {
            return $failure;
        }

        $user = $this->app->auth()->user();
        $service = new PageEngagementService($this->app->database());

        return $this->render('notifications/index', [
            'title' => 'Notifications',
            'notifications' => $service->notificationsForUser((int) $user['id']),
            'unreadCount' => $service->unreadCount((int) $user['id']),
        ]);
    }

    public function read(Request $request, string $id): Response
    {
        if (($failure = $this->requireAuth($request)) !== null) {
            return $failure;
        }
        if (($failure = $this->verifyCsrf($request)) !== null) {
            return $failure;
        }

        $notificationId = filter_var($id, FILTER_VALIDATE_INT);
        if ($notificationId === false || $notificationId < 1) {
            return $this->render('errors/404', ['title' => 'Notification not found'], 404);
        }

        $user = $this->app->auth()->user();
        $service = new PageEngagementService($this->app->database());

        $notification = $service->notificationForUser((int) $user['id'], (int) $notificationId);
        if ($notification === null || !$service->markRead((int) $user['id'], (int) $notificationId)) {
            return $this->render('errors/404', ['title' => 'Notification not found'], 404);
        }

        $target = (string) $notification['target_url'];
        if (!preg_match('~^/(?!/)[^\x00-\x20\\\\]*$~D', $target)) {
            $target = '/notifications';
        }

        return Response::redirect($target);
    }

    public function readAll(Request $request): Response
    {
        if (($failure = $this->requireAuth($request)) !== null) {
            return $failure;
        }
        if (($failure = $this->verifyCsrf($request)) !== null) {
            return $failure;
        }

        $user = $this->app->auth()->user();
        (new PageEngagementService($this->app->database()))->markAllRead((int) $user['id']);
        Session::flash('success', 'All notifications marked as read.');

        return Response::redirect('/notifications');
    }
}
