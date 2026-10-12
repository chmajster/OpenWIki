<?php
declare(strict_types=1);
namespace OpenWiki\Tests\Integration;

use OpenWiki\Admin\AdminActor;
use OpenWiki\Admin\DirectoryAdminService;
use OpenWiki\Core\Application;
use OpenWiki\Core\Database;
use OpenWiki\Core\Request;
use OpenWiki\Database\MigrationRunner;
use OpenWiki\Permissions\PageAclService;
use OpenWiki\Permissions\SpaceAccessService;
use OpenWiki\Spaces\DocumentationTreeService;
use OpenWiki\Spaces\SpaceService;
use OpenWiki\Tests\Support\IsolatedDatabase;
use PHPUnit\Framework\TestCase;

final class SpaceManagementTest extends TestCase
{
    private function instance(callable $test): void
    {
        IsolatedDatabase::run(function (Database $db, array $config) use ($test): void {
            (new MigrationRunner($db, dirname(__DIR__, 2) . '/database/migrations'))->migrate();
            $old = [];
            foreach (['DB_HOST' => $config['host'], 'DB_PORT' => $config['port'], 'DB_DATABASE' => $config['database'], 'DB_USERNAME' => $config['username'], 'DB_PASSWORD' => $config['password'], 'APP_KEY' => str_repeat('a', 64)] as $key => $value) {
                $old[$key] = getenv($key); putenv($key . '=' . $value);
            }
            try { $test(Application::boot(dirname(__DIR__, 2), false), $db); }
            finally { foreach ($old as $key => $value) $value === false ? putenv($key) : putenv($key . '=' . $value); }
        });
    }

    private function user(Database $db, string $name, bool $super = false): int
    {
        $id = $db->insert('INSERT INTO users (username, email) VALUES (?, ?)', [$name, $name . '@example.test']);
        if ($super) {
            $db->execute('INSERT IGNORE INTO permissions (name) VALUES ("*")');
            $role = $db->insert('INSERT INTO roles (name, slug) VALUES (?, ?)', [$name, $name]);
            $db->execute('INSERT INTO role_permissions (role_id, permission_id) SELECT ?, id FROM permissions WHERE name = "*"', [$role]);
            $db->execute('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)', [$id, $role]);
        }
        return $id;
    }

    private function request(array $input = []): Request { return new Request('POST', '/spaces', [], $input, [], []); }

    public function testSettingsAclGroupMembershipAndArchivedReadOnly(): void
    {
        $this->instance(function (Application $app, Database $db): void {
            $owner = $this->user($db, 'owner', true); $reader = $this->user($db, 'reader');
            $service = new SpaceService($app); $space = $service->create(['name' => 'Zespół', 'visibility' => 'restricted', 'icon' => '📚'], $owner, $this->request());
            $group = $db->insert('INSERT INTO user_groups (name, slug) VALUES ("Zespół", "team")');
            $service->rule((int) $space['id'], ['principal_type' => 'group', 'principal_id' => $group, 'permission' => 'space.view', 'effect' => 'allow'], $owner, $this->request());
            $access = new SpaceAccessService($app);
            self::assertFalse($access->canViewFor($space, $reader));
            $db->execute('INSERT INTO group_users (group_id, user_id) VALUES (?, ?)', [$group, $reader]);
            self::assertTrue($access->canViewFor($space, $reader));
            self::assertSame('group', $service->effective($space, $reader)['space.view']['rules'][0]['principal_type']);
            $page = $db->insert('INSERT INTO pages (space_id, title, slug, content_html, author_id, owner_id, status) VALUES (?, "Strona", "page", "<p>Treść</p>", ?, ?, "published")', [$space['id'], $owner, $owner]);
            $space = $service->update((int) $space['id'], ['home_page_id' => $page, 'status' => 'archived'], $owner, $this->request());
            $row = $db->fetchOne('SELECT * FROM pages WHERE id = ?', [$page]);
            self::assertTrue((new PageAclService($app))->canViewFor($row, $space, $reader));
            self::assertFalse((new PageAclService($app))->canFor($row, $space, 'page.edit', $owner, true));
            self::assertFalse($access->canEditFor($space, $owner, true));
            self::assertSame($page, (int) $space['home_page_id']);
            $space = $service->update((int) $space['id'], ['status' => 'active'], $owner, $this->request());
            self::assertTrue($access->canEditFor($space, $owner));
            $service->rule((int) $space['id'], ['principal_type' => 'user', 'principal_id' => $reader, 'permission' => 'space.view', 'effect' => 'deny'], $owner, $this->request());
            self::assertFalse($access->canViewFor($space, $reader));
            self::assertStringContainsString('deny', $service->effective($space, $reader)['space.view']['reason']);
        });
    }

