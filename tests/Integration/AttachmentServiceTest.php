<?php

declare(strict_types=1);

namespace OpenWiki\Tests\Integration;

use OpenWiki\Attachments\AttachmentService;
use OpenWiki\Core\Database;
use OpenWiki\Database\MigrationRunner;
use PHPUnit\Framework\TestCase;

final class AttachmentServiceTest extends TestCase
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
        $this->database->pdo()->beginTransaction();

        $this->basePath = sys_get_temp_dir() . '/openwiki-attachment-' . bin2hex(random_bytes(6));
        mkdir($this->basePath . '/storage/attachments', 0770, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->database) && $this->database->pdo()->inTransaction()) {
            $this->database->pdo()->rollBack();
        }
        if (isset($this->basePath)) {
            $this->removeTree($this->basePath);
        }
    }

    public function testAttachmentStorageVersioningRenameAndSoftDelete(): void
    {
        $userId = $this->createUser();
        $spaceId = $this->database->insert(
            'INSERT INTO spaces
             (name, space_key, owner_id, visibility, status, created_at, updated_at)
             VALUES ("Files", :space_key, :owner_id, "public", "active", UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            ['space_key' => 'FILES-' . strtoupper(bin2hex(random_bytes(3))), 'owner_id' => $userId]
        );
        $pageId = $this->database->insert(
            'INSERT INTO pages
             (space_id, title, slug, content_html, content_text, content_format, author_id, owner_id,
              order_index, status, version, published_at, created_at, updated_at)
             VALUES (:space_id, "Files", :slug, "<p>Body</p>", "Body", "visual", :user_id, :user_id,
                     0, "published", 1, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            ['space_id' => $spaceId, 'slug' => 'files-' . bin2hex(random_bytes(3)), 'user_id' => $userId]
        );

        $sourceOne = $this->basePath . '/source-one.txt';
        file_put_contents($sourceOne, 'version one');

        $service = new AttachmentService($this->database, $this->basePath);
        $attachmentId = $service->createFromSource(
            $pageId,
            $userId,
            $sourceOne,
            '../../notes.txt'
        );

        $attachment = $service->find($attachmentId);
        self::assertNotNull($attachment);
        self::assertSame('notes.txt', $attachment['name']);
        self::assertSame(1, (int) $attachment['current_version']);
        self::assertFileExists($service->currentPath($attachment));
        self::assertSame(hash('sha256', 'version one'), $attachment['sha256']);

        $sourceTwo = $this->basePath . '/source-two.txt';
        file_put_contents($sourceTwo, 'version two');
        $version = $service->addVersionFromSource(
            $attachmentId,
            $userId,
            $sourceTwo,
            'replacement.txt'
        );

        self::assertSame(2, $version);
        $attachment = $service->find($attachmentId);
        self::assertSame(2, (int) $attachment['current_version']);
        self::assertSame(hash('sha256', 'version two'), $attachment['sha256']);
        self::assertCount(2, $service->versions($attachmentId));

        self::assertTrue($service->rename($attachmentId, 'renamed.txt'));
        self::assertSame('renamed.txt', $service->find($attachmentId)['name']);

        self::assertTrue($service->delete($attachmentId));
        self::assertNull($service->find($attachmentId));
    }

    private function createUser(): int
    {
        $suffix = bin2hex(random_bytes(4));

        return $this->database->insert(
            'INSERT INTO users
             (username, email, password_hash, status, auth_source, created_at, updated_at)
             VALUES (:username, :email, :password_hash, "active", "local", UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            [
                'username' => 'attachment-' . $suffix,
                'email' => 'attachment-' . $suffix . '@example.test',
                'password_hash' => password_hash('CorrectHorseBatteryStaple!42', PASSWORD_DEFAULT),
            ]
        );
    }

    private function removeTree(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);
            return;
        }

        $items = scandir($path);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $child = $path . '/' . $item;
            if (is_dir($child)) {
                $this->removeTree($child);
            } else {
                @unlink($child);
            }
        }
        @rmdir($path);
    }
}
