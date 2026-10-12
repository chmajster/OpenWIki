<?php
declare(strict_types=1);
namespace OpenWiki\Tests\Integration;

use OpenWiki\Core\Database;
use OpenWiki\Tests\Support\IsolatedDatabase;
use OpenWiki\Upgrade\RecoverySnapshot;
use PHPUnit\Framework\TestCase;

final class RecoverySnapshotTest extends TestCase
{
    public function testVerifiedSnapshotRestoresBinaryDataKeyConfigurationAndAttachment(): void
    {
        IsolatedDatabase::run(function (Database $db, array $config): void {
            $directory = sys_get_temp_dir() . '/openwiki-snapshot-' . bin2hex(random_bytes(8));
            mkdir($directory); mkdir($directory . '/storage'); mkdir($directory . '/storage/attachments');
            $env = 'APP_KEY=' . bin2hex(random_bytes(32)) . "\nDB_PASSWORD=secret-never-in-ui\n";
            file_put_contents($directory . '/.env', $env);
            file_put_contents($directory . '/storage/installed.lock', 'original marker');
            $binary = "\x00\xff\x01 image\nbytes";
            file_put_contents($directory . '/storage/attachments/protected.bin', $binary);
            $db->pdo()->exec('CREATE TABLE recovery_accounts (id INT PRIMARY KEY, secret BLOB, metadata TEXT, nullable TEXT NULL)');
            $db->execute('INSERT INTO recovery_accounts VALUES (1, ?, ?, NULL)', [$binary, "Title;\nSQL quotes ' and Unicode Żółć"]);
            $before = $db->fetchAll('SELECT * FROM recovery_accounts');
            $snapshot = new RecoverySnapshot($db, $directory);
            try {
                $receipt = $snapshot->create($directory . '/recovery.tar');
                self::assertSame(1, $receipt['tables']);
                if (PHP_OS_FAMILY !== 'Windows') self::assertSame(0600, fileperms($directory . '/recovery.tar') & 0777);
                self::assertSame($config['database'], $snapshot->verify($directory . '/recovery.tar', $receipt['sha256'])['database']);
                $db->execute('DELETE FROM recovery_accounts');
                file_put_contents($directory . '/.env', 'changed');
                file_put_contents($directory . '/storage/attachments/protected.bin', 'changed');
                try { $snapshot->restore($directory . '/recovery.tar', $receipt['sha256'], 'wrong_database'); self::fail('Confirmation must match.'); }
                catch (\RuntimeException) { self::assertSame([], $db->fetchAll('SELECT * FROM recovery_accounts')); }
                $snapshot->restore($directory . '/recovery.tar', $receipt['sha256'], $config['database']);
                self::assertSame($before, $db->fetchAll('SELECT * FROM recovery_accounts'));
                self::assertSame($env, file_get_contents($directory . '/.env'));
                self::assertSame($binary, file_get_contents($directory . '/storage/attachments/protected.bin'));
                self::assertSame('original marker', file_get_contents($directory . '/storage/installed.lock'));
                try { $snapshot->verify($directory . '/recovery.tar', str_repeat('0', 64)); self::fail('Corruption must block recovery.'); }
                catch (\RuntimeException $error) { self::assertStringContainsString('checksum', $error->getMessage()); }
            } finally {
                foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $file) {
                    if ($file->isDir()) rmdir($file->getPathname()); else unlink($file->getPathname());
                }
                rmdir($directory);
            }
        });
    }
}
