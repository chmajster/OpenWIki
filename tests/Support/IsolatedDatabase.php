<?php
declare(strict_types=1);
namespace OpenWiki\Tests\Support;

use OpenWiki\Core\Database;
use PHPUnit\Framework\TestCase;

final class IsolatedDatabase
{
    public static function run(callable $callback): void
    {
        if (!getenv('OPENWIKI_TEST_DB_HOST')) TestCase::markTestSkipped('Dedicated MySQL/MariaDB environment required.');
        $config = ['host' => getenv('OPENWIKI_TEST_DB_HOST'), 'port' => (int) getenv('OPENWIKI_TEST_DB_PORT'),
            'username' => getenv('OPENWIKI_TEST_DB_USERNAME'), 'password' => getenv('OPENWIKI_TEST_DB_PASSWORD')];
        $admin = Database::connect($config, false);
        $name = 'openwiki_recovery_' . bin2hex(random_bytes(6)) . '_test';
        $admin->pdo()->exec('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4');
        try { $callback(Database::connect($config + ['database' => $name]), $config + ['database' => $name]); }
        finally { $admin->pdo()->exec('DROP DATABASE `' . $name . '`'); }
    }
}
