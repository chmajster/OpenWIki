<?php

declare(strict_types=1);

namespace OpenWiki\Tests\Unit;

use OpenWiki\Install\LocalRuntime;
use PHPUnit\Framework\TestCase;

final class LocalRuntimeTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/openwiki-local-unit-' . bin2hex(random_bytes(8));
        foreach (['scripts', 'resources/js', 'public/assets', 'vendor/composer', 'vendor/library/src'] as $path) mkdir($this->root . '/' . $path, 0700, true);
    }

    protected function tearDown(): void
    {
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
        rmdir($this->root);
    }

    public function testAssetChecksDetectChangedSourcesDeletedBundlesAndCorruptOutput(): void
    {
        foreach (['package.json', 'package-lock.json', 'scripts/build-assets.cjs', 'resources/js/editor.js'] as $file) file_put_contents($this->root . '/' . $file, 'source');
        $outputs = [];
        foreach (['wiki-editor.js', 'diagram.js', 'document-reader.js', 'diagram.html'] as $file) {
            file_put_contents($this->root . '/public/assets/' . $file, 'compiled');
            $outputs[$file] = hash('sha256', 'compiled');
        }
        $runtime = new LocalRuntime($this->root);
        file_put_contents($this->root . '/public/assets/editor-manifest.json', json_encode(['version' => 1, 'input' => $runtime->assetFingerprint(), 'outputs' => $outputs]));
        self::assertTrue($runtime->assetsReady());
        file_put_contents($this->root . '/resources/js/editor.js', 'user change');
        self::assertFalse($runtime->assetsReady());
        file_put_contents($this->root . '/resources/js/editor.js', 'source');
        file_put_contents($this->root . '/public/assets/diagram.js', 'bad output');
        self::assertFalse($runtime->assetsReady());
        file_put_contents($this->root . '/public/assets/diagram.js', 'compiled');
        unlink($this->root . '/public/assets/document-reader.js');
        self::assertFalse($runtime->assetsReady());
    }

    public function testComposerChecksMatchVersionsReferencesAndActualDirectories(): void
    {
        file_put_contents($this->root . '/vendor/autoload.php', '<?php');
        $package = ['name' => 'example/library', 'version' => '1.0.0', 'dist' => ['reference' => 'a'], 'autoload' => ['psr-4' => ['Example\\' => 'src/']]];
        file_put_contents($this->root . '/composer.lock', json_encode(['packages' => [$package]]));
        $installed = $package + ['install-path' => '../library'];
        file_put_contents($this->root . '/vendor/composer/installed.json', json_encode(['packages' => [$installed]]));
        $runtime = new LocalRuntime($this->root);
        self::assertTrue($runtime->phpDependenciesReady());
        $installed['version'] = '0.9.0';
        file_put_contents($this->root . '/vendor/composer/installed.json', json_encode(['packages' => [$installed]]));
        self::assertFalse($runtime->phpDependenciesReady());
        $installed['version'] = '1.0.0'; $installed['dist']['reference'] = 'old';
        file_put_contents($this->root . '/vendor/composer/installed.json', json_encode(['packages' => [$installed]]));
        self::assertFalse($runtime->phpDependenciesReady());
        $installed['dist']['reference'] = 'a';
        file_put_contents($this->root . '/vendor/composer/installed.json', json_encode(['packages' => [$installed]]));
        rmdir($this->root . '/vendor/library/src');
        self::assertFalse($runtime->phpDependenciesReady());
    }

    public function testAddressValidationAcceptsLocalAndExplicitWildcardInterfaces(): void
    {
        foreach (['127.0.0.1', '0.0.0.0', 'localhost', '::1', '::'] as $host) {
            LocalRuntime::validateAddress($host, '1024');
            LocalRuntime::validateAddress($host, '65535');
        }
        self::addToAssertionCount(10);
    }

    public function testAddressValidationRejectsInvalidHostsAndPorts(): void
    {
        foreach ([['http://localhost', '8080'], ['127.0.0.1;id', '8080'], ['127.0.0.999', '8080'], ['127.0.0.1', '80'],
            ['127.0.0.1', '65536'], ['127.0.0.1', '-1'], ['127.0.0.1', '8080x'], ['127.0.0.1', '']] as [$host, $port]) {
            try { LocalRuntime::validateAddress($host, $port); self::fail('Invalid address accepted.'); }
            catch (\InvalidArgumentException) { self::addToAssertionCount(1); }
        }
    }

    public function testPortCheckNeverStopsTheExistingListener(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = substr(strrchr(stream_socket_get_name($socket, false), ':'), 1);
        try {
            LocalRuntime::checkPort('127.0.0.1', $port);
            self::fail('Occupied port accepted.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('zajęty', $error->getMessage());
            self::assertIsResource($socket);
        } finally { fclose($socket); }
        LocalRuntime::checkPort('127.0.0.1', $port);
    }

    public function testStoragePreparationKeepsExistingConfigurationAndRejectsMissingEnvironment(): void
    {
        $runtime = new LocalRuntime($this->root); $runtime->prepareStorage();
        self::assertDirectoryExists($this->root . '/storage/temp/local-sessions');
        file_put_contents($this->root . '/storage/installed.lock', 'existing');
        try { $runtime->prepareStorage(); self::fail('Installed instance without configuration accepted.'); }
        catch (\RuntimeException $error) { self::assertStringContainsString('Przywróć konfigurację', $error->getMessage()); }
        file_put_contents($this->root . '/.env', 'APP_KEY=existing');
        $runtime->prepareStorage();
        self::assertSame('APP_KEY=existing', file_get_contents($this->root . '/.env'));
        self::assertSame('existing', file_get_contents($this->root . '/storage/installed.lock'));
    }
}
