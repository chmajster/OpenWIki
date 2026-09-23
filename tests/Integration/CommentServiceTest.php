<?php

declare(strict_types=1);

namespace OpenWiki\Tests\Integration;

use OpenWiki\Core\Database;
use OpenWiki\Database\MigrationRunner;
use OpenWiki\Wiki\CommentService;
use PHPUnit\Framework\TestCase;

final class CommentServiceTest extends TestCase
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

    public function testRepliesMentionsEditingAndDeletion(): void
    {
        $authorName = 'comment-author-' . bin2hex(random_bytes(3));
        $otherName = 'comment-user-' . bin2hex(random_bytes(3));
        $author = $this->createUser($authorName);
        $other = $this->createUser($otherName);

        $spaceId = $this->database->insert(
            'INSERT INTO spaces
             (name, space_key, owner_id, visibility, status, created_at, updated_at)
             VALUES ("Comments", :space_key, :owner_id, "public", "active", UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            ['space_key' => 'COM-' . strtoupper(bin2hex(random_bytes(3))), 'owner_id' => $author]
        );
        $pageId = $this->database->insert(
            'INSERT INTO pages
             (space_id, title, slug, content_html, content_text, content_format, author_id, owner_id,
              order_index, status, version, published_at, created_at, updated_at)
             VALUES (:space_id, "Comments", :slug, "<p>Body</p>", "Body", "visual", :author_id, :owner_id,
                     0, "published", 1, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            ['space_id' => $spaceId, 'slug' => 'comments-' . bin2hex(random_bytes(3)), 'author_id' => $author, 'owner_id' => $author]
        );

        $service = new CommentService($this->database);
        $root = $service->create($pageId, $spaceId, $other, 'Initial comment', null, 'Comments', '/page');
        $reply = $service->create($pageId, $spaceId, $author, 'Thanks @' . $otherName, $root, 'Comments', '/page');

        $comments = $service->listForPage($pageId);
        self::assertCount(2, $comments);
        self::assertSame($root, (int) $comments[1]['parent_id']);

        $notifications = $this->database->fetchAll(
            'SELECT event_type FROM notifications WHERE user_id = :user_id ORDER BY id ASC',
            ['user_id' => $other]
        );
        self::assertSame(['reply', 'mention'], array_column($notifications, 'event_type'));

        self::assertTrue($service->update($reply, $author, 'Updated reply'));
        $updated = $this->database->fetchOne('SELECT body_html FROM comments WHERE id = :id', ['id' => $reply]);
        self::assertSame('Updated reply', $updated['body_html']);

        $this->expectException(\RuntimeException::class);
        $service->delete($root, $author, false);
    }

    private function createUser(string $username): int
    {
        return $this->database->insert(
            'INSERT INTO users
             (username, email, password_hash, status, auth_source, created_at, updated_at)
             VALUES (:username, :email, :password_hash, "active", "local", UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            [
                'username' => $username,
                'email' => $username . '@example.test',
                'password_hash' => password_hash('CorrectHorseBatteryStaple!42', PASSWORD_DEFAULT),
            ]
        );
    }
}
