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
use OpenWiki\Http\Controllers\PageDraftController;
use OpenWiki\Import\DocumentImportService;
use OpenWiki\Repositories\PageRepository;
use OpenWiki\Repositories\SpaceRepository;
use OpenWiki\Security\Csrf;
use OpenWiki\Wiki\EditSessionService;
use OpenWiki\Wiki\PageWriteService;
use OpenWiki\Wiki\WikiMetadataService;
use PHPUnit\Framework\TestCase;

final class PageSavingTest extends TestCase
{
    private Application $app;
    private array $env = [];
    private string|false $oldLock;
    private array $space;
    private int $userId;
    private int $otherUserId;

    protected function setUp(): void
    {
        if (!getenv('OPENWIKI_TEST_DB_HOST')) self::markTestSkipped('MySQL integration environment is not configured.');
        $base = dirname(__DIR__, 2);
        $this->oldLock = is_file($base . '/storage/installed.lock') ? file_get_contents($base . '/storage/installed.lock') : false;
        file_put_contents($base . '/storage/installed.lock', '{"test":true}');
        foreach (['HOST', 'PORT', 'DATABASE', 'USERNAME', 'PASSWORD'] as $key) {
            $name = 'DB_' . $key;
            $this->env[$name] = getenv($name);
            putenv($name . '=' . (getenv('OPENWIKI_TEST_DB_' . $key) ?: ''));
        }
        $this->env['APP_KEY'] = getenv('APP_KEY');
        putenv('APP_KEY=' . str_repeat('7', 64));
        $this->app = Application::boot($base);
        $db = $this->app->database();
        (new MigrationRunner($db, $base . '/database/migrations'))->migrate();
        $db->pdo()->beginTransaction();
        $suffix = bin2hex(random_bytes(5));
        foreach (['editor', 'watcher'] as $kind) {
            $id = $db->insert('INSERT INTO users (username, email, password_hash, status, auth_source, force_password_change, created_at, updated_at) VALUES (?, ?, ?, "active", "local", 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())', [$kind . '-' . $suffix, $kind . '-' . $suffix . '@example.test', password_hash('TestPassword!42', PASSWORD_DEFAULT)]);
            if ($kind === 'editor') $this->userId = $id;
            else $this->otherUserId = $id;
        }
        $role = $db->insert('INSERT INTO roles (name, slug, is_system, created_at, updated_at) VALUES (?, ?, 0, UTC_TIMESTAMP(), UTC_TIMESTAMP())', ['Saving ' . $suffix, 'saving-' . $suffix]);
        $db->execute('INSERT IGNORE INTO permissions (name, description, created_at) VALUES ("*", "All", UTC_TIMESTAMP())');
        $permission = $db->fetchOne('SELECT id FROM permissions WHERE name = "*"');
        $db->execute('INSERT INTO role_permissions (role_id, permission_id) VALUES (?, ?)', [$role, $permission['id']]);
        $db->execute('INSERT INTO user_roles (user_id, role_id) VALUES (?, ?)', [$this->userId, $role]);
        Session::put('user_id', $this->userId);
        $id = (new SpaceRepository($db))->create(['name' => 'Saving', 'space_key' => 'SAVE-' . strtoupper($suffix), 'description' => null, 'owner_id' => $this->userId, 'visibility' => 'private']);
        $this->space = $db->fetchOne('SELECT * FROM spaces WHERE id = ?', [$id]);
        $db->execute('INSERT INTO watches (user_id, watchable_type, watchable_id, created_at) VALUES (?, "space", ?, UTC_TIMESTAMP())', [$this->otherUserId, $id]);
        $db->execute('INSERT INTO webhooks (name, target_url, secret_ciphertext, events_json, status, created_by, created_at, updated_at) VALUES ("Saving tests", "https://example.test/hook", "unused-encrypted-secret", ?, "active", ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())', [json_encode(['page.created', 'page.updated', 'page.published']), $this->userId]);
    }

    protected function tearDown(): void
    {
        if (!isset($this->app)) return;
        if ($this->app->database()->pdo()->inTransaction()) $this->app->database()->pdo()->rollBack();
        foreach ($this->env as $key => $value) $value === false ? putenv($key) : putenv($key . '=' . $value);
        $path = dirname(__DIR__, 2) . '/storage/installed.lock';
        $this->oldLock === false ? @unlink($path) : file_put_contents($path, $this->oldLock);
        Session::forget('user_id');
    }

