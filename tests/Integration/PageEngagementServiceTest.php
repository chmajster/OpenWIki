<?php

declare(strict_types=1);

namespace OpenWiki\Tests\Integration;

use OpenWiki\Core\Database;
use OpenWiki\Database\MigrationRunner;
use OpenWiki\Wiki\PageEngagementService;
use PHPUnit\Framework\TestCase;

final class PageEngagementServiceTest extends TestCase
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

    public function testFavoritesWatchesAndNotificationsArePersisted(): void
    {
        $author = $this->createUser('author-engagement', 'author-engagement@example.test');
        $watcher = $this->createUser('watcher-engagement', 'watcher-engagement@example.test');

        $spaceId = $this->database->insert(
            'INSERT INTO spaces
             (name, space_key, description, owner_id, visibility, status, created_at, updated_at)
             VALUES ("Engagement", "ENGAGEMENT", NULL, :owner_id, "public", "active", UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            ['owner_id' => $author]
        );

        $pageId = $this->database->insert(
            'INSERT INTO pages
             (space_id, parent_id, title, slug, content_html, content_markdown, content_text, content_format,
              author_id, owner_id, order_index, status, version, published_at, created_at, updated_at)
             VALUES
             (:space_id, NULL, "Watch me", "watch-me", "<p>Body</p>", NULL, "Body", "visual",
              :author_id, :owner_id, 0, "published", 1, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            ['space_id' => $spaceId, 'author_id' => $author, 'owner_id' => $author]
        );

        $service = new PageEngagementService($this->database);

        $service->setFavorite($watcher, $pageId, true);
        self::assertTrue($service->isFavorite($watcher, $pageId));
        self::assertCount(1, $service->favoritesForUser($watcher));

        $service->setWatch($watcher, 'space', $spaceId, true);
        self::assertTrue($service->isWatching($watcher, 'space', $spaceId));

        $service->notifyWatchers(
            $pageId,
            $spaceId,
            $author,
            'page.updated',
            'Page updated: Watch me',
            '/spaces/ENGAGEMENT/pages/watch-me'
        );

        self::assertSame(1, $service->unreadCount($watcher));
        $notifications = $service->notificationsForUser($watcher);
        self::assertCount(1, $notifications);

        self::assertTrue($service->markRead($watcher, (int) $notifications[0]['id']));
        self::assertSame(0, $service->unreadCount($watcher));

        $service->setFavorite($watcher, $pageId, false);
        self::assertFalse($service->isFavorite($watcher, $pageId));
    }

    private function createUser(string $username, string $email): int
    {
        return $this->database->insert(
            'INSERT INTO users
             (username, email, password_hash, status, auth_source, created_at, updated_at)
             VALUES (:username, :email, :password_hash, "active", "local", UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            [
                'username' => $username . '-' . bin2hex(random_bytes(4)),
                'email' => bin2hex(random_bytes(4)) . '-' . $email,
                'password_hash' => password_hash('CorrectHorseBatteryStaple!42', PASSWORD_DEFAULT),
            ]
        );
    }
}
