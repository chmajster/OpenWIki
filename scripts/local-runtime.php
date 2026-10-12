<?php

declare(strict_types=1);

require dirname(__DIR__) . '/app/Install/LocalRuntime.php';

use OpenWiki\Install\LocalRuntime;

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
try {
    $runtime = new LocalRuntime(dirname(__DIR__));
    switch ($argv[1] ?? '') {
        case 'php-ready': exit($runtime->phpDependenciesReady() ? 0 : 1);
        case 'assets-ready': exit($runtime->assetsReady() ? 0 : 1);
        case 'storage': $runtime->prepareStorage(); break;
        case 'address': LocalRuntime::validateAddress($argv[2] ?? '', $argv[3] ?? ''); break;
        case 'port': LocalRuntime::checkPort($argv[2] ?? '', $argv[3] ?? ''); break;
        case 'probe': echo LocalRuntime::probe($argv[2] ?? '', $argv[3] ?? ''); break;
        default: throw new InvalidArgumentException('Unknown local runtime check.');
    }
} catch (Throwable $error) {
    fwrite(STDERR, '[FAIL] ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
