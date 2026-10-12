<?php
declare(strict_types=1);
namespace OpenWiki\Tests\Unit;

use OpenWiki\Core\Application;
use OpenWiki\Core\RuntimePaths;
use OpenWiki\Install\InstallService;
use OpenWiki\Upgrade\WriteGate;
use PHPUnit\Framework\TestCase;

final class RuntimePathsTest extends TestCase
{
    private array $environment = [];
    protected function setUp(): void
    {
        foreach (['OPENWIKI_CONFIG_DIR', 'OPENWIKI_STORAGE_DIR'] as $key) { $this->environment[$key] = getenv($key); putenv($key); }
    }
    protected function tearDown(): void
    {
        foreach ($this->environment as $key => $value) $value === false ? putenv($key) : putenv($key . '=' . $value);
    }
    public function testDefaultAndExplicitPathsShareTheApplicationAndInstallerProjection(): void
    {
        $root = dirname(__DIR__, 2);
        self::assertSame($root . '/.env', RuntimePaths::configuration($root));
        self::assertSame($root . '/storage/attachments', RuntimePaths::storage($root, 'attachments'));
        $runtime = sys_get_temp_dir() . '/openwiki-paths-' . bin2hex(random_bytes(6));
        mkdir($runtime, 0700);
        try {
            putenv('OPENWIKI_CONFIG_DIR=' . $runtime); putenv('OPENWIKI_STORAGE_DIR=' . $runtime . '/data');
            $app = Application::boot($root, false);
            self::assertSame($runtime . '/.env', $app->basePath('.env'));
            self::assertSame($runtime . '/data', $app->basePath('storage'));
            self::assertSame($runtime . '/data/attachments', $app->basePath('storage/attachments'));
            self::assertSame($root . '/database/migrations', $app->basePath('database/migrations'));
            $requirements = (new InstallService($root))->requirements();
            self::assertArrayHasKey($runtime, $requirements['writable']);
            self::assertArrayHasKey($runtime . '/data/attachments', $requirements['writable']);
            self::assertSame(realpath($runtime), WriteGate::instancePath($root));
        } finally {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($runtime, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            rmdir($runtime);
        }
    }
    public function testRelativeOverridesFailInsteadOfUsingTheCurrentWorkingDirectory(): void
    {
        putenv('OPENWIKI_STORAGE_DIR=relative');
        $this->expectExceptionMessage('absolute directory'); RuntimePaths::storage('/app');
    }
    public function testAnInstalledMarkerWithoutAKeyIsAnErrorAndNeverAFreshWizard(): void
    {
        $root = sys_get_temp_dir() . '/openwiki-config-' . bin2hex(random_bytes(6));
        mkdir($root . '/storage', 0700, true);
        file_put_contents($root . '/storage/installed.lock', '{}');
        $key = getenv('APP_KEY');
        putenv('APP_KEY='); putenv('OPENWIKI_CONFIG_DIR=' . $root); putenv('OPENWIKI_STORAGE_DIR=' . $root . '/storage');
        try {
            $app = Application::boot(dirname(__DIR__, 2), false);
            $state = (new \OpenWiki\System\ReadinessService($app))->check();
            self::assertSame('configuration_error', $state['status']); self::assertFalse($state['ready']);
            $response = (new \OpenWiki\Http\Controllers\InstallerController($app))->index(new \OpenWiki\Core\Request('GET', '/install', [], [], [], []));
            self::assertSame(503, $response->status()); self::assertStringNotContainsString('name="db_host"', $response->body());
        } finally {
            $key === false ? putenv('APP_KEY') : putenv('APP_KEY=' . $key);
            unlink($root . '/storage/installed.lock'); rmdir($root . '/storage'); rmdir($root);
        }
    }
    public function testRuntimeStateDoesNotEnableTheSystemUpgradeGate(): void
    {
        $root = sys_get_temp_dir() . '/openwiki-gate-' . bin2hex(random_bytes(6));
        mkdir($root . '/.openwiki/docker', 0700, true); mkdir($root . '/.openwiki/local');
        try {
            (new WriteGate())->acquire($root);
            self::assertFalse(WriteGate::maintenance($root));
            file_put_contents($root . '/.openwiki/status.json', '{}');
            try { (new WriteGate())->acquire($root); self::fail('A managed gate missing its lock was bypassed.'); }
            catch (\RuntimeException $error) { self::assertStringContainsString('paused', $error->getMessage()); }
            unlink($root . '/.openwiki/status.json');
        } finally { rmdir($root . '/.openwiki/docker'); rmdir($root . '/.openwiki/local'); rmdir($root . '/.openwiki'); rmdir($root); }
    }
}
