<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap/autoload.php';

$name = $argv[2] ?? '';
if (!getenv('OPENWIKI_TEST_DB_HOST') || !preg_match('/^openwiki_local_[a-f0-9]{12}$/D', $name)) throw new RuntimeException('An isolated test database is required.');
$config = ['host' => getenv('OPENWIKI_TEST_DB_HOST'), 'port' => getenv('OPENWIKI_TEST_DB_PORT') ?: '3306',
    'database' => $name, 'username' => getenv('OPENWIKI_TEST_DB_USERNAME'), 'password' => getenv('OPENWIKI_TEST_DB_PASSWORD'), 'charset' => 'utf8mb4'];
if (($argv[1] ?? '') === 'drop') {
    OpenWiki\Core\Database::connect($config, false)->pdo()->exec('DROP DATABASE IF EXISTS `' . $name . '`');
    exit;
}
$db = OpenWiki\Core\Database::connect($config, true);
$root = $argv[3] ?? '';
if (($argv[1] ?? '') === 'seed') {
    $user = $db->fetchOne('SELECT id FROM users WHERE username = "local-tester"');
    $spaceId = $db->insert('INSERT INTO spaces (space_key, name, description, owner_id, created_at, updated_at) VALUES ("local", "Local test", "", ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())', [$user['id']]);
    $space = $db->fetchOne('SELECT * FROM spaces WHERE id = ?', [$spaceId]);
    $page = (new OpenWiki\Wiki\PageWriteService($db, $root))->save($space, null,
        ['title' => 'Preserved local page', 'content_html' => '<h2>Local documentation</h2><p>Preserved content</p>', 'status' => 'published'], (int) $user['id']);
    $file = $root . '/storage/temp/local-attachment.txt'; file_put_contents($file, 'Preserved attachment bytes');
    $attachment = (new OpenWiki\Attachments\AttachmentService($db, $root))->createFromSource((int) $page['id'], (int) $user['id'], $file, 'local.txt');
    $token = (new OpenWiki\Auth\ApiTokenService($db))->create((int) $user['id'], 'Local runtime verification', OpenWiki\Auth\ApiTokenService::SCOPES, null);
    echo json_encode(['token' => $token['token'], 'page' => $page['id'], 'slug' => $page['slug'], 'attachment' => $attachment], JSON_THROW_ON_ERROR);
} elseif (($argv[1] ?? '') === 'snapshot') {
    $rows = [];
    $rows['users'] = $db->fetchAll('SELECT id, username, password_hash, force_password_change FROM users ORDER BY id');
    foreach (['pages', 'page_revisions', 'attachments', 'attachment_versions', 'user_roles'] as $table) $rows[$table] = $db->fetchAll('SELECT * FROM ' . $table . ' ORDER BY 1');
    foreach ($rows['attachments'] as $attachment) $rows['files'][] = hash_file('sha256', $root . '/storage/attachments/' . $attachment['storage_key']);
    echo hash('sha256', json_encode($rows, JSON_THROW_ON_ERROR));
} else throw new RuntimeException('Unknown local fixture operation.');
