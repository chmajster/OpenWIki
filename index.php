<?php

declare(strict_types=1);

// Convenience front controller for deployments where the extracted repository
// itself is the web root. The canonical application front controller remains
// public/index.php so deployments with DocumentRoot=public continue to work.
require __DIR__ . '/public/index.php';
