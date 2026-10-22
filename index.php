<?php

declare(strict_types=1);

// Convenience front controller for deployments where the extracted repository
// itself is the web root. Serve the public assets here as well because Nginx
// does not read the root .htaccess rewrite that maps /assets to public/assets.
$requestPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
if (is_string($requestPath)) {
    $publicFile = null;
    if ($requestPath === '/favicon.ico' || $requestPath === '/manifest.json') {
        $publicFile = __DIR__ . '/public' . $requestPath;
    } elseif (preg_match('#\\A/assets/(?!.*(?:^|/)\\.\\.(?:/|$))[A-Za-z0-9_./-]+\\.(?:css|js|json|html|png|jpe?g|gif|webp|ico|svg|woff2?)\\z#iD', $requestPath)) {
        $publicFile = __DIR__ . '/public' . $requestPath;
    }

    if ($publicFile !== null) {
        $assetRoot = realpath(__DIR__ . '/public');
        $resolvedFile = realpath($publicFile);
        if ($assetRoot !== false && $resolvedFile !== false
            && str_starts_with($resolvedFile, $assetRoot . DIRECTORY_SEPARATOR)
            && is_file($resolvedFile) && is_readable($resolvedFile)) {
            $types = [
                'css' => 'text/css; charset=UTF-8',
                'gif' => 'image/gif',
                'html' => 'text/html; charset=UTF-8',
                'ico' => 'image/x-icon',
                'jpeg' => 'image/jpeg',
                'jpg' => 'image/jpeg',
                'js' => 'text/javascript; charset=UTF-8',
                'png' => 'image/png',
                'svg' => 'image/svg+xml',
                'webp' => 'image/webp',
                'woff' => 'font/woff',
                'woff2' => 'font/woff2',
                'json' => 'application/manifest+json; charset=UTF-8',
            ];
            $extension = strtolower(pathinfo($resolvedFile, PATHINFO_EXTENSION));
            header('Content-Type: ' . ($types[$extension] ?? 'application/octet-stream'));
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: no-cache, must-revalidate');
            header('Content-Length: ' . filesize($resolvedFile));
            if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'HEAD') {
                readfile($resolvedFile);
            }
            exit;
        }
    }
}

// The canonical application front controller remains public/index.php for
// deployments whose document root already points to public/.
require __DIR__ . '/public/index.php';
