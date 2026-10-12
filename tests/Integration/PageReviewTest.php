<?php
declare(strict_types=1);
namespace OpenWiki\Tests\Integration;

use OpenWiki\Auth\ApiTokenService;
use OpenWiki\Core\Application;
use OpenWiki\Core\Request;
use OpenWiki\Core\Session;
use OpenWiki\Database\MigrationRunner;
use OpenWiki\Http\Controllers\Api\ApiV1Controller;
use OpenWiki\Http\Controllers\PageController;
use OpenWiki\Import\DocumentImportService;
use OpenWiki\Permissions\PageAclService;
use OpenWiki\Repositories\PageRepository;
use OpenWiki\Repositories\SpaceRepository;
use OpenWiki\Security\Csrf;
use OpenWiki\Spaces\SpaceActor;
use OpenWiki\Wiki\PageReviewService;
use OpenWiki\Wiki\PageWriteService;
use PHPUnit\Framework\TestCase;

final class PageReviewTest extends TestCase
{
    private Application $app;
    private array $env = [];
    private string|false $lock;
    private array $users = [];
    private array $space;

    protected function setUp(): void
    {
        if (!getenv('OPENWIKI_TEST_DB_HOST')) self::markTestSkipped('Dedicated database required.');
        $base = dirname(__DIR__, 2);
        $this->lock = is_file($base . '/storage/installed.lock') ? file_get_contents($base . '/storage/installed.lock') : false;
        file_put_contents($base . '/storage/installed.lock', '{"review_test":true}');
        foreach (['HOST', 'PORT', 'DATABASE', 'USERNAME', 'PASSWORD'] as $key) {
            $this->env['DB_' . $key] = getenv('DB_' . $key); putenv('DB_' . $key . '=' . getenv('OPENWIKI_TEST_DB_' . $key));
        }
        $this->env['APP_KEY'] = getenv('APP_KEY'); putenv('APP_KEY=' . str_repeat('9', 64));
        $this->app = Application::boot($base); $db = $this->app->database();
        (new MigrationRunner($db, $base . '/database/migrations'))->migrate();
        $db->pdo()->beginTransaction(); $suffix = bin2hex(random_bytes(5));
        foreach (['author' => ['page.view', 'page.create', 'page.edit', 'attachment.upload'], 'reviewer' => ['page.view', 'page.review', 'page.verify'], 'publisher' => ['page.view', 'page.publish'], 'reader' => ['page.view'], 'super' => ['*']] as $kind => $permissions) {
            $id = $db->insert('INSERT INTO users (username, email, status, auth_source, force_password_change) VALUES (?, ?, "active", "local", 0)', [$kind . $suffix, $kind . $suffix . '@example.test']); $this->users[$kind] = $id;
            $role = $db->insert('INSERT INTO roles (name, slug) VALUES (?, ?)', [$kind . $suffix, $kind . $suffix]);
            foreach ($permissions as $name) { $db->execute('INSERT IGNORE INTO permissions (name) VALUES (?)', [$name]); $db->execute('INSERT INTO role_permissions (role_id, permission_id) SELECT ?, id FROM permissions WHERE name = ?', [$role, $name]); }
            $db->execute('INSERT INTO user_roles VALUES (?, ?)', [$id, $role]);
        }
        $id = (new SpaceRepository($db))->create(['name' => 'Review', 'space_key' => 'REVIEW-' . strtoupper($suffix), 'owner_id' => $this->users['super'], 'visibility' => 'public', 'description' => null]);
        foreach ($this->users as $kind => $user) if ($kind !== 'reader') $db->execute('INSERT INTO space_acl (space_id, principal_type, principal_id, permission, effect) VALUES (?, "user", ?, "space.edit", "allow")', [$id, $user]);
        $this->space = $db->fetchOne('SELECT * FROM spaces WHERE id = ?', [$id]); Session::put('user_id', $this->users['super']);
    }

    protected function tearDown(): void
    {
        if (!isset($this->app)) return;
        if ($this->app->database()->pdo()->inTransaction()) $this->app->database()->pdo()->rollBack();
        foreach ($this->env as $name => $value) putenv($value === false ? $name : $name . '=' . $value);
        if ($this->lock === false) @unlink($this->app->basePath('storage/installed.lock')); else file_put_contents($this->app->basePath('storage/installed.lock'), $this->lock);
        Session::forget('user_id');
    }

    private function policy(string $kind): callable
    {
        $actor = new SpaceActor($this->app->database(), $this->users[$kind]); $acl = new PageAclService($this->app);
        return fn (string $permission, ?array $page): bool => $page === null ? $actor->can($permission) : $acl->canFor($page, $this->space, $permission, $actor->id, $actor->can('*'), $actor->can($permission));
    }

    private function write(?array $page, array $input, string $kind = 'author'): array
    {
        return (new PageWriteService($this->app->database(), $this->app->basePath()))->save($this->space, $page, $input, $this->users[$kind], null, $this->policy($kind));
    }

    private function enable(): void
    {
        $this->app->database()->execute('UPDATE spaces SET review_workflow = 1 WHERE id = ?', [$this->space['id']]); $this->space['review_workflow'] = 1;
    }

