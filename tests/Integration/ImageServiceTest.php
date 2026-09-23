<?php

declare(strict_types=1);

namespace OpenWiki\Tests\Integration;

use FilesystemIterator;
use OpenWiki\Attachments\AttachmentService;
use OpenWiki\Core\Database;
use OpenWiki\Database\MigrationRunner;
use OpenWiki\Images\ImageService;
use OpenWiki\Images\InlineImageService;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

final class ImageServiceTest extends TestCase
{
    private Database $database;
    private string $basePath;

    protected function setUp(): void
    {
        if (!getenv('OPENWIKI_TEST_DB_HOST')) {
            self::markTestSkipped('MySQL integration environment is not configured.');
        }
        if (!extension_loaded('gd')) {
            self::markTestSkipped('GD extension is not available.');
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

        $this->basePath = sys_get_temp_dir() . '/openwiki-image-' . bin2hex(random_bytes(5));
        mkdir($this->basePath . '/storage/attachments', 0770, true);
        mkdir($this->basePath . '/storage/cache', 0770, true);
    }

    protected function tearDown(): void
    {
        if (isset($this->database) && $this->database->pdo()->inTransaction()) {
            $this->database->pdo()->rollBack();
        }

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

    public function testImageThumbnailAndInlineMaterialization(): void
    {
        [$userId, $pageId] = $this->fixture();
        $png = base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAusB9Y9ZlYsAAAAASUVORK5CYII=',
            true
        );
        self::assertIsString($png);

        $source = $this->basePath . '/pixel.png';
        file_put_contents($source, $png);

        $attachments = new AttachmentService($this->database, $this->basePath);
        $attachmentId = $attachments->createFromSource($pageId, $userId, $source, 'pixel.png');
        $attachment = $attachments->find($attachmentId);
        self::assertNotNull($attachment);

        $images = new ImageService($attachments, $this->basePath);
        $info = $images->inspectAttachment($attachment);
        self::assertSame(1, $info['width']);
        self::assertSame(1, $info['height']);
        self::assertSame('image/png', $info['mime_type']);

        $thumbnail = $images->thumbnail($attachment, 320);
        self::assertFileExists($thumbnail['path']);
        self::assertSame(1, $thumbnail['width']);
        self::assertSame(1, $thumbnail['height']);

        $inline = new InlineImageService($this->database, $this->basePath);
        $result = $inline->materializeDataImages(
            '<figure><img src="data:image/png;base64,' . base64_encode($png)
            . '" alt="Pixel" width="300"><figcaption>Caption</figcaption></figure>',
            $pageId,
            $userId
        );

        self::assertCount(1, $result['attachment_ids']);
        self::assertStringNotContainsString('data:image/', $result['html']);
        self::assertStringContainsString('/thumbnail?width=300', $result['html']);
        self::assertStringContainsString('data-attachment-id=', $result['html']);
        self::assertStringContainsString('<figcaption>Caption</figcaption>', $result['html']);
        self::assertStringContainsString('Caption', $result['text']);
    }

    public function testNonImageIsRejected(): void
    {
        $path = $this->basePath . '/not-image.txt';
        file_put_contents($path, 'not an image');

        $this->expectException(\InvalidArgumentException::class);
        (new ImageService(
            new AttachmentService($this->database, $this->basePath),
            $this->basePath
        ))->inspectPath($path);
    }

    private function fixture(): array
    {
        $suffix = bin2hex(random_bytes(4));
        $userId = $this->database->insert(
            'INSERT INTO users
             (username, email, password_hash, status, auth_source, force_password_change, created_at, updated_at)
             VALUES (:username, :email, :password_hash, "active", "local", 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            [
                'username' => 'image-' . $suffix,
                'email' => 'image-' . $suffix . '@example.test',
                'password_hash' => password_hash('CorrectHorseBatteryStaple!42', PASSWORD_DEFAULT),
            ]
        );
        $spaceId = $this->database->insert(
            'INSERT INTO spaces
             (name, space_key, owner_id, visibility, status, created_at, updated_at)
             VALUES ("Image Space", :space_key, :owner_id, "public", "active", UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            [
                'space_key' => 'IMG-' . strtoupper(substr($suffix, 0, 6)),
                'owner_id' => $userId,
            ]
        );
        $pageId = $this->database->insert(
            'INSERT INTO pages
             (space_id, title, slug, content_html, content_text, content_format, author_id, owner_id,
              order_index, inherit_acl, status, version, published_at, created_at, updated_at)
             VALUES (:space_id, "Image page", :slug, "<p>Image</p>", "Image", "visual", :author_id, :owner_id,
                     0, 1, "published", 1, UTC_TIMESTAMP(), UTC_TIMESTAMP(), UTC_TIMESTAMP())',
            [
                'space_id' => $spaceId,
                'slug' => 'image-' . $suffix,
                'author_id' => $userId,
                'owner_id' => $userId,
            ]
        );

        return [$userId, $pageId];
    }
}
