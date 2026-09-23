<?php

declare(strict_types=1);

namespace OpenWiki\Tests\Integration;

use OpenWiki\Core\Application;
use OpenWiki\Database\MigrationRunner;
use OpenWiki\Export\DocumentExportService;
use PHPUnit\Framework\TestCase;
use ZipArchive;

final class DocumentExportServiceTest extends TestCase
{
    private Application $app;
    private array $oldEnv = [];
    private string $lockPath;
    private string|false $oldLock;
    private array $tempFiles = [];

    protected function setUp(): void
    {
        if (!getenv('OPENWIKI_TEST_DB_HOST')) {
            self::markTestSkipped('MySQL integration environment is not configured.');
        }

        $basePath = dirname(__DIR__, 2);
        $this->lockPath = $basePath . '/storage/installed.lock';
        $this->oldLock = is_file($this->lockPath) ? file_get_contents($this->lockPath) : false;

        foreach ([
            'APP_KEY' => str_repeat('7', 64),
            'DB_HOST' => (string) getenv('OPENWIKI_TEST_DB_HOST'),
            'DB_PORT' => (string) getenv('OPENWIKI_TEST_DB_PORT'),
            'DB_DATABASE' => (string) getenv('OPENWIKI_TEST_DB_DATABASE'),
            'DB_USERNAME' => (string) getenv('OPENWIKI_TEST_DB_USERNAME'),
            'DB_PASSWORD' => (string) getenv('OPENWIKI_TEST_DB_PASSWORD'),
            'DB_CHARSET' => 'utf8mb4',
        ] as $key => $value) {
            $this->oldEnv[$key] = getenv($key);
            putenv($key . '=' . $value);
        }

        file_put_contents($this->lockPath, '{"test":true}' . PHP_EOL);
        $this->app = Application::boot($basePath);
        (new MigrationRunner($this->app->database(), $basePath . '/database/migrations'))->migrate();
        $this->app->database()->pdo()->beginTransaction();
    }

    protected function tearDown(): void
    {
        if (isset($this->app) && $this->app->database()->pdo()->inTransaction()) {
            $this->app->database()->pdo()->rollBack();
        }

        foreach ($this->tempFiles as $path) {
            @unlink($path);
        }

        foreach ($this->oldEnv as $key => $value) {
            $value === false ? putenv($key) : putenv($key . '=' . $value);
        }

        if ($this->oldLock === false) {
            @unlink($this->lockPath);
        } else {
            file_put_contents($this->lockPath, $this->oldLock);
        }
    }

    public function testPageAndSpaceExportsAreComplete(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $userId = $this->app->database()->insert(
            'INSERT INTO users
             (username, email, password_hash, status, auth_source, force_password_change, created_at, updated_at)
             VALUES (:username, :email, :password_hash, "active", "local", 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            [
                'username' => 'export-' . $suffix,
                'email' => 'export-' . $suffix . '@example.test',
                'password_hash' => password_hash('CorrectHorseBatteryStaple!42', PASSWORD_DEFAULT),
            ]
        );

        $spaceId = $this->app->database()->insert(
            'INSERT INTO spaces
             (name, space_key, description, icon, owner_id, visibility, status, created_at, updated_at, deleted_at)
             VALUES ("Export Space", :space_key, NULL, NULL, :owner_id, "public", "active", UTC_TIMESTAMP(), UTC_TIMESTAMP(), NULL)',
            [
                'space_key' => 'EXP-' . strtoupper(substr($suffix, 0, 6)),
                'owner_id' => $userId,
            ]
        );
        $space = $this->app->database()->fetchOne(
            'SELECT * FROM spaces WHERE id = :id',
            ['id' => $spaceId]
        );

        $pageId = $this->app->database()->insert(
            'INSERT INTO pages
             (space_id, parent_id, title, slug, content_html, content_markdown, content_text, content_format,
              author_id, owner_id, order_index, inherit_acl, status, version, published_at, created_at, updated_at, deleted_at)
             VALUES
             (:space_id, NULL, :title, :slug, :html, :markdown, :text, "markdown",
              :author_id, :owner_id, 0, 1, "published", 1, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP(), NULL)',
            [
                'space_id' => $spaceId,
                'title' => 'Export Guide ' . $suffix,
                'slug' => 'export-guide-' . $suffix,
                'html' => '<h2>Overview</h2><p>Export body</p><p>{{status:Ready}}</p>',
                'markdown' => '## Overview' . "\n\n" . 'Export body',
                'text' => 'Overview Export body',
                'author_id' => $userId,
                'owner_id' => $userId,
            ]
        );

        $page = $this->app->database()->fetchOne(
            'SELECT p.*, u.username AS author_username
             FROM pages p
             INNER JOIN users u ON u.id = p.author_id
             WHERE p.id = :id',
            ['id' => $pageId]
        );

        $service = new DocumentExportService($this->app);
        $html = $service->pageHtml($page, $space);
        self::assertStringContainsString('<!doctype html>', strtolower($html));
        self::assertStringContainsString('Export body', $html);
        self::assertStringContainsString('macro-status', $html);

        $markdown = $service->pageMarkdown($page);
        self::assertStringStartsWith('# Export Guide ' . $suffix, $markdown);
        self::assertStringContainsString('## Overview', $markdown);

        $pdf = $service->pagePdf($page, $space);
        self::assertStringStartsWith('%PDF-1.4', $pdf);
        self::assertStringContainsString('xref', $pdf);
        self::assertStringContainsString('%%EOF', $pdf);

        $archive = $service->spaceArchive($space, [$page]);
        $this->tempFiles[] = $archive['path'];
        self::assertFileExists($archive['path']);
        self::assertStringEndsWith('.zip', $archive['filename']);

        $zip = new ZipArchive();
        self::assertTrue($zip->open($archive['path']) === true);
        try {
            self::assertNotFalse($zip->locateName('manifest.json'));
            self::assertNotFalse($zip->locateName('index.html'));

            $manifest = json_decode(
                (string) $zip->getFromName('manifest.json'),
                true,
                512,
                JSON_THROW_ON_ERROR
            );
            self::assertSame((int) $space['id'], (int) $manifest['space']['id']);
            self::assertCount(1, $manifest['pages']);

            $htmlPath = $manifest['pages'][0]['html'];
            $markdownPath = $manifest['pages'][0]['markdown'];
            self::assertNotFalse($zip->locateName($htmlPath));
            self::assertNotFalse($zip->locateName($markdownPath));
            self::assertStringContainsString(
                'Export body',
                (string) $zip->getFromName($htmlPath)
            );
        } finally {
            $zip->close();
        }
    }

    public function testHtmlOnlyPageGetsMarkdownFallback(): void
    {
        $service = new DocumentExportService($this->app);
        $markdown = $service->pageMarkdown([
            'title' => 'HTML page',
            'content_markdown' => null,
            'content_html' => '<h2>Heading</h2><p><strong>Bold</strong> text.</p>',
        ]);

        self::assertStringContainsString('## Heading', $markdown);
        self::assertStringContainsString('**Bold** text.', $markdown);
    }
}