    public function testPrivateIgnoresGrantsAndProtectsLastManagerThroughAccountChanges(): void
    {
        $this->instance(function (Application $app, Database $db): void {
            $owner = $this->user($db, 'owner', true); $other = $this->user($db, 'other', true); $reader = $this->user($db, 'reader');
            $service = new SpaceService($app); $space = $service->create(['name' => 'Prywatna'], $owner, $this->request());
            $service->rule((int) $space['id'], ['principal_type' => 'user', 'principal_id' => $reader, 'permission' => 'space.view', 'effect' => 'allow'], $owner, $this->request());
            self::assertFalse((new SpaceAccessService($app))->canViewFor($space, $reader));
            $directory = new DirectoryAdminService($db, AdminActor::user($other));
            try { $directory->disableUser($owner); self::fail('Last space manager must be protected independently of other Super Admins.'); }
            catch (\DomainException $error) { self::assertStringContainsString('zarządzającego', $error->getMessage()); }
            self::assertSame('active', $db->fetchOne('SELECT status FROM users WHERE id = ?', [$owner])['status']);
            $space = $service->update((int) $space['id'], ['owner_id' => $reader], $owner, $this->request());
            self::assertTrue((new SpaceAccessService($app))->canViewFor($space, $reader));
            self::assertTrue($directory->disableUser($owner));
            self::assertNotEmpty($service->effective($space, $reader)['space.manage_access']['allowed']);
        });
    }

    public function testTreeHidesParentMetadataAndPaginatesAuthorizedBranches(): void
    {
        $this->instance(function (Application $app, Database $db): void {
            $owner = $this->user($db, 'owner', true);
            $space = (new SpaceService($app))->create(['name' => 'Publiczna', 'visibility' => 'public'], $owner, $this->request());
            $parent = $db->insert('INSERT INTO pages (space_id, title, slug, content_html, author_id, owner_id, status) VALUES (?, "TAJNY RODZIC", "secret-parent", "", ?, ?, "draft")', [$space['id'], $owner, $owner]);
            $child = $db->insert('INSERT INTO pages (space_id, parent_id, title, slug, content_html, author_id, owner_id, status, inherit_acl) VALUES (?, ?, "Widoczne dziecko", "child", "", ?, ?, "published", 0)', [$space['id'], $parent, $owner, $owner]);
            $tree = new DocumentationTreeService($app);
            $result = $tree->branch($space, null, false, false, false);
            self::assertCount(1, $result['items']); self::assertNull($result['items'][0]['parent_id']);
            self::assertStringNotContainsString('TAJNY', json_encode($result, JSON_THROW_ON_ERROR));
            self::assertStringNotContainsString('secret-parent', json_encode($result, JSON_THROW_ON_ERROR));
            $path = $tree->breadcrumbs($space, $db->fetchOne('SELECT * FROM pages WHERE id = ?', [$child]), null, false, false);
            self::assertSame([['title' => 'Niedostępny poziom', 'url' => null]], $path);
            for ($i = 0; $i < 54; $i++) $db->execute('INSERT INTO pages (space_id, parent_id, title, slug, content_html, author_id, owner_id, status) VALUES (?, ?, ?, ?, "", ?, ?, "published")', [$space['id'], $child, 'Potomek ' . $i, 'child-' . $i, $owner, $owner]);
            $branch = $tree->branch($space, null, false, false, false, $child);
            self::assertCount(50, $branch['items']); self::assertSame(50, $branch['next_offset']);
            self::assertCount(4, $tree->branch($space, null, false, false, false, $child, '', 50)['items']);
        });
    }

