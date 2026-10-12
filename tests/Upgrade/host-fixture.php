<?php
declare(strict_types=1);

use OpenWiki\Core\Database;
use OpenWiki\Core\Env;

require dirname(__DIR__, 2) . '/bootstrap/autoload.php';
$root = realpath($argv[2] ?? '');
if ($root !== '/var/www/openwiki-legacy-ci' || !is_file($root . '/storage/installed.lock')) throw new RuntimeException('Only the dedicated legacy host fixture is allowed.');
Env::load($root . '/.env');
if (Env::get('DB_DATABASE') !== 'openwiki_legacy_ci') throw new RuntimeException('Dedicated CI database required.');
$db = Database::fromEnvironment();
if ($argv[1] === 'seed') {
    $user = $db->insert('INSERT INTO users (username, email, password_hash, status, auth_source, created_at, updated_at) VALUES ("upgrade-tester", "upgrade@example.test", ?, "active", "local", UTC_TIMESTAMP(), UTC_TIMESTAMP())', [password_hash('UpgradeRegression!42', PASSWORD_DEFAULT)]);
    $role = $db->fetchOne('SELECT id FROM roles WHERE slug = "super-admin"');
    $db->execute('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)', [$user, $role['id']]);
    $space = $db->insert('INSERT INTO spaces (name, space_key, owner_id, visibility, status, created_at, updated_at) VALUES ("Upgrade", "UPGRADE", ?, "private", "active", UTC_TIMESTAMP(), UTC_TIMESTAMP())', [$user]);
    $page = $db->insert('INSERT INTO pages (space_id, title, slug, content_html, content_format, status, author_id, owner_id, version, created_at, updated_at) VALUES (?, "Preserved page", "preserved", "<p>Preserved source</p>", "visual", "published", ?, ?, 3, UTC_TIMESTAMP(), UTC_TIMESTAMP())', [$space, $user, $user]);
    $db->execute('INSERT INTO page_revisions (page_id, revision_number, title, content_html, content_format, status, author_id, created_at) VALUES (?, 1, "History", "<p>Immutable history</p>", "visual", "published", ?, UTC_TIMESTAMP())', [$page, $user]);
    $db->execute('INSERT INTO draft_autosaves (page_id, user_id, content_html, content_format, base_version, updated_at) VALUES (?, ?, "<p>Local draft</p>", "visual", 1, UTC_TIMESTAMP())', [$page, $user]);
    $source = $root . '/storage/temp/source.txt'; file_put_contents($source, "Protected upgrade attachment\n\0\xff");
    $attachment = (new OpenWiki\Attachments\AttachmentService($db, $root))->createFromSource($page, $user, $source, 'protected.txt');
    $token = (new OpenWiki\Auth\ApiTokenService($db))->create($user, 'Upgrade test', ['pages:read', 'pages:write'], null)['token'];
    echo json_encode(['page' => $page, 'attachment' => $attachment, 'token' => $token], JSON_THROW_ON_ERROR);
} elseif ($argv[1] === 'snapshot') {
    $data = [];
    foreach (['users', 'user_roles', 'attachments', 'attachment_versions'] as $table) $data[$table] = $db->fetchAll('SELECT * FROM `' . $table . '` ORDER BY 1');
    $data['pages'] = $db->fetchAll('SELECT id, title, slug, content_html, author_id, owner_id, version FROM pages ORDER BY id');
    $data['history'] = $db->fetchAll('SELECT id, title, content_html FROM page_revisions ORDER BY id');
    foreach ($data['attachments'] as $file) $data['files'][] = hash_file('sha256', $root . '/storage/attachments/' . $file['storage_key']);
    echo hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
} else throw new RuntimeException('Unknown host fixture operation.');
