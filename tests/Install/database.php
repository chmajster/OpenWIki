<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap/autoload.php';
use OpenWiki\Core\Database;

$name = $argv[2] ?? '';
if (!preg_match('/^openwiki_web_[a-f0-9]{12}_test$/D', $name)) throw new RuntimeException('Dedicated installer database required.');
$db = Database::connect(['host' => getenv('OPENWIKI_TEST_DB_HOST'), 'port' => getenv('OPENWIKI_TEST_DB_PORT'),
    'username' => getenv('OPENWIKI_TEST_DB_USERNAME'), 'password' => getenv('OPENWIKI_TEST_DB_PASSWORD')], false);
match ($argv[1] ?? '') {
    'create' => $db->pdo()->exec('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4'),
    'drop' => $db->pdo()->exec('DROP DATABASE IF EXISTS `' . $name . '`'),
    default => throw new RuntimeException('Unknown fixture action.'),
};