    public function testUiApiImportAndRestoreHaveTheSameDomainEffects(): void
    {
        $db = $this->app->database();
        $ui = new PageController($this->app);
        $created = $ui->store($this->request(['title' => 'UI page', 'content_html' => '<p>[[API page]]</p>', 'tags' => 'initial']), $this->space['space_key']);
        self::assertSame(200, $created->status());
        $page = json_decode($created->body(), true)['data']['page'];
        $token = (new ApiTokenService($db))->create($this->userId, 'Saving', ['pages:read', 'pages:write'], null)['token'];
        $api = new ApiV1Controller($this->app);
        $apiCreated = $api->createPage($this->request(['space_id' => $this->space['id'], 'title' => 'API page', 'content_html' => '<p>API</p>'], $token));
        self::assertSame(201, $apiCreated->status());
        self::assertCount(1, (new WikiMetadataService($db))->backlinks((int) json_decode($apiCreated->body(), true)['data']['id']));

        $updated = $api->updatePage($this->request(['base_version' => 1, 'title' => 'API edited UI', 'slug' => 'changed', 'content_format' => 'visual', 'content_html' => $page['content_html'], 'tags' => ['later'], 'change_summary' => 'API revision'], $token), (string) $page['id']);
        self::assertSame(200, $updated->status());
        $revision = (new PageRepository($db))->revision((int) $page['id'], 2);
        self::assertSame('changed', $revision['slug']);
        self::assertSame(['later'], json_decode($revision['tags_json'], true));
        $restored = $ui->restore($this->request(['revision' => 1, 'base_version' => 2]), $this->space['space_key'], 'changed');
        self::assertSame(302, $restored->status());
        $restoredPage = (new PageRepository($db))->findById((int) $page['id']);
        self::assertSame(3, (int) $restoredPage['version']);
        self::assertSame('ui-page', $restoredPage['slug']);
        self::assertSame(['initial'], array_column((new WikiMetadataService($db))->tagsForPage((int) $page['id']), 'name'));
        self::assertSame($page['content_html'], $restoredPage['content_html']);

        $temp = tempnam(sys_get_temp_dir(), 'openwiki-import-saving-');
        file_put_contents($temp, '# Imported');
        try {
            (new DocumentImportService($db))->importFromPath($this->space, $this->userId, $temp, 'Imported.md');
        } finally { @unlink($temp); }
        self::assertSame(5, (int) $db->fetchOne('SELECT COUNT(*) AS n FROM audit_logs WHERE user_id = ? AND object_type = "page"', [$this->userId])['n']);
        self::assertSame(5, (int) $db->fetchOne('SELECT COUNT(*) AS n FROM notifications WHERE user_id = ?', [$this->otherUserId])['n']);
        self::assertSame(5, (int) $db->fetchOne('SELECT COUNT(*) AS n FROM webhook_deliveries d INNER JOIN webhooks w ON w.id = d.webhook_id WHERE w.created_by = ?', [$this->userId])['n']);
    }

    public function testPreviewAndApiSaveUseTheSameRendererAndPreviewDoesNotWrite(): void
    {
        $markdown = "| Nazwa | Wynik |\n| --- | --- |\n| Żółć | OK |\n\n- [x] gotowe\n\n```php\necho 1;\n```";
        $draft = new PageDraftController($this->app);
        $preview = $draft->preview($this->request(['document_key' => 'new:10000000-1000-4000-8000-100000000000', 'content_markdown' => $markdown]), $this->space['space_key']);
        self::assertSame(200, $preview->status());
        self::assertSame(0, (int) $this->app->database()->fetchOne('SELECT COUNT(*) AS n FROM pages WHERE space_id = ?', [$this->space['id']])['n']);
        $page = (new PageWriteService($this->app->database()))->save($this->space, null, ['title' => 'Preview', 'content_format' => 'markdown', 'content_markdown' => $markdown], $this->userId);
        self::assertSame($page['content_html'], json_decode($preview->body(), true)['data']['html']);
        $bad = $draft->preview($this->request(['document_key' => 'new:10000000-1000-4000-8000-100000000000', 'content_markdown' => ['invalid']]), $this->space['space_key']);
        self::assertSame(422, $bad->status());
    }

