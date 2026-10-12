<?php
declare(strict_types=1);

use OpenWiki\Auth\ApiTokenService;
use OpenWiki\Core\Application;
use OpenWiki\Core\Database;
use OpenWiki\Core\Request;
use OpenWiki\Database\MigrationRunner;
use OpenWiki\Http\Controllers\Api\ApiV1Controller;

require dirname(__DIR__, 2) . '/vendor/autoload.php';
$root = dirname(__DIR__, 2);
if (($argv[1] ?? '') === '--baseline') {
    $baseline = $root . '/storage/temp/search-baseline/app/';
    if (!is_dir($baseline)) throw new RuntimeException('Extract git archive of the baseline into storage/temp/search-baseline first.');
    spl_autoload_register(static function (string $class) use ($baseline): void {
        if (!str_starts_with($class, 'OpenWiki\\')) return;
        $path = $baseline . str_replace('\\', '/', substr($class, 9)) . '.php';
        if (is_file($path)) require $path;
    }, true, true);
}
$config = ['host' => getenv('OPENWIKI_TEST_DB_HOST'), 'port' => (int) getenv('OPENWIKI_TEST_DB_PORT'),
    'database' => getenv('OPENWIKI_TEST_DB_DATABASE'), 'username' => getenv('OPENWIKI_TEST_DB_USERNAME'),
    'password' => getenv('OPENWIKI_TEST_DB_PASSWORD'), 'charset' => 'utf8mb4'];