    public function testMovePreviewCannotBeReusedAfterChangeAndPreservesHistoryAndRedirects(): void
    {
        $this->instance(function (Application $app, Database $db): void {
            $owner = $this->user($db, 'owner', true); $spaces = new SpaceService($app);
            $source = $spaces->create(['name' => 'Public', 'visibility' => 'public'], $owner, $this->request());
            $destination = $spaces->create(['name' => 'Private'], $owner, $this->request());
            $writer = new \OpenWiki\Wiki\PageWriteService($db, $app->basePath());
            $root = $writer->save($source, null, ['title' => 'Root', 'status' => 'published', 'content_html' => '<p>Treść</p>'], $owner);
            $child = $writer->save($source, null, ['title' => 'Child', 'parent_id' => $root['id'], 'status' => 'published', 'content_html' => '<p>[[Root]]</p>'], $owner);
            $snapshot = $db->fetchOne('SELECT * FROM page_revisions WHERE page_id = ?', [$root['id']]);
            $service = new \OpenWiki\Spaces\StructureService($app);
            $input = ['action' => 'move', 'scope' => 'subtree', 'destination_space_id' => (int) $destination['id'], 'base_version' => 1];
            $preview = $service->preview((int) $root['id'], $input, $owner);
            self::assertSame(2, $preview['count']); self::assertSame('Gość', $preview['access_changes'][0]['principal']); self::assertSame(2, $preview['access_changes'][0]['lost']);
            $db->execute('UPDATE pages SET version = version + 1 WHERE id = ?', [$child['id']]);
            try { $service->execute((int) $root['id'], $input + ['preview_token' => $preview['preview_token'], 'operation_id' => '11111111-1111-4111-8111-111111111111'], $owner, $this->request()); self::fail('Stale plan must fail.'); }
            catch (\OpenWiki\Spaces\SpaceConflict) { self::assertSame((int) $source['id'], (int) $db->fetchOne('SELECT space_id FROM pages WHERE id = ?', [$root['id']])['space_id']); }
            $preview = $service->preview((int) $root['id'], $input, $owner); $input += ['preview_token' => $preview['preview_token'], 'operation_id' => '22222222-2222-4222-8222-222222222222'];
            $result = $service->execute((int) $root['id'], $input, $owner, $this->request());
            self::assertSame((int) $root['id'], $result['id']); self::assertSame($result, $service->execute((int) $root['id'], $input, $owner, $this->request()));
            self::assertSame($snapshot, $db->fetchOne('SELECT * FROM page_revisions WHERE id = ?', [$snapshot['id']]));
            $repository = new \OpenWiki\Repositories\PageRepository($db);
            self::assertSame($destination['space_key'], $repository->redirectTarget((int) $source['id'], 'root')['space_key']);
            self::assertSame((int) $destination['id'], (int) $repository->findById((int) $child['id'])['space_id']);
            self::assertSame((int) $root['id'], (int) $db->fetchOne('SELECT target_page_id FROM page_links WHERE source_page_id = ?', [$child['id']])['target_page_id']);
        });
    }

