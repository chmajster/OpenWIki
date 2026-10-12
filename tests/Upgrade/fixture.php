<?php
declare(strict_types=1);
require dirname(__DIR__, 2) . '/bootstrap/autoload.php';

use OpenWiki\Core\Database;
use OpenWiki\Core\Env;

$name = $argv[2] ?? '';
if (!getenv('OPENWIKI_TEST_DB_HOST') || !preg_match('/^openwiki_upgrade_[a-f0-9]{12}_test$/D', $name)) throw new RuntimeException('Isolated upgrade database required.');
$config = ['host' => getenv('OPENWIKI_TEST_DB_HOST'), 'port' => getenv('OPENWIKI_TEST_DB_PORT') ?: 3306, 'database' => $name,
    'username' => getenv('OPENWIKI_TEST_DB_USERNAME'), 'password' => getenv('OPENWIKI_TEST_DB_PASSWORD')];
$action = $argv[1]; $root = $argv[3];
if ($action === 'drop') { Database::connect($config, false)->pdo()->exec('DROP DATABASE IF EXISTS `' . $name . '`'); exit; }
if ($action === 'seed') {
    Database::connect($config, false)->pdo()->exec('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4');
    $db = Database::connect($config);
    $db->pdo()->exec('CREATE TABLE schema_migrations (version VARCHAR(191) PRIMARY KEY, applied_at DATETIME NOT NULL)');
    foreach (glob(dirname(__DIR__, 2) . '/database/migrations/00[1-4]_*.php') as $file) {
        $plan = require $file;
        foreach ($plan['up'] as $step) $db->pdo()->exec(is_array($step) ? $step['sql'] : $step);
        $db->execute('INSERT INTO schema_migrations VALUES (?, UTC_TIMESTAMP())', [$plan['version']]);
    }
    $user = $db->insert('INSERT INTO users (username, email, password_hash, status, auth_source, created_at, updated_at) VALUES ("upgrade-tester", "upgrade@example.test", ?, "active", "local", UTC_TIMESTAMP(), UTC_TIMESTAMP())', [password_hash('UpgradeRegression!42', PASSWORD_DEFAULT)]);
    $role = $db->insert('INSERT INTO roles (name, slug, is_system, created_at, updated_at) VALUES ("Super Admin", "super-admin", 1, UTC_TIMESTAMP(), UTC_TIMESTAMP())');
    $permission = $db->insert('INSERT INTO permissions (name, description, created_at) VALUES ("*", "All", UTC_TIMESTAMP())');
    $db->execute('INSERT INTO role_permissions VALUES (?, ?)', [$role, $permission]);
    $db->execute('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)', [$user, $role]);
    $space = $db->insert('INSERT INTO spaces (name, space_key, owner_id, visibility, status, created_at, updated_at) VALUES ("Upgrade", "UPGRADE", ?, "private", "active", UTC_TIMESTAMP(), UTC_TIMESTAMP())', [$user]);
    $page = $db->insert('INSERT INTO pages (space_id, title, slug, content_html, content_format, status, author_id, owner_id, version, created_at, updated_at) VALUES (?, "Preserved page", "preserved", ?, "visual", "published", ?, ?, 3, UTC_TIMESTAMP(), UTC_TIMESTAMP())', [$space, '<h2>Żółć</h2><p>Preserved source</p>', $user, $user]);
    $db->execute('INSERT INTO page_revisions (page_id, revision_number, title, content_html, content_format, status, author_id, created_at) VALUES (?, 1, "History", "<p>Immutable history</p>", "visual", "published", ?, UTC_TIMESTAMP())', [$page, $user]);
    $db->execute('INSERT INTO draft_autosaves (page_id, user_id, content_html, content_format, base_version, updated_at) VALUES (?, ?, "<p>Local draft</p>", "visual", 1, UTC_TIMESTAMP())', [$page, $user]);
    foreach (['attachments', 'backups', 'cache', 'logs', 'temp'] as $directory) @mkdir($root . '/storage/' . $directory, 0770, true);
    $source = $root . '/storage/temp/source.txt'; file_put_contents($source, "Protected upgrade attachment\n\0\xff");
    $attachment = (new OpenWiki\Attachments\AttachmentService($db, $root))->createFromSource($page, $user, $source, 'protected.txt');
    $values = ['APP_KEY' => bin2hex(random_bytes(32)), 'APP_URL' => 'http://127.0.0.1:18219', 'APP_NAME' => 'Upgrade regression',
        'DB_HOST' => $config['host'], 'DB_PORT' => $config['port'], 'DB_DATABASE' => $name, 'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password']];
    $env = '';
    foreach ($values as $key => $value) $env .= $key . '=' . json_encode((string) $value, JSON_THROW_ON_ERROR) . "\n";
    file_put_contents($root . '/.env', $env); file_put_contents($root . '/storage/installed.lock', '{"version":"0.1.0","original":true}');
    Env::load($root . '/.env');
    $token = (new OpenWiki\Auth\ApiTokenService($db))->create($user, 'Upgrade test', ['pages:read', 'pages:write'], null)['token'];
    echo json_encode(['page' => $page, 'attachment' => $attachment, 'token' => $token], JSON_THROW_ON_ERROR);
    exit;
}
$db = Database::connect($config);
if ($action === 'snapshot') {
    $data = [];
    foreach (['users', 'user_roles', 'attachments', 'attachment_versions'] as $table) $data[$table] = $db->fetchAll('SELECT * FROM `' . $table . '` ORDER BY 1');
    // Migrations 009 and 011 add limited system roles. Existing account
    // assignments and original grants remain in the preservation snapshot.
    $data['roles'] = $db->fetchAll('SELECT * FROM roles WHERE slug NOT IN ("space-owner", "reviewer", "publisher") ORDER BY id');
    $data['role_permissions'] = $db->fetchAll('SELECT rp.* FROM role_permissions rp INNER JOIN roles r ON r.id = rp.role_id WHERE r.slug NOT IN ("space-owner", "reviewer", "publisher") ORDER BY rp.role_id, rp.permission_id');
    if ($db->fetchOne('SELECT id FROM roles WHERE slug = "space-owner"') !== null) {
        $grants = array_column($db->fetchAll('SELECT p.name FROM permissions p INNER JOIN role_permissions rp ON rp.permission_id = p.id INNER JOIN roles r ON r.id = rp.role_id WHERE r.slug = "space-owner" ORDER BY p.name'), 'name');
        if ($grants !== ['space.archive', 'space.edit', 'space.manage_access', 'space.manage_owner', 'space.view']) throw new RuntimeException('Unexpected owner role grants.');
    }
    $data['pages'] = $db->fetchAll('SELECT id, title, slug, content_html, author_id, owner_id, version FROM pages ORDER BY id');
    $data['history'] = $db->fetchAll('SELECT id, title, content_html FROM page_revisions WHERE change_summary IS NULL OR change_summary <> "Migration: preserved published page snapshot" ORDER BY id');
    $data['files'] = [];
    foreach ($data['attachments'] as $file) $data['files'][] = hash_file('sha256', $root . '/storage/attachments/' . $file['storage_key']);
    echo hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
} elseif ($action === 'fix-migration') {
    $db->pdo()->exec('CREATE TABLE upgrade_dependency (id INT PRIMARY KEY)');
} elseif ($action === 'verify-draft') {
    $draft = $db->fetchOne('SELECT base_version, payload_json FROM page_editor_drafts');
    if ((int) $draft['base_version'] !== 1 || json_decode($draft['payload_json'], true)['html'] !== '<p>Local draft</p>') throw new RuntimeException('Legacy draft was changed.');
    $page = $db->fetchOne('SELECT * FROM pages WHERE slug = "preserved"');
    $revision = $db->fetchOne('SELECT * FROM page_revisions WHERE page_id = ? AND revision_number = ?', [$page['id'], $page['version']]);
    if ((int) $page['published_revision'] !== 3 || $page['workflow_state'] !== 'published' || $revision['content_html'] !== $page['content_html'] || !(bool) $revision['was_published']) throw new RuntimeException('Current publication was not migrated exactly.');
    foreach (['reviewer' => ['page.review', 'page.verify', 'page.view', 'space.view'], 'publisher' => ['page.publish', 'page.view', 'space.view']] as $role => $expected) {
        $grants = array_column($db->fetchAll('SELECT p.name FROM permissions p INNER JOIN role_permissions rp ON rp.permission_id = p.id INNER JOIN roles r ON r.id = rp.role_id WHERE r.slug = ? ORDER BY p.name', [$role]), 'name');
        if ($grants !== $expected) throw new RuntimeException('Unexpected review role grants.');
    }
    $file = $db->fetchOne('SELECT f.version_number, a.current_version, f.name, a.name AS current_name FROM page_revision_attachments f INNER JOIN attachments a ON a.id = f.attachment_id WHERE f.page_id = ? AND f.revision_number = 3', [$page['id']]);
    if ($file === null || (int) $file['version_number'] !== (int) $file['current_version'] || $file['name'] !== $file['current_name']) throw new RuntimeException('Published attachment snapshot was not preserved.');
    echo 'preserved';
} else throw new RuntimeException('Unknown upgrade fixture command.');
