<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/bootstrap/autoload.php';

use OpenWiki\Auth\ApiTokenService;
use OpenWiki\Core\Database;
use OpenWiki\Database\MigrationRunner;
use OpenWiki\Repositories\SpaceRepository;

$name = (string) getenv('OPENWIKI_TEST_DB_DATABASE');
if (!str_ends_with($name, '_test') || !getenv('OPENWIKI_TEST_DB_HOST')) throw new RuntimeException('Dedicated test database required.');
$database = Database::connect([
    'host' => getenv('OPENWIKI_TEST_DB_HOST'), 'port' => (int) getenv('OPENWIKI_TEST_DB_PORT'),
    'database' => $name, 'username' => getenv('OPENWIKI_TEST_DB_USERNAME'), 'password' => getenv('OPENWIKI_TEST_DB_PASSWORD'),
]);
if (($argv[1] ?? '') === 'seed') {
    (new MigrationRunner($database, dirname(__DIR__, 2) . '/database/migrations'))->migrate();
    $suffix = bin2hex(random_bytes(5));
    $roleId = $database->insert('INSERT INTO roles (name, slug, is_system, created_at, updated_at) VALUES (:name, :slug, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())', ['name' => 'Browser ' . $suffix, 'slug' => 'browser-' . $suffix]);
    $database->execute('INSERT IGNORE INTO permissions (name, description, created_at) VALUES ("*", "All", UTC_TIMESTAMP())');
    $permission = $database->fetchOne('SELECT id FROM permissions WHERE name = "*"');
    $database->execute('INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)', [$roleId, $permission['id']]);
    $users = [];
    foreach ([1, 2, 3] as $index) {
        $username = 'browser-' . $suffix . '-' . $index;
        $password = 'BrowserRegression!42';
        $id = $database->insert('INSERT INTO users (username, email, password_hash, status, auth_source, force_password_change, created_at, updated_at) VALUES (?, ?, ?, "active", "local", 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())', [$username, $username . '@example.test', password_hash($password, PASSWORD_DEFAULT)]);
        if ($index < 3) $database->execute('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)', [$id, $roleId]);
        $users[] = ['id' => $id, 'username' => $username, 'password' => $password];
    }
    $spaceKey = 'BROWSER-' . strtoupper($suffix);
    $spaceId = (new SpaceRepository($database))->create(['name' => 'Browser regression', 'space_key' => $spaceKey, 'description' => null, 'owner_id' => $users[0]['id'], 'visibility' => 'private']);
    $limitedRole = $database->insert('INSERT INTO roles (name, slug, is_system, created_at, updated_at) VALUES (?, ?, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())', ['Limited browser ' . $suffix, 'limited-browser-' . $suffix]);
    foreach (['page.view', 'page.edit', 'page.create', 'attachment.view'] as $name) {
        $database->execute('INSERT IGNORE INTO permissions (name, description, created_at) VALUES (?, ?, UTC_TIMESTAMP())', [$name, $name]);
        $permission = $database->fetchOne('SELECT id FROM permissions WHERE name = ?', [$name]);
        $database->execute('INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)', [$limitedRole, $permission['id']]);
    }
    $database->execute('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)', [$users[2]['id'], $limitedRole]);
    foreach (['space.view', 'space.edit'] as $permission) $database->execute('INSERT INTO space_acl (space_id, principal_type, principal_id, permission, effect) VALUES (?, "user", ?, ?, "allow")', [$spaceId, $users[2]['id'], $permission]);
    $token = (new ApiTokenService($database))->create($users[0]['id'], 'Browser tests', ['pages:read', 'pages:write', 'spaces:read'], null)['token'];
    $image = imagecreatetruecolor(256, 128);
    ob_start(); imagepng($image); $imageBase64 = base64_encode((string) ob_get_clean()); imagedestroy($image);
    echo json_encode(['imageBase64' => $imageBase64, 'users'  => $users, 'roleId' => $roleId, 'limitedRoleId' => $limitedRole, 'spaceId' => $spaceId, 'spaceKey' => $spaceKey, 'token' => $token], JSON_THROW_ON_ERROR);
} elseif (($argv[1] ?? '') === 'cleanup') {
    $data = json_decode(file_get_contents(dirname(__DIR__, 2) . '/storage/temp/editor-browser.json'), true, 512, JSON_THROW_ON_ERROR);
    $database->execute('DELETE FROM spaces WHERE id = ?', [$data['spaceId']]);
    foreach ($data['users'] as $user) {
        $database->execute('DELETE FROM audit_logs WHERE user_id = ?', [$user['id']]);
        $database->execute('DELETE FROM users WHERE id = ?', [$user['id']]);
    }
    $database->execute('DELETE FROM roles WHERE id = ?', [$data['roleId']]);
    $database->execute('DELETE FROM roles WHERE id = ?', [$data['limitedRoleId']]);
}
