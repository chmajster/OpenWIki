<?php

declare(strict_types=1);

namespace OpenWiki\Tests\Integration;

use OpenWiki\Core\Application;
use OpenWiki\Database\MigrationRunner;
use OpenWiki\Wiki\MacroService;
use PHPUnit\Framework\TestCase;

final class MacroServiceTest extends TestCase
{
    private Application $app;
    private array $oldEnv = [];
    private string $lockPath;
    private string|false $oldLock;

    protected function setUp(): void
    {
        if (!getenv('OPENWIKI_TEST_DB_HOST')) {
            self::markTestSkipped('MySQL integration environment is not configured.');
        }

        $basePath = dirname(__DIR__, 2);
        $this->lockPath = $basePath . '/storage/installed.lock';
        $this->oldLock = is_file($this->lockPath) ? file_get_contents($this->lockPath) : false;

        foreach ([
            'APP_KEY' => str_repeat('9', 64),
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

        foreach ($this->oldEnv as $key => $value) {
            $value === false ? putenv($key) : putenv($key . '=' . $value);
        }

        if ($this->oldLock === false) {
            @unlink($this->lockPath);
        } else {
            file_put_contents($this->lockPath, $this->oldLock);
        }
    }

    public function testMacrosRenderSafelyAndBuildAnchoredToc(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $userId = $this->app->database()->insert(
            'INSERT INTO users
             (username, email, password_hash, first_name, last_name, status, auth_source,
              force_password_change, created_at, updated_at)
             VALUES (:username, :email, :password_hash, "Macro", "User", "active", "local",
              0, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            [
                'username' => 'macro-' . $suffix,
                'email' => 'macro-' . $suffix . '@example.test',
                'password_hash' => password_hash('CorrectHorseBatteryStaple!42', PASSWORD_DEFAULT),
            ]
        );

        $spaceId = $this->app->database()->insert(
            'INSERT INTO spaces
             (name, space_key, description, icon, owner_id, visibility, status, created_at, updated_at, deleted_at)
             VALUES (:name, :space_key, NULL, NULL, :owner_id, "public", "active", UTC_TIMESTAMP(), UTC_TIMESTAMP(), NULL)',
            [
                'name' => 'Macro Space',
                'space_key' => 'MAC-' . strtoupper(substr($suffix, 0, 6)),
                'owner_id' => $userId,
            ]
        );

        $content = '<h2>Introduction</h2>'
            . '<p>{{status:Ready}}</p>'
            . '<h3>Details</h3>'
            . '<p>{{toc}}</p>'
            . '<p>{{child-pages}}</p>'
            . '<p>{{attachments}}</p>'
            . '<p>{{user-profile:macro-' . $suffix . '}}</p>'
            . '<p>{{info:&lt;script&gt;safe&lt;/script&gt;}}</p>'
            . '<pre><code>{{status:do-not-expand}}</code></pre>';

        $pageId = $this->createPage($spaceId, $userId, null, 'Parent ' . $suffix, $content);
        $childId = $this->createPage(
            $spaceId,
            $userId,
            $pageId,
            'Child ' . $suffix,
            '<p>Child</p>'
        );

        $this->app->database()->execute(
            'INSERT INTO attachments
             (page_id, uploader_id, name, storage_key, mime_type, size_bytes, current_version, created_at, updated_at, deleted_at)
             VALUES
             (:page_id, :uploader_id, "runbook.txt", :storage_key, "text/plain", 32, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP(), NULL)',
            [
                'page_id' => $pageId,
                'uploader_id' => $userId,
                'storage_key' => 'macro-test-' . $suffix,
            ]
        );

        $page = $this->app->database()->fetchOne(
            'SELECT p.*, u.username AS author_username
             FROM pages p
             INNER JOIN users u ON u.id = p.author_id
             WHERE p.id = :id',
            ['id' => $pageId]
        );
        $space = $this->app->database()->fetchOne(
            'SELECT * FROM spaces WHERE id = :id',
            ['id' => $spaceId]
        );

        $rendered = (new MacroService($this->app))->renderPage($page, $space);

        self::assertStringContainsString('id="introduction"', $rendered['html']);
        self::assertStringContainsString('id="details"', $rendered['html']);
        self::assertStringContainsString('Table of contents', $rendered['toc']);
        self::assertStringContainsString('href="#introduction"', $rendered['toc']);
        self::assertStringContainsString('macro-status', $rendered['html']);
        self::assertStringContainsString('Child ' . $suffix, $rendered['html']);
        self::assertStringContainsString('/pages/child-', $rendered['html']);
        self::assertStringContainsString('runbook.txt', $rendered['html']);
        self::assertStringContainsString('macro-' . $suffix, $rendered['html']);
        self::assertStringNotContainsString('<script>safe</script>', $rendered['html']);
        self::assertStringContainsString('&lt;script&gt;safe&lt;/script&gt;', $rendered['html']);
        self::assertStringContainsString('{{status:do-not-expand}}', $rendered['html']);
        self::assertGreaterThan(0, $childId);
    }

    private function createPage(
        int $spaceId,
        int $userId,
        ?int $parentId,
        string $title,
        string $contentHtml
    ): int {
        $slug = strtolower(str_replace(' ', '-', $title));

        return $this->app->database()->insert(
            'INSERT INTO pages
             (space_id, parent_id, title, slug, content_html, content_markdown, content_text, content_format,
              author_id, owner_id, order_index, inherit_acl, status, version, published_at, created_at, updated_at, deleted_at)
             VALUES
             (:space_id, :parent_id, :title, :slug, :content_html, NULL, :content_text, "visual",
              :author_id, :owner_id, 0, 1, "published", 1, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP(), NULL)',
            [
                'space_id' => $spaceId,
                'parent_id' => $parentId,
                'title' => $title,
                'slug' => $slug,
                'content_html' => $contentHtml,
                'content_text' => strip_tags($contentHtml),
                'author_id' => $userId,
                'owner_id' => $userId,
            ]
        );
    }
}
