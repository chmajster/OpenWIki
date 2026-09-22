<?php

declare(strict_types=1);

namespace OpenWiki\Tests\Integration;

use OpenWiki\Core\Database;
use OpenWiki\Database\MigrationRunner;
use OpenWiki\Wiki\PageTemplateService;
use PHPUnit\Framework\TestCase;

final class PageTemplateServiceTest extends TestCase
{
    private Database $database;

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
    }

    protected function tearDown(): void
    {
        if (isset($this->database) && $this->database->pdo()->inTransaction()) {
            $this->database->pdo()->rollBack();
        }
    }

    public function testCustomTemplateLifecycleSanitizesContentAndProtectsSystemTemplates(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $userId = $this->database->insert(
            'INSERT INTO users
             (username, email, password_hash, status, auth_source, force_password_change, created_at, updated_at)
             VALUES (:username, :email, :password_hash, "active", "local", 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            [
                'username' => 'template-' . $suffix,
                'email' => 'template-' . $suffix . '@example.test',
                'password_hash' => password_hash('CorrectHorseBatteryStaple!42', PASSWORD_DEFAULT),
            ]
        );

        $systemId = $this->database->insert(
            'INSERT INTO page_templates
             (name, description, content_html, content_markdown, created_by, is_system, created_at, updated_at)
             VALUES ("System Test", NULL, "<p>System</p>", NULL, :created_by, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            ['created_by' => $userId]
        );

        $service = new PageTemplateService($this->database);
        $customId = $service->save(null, [
            'name' => 'Custom ' . $suffix,
            'description' => 'Custom template',
            'content_format' => 'visual',
            'content_html' => '<h2>Safe</h2><script>alert(1)</script><p onclick="bad()">Body</p>',
            'content_markdown' => '',
        ], $userId);

        $custom = $service->find($customId);
        self::assertNotNull($custom);
        self::assertSame(0, (int) $custom['is_system']);
        self::assertStringContainsString('<h2>Safe</h2>', $custom['content_html']);
        self::assertStringNotContainsString('<script', $custom['content_html']);
        self::assertStringNotContainsString('onclick=', $custom['content_html']);

        $service->save($customId, [
            'name' => 'Markdown ' . $suffix,
            'description' => 'Updated',
            'content_format' => 'markdown',
            'content_html' => '',
            'content_markdown' => '# Heading' . "\n\n" . 'Template body',
        ], $userId);

        $custom = $service->find($customId);
        self::assertSame('# Heading' . "\n\n" . 'Template body', $custom['content_markdown']);
        self::assertStringContainsString('<h1>Heading</h1>', $custom['content_html']);

        try {
            $service->save($systemId, [
                'name' => 'Changed system',
                'content_format' => 'visual',
                'content_html' => '<p>Changed</p>',
            ], $userId);
            self::fail('System template update should be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('System templates are read-only.', $exception->getMessage());
        }

        try {
            $service->delete($systemId);
            self::fail('System template deletion should be rejected.');
        } catch (\InvalidArgumentException $exception) {
            self::assertSame('System templates cannot be deleted.', $exception->getMessage());
        }

        self::assertTrue($service->delete($customId));
        self::assertNull($service->find($customId));
    }
}
