<?php
// Route the real application, serving only its public static assets directly.
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if (is_string($path) && str_starts_with($path, '/assets/') && is_file(dirname(__DIR__, 2) . '/public' . $path)) return false;
require dirname(__DIR__, 2) . '/public/index.php';
