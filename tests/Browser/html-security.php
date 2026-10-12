<?php
require dirname(__DIR__, 2) . '/bootstrap/autoload.php';
$sanitizer = new OpenWiki\Security\HtmlSanitizer();
echo json_encode(['policy' => $sanitizer::policy(), 'html' => array_map($sanitizer->sanitize(...), require dirname(__DIR__) . '/Fixtures/security-html.php')], JSON_THROW_ON_ERROR);
