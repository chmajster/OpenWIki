<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/app/Install/LocalRouter.php';

if (PHP_SAPI !== 'cli-server') { http_response_code(404); exit; }
$token = getenv('OPENWIKI_LOCAL_PROBE_TOKEN');
if (is_string($token) && preg_match('/^[a-f0-9]{32}$/D', $token)) header('X-OpenWiki-Local-Token: ' . $token);
$file = \OpenWiki\Install\LocalRouter::resolve(dirname(__DIR__) . '/public', $_SERVER['REQUEST_URI'] ?? '/');
if ($file === false) { http_response_code(404); echo 'Not found.'; return true; }
if (is_string($file)) {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['GET', 'HEAD'], true)) { http_response_code(405); header('Allow: GET, HEAD'); return true; }
    $types = ['css' => 'text/css', 'js' => 'application/javascript', 'json' => 'application/json', 'html' => 'text/html',
        'txt' => 'text/plain', 'xml' => 'application/xml', 'ico' => 'image/x-icon', 'png' => 'image/png', 'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg', 'gif' => 'image/gif', 'webp' => 'image/webp', 'svg' => 'image/svg+xml',
        'woff' => 'font/woff', 'woff2' => 'font/woff2', 'ttf' => 'font/ttf', 'eot' => 'application/vnd.ms-fontobject', 'webmanifest' => 'application/manifest+json'];
    header('Content-Type: ' . $types[strtolower(pathinfo($file, PATHINFO_EXTENSION))]);
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-cache');
    header('Content-Length: ' . filesize($file));
    if ($method === 'GET') readfile($file);
    return true;
}
// No test bootstrap or configuration; use the actual application and installer.
require dirname(__DIR__) . '/public/index.php';