    public function testCopyCreatesIndependentAttachmentsRewritesLinksAndPreservesMarkdownCode(): void
    {
        $this->instance(function (Application $app, Database $db): void {
            $owner = $this->user($db, 'owner', true); $spaces = new SpaceService($app);
            $source = $spaces->create(['name' => 'Source', 'visibility' => 'public'], $owner, $this->request());
            $destination = $spaces->create(['name' => 'Destination', 'visibility' => 'public'], $owner, $this->request());
            $writer = new \OpenWiki\Wiki\PageWriteService($db, $app->basePath());
            $root = $writer->save($source, null, ['title' => 'Root', 'status' => 'published', 'tags' => ['tag'], 'content_html' => '<p>[[Child]]</p>'], $owner);
            $child = $writer->save($source, null, ['title' => 'Child', 'parent_id' => $root['id'], 'status' => 'published', 'content_format' => 'markdown', 'content_markdown' => "[[Root]]\n\n`[[Root]]`\n\n```php\n[[Root]]\n```"], $owner);
            $files = new \OpenWiki\Attachments\AttachmentService($db, $app->basePath()); $path = tempnam(sys_get_temp_dir(), 'wiki-copy-'); file_put_contents($path, 'first version');
            $attachment = $files->createFromSource((int) $root['id'], $owner, $path, 'note.txt'); file_put_contents($path, 'second version'); $files->addVersionFromSource($attachment, $owner, $path, 'note.txt'); unlink($path);
            $writer->save($source, $root, ['base_version' => 1, 'content_html' => '<p>[[Child]] <a href="/attachments/' . $attachment . '/download">Plik</a></p>'], $owner);
            $service = new \OpenWiki\Spaces\StructureService($app); $input = ['action' => 'copy', 'scope' => 'subtree', 'destination_space_id' => (int) $destination['id'], 'base_version' => 2];
            $preview = $service->preview((int) $root['id'], $input, $owner); $input += ['preview_token' => $preview['preview_token'], 'operation_id' => '33333333-3333-4333-8333-333333333333'];
            $result = $service->execute((int) $root['id'], $input, $owner, $this->request());
            try {
                self::assertNotSame((int) $root['id'], $result['id']); $newRoot = (new \OpenWiki\Repositories\PageRepository($db))->findById($result['id']);
                $newChild = (new \OpenWiki\Repositories\PageRepository($db))->findById($result['mapping'][$child['id']]);
                self::assertSame(1, (int) $newRoot['version']); self::assertSame($newRoot['id'], $newChild['parent_id']);
                self::assertStringContainsString('/spaces/' . $destination['space_key'] . '/pages/child', $newRoot['content_html']);
                self::assertStringContainsString('[Root](/spaces/' . $destination['space_key'] . '/pages/root-kopia)', $newChild['content_markdown']);
                self::assertStringContainsString('`[[Root]]`', $newChild['content_markdown']); self::assertStringContainsString("```php\n[[Root]]\n```", $newChild['content_markdown']);
                $copyAttachment = $files->listForPage($result['id'])[0];
                self::assertNotSame($files->find($attachment)['storage_key'], $copyAttachment['storage_key']); self::assertCount(2, $files->versions((int) $copyAttachment['id']));
                self::assertSame('second version', file_get_contents($files->currentPath($copyAttachment)));
                self::assertStringContainsString('/attachments/' . $copyAttachment['id'] . '/download', $newRoot['content_html']);
                self::assertSame(['tag'], array_column((new \OpenWiki\Wiki\WikiMetadataService($db))->tagsForPage($result['id']), 'name'));
                self::assertSame($result, $service->execute((int) $root['id'], $input, $owner, $this->request()));
            } finally { foreach ([$root['id'], ...array_values($result['mapping'])] as $page) $files->purgeStorageKeys($files->storageKeysForPage((int) $page)); }
        });
    }

    public function testCycleDenialPageOnlyMoveAndPersistentOrder(): void
    {
        $this->instance(function (Application $app, Database $db): void {
            $owner = $this->user($db, 'owner', true); $space = (new SpaceService($app))->create(['name' => 'Tree'], $owner, $this->request());
            $writer = new \OpenWiki\Wiki\PageWriteService($db, $app->basePath()); $pages = [];
            foreach (['Root', 'Sibling', 'Child'] as $title) $pages[] = $writer->save($space, null, ['title' => $title, 'parent_id' => $title === 'Child' ? $pages[0]['id'] : null, 'content_html' => '<p>Text</p>'], $owner);
            $service = new \OpenWiki\Spaces\StructureService($app);
            try { $service->preview((int) $pages[0]['id'], ['base_version' => 1, 'parent_id' => $pages[2]['id']], $owner); self::fail('Cycle must fail.'); }
            catch (\InvalidArgumentException $error) { self::assertStringContainsString('potomka', $error->getMessage()); }
            $input = ['base_version' => 1, 'action' => 'move', 'scope' => 'page', 'parent_id' => $pages[1]['id']]; $preview = $service->preview((int) $pages[0]['id'], $input, $owner);
            self::assertSame(1, $preview['detached_children']);
            $service->execute((int) $pages[0]['id'], $input + ['preview_token' => $preview['preview_token'], 'operation_id' => '44444444-4444-4444-8444-444444444444'], $owner, $this->request());
            self::assertNull($db->fetchOne('SELECT parent_id FROM pages WHERE id = ?', [$pages[2]['id']])['parent_id']);
            $input = ['base_version' => 2, 'before_id' => $pages[1]['id']]; $preview = $service->preview((int) $pages[2]['id'], $input, $owner);
            $service->execute((int) $pages[2]['id'], $input + ['preview_token' => $preview['preview_token'], 'operation_id' => '55555555-5555-4555-8555-555555555555'], $owner, $this->request());
            self::assertSame([(int) $pages[2]['id'], (int) $pages[1]['id']], array_map('intval', array_column($db->fetchAll('SELECT id FROM pages WHERE space_id = ? AND parent_id IS NULL ORDER BY order_index, id', [$space['id']]), 'id')));
        });
    }
}