    public function testRepeatedConflictsKeepTheOriginalBaseAndDoNotCreateRevisionsOrEvents(): void
    {
        $writer = new PageWriteService($this->app->database());
        $page = $writer->save($this->space, null, ['title' => 'Conflict', 'content_html' => '<p>Base</p>'], $this->userId);
        $server = $writer->save($this->space, $page, ['base_version' => 1, 'content_html' => '<p>Server</p>'], $this->userId);
        $controller = new PageController($this->app);
        foreach ([1, 2] as $attempt) {
            $result = $controller->update($this->request(['base_version' => 1, 'title' => 'Local', 'content_html' => '<p>Local</p>']), $this->space['space_key'], $page['slug']);
            self::assertSame(409, $result->status());
            $conflict = json_decode($result->body(), true)['error']['conflict'];
            self::assertSame(1, $conflict['base_version']);
            self::assertSame('<p>Base</p>', $conflict['base']['content_html']);
            self::assertSame('<p>Local</p>', $conflict['local']['content_html']);
            self::assertSame('<p>Server</p>', $conflict['server']['content_html']);
        }
        self::assertCount(2, (new PageRepository($this->app->database()))->revisions((int) $page['id']));
        $resolved = $controller->update($this->request(['base_version' => 2, 'title' => 'Conflict', 'content_html' => '<p>Server and local</p>']), $this->space['space_key'], $page['slug']);
        self::assertSame(200, $resolved->status());
        self::assertSame(3, json_decode($resolved->body(), true)['data']['page']['version']);
        self::assertSame(2, (int) $server['version']);
    }

    public function testValidationRestoresSafeRichHtmlAndAllSubmittedMetadata(): void
    {
        $request = new Request('POST', '/', [], ['_token' => Csrf::token(), 'title' => '', 'slug' => 'my-slug',
            'content_html' => '<unknown><p onclick="bad()"><strong>Zażółć</strong><img src="/attachments/1/thumbnail" data-attachment-id="1" width="640" onerror="bad()"></p></unknown>',
            'tags' => 'my-tag', 'change_summary' => 'my-summary', 'content_format' => 'visual'], [], []);
        $result = (new PageController($this->app))->store($request, $this->space['space_key']);
        self::assertSame(422, $result->status());
        self::assertStringContainsString('<strong>Zażółć</strong>', $result->body());
        self::assertStringContainsString('data-attachment-id="1" width="640"', $result->body());
        self::assertStringNotContainsString('onclick=', $result->body());
        self::assertStringNotContainsString('onerror=', $result->body());
        self::assertStringContainsString('value="my-tag"', $result->body());
        self::assertStringContainsString('my-summary', $result->body());
    }

    public function testDraftSequencesAndAcknowledgementsAreIsolatedByUserDocumentAndTab(): void
    {
        $service = new EditSessionService($this->app->database());
        $document = 'new:11111111-1111-4111-8111-111111111111';
        $tab = '22222222-2222-4222-8222-222222222222';
        $otherTab = '33333333-3333-4333-8333-333333333333';
        $service->saveEditorDraft((int) $this->space['id'], $this->userId, $document, $tab, 5, 0, ['title' => 'Latest']);
        $late = $service->saveEditorDraft((int) $this->space['id'], $this->userId, $document, $tab, 3, 0, ['title' => 'Older']);
        self::assertSame(5, $late['sequence']);
        $service->saveEditorDraft((int) $this->space['id'], $this->userId, $document, $otherTab, 1, 0, ['title' => 'Other tab']);
        $service->saveEditorDraft((int) $this->space['id'], $this->otherUserId, $document, $tab, 1, 0, ['title' => 'Other account']);
        $service->acknowledgeDraft((int) $this->space['id'], $this->userId, $document, $tab, 3);
        self::assertCount(2, $service->editorDrafts((int) $this->space['id'], $this->userId, $document));
        self::assertSame('Latest', $service->editorDrafts((int) $this->space['id'], $this->userId, $document)[0]['title']);
        $service->acknowledgeDraft((int) $this->space['id'], $this->userId, $document, $tab, 5);
        // A timed-out autosave arriving after a page acknowledgement cannot resurrect the copy.
        $service->saveEditorDraft((int) $this->space['id'], $this->userId, $document, $tab, 5, 0, ['title' => 'Late arrival']);
        self::assertCount(1, $service->editorDrafts((int) $this->space['id'], $this->userId, $document));
        self::assertSame('Other account', $service->editorDrafts((int) $this->space['id'], $this->otherUserId, $document)[0]['title']);
        $denied = (new PageDraftController($this->app))->saveEditorDraft($this->request(['document_key' => $document, 'editor_user_id' => $this->otherUserId]), $this->space['space_key']);
        self::assertSame(403, $denied->status());
    }

