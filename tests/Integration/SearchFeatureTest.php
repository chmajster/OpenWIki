<?php

declare(strict_types=1);

namespace OpenWiki\Tests\Integration;

use OpenWiki\Core\Database;
use OpenWiki\Database\MigrationRunner;
use OpenWiki\Repositories\SearchRepository;
use OpenWiki\Search\SearchIndexer;
use PHPUnit\Framework\TestCase;

final class SearchFeatureTest extends TestCase
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

    public function testMultiTypeSearchFiltersAndIndexRebuild(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $authorId = $this->createUser('alice-' . $suffix, 'Alice', 'Admin');
        $spaceId = $this->database->insert(
            'INSERT INTO spaces
             (name, space_key, description, icon, owner_id, visibility, status, created_at, updated_at, deleted_at)
             VALUES (:name, :space_key, :description, NULL, :owner_id, "public", "active", UTC_TIMESTAMP(), UTC_TIMESTAMP(), NULL)',
            [
                'name' => 'Networking Knowledge ' . $suffix,
                'space_key' => 'NET-' . strtoupper(substr($suffix, 0, 6)),
                'description' => 'Firewall and routing knowledge',
                'owner_id' => $authorId,
            ]
        );

        $pageId = $this->database->insert(
            'INSERT INTO pages
             (space_id, parent_id, title, slug, content_html, content_markdown, content_text, content_format,
              author_id, owner_id, order_index, inherit_acl, status, version, published_at, created_at, updated_at, deleted_at)
             VALUES
             (:space_id, NULL, :title, :slug, :content_html, NULL, "stale index", "visual",
              :author_id, :owner_id, 0, 1, "published", 1, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP(), NULL)',
            [
                'space_id' => $spaceId,
                'title' => 'Linux Firewall Guide ' . $suffix,
                'slug' => 'linux-firewall-' . $suffix,
                'content_html' => '<p>Firewall iptables troubleshooting guide</p>',
                'author_id' => $authorId,
                'owner_id' => $authorId,
            ]
        );

        $tagId = $this->database->insert(
            'INSERT INTO tags (name, slug, created_at)
             VALUES (:name, :slug, UTC_TIMESTAMP())',
            ['name' => 'security-' . $suffix, 'slug' => 'security-' . $suffix]
        );
        $this->database->execute(
            'INSERT INTO page_tags (page_id, tag_id) VALUES (:page_id, :tag_id)',
            ['page_id' => $pageId, 'tag_id' => $tagId]
        );

        $this->database->execute(
            'INSERT INTO comments
             (page_id, parent_id, author_id, body_html, created_at, updated_at, deleted_at)
             VALUES (:page_id, NULL, :author_id, :body_html, UTC_TIMESTAMP(), UTC_TIMESTAMP(), NULL)',
            [
                'page_id' => $pageId,
                'author_id' => $authorId,
                'body_html' => 'Investigate nftables migration ' . $suffix,
            ]
        );

        $this->database->execute(
            'INSERT INTO attachments
             (page_id, uploader_id, name, storage_key, mime_type, size_bytes, current_version, created_at, updated_at, deleted_at)
             VALUES
             (:page_id, :uploader_id, :name, :storage_key, "image/png", 2048, 1, UTC_TIMESTAMP(), UTC_TIMESTAMP(), NULL)',
            [
                'page_id' => $pageId,
                'uploader_id' => $authorId,
                'name' => 'firewall-diagram-' . $suffix . '.png',
                'storage_key' => 'test/' . $suffix,
            ]
        );

        $index = (new SearchIndexer($this->database))->rebuild();
        self::assertGreaterThanOrEqual(1, $index['updated']);

        $page = $this->database->fetchOne(
            'SELECT content_text FROM pages WHERE id = :id',
            ['id' => $pageId]
        );
        self::assertSame('Firewall iptables troubleshooting guide', $page['content_text']);

        $repository = new SearchRepository($this->database);
        $filters = [
            'space' => 'NET-',
            'author' => 'alice-',
            'tag' => 'security-' . $suffix,
            'created_from' => gmdate('Y-m-d 00:00:00', time() - 86400),
            'created_to' => gmdate('Y-m-d 23:59:59', time() + 86400),
            'updated_from' => '',
            'updated_to' => '',
        ];

        $pages = $repository->searchPages('Firewall', $filters);
        self::assertNotEmpty($pages);
        self::assertSame($pageId, (int) $pages[0]['id']);

        self::assertNotEmpty($repository->searchPages('security-' . $suffix));
        self::assertNotEmpty($repository->searchSpaces('Networking', ['space' => 'NET-']));
        self::assertNotEmpty($repository->searchUsers('alice-' . $suffix, ['author' => 'alice-']));
        self::assertNotEmpty($repository->searchComments('nftables', $filters));
        self::assertNotEmpty($repository->searchAttachments('diagram-' . $suffix, $filters));
        self::assertNotEmpty($repository->searchTags('security-' . $suffix));

        $futureFilters = $filters;
        $futureFilters['created_from'] = '2099-01-01 00:00:00';
        self::assertSame([], $repository->searchPages('Firewall', $futureFilters));
    }

    private function createUser(string $username, string $firstName, string $lastName): int
    {
        return $this->database->insert(
            'INSERT INTO users
             (username, email, password_hash, first_name, last_name, status, auth_source,
              force_password_change, created_at, updated_at)
             VALUES
             (:username, :email, :password_hash, :first_name, :last_name, "active", "local",
              0, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            [
                'username' => $username,
                'email' => $username . '@example.test',
                'password_hash' => password_hash('CorrectHorseBatteryStaple!42', PASSWORD_DEFAULT),
                'first_name' => $firstName,
                'last_name' => $lastName,
            ]
        );
    }
}
