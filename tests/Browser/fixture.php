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
    foreach (range(1, 9) as $index) {
        $username = 'browser-' . $suffix . '-' . $index;
        $password = 'BrowserRegression!42';
        $id = $database->insert('INSERT INTO users (username, email, password_hash, status, auth_source, force_password_change, created_at, updated_at) VALUES (?, ?, ?, "active", "local", 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())', [$username, $username . '@example.test', password_hash($password, PASSWORD_DEFAULT)]);
        if ($index < 3) $database->execute('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)', [$id, $roleId]);
        $users[] = ['id' => $id, 'username' => $username, 'password' => $password];
    }
    $spaceKey = 'BROWSER-' . strtoupper($suffix);
    $spaceId = (new SpaceRepository($database))->create(['name' => 'Browser regression', 'space_key' => $spaceKey, 'description' => null, 'owner_id' => $users[0]['id'], 'visibility' => 'restricted']);
    $limitedRole = $database->insert('INSERT INTO roles (name, slug, is_system, created_at, updated_at) VALUES (?, ?, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())', ['Limited browser ' . $suffix, 'limited-browser-' . $suffix]);
    foreach (['page.view', 'page.edit', 'page.create', 'attachment.view'] as $name) {
        $database->execute('INSERT IGNORE INTO permissions (name, description, created_at) VALUES (?, ?, UTC_TIMESTAMP())', [$name, $name]);
        $permission = $database->fetchOne('SELECT id FROM permissions WHERE name = ?', [$name]);
        $database->execute('INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)', [$limitedRole, $permission['id']]);
    }
    $database->execute('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)', [$users[2]['id'], $limitedRole]);
    foreach (['space.view', 'space.edit'] as $permission) $database->execute('INSERT INTO space_acl (space_id, principal_type, principal_id, permission, effect) VALUES (?, "user", ?, ?, "allow")', [$spaceId, $users[2]['id'], $permission]);
    $securityRoles = [];
    foreach ([3 => ['page.view'], 4 => ['page.view', 'page.edit', 'page.create', 'page.delete', 'page.publish', 'page.archive', 'user.manage', 'role.manage', 'group.manage', 'settings.manage']] as $index => $names) {
        $id = $database->insert('INSERT INTO roles (name, slug, is_system, created_at, updated_at) VALUES (?, ?, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())', ['Security browser ' . $suffix . '-' . $index, 'security-browser-' . $suffix . '-' . $index]);
        $securityRoles[] = $id;
        foreach ($names as $name) {
            $database->execute('INSERT IGNORE INTO permissions (name, description, created_at) VALUES (?, ?, UTC_TIMESTAMP())', [$name, $name]);
            $entry = $database->fetchOne('SELECT id FROM permissions WHERE name = ?', [$name]);
            $database->execute('INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)', [$id, $entry['id']]);
        }
        $database->execute('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)', [$users[$index]['id'], $id]);
        foreach ($index === 3 ? ['space.view'] : ['space.view', 'space.edit'] as $name) $database->execute('INSERT INTO space_acl (space_id, principal_type, principal_id, permission, effect) VALUES (?, "user", ?, ?, "allow")', [$spaceId, $users[$index]['id'], $name]);
    }
    $scopes = ['pages:read', 'pages:write', 'spaces:read', 'spaces:write', 'attachments:read', 'attachments:write', 'users:read', 'users:write', 'roles:read', 'roles:write', 'groups:read', 'groups:write'];
    $workflowRoles = []; $workflowTokens = [];
    foreach ([5 => ['page.view', 'page.create', 'page.edit', 'attachment.upload'], 6 => ['page.view', 'page.review', 'page.verify'], 7 => ['page.view', 'page.publish'], 8 => ['page.view']] as $index => $names) {
        $role = $database->insert('INSERT INTO roles (name, slug) VALUES (?, ?)', ['Workflow browser ' . $suffix . '-' . $index, 'workflow-browser-' . $suffix . '-' . $index]);
        $workflowRoles[] = $role;
        foreach ($names as $name) {
            $database->execute('INSERT IGNORE INTO permissions (name) VALUES (?)', [$name]);
            $database->execute('INSERT INTO role_permissions (role_id, permission_id) SELECT ?, id FROM permissions WHERE name = ?', [$role, $name]);
        }
        $database->execute('INSERT INTO user_roles VALUES (?, ?)', [$users[$index]['id'], $role]);
        $workflowTokens[$index] = (new ApiTokenService($database))->create($users[$index]['id'], 'Workflow browser', $scopes, null)['token'];
    }
    $token = (new ApiTokenService($database))->create($users[0]['id'], 'Browser tests', $scopes, null)['token'];
    $delegateToken = (new ApiTokenService($database))->create($users[4]['id'], 'Browser security tests', $scopes, null)['token'];
    $wildcardPermissionId = (int) $database->fetchOne('SELECT id FROM permissions WHERE name = "*"')['id'];
    $image = imagecreatetruecolor(256, 128);
    ob_start(); imagepng($image); $imageBase64 = base64_encode((string) ob_get_clean()); imagedestroy($image);
    echo json_encode(['imageBase64' => $imageBase64, 'users'  => $users, 'roleId' => $roleId, 'limitedRoleId' => $limitedRole, 'securityRoleIds' => $securityRoles,
        'workflowRoleIds' => $workflowRoles, 'workflowTokens' => $workflowTokens,
        'wildcardPermissionId' => $wildcardPermissionId, 'delegateToken' => $delegateToken, 'spaceId' => $spaceId, 'spaceKey' => $spaceKey, 'token' => $token], JSON_THROW_ON_ERROR);
} elseif (in_array($argv[1] ?? '', ['acl-search-scale', 'acl-search-cleanup'], true)) {
    $data = json_decode(file_get_contents(dirname(__DIR__, 2) . '/storage/temp/editor-browser.json'), true, 512, JSON_THROW_ON_ERROR);
    $parentId = filter_var($argv[2] ?? '', FILTER_VALIDATE_INT);
    $parent = $parentId === false ? null : $database->fetchOne('SELECT id FROM pages WHERE id = ? AND space_id = ?', [$parentId, $data['spaceId']]);
    if ($parent === null) throw new RuntimeException('Browser fixture parent required.');
    if ($argv[1] === 'acl-search-cleanup') {
        $database->execute('DELETE FROM pages WHERE space_id = ? AND parent_id = ? AND slug LIKE ?', [$data['spaceId'], $parentId, 'scale-' . $parentId . '-%']);
        exit(0);
    }
    $database->transaction(function () use ($database, $data, $parentId): void {
        for ($index = 0; $index < 100; $index++) {
            $database->execute('INSERT INTO pages (space_id, parent_id, title, slug, content_html, content_format, author_id, owner_id, status, created_at, updated_at)
                VALUES (?, ?, ?, ?, "<p>Protected scale document</p>", "visual", ?, ?, "draft", UTC_TIMESTAMP(), UTC_TIMESTAMP())',
                [$data['spaceId'], $parentId, 'A protected scale document ' . $index, 'scale-' . $parentId . '-' . $index, $data['users'][0]['id'], $data['users'][0]['id']]);
        }
    });
} elseif (in_array($argv[1] ?? '', ['legacy-html', 'legacy-markdown', 'legacy-snapshot'], true)) {
    $data = json_decode(file_get_contents(dirname(__DIR__, 2) . '/storage/temp/editor-browser.json'), true, 512, JSON_THROW_ON_ERROR);
    $id = filter_var($argv[2] ?? '', FILTER_VALIDATE_INT);
    $page = $id === false ? null : $database->fetchOne('SELECT id FROM pages WHERE id = ? AND space_id = ?', [$id, $data['spaceId']]);
    if ($page === null) throw new RuntimeException('Browser fixture page required.');
    if ($argv[1] !== 'legacy-snapshot') {
        $format = $argv[1] === 'legacy-markdown' ? 'markdown' : 'visual';
        $markdown = $format === 'markdown' ? '# Original Markdown source' : null;
        $html = '<article><p onclick="window.__xss=1">Legacy safe</p><svg><script>window.__xss=1</script></svg><script>window.__xss=1</script></article>';
        if ($format === 'markdown') $html .= '<table><tbody><tr><td>Historical table</td></tr></tbody></table>';
        $document = json_encode(['version' => 1, 'format' => $format, 'source' => $markdown ?? $html], JSON_THROW_ON_ERROR);
        $database->execute('UPDATE pages SET content_html = ?, content_markdown = ?, content_format = ?, document_json = ? WHERE id = ?', [$html, $markdown, $format, $document, $id]);
        $database->execute('UPDATE page_revisions SET content_html = ?, content_markdown = ?, content_format = ?, document_json = ? WHERE page_id = ? AND revision_number = 1', [$html, $markdown, $format, $document, $id]);
    }
    echo json_encode([
        'page' => $database->fetchOne('SELECT content_html, content_markdown, content_format, document_json FROM pages WHERE id = ?', [$id]),
        'revision' => $database->fetchOne('SELECT content_html, content_markdown, content_format, document_json FROM page_revisions WHERE page_id = ? AND revision_number = 1', [$id]),
    ], JSON_THROW_ON_ERROR);
} elseif (($argv[1] ?? '') === 'cleanup') {
    $data = json_decode(file_get_contents(dirname(__DIR__, 2) . '/storage/temp/editor-browser.json'), true, 512, JSON_THROW_ON_ERROR);
    $database->execute('DELETE FROM spaces WHERE id = ?', [$data['spaceId']]);
    foreach ($data['users'] as $user) {
        foreach ($database->fetchAll('SELECT p.id FROM pages p INNER JOIN spaces s ON s.id = p.space_id WHERE s.owner_id = ?', [$user['id']]) as $page) {
            $files = new \OpenWiki\Attachments\AttachmentService($database, dirname(__DIR__, 2)); $files->purgeStorageKeys($files->storageKeysForPage((int) $page['id']));
        }
        $database->execute('DELETE FROM spaces WHERE owner_id = ?', [$user['id']]);
    }
    $database->execute('DELETE FROM user_groups WHERE slug LIKE "space-browser-%"');
    foreach ($data['users'] as $user) {
        $database->execute('DELETE FROM audit_logs WHERE user_id = ?', [$user['id']]);
        $database->execute('DELETE FROM users WHERE id = ?', [$user['id']]);
    }
    $database->execute('DELETE FROM roles WHERE id = ?', [$data['roleId']]);
    $database->execute('DELETE FROM roles WHERE id = ?', [$data['limitedRoleId']]);
    foreach ($data['securityRoleIds'] as $id) $database->execute('DELETE FROM roles WHERE id = ?', [$id]);
    foreach ($data['workflowRoleIds'] as $id) $database->execute('DELETE FROM roles WHERE id = ?', [$id]);
}
