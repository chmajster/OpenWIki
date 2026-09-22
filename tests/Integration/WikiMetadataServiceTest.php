<?php

declare(strict_types=1);

namespace OpenWiki\Tests\Integration;

use OpenWiki\Core\Database;
use OpenWiki\Database\MigrationRunner;
use OpenWiki\Wiki\WikiMetadataService;
use PHPUnit\Framework\TestCase;

final class WikiMetadataServiceTest extends TestCase
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

    public function testTagsWikiLinksBacklinksAndBrokenLinksStayConsistent(): void
    {
        $userId = $this->createUser();
        $spaceId = $this->database->insert(
            'INSERT INTO spaces
             (name, space_key, owner_id, visibility, status, created_at, updated_at)
             VALUES ("Wiki metadata", :space_key, :owner_id, "public", "active", UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            ['space_key' => 'META-' . strtoupper(bin2hex(random_bytes(3))), 'owner_id' => $userId]
        );

        $targetId = $this->createPage($spaceId, $userId, 'Linux', 'linux', '<p>Linux</p>');
        $sourceId = $this->createPage($spaceId, $userId, 'Runbook', 'runbook', '<p>Placeholder</p>');

        $service = new WikiMetadataService($this->database);
        $decorated = $service->decorateWikiLinks(
            $spaceId,
            'META',
            '<p>See [[Linux]] and [[Missing page]].</p><pre>[[Do not link]]</pre>'
        );

        self::assertCount(2, $decorated['references']);
        self::assertStringContainsString('/wiki/Linux', $decorated['html']);
        self::assertStringContainsString('wiki-link--broken', $decorated['html']);
        self::assertStringContainsString('[[Do not link]]', $decorated['html']);

        $service->syncLinks($sourceId, $spaceId, $decorated['references']);
        self::assertCount(1, $service->backlinks($targetId));
        self::assertCount(1, $service->brokenLinks());

        $service->syncTags($sourceId, 'linux, server, Linux');
        $tags = $service->tagsForPage($sourceId);
        self::assertCount(2, $tags);
        self::assertSame(['linux', 'server'], array_column($tags, 'slug'));

        $missingId = $this->createPage($spaceId, $userId, 'Missing page', 'missing-page', '<p>Now present</p>');
        self::assertGreaterThan(0, $missingId);

        $service->refreshSpaceLinks($spaceId);
        self::assertCount(0, $service->brokenLinks());

        $resolved = $service->resolveTarget($spaceId, 'Missing page');
        self::assertNotNull($resolved);
        self::assertSame('missing-page', $resolved['slug']);
    }

    private function createUser(): int
    {
        $suffix = bin2hex(random_bytes(4));

        return $this->database->insert(
            'INSERT INTO users
             (username, email, password_hash, status, auth_source, created_at, updated_at)
             VALUES (:username, :email, :password_hash, "active", "local", UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            [
                'username' => 'metadata-' . $suffix,
                'email' => 'metadata-' . $suffix . '@example.test',
                'password_hash' => password_hash('CorrectHorseBatteryStaple!42', PASSWORD_DEFAULT),
            ]
        );
    }

    private function createPage(int $spaceId, int $userId, string $title, string $slug, string $html): int
    {
        return $this->database->insert(
            'INSERT INTO pages
             (space_id, title, slug, content_html, content_text, content_format, author_id, owner_id,
              order_index, status, version, published_at, created_at, updated_at)
             VALUES (:space_id, :title, :slug, :content_html, :content_text, "visual", :author_id, :owner_id,
                     0, "published", 1, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            [
                'space_id' => $spaceId,
                'title' => $title,
                'slug' => $slug,
                'content_html' => $html,
                'content_text' => strip_tags($html),
                'author_id' => $userId,
                'owner_id' => $userId,
            ]
        );
    }
}
