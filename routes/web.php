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
