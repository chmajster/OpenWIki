<?php

declare(strict_types=1);

namespace OpenWiki\Tests\Unit;

use OpenWiki\Install\LocalRouter;
use PHPUnit\Framework\TestCase;

final class LocalRouterTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/openwiki-local-router-' . bin2hex(random_bytes(8));
        mkdir($this->root . '/public/assets', 0700, true);
        file_put_contents($this->root . '/public/assets/app.js', 'public');
        file_put_contents($this->root . '/public/index.php', '<?php');
        file_put_contents($this->root . '/public/assets/debug.php', '<?php');
        file_put_contents($this->root . '/secret.txt', 'secret');
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        rmdir($this->root);
    }

    public function testPublicAssetsAndApplicationRoutesAreDistinguished(): void
    {
        self::assertSame(realpath($this->root . '/public/assets/app.js'), LocalRouter::resolve($this->root . '/public', '/assets/app.js?cache=1'));
        foreach (['/', '/install', '/health', '/login', '/spaces/team/pages/page', '/api/v1/pages', '/index.php'] as $uri) {
            self::assertNull(LocalRouter::resolve($this->root . '/public', $uri));
        }
    }

    public function testInternalPathsTraversalAndPhpResourcesAreBlocked(): void
    {
        foreach (['/.env', '/.git/config', '/storage/file.txt', '/vendor/autoload.php', '/scripts/local-runtime.php',
            '/assets/../secret.txt', '/assets/%2e%2e/secret.txt', '/assets/%00.js', '/assets/%5c..%5csecret.txt', '/assets/debug.php'] as $uri) {
            self::assertFalse(LocalRouter::resolve($this->root . '/public', $uri), $uri);
        }
    }

    public function testSymlinkCannotPublishAFileOutsidePublic(): void
    {
        if (!@symlink($this->root . '/secret.txt', $this->root . '/public/assets/leak.txt')) self::markTestSkipped('Symlink creation is unavailable on this host.');
        self::assertFalse(LocalRouter::resolve($this->root . '/public', '/assets/leak.txt'));
    }
}
