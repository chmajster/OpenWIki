<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/bootstrap/autoload.php';

use OpenWiki\Core\Database;
use OpenWiki\Core\Env;
use OpenWiki\Core\RuntimePaths;

$root = dirname(__DIR__);
Env::load(RuntimePaths::configuration($root));
$heartbeat = RuntimePaths::storage($root, 'worker-state.json');
$get = static function (string $path): array {
        $curl = curl_init('http://web' . $path);
        curl_setopt_array($curl, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 1, CURLOPT_TIMEOUT => 3, CURLOPT_PROXY => '']);
        $body = curl_exec($curl); $status = curl_getinfo($curl, CURLINFO_RESPONSE_CODE); curl_close($curl);
        return [$status, $body];
};
$database = null;
$connection = static function () use (&$database): ?Database {
    try {
        if ($database === null) { $database = Database::fromEnvironment(); $database->fetchOne('SELECT 1 AS ok'); }
        return $database;
    } catch (Throwable) { return null; }
};
$state = static function () use ($root, $get, $connection): string {
    $installed = is_file(RuntimePaths::storage($root, 'installed.lock'));
    $key = Env::get('APP_KEY', '') ?? '';
    if (($installed && strlen($key) < 32) || (!$installed && $key !== '')) return 'configuration_error';
    $db = $connection();
    if ($db === null) return 'database_error';
    if (!$installed) {
        // A lost marker/configuration must not expose a completed database as a fresh wizard.
        $table = $db->fetchOne("SELECT COUNT(*) AS n FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'");
        if ((int) $table['n'] !== 0 && (int) $db->fetchOne('SELECT COUNT(*) AS n FROM users')['n'] !== 0) return 'configuration_error';
    }
    [$status, $body] = $get('/health');
    $data = is_string($body) ? json_decode($body, true) : null;
    if ($status === 200 && ($data['status'] ?? '') === 'ok') return 'ready';
    if ($status === 503 && ($data['status'] ?? '') === 'setup_required') {
        [$status, $body] = $get('/install');
        return $status === 200 && is_string($body) && str_contains($body, 'name="db_host"') ? 'setup' : 'installer_error';
    }
    if (($data['status'] ?? '') === 'degraded') {
        foreach (['database', 'storage', 'schema'] as $check) if (($data['checks'][$check] ?? false) !== true) return $check . '_error';
    }
    return ($data['status'] ?? null) === 'maintenance' ? 'maintenance' : 'http_error';
};
switch ($argv[1] ?? '') {
    case 'state': echo $state(); break;
    case 'diagnostics':
        [$live, $body] = $get('/health/live');
        echo json_encode(['http' => $live === 200 && (json_decode((string) $body, true)['status'] ?? '') === 'alive',
            'database' => $connection() !== null, 'application' => $state(),
            'worker' => is_file($heartbeat) ? json_decode((string) file_get_contents($heartbeat), true) : ['state' => 'waiting']], JSON_THROW_ON_ERROR);
        break;
    case 'heartbeat':
        $temporary = $heartbeat . '.' . getmypid() . '.tmp';
        if (file_put_contents($temporary, json_encode(['state' => $argv[2] ?? 'failed', 'time' => time()], JSON_THROW_ON_ERROR)) === false || !rename($temporary, $heartbeat)) exit(1);
        break;
    case 'worker-health':
        $data = is_file($heartbeat) ? json_decode((string) file_get_contents($heartbeat), true) : [];
        $interval = max(1, (int) Env::get('OPENWIKI_WORKER_INTERVAL', '60'));
        exit(in_array($data['state'] ?? '', ['ready', 'setup'], true) && time() - ($data['time'] ?? 0) < max(30, 2 * $interval + 10) ? 0 : 1);
    case 'worker-status':
        echo is_file($heartbeat) ? file_get_contents($heartbeat) : '{"state":"waiting"}'; break;
    default: exit(2);
}
