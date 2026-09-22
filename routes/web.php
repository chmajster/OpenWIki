<?php

declare(strict_types=1);

use OpenWiki\Http\Controllers\AuthController;
use OpenWiki\Http\Controllers\DashboardController;
use OpenWiki\Http\Controllers\HealthController;
use OpenWiki\Http\Controllers\InstallerController;
use OpenWiki\Http\Controllers\PageController;
use OpenWiki\Http\Controllers\PageDraftController;
use OpenWiki\Http\Controllers\SearchController;
use OpenWiki\Http\Controllers\SpaceController;

$router = $app->router();

$router->get('/install', [InstallerController::class, 'index']);
$router->post('/install', [InstallerController::class, 'store']);
$router->get('/health', [HealthController::class, 'show']);

$router->get('/login', [AuthController::class, 'loginForm']);
$router->post('/login', [AuthController::class, 'login']);
$router->post('/logout', [AuthController::class, 'logout']);

$router->get('/', [DashboardController::class, 'index']);
$router->get('/search', [SearchController::class, 'index']);

$router->get('/spaces/create', [SpaceController::class, 'create']);
$router->post('/spaces', [SpaceController::class, 'store']);
$router->get('/spaces/{spaceKey}', [SpaceController::class, 'show']);

$router->get('/spaces/{spaceKey}/pages/create', [PageController::class, 'create']);
$router->post('/spaces/{spaceKey}/pages', [PageController::class, 'store']);
$router->get('/spaces/{spaceKey}/pages/{slug}', [PageController::class, 'show']);
$router->get('/spaces/{spaceKey}/pages/{slug}/edit', [PageController::class, 'edit']);
$router->post('/spaces/{spaceKey}/pages/{slug}', [PageController::class, 'update']);
$router->get('/spaces/{spaceKey}/pages/{slug}/history', [PageController::class, 'history']);
$router->post('/spaces/{spaceKey}/pages/{slug}/restore', [PageController::class, 'restore']);

$router->post('/spaces/{spaceKey}/pages/{slug}/edit-lock', [PageDraftController::class, 'lock']);
$router->post('/spaces/{spaceKey}/pages/{slug}/edit-unlock', [PageDraftController::class, 'unlock']);
$router->post('/spaces/{spaceKey}/pages/{slug}/autosave', [PageDraftController::class, 'autosave']);

$router->post('/spaces/{spaceKey}/watch', [PageEngagementController::class, 'watchSpace']);
$router->post('/spaces/{spaceKey}/pages/{slug}/favorite', [PageEngagementController::class, 'favorite']);
$router->post('/spaces/{spaceKey}/pages/{slug}/watch', [PageEngagementController::class, 'watch']);
$router->get('/notifications', [NotificationController::class, 'index']);
$router->post('/notifications/read-all', [NotificationController::class, 'readAll']);
$router->post('/notifications/{id}/read', [NotificationController::class, 'read']);

$router->get('/account/api-tokens', [ApiTokenController::class, 'index']);
$router->post('/account/api-tokens', [ApiTokenController::class, 'create']);
$router->post('/account/api-tokens/{id}/revoke', [ApiTokenController::class, 'revoke']);

$router->get('/api/v1/spaces', [ApiV1Controller::class, 'spaces']);
$router->post('/api/v1/spaces', [ApiV1Controller::class, 'createSpace']);
$router->get('/api/v1/pages', [ApiV1Controller::class, 'pages']);
$router->post('/api/v1/pages', [ApiV1Controller::class, 'createPage']);
$router->get('/api/v1/pages/{id}', [ApiV1Controller::class, 'page']);
$router->put('/api/v1/pages/{id}', [ApiV1Controller::class, 'updatePage']);
$router->patch('/api/v1/pages/{id}', [ApiV1Controller::class, 'updatePage']);
$router->delete('/api/v1/pages/{id}', [ApiV1Controller::class, 'deletePage']);
$router->get('/api/v1/search', [ApiV1Controller::class, 'search']);
$router->get('/api/v1/users', [ApiV1Controller::class, 'users']);
$router->get('/api/v1/groups', [ApiV1Controller::class, 'groups']);
$router->get('/api/v1/tags', [ApiV1Controller::class, 'tags']);
$router->get('/api/v1/attachments', [ApiV1Controller::class, 'attachments']);
