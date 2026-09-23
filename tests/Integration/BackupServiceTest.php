<?php

declare(strict_types=1);

namespace OpenWiki\Tests\Integration;

use OpenWiki\Backup\BackupService;
use OpenWiki\Core\Database;
use OpenWiki\Database\MigrationRunner;
use PharData;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;

final class BackupServiceTest extends TestCase
{
    private Database $database;
    private string $basePath;

    protected function setUp(): void
    {
        if (!getenv('OPENWIKI_TEST_DB_HOST')) {
            self::markTestSkipped('MySQL integration environment is not configured.');
        }

        $this->database = Database::connect([
            'host' => (string) getenv('OPENWIKI_TEST_DB_HOST'),
            'port' => (int) getenv('OPENWIKI_TEST_DB_PORT'),
            'database' => (string) getenv('OPENWIKI_TEST_DB_DATABASE'),
            'username' => (string) getenv('OPENWIKI_TEST_DB_USERNAME'),
            'password' => (string) getenv('OPENWIKI_TEST_DB_PASSWORD'),
            'charset' => 'utf8mb4',
        ]);
        (new MigrationRunner($this->database, dirname(__DIR__, 2) . '/database/migrations'))->migrate();

        $this->basePath = sys_get_temp_dir() . '/openwiki-backup-test-' . bin2hex(random_bytes(6));
        mkdir($this->basePath . '/storage/attachments', 0770, true);
        mkdir($this->basePath . '/storage/backups', 0770, true);
        mkdir($this->basePath . '/storage/temp', 0770, true);
    }

    protected function tearDown(): void
    {
        if (!isset($this->basePath) || !is_dir($this->basePath)) {
            return;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($this->basePath, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($this->basePath);
    }

    public function testBackupContainsDatabaseManifestAndSanitizedConfiguration(): void
    {
        file_put_contents($this->basePath . '/storage/attachments/test.bin', 'attachment');

        $service = new BackupService($this->database, $this->basePath);
        $backup = $service->create();

        self::assertFileExists($backup['path']);
        self::assertGreaterThan(0, $backup['size_bytes']);
        self::assertCount(1, $service->list());
        self::assertSame($backup['path'], $service->find($backup['name']));
        self::assertNull($service->find('../.env'));

        $archive = new PharData($backup['path']);
        self::assertTrue(isset($archive['database.sql']));
        self::assertTrue(isset($archive['manifest.json']));
        self::assertTrue(isset($archive['config.json']));
        self::assertTrue(isset($archive['attachments/test.bin']));

        $sql = $archive['database.sql']->getContent();
        self::assertStringContainsString('CREATE TABLE', $sql);
        self::assertStringContainsString('SET FOREIGN_KEY_CHECKS=0', $sql);

        $config = json_decode($archive['config.json']->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('APP_KEY', $config);
        self::assertArrayNotHasKey('DB_USERNAME', $config);
        self::assertArrayNotHasKey('DB_PASSWORD', $config);
        self::assertSame(['APP_KEY', 'DB_USERNAME', 'DB_PASSWORD'], $config['secrets_excluded']);
    }
}