if (!preg_match('/^openwiki_search_[a-z_]*test$/D', (string) $config['database'])) {
    throw new RuntimeException('Use a dedicated openwiki_search_*test database.');
}
$db = Database::connect($config);
(new MigrationRunner($db, $root . (($argv[1] ?? '') === '--baseline' ? '/storage/temp/search-baseline/database/migrations' : '/database/migrations')))->migrate();
if (($argv[1] ?? '') === '--seed') {
    if ((int) $db->fetchOne('SELECT COUNT(*) AS n FROM pages')['n'] !== 0) throw new RuntimeException('Seed requires an empty dedicated database.');
    $users = [];
    foreach (['reader', 'owner'] as $name) $users[] = (int) ($db->fetchOne('SELECT id FROM users WHERE username = ?', ['benchmark-' . $name])['id'] ?? $db->insert('INSERT INTO users (username,email,password_hash,status,auth_source,force_password_change,created_at,updated_at) VALUES (?, ?, ?, "active", "local", 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())', ['benchmark-' . $name, $name . '@benchmark.invalid', password_hash('BenchmarkFixtureOnly!42', PASSWORD_DEFAULT)]));
    $viewer = $db->insert('INSERT INTO roles (name,slug,is_system,created_at,updated_at) VALUES ("Benchmark reader", "benchmark-reader", 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())');
    $db->execute('INSERT IGNORE INTO permissions (name,description,created_at) VALUES ("page.view", "Read pages", UTC_TIMESTAMP())');
    $db->execute('INSERT INTO role_permissions (role_id,permission_id) SELECT ?, id FROM permissions WHERE name = "page.view"', [$viewer]);
    $db->execute('INSERT INTO user_roles (user_id,role_id) VALUES (?,?)', [$users[0], $viewer]);
    $space = $db->insert('INSERT INTO spaces (name,space_key,description,owner_id,visibility,status,created_at,updated_at) VALUES ("Benchmark", "SEARCH-BENCH", "Deterministic synthetic documentation", ?, "public", "active", UTC_TIMESTAMP(), UTC_TIMESTAMP())', [$users[1]]);
    for ($batch = 0; $batch < 20; $batch++) $db->transaction(function () use ($db, $batch, $space, $users): void {
        for ($j = 1; $j <= 500; $j++) {
            $i = $batch * 500 + $j;
            $title = $i <= 220 ? 'Firewall' : ($i <= 320 ? 'Firewall accessible ' . $i : 'Documentation ' . $i);
            $text = str_repeat('Routine documentation about operational procedures. ', 100)
                . ($i % 100 === 0 ? 'needlecontext Zażółć gęślą jaźń IP C++ foo_bar nginx.conf router' : 'ordinary maintenance');
            $id = $db->insert('INSERT INTO pages (space_id,title,slug,content_html,content_text,content_format,author_id,owner_id,status,version,published_at,created_at,updated_at) VALUES (?, ?, ?, ?, ?, "visual", ?, ?, "published", 1, "2026-01-01 12:00:00", "2026-01-01 12:00:00", "2026-01-01 12:00:00")', [$space, $title, 'document-' . $i, '<p>' . $text . '</p>', $text, $users[1], $users[1]]);
            if ($i <= 220) $db->execute('INSERT INTO page_acl (page_id,principal_type,principal_id,permission,effect,inherit_to_children,created_at) VALUES (?, "user", ?, "page.view", "allow", 1, UTC_TIMESTAMP())', [$id, $users[1]]);
        }
    });
    fwrite(STDOUT, "Seeded 10000 pages; 220 leading hidden Firewall hits and 100 accessible hits.\n");
    exit;
}
foreach (['APP_KEY' => str_repeat('b', 64), 'DB_HOST' => $config['host'], 'DB_PORT' => $config['port'], 'DB_DATABASE' => $config['database'], 'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password']] as $key => $value) putenv($key . '=' . $value);
$runtime = $root . '/storage/temp/search-benchmark-' . bin2hex(random_bytes(6));
mkdir($runtime . '/storage', 0700, true);
file_put_contents($runtime . '/storage/installed.lock', '{"benchmark":true}');
$app = Application::boot($runtime, false);
$reader = (int) $db->fetchOne('SELECT id FROM users WHERE username = "benchmark-reader"')['id'];
$token = (new ApiTokenService($db))->create($reader, 'Benchmark ephemeral', ['search:read'], null);
$report = ['engine' => $db->fetchOne('SELECT VERSION() AS version')['version'], 'pages' => (int) $db->fetchOne('SELECT COUNT(*) AS n FROM pages')['n'], 'php' => PHP_VERSION, 'runs' => []];
try {
    foreach (isset($argv[2]) ? [$argv[2]] : ['Firewall', 'needlecontext', 'IP', 'Zażółć', 'C++', 'foo_bar', 'nginx.conf', '"gęślą jaźń"', 'router*', 'ordinary'] as $q) {
        for ($iteration = 0; $iteration < 5; $iteration++) {
            memory_reset_peak_usage(); $startMemory = memory_get_usage();
            $before = (int) $app->database()->fetchOne("SHOW SESSION STATUS LIKE 'Questions'")['Value'];
            $start = hrtime(true);
            $response = (new ApiV1Controller($app))->search(new Request('GET', '/api/v1/search', ['q' => $q, 'type' => 'page', 'per_page' => 25], [], ['HTTP_AUTHORIZATION' => 'Bearer ' . $token['token'], 'REMOTE_ADDR' => '127.0.0.1'], []));
            $elapsed = (hrtime(true) - $start) / 1e6;
            $after = (int) $app->database()->fetchOne("SHOW SESSION STATUS LIKE 'Questions'")['Value'];
            $payload = json_decode($response->body(), true, 512, JSON_THROW_ON_ERROR);
            $report['runs'][] = ['query' => $q, 'iteration' => $iteration, 'ms' => round($elapsed, 2), 'queries' => $after - $before - 1, 'peak_bytes' => memory_get_peak_usage() - $startMemory, 'total' => $payload['meta']['total'] ?? null, 'returned' => count($payload['data'] ?? []), 'status' => $response->status()];
        }
    }
} finally {
    $db->execute('DELETE FROM api_tokens WHERE id = ?', [$token['id']]);
    unlink($runtime . '/storage/installed.lock'); rmdir($runtime . '/storage'); rmdir($runtime);
}
fwrite(STDOUT, json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR) . "\n");