    public function testInlineImageFailureRollsBackTheWholeWriteEvenInAnOuterTransaction(): void
    {
        $db = $this->app->database();
        $writer = new PageWriteService($db, $this->app->basePath());
        try {
            $writer->save($this->space, null, ['title' => 'Image failure', 'content_html' => '<img src="data:image/png;base64,bG9s">'], $this->userId, null, null, true);
            self::fail('Invalid image should fail.');
        } catch (\InvalidArgumentException) {
            self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) AS n FROM pages WHERE space_id = ?', [$this->space['id']])['n']);
            self::assertSame(0, (int) $db->fetchOne('SELECT COUNT(*) AS n FROM audit_logs WHERE user_id = ?', [$this->userId])['n']);
            self::assertTrue($db->pdo()->inTransaction());
        }
    }

    public function testChangingFormatWithoutASourceCannotEmptyAPage(): void
    {
        $writer = new PageWriteService($this->app->database());
        $page = $writer->save($this->space, null, ['title' => 'Source', 'content_html' => '<p>Existing</p>'], $this->userId);
        try {
            $writer->save($this->space, $page, ['base_version' => 1, 'content_format' => 'markdown'], $this->userId);
            self::fail('An explicit converted source is required.');
        } catch (\InvalidArgumentException) {
            self::assertSame('<p>Existing</p>', (new PageRepository($this->app->database()))->findById((int) $page['id'])['content_html']);
        }
    }

    public function testRestoreConflictRetainsTheIntendedRevisionAndItsOriginalBase(): void
    {
        $writer = new PageWriteService($this->app->database());
        $page = $writer->save($this->space, null, ['title' => 'Original title', 'content_html' => '<p><strong>Original</strong></p>', 'tags' => 'original'], $this->userId);
        $writer->save($this->space, $page, ['base_version' => 1, 'title' => 'Server title', 'content_html' => '<p>Server</p>', 'tags' => 'server'], $this->userId);
        $controller = new PageController($this->app);
        $response = $controller->restore($this->request(['revision' => 1, 'base_version' => 1]), $this->space['space_key'], $page['slug']);
        self::assertSame(409, $response->status());
        $conflict = json_decode($response->body(), true)['error']['conflict'];
        self::assertSame(1, $conflict['base_version']);
        self::assertSame('Original title', $conflict['local']['title']);
        self::assertSame('<p><strong>Original</strong></p>', $conflict['local']['content_html']);
        self::assertSame(['original'], $conflict['local']['tags']);
        $html = $controller->restore(new Request('POST', '/', [], ['_token' => Csrf::token(), 'revision' => 1, 'base_version' => 1], [], []), $this->space['space_key'], $page['slug']);
        self::assertSame(409, $html->status());
        self::assertStringContainsString('<p><strong>Original</strong></p>', $html->body());
        self::assertStringContainsString('name="base_version" value="1"', $html->body());
        self::assertCount(2, (new PageRepository($this->app->database()))->revisions((int) $page['id']));
    }

    public function testConversionRecoveryIsSanitizedAndRequiresImagePermission(): void
    {
        $writer = new PageWriteService($this->app->database(), $this->app->basePath());
        $backup = ['format' => 'visual', 'html' => '<p><u onclick="bad()">Original</u></p>', 'markdown' => 'stale'];
        $page = $writer->save($this->space, null, ['title' => 'Recovery', 'content_format' => 'markdown', 'content_markdown' => 'Original', 'conversion_backup' => $backup], $this->userId);
        $revision = (new PageRepository($this->app->database()))->revision((int) $page['id'], 1);
        self::assertSame(['format' => 'visual', 'html' => '<p><u>Original</u></p>', 'markdown' => ''], json_decode($revision['conversion_backup_json'], true));
        foreach (['data:image/png;base64,bG9s', 'DATA:IMAGE/PNG;BASE64,bG9s'] as $source) {
            try {
                $writer->save($this->space, $page, ['base_version' => 1, 'conversion_backup' => ['format' => 'visual', 'html' => '<img src="' . $source . '">']], $this->userId);
                self::fail('Images in recovery copies require the same permissions.');
            } catch (\DomainException) {
                self::assertSame(1, (int) (new PageRepository($this->app->database()))->findById((int) $page['id'])['version']);
            }
        }
    }

    public function testMalformedRecoveryAndCrossDocumentAcknowledgementCannotCommit(): void
    {
        $writer = new PageWriteService($this->app->database());
        $page = $writer->save($this->space, null, ['title' => 'Validated recovery', 'content_html' => '<p>Base</p>'], $this->userId);
        foreach ([['conversion_backup' => ['html' => ['invalid']]], ['document_key' => 'page:' . ($page['id'] + 1), 'editor_id' => '11111111-1111-4111-8111-111111111111', 'draft_sequence' => 1]] as $input) {
            try {
                $writer->save($this->space, $page, ['base_version' => 1] + $input, $this->userId);
                self::fail('Invalid recovery metadata should fail validation.');
            } catch (\InvalidArgumentException) {
                self::assertSame(1, (int) (new PageRepository($this->app->database()))->findById((int) $page['id'])['version']);
            }
        }
    }

    public function testExistingHtmlLinksAreCountedOnceAndExternalLookalikesAreIgnored(): void
    {
        $writer = new PageWriteService($this->app->database());
        $target = $writer->save($this->space, null, ['title' => 'Link target', 'content_html' => '<p>Target</p>'], $this->userId);
        $url = '/spaces/' . rawurlencode($this->space['space_key']) . '/pages/' . $target['slug'];
        $source = $writer->save($this->space, null, ['title' => 'Link source', 'content_html' => '<p>[[Link target]] <a href="' . $url . '">Existing link</a></p>'], $this->userId);
        $writer->save($this->space, $source, ['base_version' => 1, 'content_html' => $source['content_html']], $this->userId);
        $writer->save($this->space, null, ['title' => 'External lookalike', 'content_html' => '<p><a href="https://external.example.test' . $url . '">External</a></p>'], $this->userId);
        $backlinks = (new WikiMetadataService($this->app->database()))->backlinks((int) $target['id']);
        self::assertCount(1, $backlinks);
        self::assertSame((int) $source['id'], (int) $backlinks[0]['id']);
    }

    public function testConditionalDraftSaveIsIdempotentAndKeepsRecoveryBeforeDeletion(): void
    {
        $controller = new PageDraftController($this->app);
        $identity = ['document_key' => 'new:11111111-1111-4111-8111-111111111111', 'editor_id' => '22222222-2222-4222-8222-222222222222', 'base_version' => 0];
        $first = $identity + ['draft_sequence' => 1, 'draft_version' => 0, 'operation_id' => '33333333-3333-4333-8333-333333333333', 'content_html' => '<p><strong>Recover me</strong></p>', 'title' => ''];
        $save = $controller->saveEditorDraft($this->request($first), $this->space['space_key']);
        self::assertSame(200, $save->status());
        self::assertSame(1, json_decode($save->body(), true)['data']['draft_version']);
        $empty = $identity + ['draft_sequence' => 2, 'draft_version' => 1, 'operation_id' => '44444444-4444-4444-8444-444444444444', 'content_html' => '', 'title' => ''];
        self::assertSame(200, $controller->saveEditorDraft($this->request($empty), $this->space['space_key'])->status());
        self::assertSame($save->body(), $controller->saveEditorDraft($this->request($first), $this->space['space_key'])->body());
        $stale = array_replace($empty, ['draft_sequence' => 3, 'operation_id' => '55555555-5555-4555-8555-555555555555']);
        self::assertSame(409, $controller->saveEditorDraft($this->request($stale), $this->space['space_key'])->status());
        $sessions = new EditSessionService($this->app->database());
        $points = $sessions->recoveryPoints((int) $this->space['id'], $this->userId, $identity['document_key']);
        self::assertCount(2, $points);
        self::assertSame('<p><strong>Recover me</strong></p>', $sessions->recoveryPoint((int) $this->space['id'], $this->userId, $identity['document_key'], (int) $points[1]['id'])['html']);
        self::assertNull($sessions->recoveryPoint((int) $this->space['id'], $this->otherUserId, $identity['document_key'], (int) $points[1]['id']));
        self::assertSame(0, (int) $this->app->database()->fetchOne('SELECT COUNT(*) AS total FROM pages WHERE space_id = ?', [$this->space['id']])['total']);
    }

    public function testRetriedExplicitSaveCreatesOneRevisionAndRejectsReusedOperation(): void
    {
        $writer = new PageWriteService($this->app->database());
        $input = ['title' => 'Idempotent article', 'operation_id' => '11111111-1111-4111-8111-111111111111',
            'document' => ['version' => 1, 'format' => 'visual', 'source' => '<p>Canonical <strong>source</strong></p>'], 'content_html' => '<p>stale</p>'];
        $page = $writer->save($this->space, null, $input, $this->userId);
        self::assertSame($page, $writer->save($this->space, null, $input, $this->userId));
        self::assertSame('<p>Canonical <strong>source</strong></p>', $page['content_html']);
        self::assertSame(1, json_decode($page['document_json'], true)['version']);
        self::assertCount(1, (new PageRepository($this->app->database()))->revisions((int) $page['id']));
        $this->expectException(\InvalidArgumentException::class);
        $writer->save($this->space, null, array_replace($input, ['title' => 'Different operation content']), $this->userId);
    }

    public function testPublishedArticleRemainsUnchangedWhileIncompleteDraftSaves(): void
    {
        $writer = new PageWriteService($this->app->database());
        $page = $writer->save($this->space, null, ['title' => 'Published article', 'status' => 'published', 'content_html' => '<p>Reader version</p>'], $this->userId);
        $controller = new PageDraftController($this->app);
        $draft = ['document_key' => 'page:' . $page['id'], 'editor_id' => '22222222-2222-4222-8222-222222222222', 'base_version' => 1,
            'draft_sequence' => 1, 'draft_version' => 0, 'operation_id' => '33333333-3333-4333-8333-333333333333', 'title' => '', 'parent_id' => '99999999', 'content_html' => '<p>Work in progress</p>'];
        self::assertSame(200, $controller->saveEditorDraft($this->request($draft), $this->space['space_key'])->status());
        $fresh = (new PageRepository($this->app->database()))->findById((int) $page['id']);
        self::assertSame(1, (int) $fresh['version']);
        self::assertSame('<p>Reader version</p>', $fresh['content_html']);
        self::assertSame($page['parent_id'], $fresh['parent_id']);
        self::assertCount(1, (new PageRepository($this->app->database()))->revisions((int) $page['id']));
    }

    public function testDocumentedPrivateFileUrlsInsideCodeAreNotAttachmentReferences(): void
    {
        $html = '<pre><code>/editor-files/999999/download data-draft-file-id="999999"</code></pre>';
        $response = (new PageDraftController($this->app))->saveEditorDraft($this->request([
            'document_key' => 'new:11111111-1111-4111-8111-111111111111', 'editor_id' => '22222222-2222-4222-8222-222222222222',
            'base_version' => 0, 'draft_sequence' => 1, 'content_html' => $html,
        ]), $this->space['space_key']);
        self::assertSame(200, $response->status());
        $page = (new PageWriteService($this->app->database()))->save($this->space, null, ['title' => 'Literal asset examples',
            'content_html' => $html . '<pre><code>data:image/png;base64,example</code></pre>'], $this->userId);
        self::assertStringContainsString('/editor-files/999999/download', $page['content_html']);
    }

    public function testReactivatingAnAcknowledgedContextCannotBypassTheActiveDraftQuota(): void
    {
        $db = $this->app->database(); $service = new EditSessionService($db);
        $document = 'new:11111111-1111-4111-8111-111111111111'; $tab = '22222222-2222-4222-8222-222222222222';
        $service->saveEditorDraft((int) $this->space['id'], $this->userId, $document, $tab, 1, 0, ['title' => 'Completed']);
        $service->acknowledgeDraft((int) $this->space['id'], $this->userId, $document, $tab, 1);
        for ($i = 0; $i < 100; $i++) {
            $db->execute('INSERT INTO page_editor_drafts (space_id, user_id, document_key, editor_id, sequence, base_version, payload_json, updated_at) VALUES (?, ?, ?, ?, 1, 0, "{}", UTC_TIMESTAMP())',
                [$this->space['id'], $this->userId, $document, sprintf('00000000-0000-4000-8000-%012d', $i)]);
        }
        $operation = '33333333-3333-4333-8333-333333333333';
        try {
            $service->saveEditorDraft((int) $this->space['id'], $this->userId, $document, $tab, 2, 0, ['title' => 'Reactivated'], 1, $operation);
            self::fail('Acknowledged contexts must count again when reactivated.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('Active draft limit', $error->getMessage());
        }
        $service->acknowledgeDraft((int) $this->space['id'], $this->userId, $document, '00000000-0000-4000-8000-000000000000', 1);
        $saved = $service->saveEditorDraft((int) $this->space['id'], $this->userId, $document, $tab, 2, 0, ['title' => 'Reactivated'], 1, $operation);
        self::assertSame(2, $saved['draft_version']);
        self::assertSame(100, (int) $db->fetchOne('SELECT COUNT(*) AS total FROM page_editor_drafts WHERE user_id = ? AND sequence > acknowledged_sequence', [$this->userId])['total']);
    }

    public function testMarkdownInlineImagesAndConversionBackupsUseProtectedSourcesWithoutDuplicatingFiles(): void
    {
        $db = $this->app->database(); $writer = new PageWriteService($db, $this->app->basePath());
        $image = imagecreatetruecolor(2, 1); ob_start(); imagepng($image); $bytes = ob_get_clean(); imagedestroy($image);
        $source = 'data:image/png;base64,' . base64_encode($bytes);
        $pageIds = [];
        try {
            $literalCode = "\n\n```text\n![Example](" . $source . ")\n```\n\n`![Inline](" . $source . ")`";
            $input = ['title' => 'Markdown image', 'content_format' => 'markdown', 'content_markdown' => '![Pixel](' . $source . ')' . $literalCode,
                'operation_id' => '77777777-7777-4777-8777-777777777777'];
            $page = $writer->save($this->space, null, $input, $this->userId, allowInlineImages: true); $pageIds[] = $page['id'];
            self::assertStringContainsString($literalCode, $page['content_markdown']);
            self::assertStringStartsWith('![Pixel](/attachments/', $page['content_markdown']);
            self::assertStringContainsString('/attachments/', $page['content_markdown']);
            self::assertSame($page['content_markdown'], json_decode($page['document_json'], true)['source']);
            self::assertStringContainsString('/attachments/', $page['asset_replacements'][$source]);
            self::assertSame($page, $writer->save($this->space, null, $input, $this->userId, allowInlineImages: true));
            $writer->save($this->space, $page, ['base_version' => 1], $this->userId, allowInlineImages: true);
            self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) AS total FROM attachments WHERE page_id = ?', [$page['id']])['total']);
            $backupInput = ['title' => 'Image only in conversion source', 'content_html' => '<p>Current content</p>',
                'conversion_backup' => ['format' => 'markdown', 'html' => '', 'markdown' => '![Prior image](' . $source . ')']];
            try {
                $writer->save($this->space, null, $backupInput, $this->userId);
                self::fail('Images in Markdown backups require attachment permission.');
            } catch (\DomainException $error) { self::assertStringContainsString('attachment.upload', $error->getMessage()); }
            $storageKey = $db->fetchOne('SELECT storage_key FROM attachments WHERE page_id = ?', [$page['id']])['storage_key'];
            self::assertFileExists((new \OpenWiki\Attachments\AttachmentService($db, $this->app->basePath()))->stagedPath($storageKey));
            $backupPage = $writer->save($this->space, null, $backupInput, $this->userId, allowInlineImages: true); $pageIds[] = $backupPage['id'];
            $revision = (new PageRepository($db))->revision((int) $backupPage['id'], 1);
            $backup = json_decode($revision['conversion_backup_json'], true);
            self::assertStringNotContainsString('data:image/', $backup['markdown']);
            self::assertStringContainsString('/attachments/', $backup['markdown']);
            self::assertSame('', $backup['html']);
            self::assertStringContainsString('/attachments/', $backupPage['asset_replacements'][$source]);
        } finally {
            $attachments = new \OpenWiki\Attachments\AttachmentService($db, $this->app->basePath());
            foreach ($pageIds as $id) $attachments->purgeStorageKeys(array_column($db->fetchAll('SELECT storage_key FROM attachments WHERE page_id = ?', [$id]), 'storage_key'));
        }
    }

    private function request(array $input, ?string $token = null): Request
    {
        $server = ['HTTP_ACCEPT' => 'application/json'];
        if ($token !== null) $server['HTTP_AUTHORIZATION'] = 'Bearer ' . $token;
        return new Request('POST', '/', [], ['_token' => Csrf::token()] + $input, $server, []);
    }
}
