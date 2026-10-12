<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/app/Install/LocalRuntime.php';
require dirname(__DIR__) . '/app/Install/LocalProcess.php';
try {
    $process = new \OpenWiki\Install\LocalProcess(dirname(__DIR__));
    switch ($argv[1] ?? '') {
        case 'settings':
            $state = $process->read();
            echo ($state['host'] ?? '') . "\n" . ($state['port'] ?? '') . "\n" . ($state['token'] ?? '') . "\n";
            break;
        case 'pid': $pid = $process->ownedPid(); if ($pid === null) exit(1); echo $pid; break;
        case 'record': $process->configure($argv[2], $argv[3], (int) $argv[4], $argv[5]); break;
        case 'configure': $process->configure($argv[2], $argv[3]); break;
        case 'clear': $process->clear((int) $argv[2]); break;
        default: exit(2);
    }
} catch (Throwable $error) { fwrite(STDERR, '[FAIL] ' . $error->getMessage() . PHP_EOL); exit(1); }