    private function decide(array $page, string $action, string $kind, array $extra = []): array
    {
        return (new PageReviewService($this->app->database()))->transition((int) $page['id'], ['revision' => $page['version'], 'action' => $action, 'comment' => 'Decision'] + $extra, $this->users[$kind], new Request('POST', '/', [], [], [], []));
    }

    private function token(string $kind): string { return (new ApiTokenService($this->app->database()))->create($this->users[$kind], 'Review', ['pages:read', 'pages:write', 'attachments:read'], null)['token']; }
    private function request(array $input, string $token, array $query = []): Request { return new Request('POST', '/', $query, $input, ['HTTP_AUTHORIZATION' => 'Bearer ' . $token, 'CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], []); }

    public function testReaderKeepsPublishedContentAndMetadataWhileApprovalIsBoundToHead(): void
    {
        $page = $this->write(null, ['title' => 'Public N', 'content_html' => '<p>Published N</p>', 'status' => 'published', 'tags' => ['public']], 'super'); $this->enable();
        $head = $this->write($page, ['base_version' => 1, 'title' => 'Private N+1', 'slug' => 'private-n1', 'content_html' => '<p>Private N+1</p>', 'tags' => ['private']]);
        $repo = new PageRepository($this->app->database()); $public = $repo->findById((int) $page['id']);
        self::assertSame('Public N', $public['title']); self::assertSame('<p>Published N</p>', $public['content_html']); self::assertSame(1, (int) $public['published_revision']); self::assertSame('Private N+1', $repo->working($public)['title']);
        try { $this->write(null, ['title' => 'Conflicting route', 'slug' => 'private-n1', 'content_html' => '<p>Other</p>']); self::fail('Working slug was not reserved.'); }
        catch (\InvalidArgumentException) { self::assertSame(2, (int) $repo->findById((int) $page['id'])['version']); }
        $api = new ApiV1Controller($this->app); $reader = $this->token('reader');
        $resource = json_decode($api->page($this->request([], $reader), (string) $page['id'])->body(), true)['data'];
        self::assertSame(1, $resource['version']); self::assertSame('Public N', $resource['title']); self::assertSame('public', $resource['tags'][0]['name']);
        $this->decide($head, 'submit', 'author'); $this->decide($head, 'approve', 'reviewer');
        $new = $this->write($public, ['base_version' => 2, 'content_html' => '<p>Private N+2</p>']); self::assertSame('draft', $new['workflow_state']);
        try { $this->decide($head, 'publish', 'publisher'); self::fail('Stale approval published newer content.'); } catch (\RuntimeException $e) { self::assertSame('EDIT_CONFLICT', $e->getMessage()); }
        try { $this->decide($new, 'publish', 'publisher'); self::fail('Unreviewed revision published.'); } catch (\DomainException $e) { self::assertStringContainsString('zatwierdzenia', $e->getMessage()); }
        $this->decide($new, 'submit', 'author'); $this->decide($new, 'approve', 'reviewer'); $final = $this->decide($new, 'publish', 'publisher');
        self::assertSame(3, (int) $final['published_revision']); self::assertSame('<p>Private N+2</p>', $final['content_html']);
    }

    public function testAuthorCannotBypassReviewThroughApiRestoreOrImportAndReaderCannotReadDiff(): void
    {
        $page = $this->write(null, ['title' => 'Restore', 'content_html' => '<p>Public</p>', 'status' => 'published'], 'super'); $this->enable();
        $api = new ApiV1Controller($this->app); $author = $this->token('author'); $publisher = $this->token('publisher'); $reader = $this->token('reader');
        self::assertSame(403, $api->updatePage($this->request(['base_version' => 1, 'status' => 'published', 'content_html' => '<p>Bypass</p>'], $author), (string) $page['id'])->status());
        $head = $this->write($page, ['base_version' => 1, 'content_html' => '<p>Private</p>']);
        self::assertSame(404, $api->pageRevision($this->request([], $reader), (string) $page['id'], '2')->status());
        self::assertSame(404, $api->pageDiff($this->request([], $reader, ['from' => '1', 'to' => '2']), (string) $page['id'])->status());
        self::assertSame(404, $api->page($this->request([], $reader, ['view' => 'working']), (string) $page['id'])->status());
        self::assertSame(404, $api->pageReview($this->request([], $reader), (string) $page['id'])->status());
        self::assertSame(200, $api->pageRevision($this->request([], $publisher), (string) $page['id'], '2')->status());
        self::assertSame(200, $api->restorePage($this->request(['revision' => 2, 'base_version' => 2], $author), (string) $page['id'])->status());
        self::assertSame('draft', $this->app->database()->fetchOne('SELECT workflow_state FROM pages WHERE id = ?', [$page['id']])['workflow_state']);
        $temp = tempnam(sys_get_temp_dir(), 'wiki-review-'); file_put_contents($temp, '# Imported private knowledge');
        try { $result = (new DocumentImportService($this->app->database(), $this->app->basePath(), false, $this->policy('author')))->importFromPath($this->space, $this->users['author'], $temp, 'Private.md'); }
        finally { unlink($temp); }
        $imported = (new PageRepository($this->app->database()))->findById($result['page_ids'][0]); self::assertNull($imported['published_revision']); self::assertSame('draft', $imported['workflow_state']);
    }

    public function testSelfApprovalRejectionPermissionDenialAndFreshness(): void
    {
        $this->enable(); $page = $this->write(null, ['title' => 'Policy', 'content_html' => '<p>Current</p>', 'review_due_at' => '2000-01-01']);
        $this->decide($page, 'submit', 'author');
        $role = $this->app->database()->fetchOne('SELECT role_id FROM user_roles WHERE user_id = ?', [$this->users['reviewer']]); $this->app->database()->execute('INSERT INTO user_roles VALUES (?, ?)', [$this->users['author'], $role['role_id']]);
        try { $this->decide($page, 'approve', 'author'); self::fail('Self approval accepted.'); } catch (\DomainException $e) { self::assertStringContainsString('własnej', $e->getMessage()); }
        $rejected = $this->decide($page, 'reject', 'reviewer'); self::assertSame('rejected', $rejected['workflow_state']);
        $this->decide($page, 'submit', 'author'); $this->decide($page, 'approve', 'reviewer');
        $acl = new PageAclService($this->app); $acl->addRule((int) $page['id'], 'user', $this->users['publisher'], 'page.publish', 'deny', false);
        try { $this->decide($page, 'publish', 'publisher'); self::fail('ACL deny ignored.'); } catch (\DomainException $e) { self::assertStringContainsString('page.publish', $e->getMessage()); }
        $published = $this->decide($page, 'publish', 'super'); $number = (int) $published['published_revision'];
        $verified = $this->decide($published, 'verify', 'reviewer', ['review_due_at' => gmdate('Y-m-d', time() + 86400 * 30)]);
        self::assertSame($number, (int) $verified['verified_revision']); self::assertNotNull($verified['verified_at']);
        self::assertCount(6, (new PageReviewService($this->app->database()))->decisions((int) $page['id']));
        $draft = $this->write($verified, ['base_version' => $published['version'], 'content_html' => '<p>Later private edit</p>']);
        self::assertSame($verified['review_due_at'], (new PageRepository($this->app->database()))->findById((int) $page['id'])['review_due_at']);
        $api = new ApiV1Controller($this->app);
        $decisions = json_decode($api->pageReview($this->request([], $this->token('reviewer'), ['per_page' => 2]), (string) $page['id'])->body(), true);
        self::assertCount(2, $decisions['data']); self::assertSame(6, $decisions['meta']['total']);
        $this->app->database()->execute('UPDATE spaces SET allow_self_review = 1 WHERE id = ?', [$this->space['id']]);
        $this->decide($draft, 'submit', 'author');
        self::assertSame('approved', $this->decide($draft, 'approve', 'author')['workflow_state']);
    }

    public function testDeletingWorkingAttachmentPreservesPublishedBytesUntilNextPublication(): void
    {
        $this->enable(); $db = $this->app->database();
        $page = $this->write(null, ['title' => 'Files', 'content_html' => '<p>File</p>']);
        $files = new \OpenWiki\Attachments\AttachmentService($db, $this->app->basePath());
        $source = tempnam(sys_get_temp_dir(), 'wiki-review-file-'); file_put_contents($source, 'Approved bytes');
        $id = $files->createFromSource((int) $page['id'], $this->users['author'], $source, 'approved.txt'); unlink($source);
        $keys = $files->storageKeysForPage((int) $page['id']);
        try {
            $head = $this->write($page, ['base_version' => 1, 'content_html' => '<p>Approved file</p>']);
            $this->decide($head, 'submit', 'author'); $this->decide($head, 'approve', 'reviewer'); $published = $this->decide($head, 'publish', 'publisher');
            self::assertTrue($files->delete($id)); self::assertNull($files->find($id));
            $api = new ApiV1Controller($this->app); $reader = $this->token('reader');
            self::assertSame(200, $api->downloadAttachment($this->request([], $reader), (string) $id)->status());
            $visibility = new \OpenWiki\Attachments\AttachmentVisibility($db);
            $visible = $visibility->forPage($files->listForPage((int) $page['id'], true), $this->users['reader']);
            self::assertSame('approved.txt', $visible[0]['name']); self::assertSame('Approved bytes', file_get_contents($files->currentPath($visible[0])));
            $draft = $this->write($published, ['base_version' => 2, 'content_html' => '<p>File removed</p>']);
            self::assertSame(200, $api->downloadAttachment($this->request([], $reader), (string) $id)->status());
            $this->decide($draft, 'submit', 'author'); $this->decide($draft, 'approve', 'reviewer'); $this->decide($draft, 'publish', 'publisher');
            self::assertSame(404, $api->downloadAttachment($this->request([], $reader), (string) $id)->status());
            self::assertSame(200, $api->downloadAttachment($this->request([], $reader, ['revision' => 2]), (string) $id)->status());
            self::assertSame(404, $api->downloadAttachment($this->request([], $reader, ['revision' => 1]), (string) $id)->status());
        } finally { $files->purgeStorageKeys($keys); }
    }
}
