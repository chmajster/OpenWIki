<?php

declare(strict_types=1);

namespace OpenWiki\Tests\Integration;

use OpenWiki\Core\Database;
use OpenWiki\Database\MigrationRunner;
use OpenWiki\Import\DocumentImportService;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class DocumentImportServiceTest extends TestCase
{
    private Database $database;
    private int $userId;
    private array $space;
    private array $tempFiles = [];

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
        $this->database->pdo()->beginTransaction();

        $suffix = bin2hex(random_bytes(4));
        $this->userId = $this->database->insert(
            'INSERT INTO users
             (username, email, password_hash, status, auth_source, force_password_change, created_at, updated_at)
             VALUES (:username, :email, :password_hash, "active", "local", 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            [
                'username' => 'import-' . $suffix,
                'email' => 'import-' . $suffix . '@example.test',
                'password_hash' => password_hash('CorrectHorseBatteryStaple!42', PASSWORD_DEFAULT),
            ]
        );
        $spaceId = $this->database->insert(
            'INSERT INTO spaces
             (name, space_key, description, icon, owner_id, visibility, status, created_at, updated_at, deleted_at)
             VALUES ("Import Space", :space_key, NULL, NULL, :owner_id, "private", "active", UTC_TIMESTAMP(), UTC_TIMESTAMP(), NULL)',
            ['space_key' => 'IMP-' . strtoupper(substr($suffix, 0, 6)), 'owner_id' => $this->userId]
        );
        $this->space = $this->database->fetchOne('SELECT * FROM spaces WHERE id = :id', ['id' => $spaceId]);
    }

    protected function tearDown(): void
    {
        if (isset($this->database) && $this->database->pdo()->inTransaction()) {
            $this->database->pdo()->rollBack();
        }
        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }
    }

    public function testMarkdownHtmlAndZipImportCreateDraftHierarchy(): void
    {
        $service = new DocumentImportService($this->database);

        $markdown = $this->tempFile('.md', "# Linux Runbook\n\nUse **systemctl**.");
        $single = $service->importFromPath(
            $this->space,
            $this->userId,
            $markdown,
            'Linux_Runbook.md'
        );

        self::assertSame(1, $single['imported_pages']);
        $page = $this->database->fetchOne(
            'SELECT * FROM pages WHERE id = :id',
            ['id' => (int) $single['page_ids'][0]]
        );
        self::assertSame('draft', $page['status']);
        self::assertSame('markdown', $page['content_format']);
        self::assertStringContainsString('<h1>Linux Runbook</h1>', $page['content_html']);

        $html = $this->tempFile('.html', '<h2>Safe</h2><script>alert(1)</script><p onclick="bad()">Body</p>');
        $htmlResult = $service->importFromPath(
            $this->space,
            $this->userId,
            $html,
            'safe.html'
        );
        $htmlPage = $this->database->fetchOne(
            'SELECT content_html FROM pages WHERE id = :id',
            ['id' => (int) $htmlResult['page_ids'][0]]
        );
        self::assertStringNotContainsString('<script', $htmlPage['content_html']);
        self::assertStringNotContainsString('onclick=', $htmlPage['content_html']);

        $zipPath = $this->tempFile('.zip', '');
        @unlink($zipPath);
        $zip = new ZipArchive();
        self::assertTrue($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('docs/intro.md', "# Intro\n\nHello");
        $zip->addFromString('docs/setup.html', '<h2>Setup</h2><p>Install</p>');
        $zip->addFromString('assets/logo.png', 'not-an-image');
        $zip->close();

        $zipResult = $service->importFromPath(
            $this->space,
            $this->userId,
            $zipPath,
            'documentation.zip'
        );

        self::assertSame(2, $zipResult['imported_pages']);
        self::assertSame(1, $zipResult['section_pages']);
        self::assertSame(1, $zipResult['skipped_files']);

        $section = $this->database->fetchOne(
            'SELECT * FROM pages WHERE space_id = :space_id AND title = "docs" AND deleted_at IS NULL',
            ['space_id' => (int) $this->space['id']]
        );
        self::assertNotNull($section);

        $children = $this->database->fetchAll(
            'SELECT title, parent_id FROM pages
             WHERE space_id = :space_id AND parent_id = :parent_id AND deleted_at IS NULL
             ORDER BY title',
            ['space_id' => (int) $this->space['id'], 'parent_id' => (int) $section['id']]
        );
        self::assertCount(2, $children);
        self::assertSame(['intro', 'setup'], array_column($children, 'title'));
    }

    public function testZipPathTraversalIsRejected(): void
    {
        $zipPath = $this->tempFile('.zip', '');
        @unlink($zipPath);
        $zip = new ZipArchive();
        self::assertTrue($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE));
        $zip->addFromString('../escape.md', '# Escape');
        $zip->close();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('path traversal');

        (new DocumentImportService($this->database))->importFromPath(
            $this->space,
            $this->userId,
            $zipPath,
            'malicious.zip'
        );
    }

    private function tempFile(string $suffix, string $content): string
    {
        $base = tempnam(sys_get_temp_dir(), 'openwiki-import-');
        self::assertNotFalse($base);
        $path = $base . $suffix;
        @unlink($base);
        file_put_contents($path, $content);
        $this->tempFiles[] = $path;
        return $path;
    }
}
