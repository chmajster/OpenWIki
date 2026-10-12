<?php

declare(strict_types=1);

use OpenWiki\Core\Application;
use OpenWiki\Core\Env;
use OpenWiki\Database\MigrationRunner;
use OpenWiki\System\ReadinessService;
use OpenWiki\Upgrade\RecoverySnapshot;
use OpenWiki\Upgrade\UpgradeState;
use OpenWiki\Upgrade\WriteGate;

require dirname(__DIR__) . '/bootstrap/autoload.php';

try {
    $command = $argv[1] ?? '';
    $instance = realpath($argv[2] ?? '');
    if (PHP_SAPI !== 'cli' || $instance === false || !is_dir($instance . '/storage')) throw new RuntimeException('An existing instance directory is required.');
    $directory = $instance . '/.openwiki';
    $state = new UpgradeState($directory);
    if ($command === 'get') {
        $value = $state->read()[$argv[3] ?? ''] ?? '';
        echo is_array($value) ? implode("\n", $value) : $value;
        exit(0);
    }
    if ($command === 'status') { echo json_encode($state->read(), JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n"; exit(0); }
    if ($command === 'init') {
        $old = $state->read();
        if (!in_array($old['status'], ['idle', 'completed', 'rolled_back', 'restored'], true)) throw new RuntimeException('An unfinished upgrade exists. Use --resume or controlled recovery.');
        unset($old['prior_state']);
        $state->update(['prior_state' => $old, 'id' => gmdate('YmdHis') . '-' . bin2hex(random_bytes(6)), 'status' => 'running', 'stage' => 'preflight', 'ref' => $argv[3],
            'completed' => [], 'sha' => null, 'previous' => null, 'backup_sha256' => null, 'error' => null]);
        exit(0);
    }
    if ($command === 'unchanged') {
        $previous = $state->read()['prior_state'] ?? [];
        // Keep the last verified recovery archive and previous release on a no-op.
        $state->update($previous + ['status' => 'completed']);
        $state->update(['status' => 'completed', 'result' => 'unchanged']);
        exit(0);
    }
    if ($command === 'set') { $state->update([$argv[3] => $argv[4]]); exit(0); }
    if ($command === 'complete') { $state->complete($argv[3]); exit(0); }
    $code = realpath($argv[3] ?? $instance);
    if ($code === false || !is_file($code . '/.env')) throw new RuntimeException('Existing instance configuration is required.');
    $app = Application::boot($code);
    if (!$app->installed()) throw new RuntimeException('Existing APP_KEY and installation marker are required.');
    if ($command === 'preflight') {
        $app->database()->fetchOne('SELECT 1');
        if ($app->database()->fetchOne("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND (TABLE_TYPE <> 'BASE TABLE' OR ENGINE <> 'InnoDB') LIMIT 1") !== null) {
            throw new RuntimeException('Verified recovery requires InnoDB tables and no views.');
        }
        $requirements = (new \OpenWiki\Install\InstallService($code))->requirements();
        if (!$requirements['ok']) throw new RuntimeException('PHP extensions or writable storage requirements failed. Run --diagnose.');
        $size = (int) ($app->database()->fetchOne('SELECT COALESCE(SUM(data_length + index_length), 0) AS bytes FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()')['bytes'] ?? 0);
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($instance . '/storage', FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file->isFile() && !$file->isLink()) $size += $file->getSize();
        }
        $free = disk_free_space($instance);
        if ($free === false || $free < $size * 3 + 512 * 1024 * 1024) throw new RuntimeException('Insufficient free space for verified recovery and a prepared release.');
        echo "[ OK ] Runtime, database, original configuration and recovery disk space.\n";
        exit(0);
    }
    if (in_array($command, ['ready', 'compatible'], true)) {
        $result = (new ReadinessService($app))->check(true);
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
        exit($result['ready'] ? 0 : 1);
    }
    if ($command === 'database-name') { echo Env::get('DB_DATABASE'); exit(0); }
    // Recovery can only operate while the shell coordinator holds the exclusive gate.
    if (!WriteGate::maintenance($code) || !is_readable($directory . '/owner')
        || !hash_equals(trim((string) file_get_contents($directory . '/owner')), (string) getenv('OPENWIKI_UPGRADE_OWNER'))) {
        throw new RuntimeException('Recovery requires the upgrade coordinator and paused application writes.');
    }
    $snapshot = new RecoverySnapshot($app->database(), $instance);
    $archive = $directory . '/backups/' . $state->read()['id'] . '.tar';
    if ($command === 'snapshot') {
        if (is_file($archive)) {
            try { $snapshot->verify($archive, $state->read()['backup_sha256'] ?? null); }
            catch (Throwable $error) {
                if (!empty($state->read()['backup_sha256'])) throw $error;
                // A crash while writing the archive has no verification receipt.
                rename($archive, $archive . '.incomplete-' . bin2hex(random_bytes(4)));
                $snapshot->create($archive);
            }
            $result = ['sha256' => hash_file('sha256', $archive)];
        } else $result = $snapshot->create($archive);
        $state->update(['backup_sha256' => $result['sha256']]);
        echo "[ OK ] Recovery snapshot verified.\n";
    } elseif ($command === 'verify') {
        $hash = $state->read()['backup_sha256'] ?? null;
        if (!is_string($hash) || $hash === '') throw new RuntimeException('Verified snapshot receipt is missing.');
        $snapshot->verify($archive, $hash);
        echo "[ OK ] Recovery archive and all file hashes match.\n";
    } elseif ($command === 'restore') {
        $hash = $state->read()['backup_sha256'] ?? null;
        if (!is_string($hash) || $hash === '') throw new RuntimeException('Verified recovery checksum is missing.');
        $snapshot->verify($archive, $hash);
        // Never lose changes made after a successful upgrade or a partial failure.
        $beforeRestore = $directory . '/backups/' . $state->read()['id'] . '-before-restore.tar';
        if (!is_file($beforeRestore)) $snapshot->create($beforeRestore);
        else $snapshot->verify($beforeRestore);
        $snapshot->restore($archive, $hash, $argv[4] ?? '');
        echo "[ OK ] Original database, configuration and storage restored.\n";
    } else throw new InvalidArgumentException('Unknown upgrade tool command.');
} catch (Throwable $error) {
    fwrite(STDERR, '[FAIL] ' . $error->getMessage() . "\n");
    if ($error->getPrevious() !== null) fwrite(STDERR, '[CAUSE] ' . $error->getPrevious()->getMessage() . "\n");
    exit(1);
}
