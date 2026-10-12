<?php
declare(strict_types=1);
namespace OpenWiki\Tests\Unit;

use OpenWiki\Install\LocalProcess;
use PHPUnit\Framework\TestCase;

final class LocalProcessTest extends TestCase
{
    public function testPidReuseAndForeignProcessesAreNeverAcceptedAsTheOwnedServer(): void
    {
        if (!is_dir('/proc/self')) self::markTestSkipped('Linux/WSL process identity required.');
        $root = sys_get_temp_dir() . '/openwiki-process-' . bin2hex(random_bytes(6));
        mkdir($root . '/public', 0700, true); mkdir($root . '/scripts');
        file_put_contents($root . '/scripts/local-router.php', '<?php echo "test";');
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        $port = substr(strrchr(stream_socket_get_name($socket, false), ':'), 1); fclose($socket);
        $token = bin2hex(random_bytes(16));
        $child = proc_open([PHP_BINARY, '-S', '127.0.0.1:' . $port, '-t', $root . '/public', $root . '/scripts/local-router.php'],
            [['file', '/dev/null', 'r'], ['file', '/dev/null', 'w'], ['file', '/dev/null', 'w']], $pipes, $root, array_merge(getenv(), ['OPENWIKI_LOCAL_PROBE_TOKEN' => $token]));
        $pid = proc_get_status($child)['pid'];
        try {
            $process = new LocalProcess($root);
            for ($i = 0; $i < 100; $i++) { $connection = @stream_socket_client('tcp://127.0.0.1:' . $port, $code, $error, 0.01); if ($connection !== false) { fclose($connection); break; } usleep(10000); }
            $process->configure('127.0.0.1', $port, $pid, $token);
            self::assertSame($pid, $process->ownedPid());
            $process->clear($pid + 1); self::assertSame($pid, $process->ownedPid());
            $state = $process->read(); $state['start'] .= '9';
            file_put_contents($root . '/.openwiki/local/state.json', json_encode($state));
            self::assertNull($process->ownedPid());
            $process->configure('127.0.0.1', $port, $pid, str_repeat('a', 32));
            self::assertNull($process->ownedPid());
            $process->configure('127.0.0.1', $port, getmypid(), $token);
            self::assertNull($process->ownedPid());
            $process->clear(getmypid()); self::assertNull($process->read()['pid']);
            self::assertSame((int) $port, $process->read()['port']);
        } finally {
            proc_terminate($child); proc_close($child);
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $file) $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
            rmdir($root);
        }
    }
}
