<?php

declare(strict_types=1);

namespace OpenWiki\Tests\Integration;

use OpenWiki\Core\Database;
use OpenWiki\Database\MigrationRunner;
use OpenWiki\Wiki\EditSessionService;
use PHPUnit\Framework\TestCase;

final class EditorDraftMigrationTest extends TestCase
{
    public function testUpgradePreservesLegacyContentOwnerAndOriginalBaseVersion(): void
    {
        if (!getenv('OPENWIKI_TEST_DB_HOST')) self::markTestSkipped('MySQL integration environment is not configured.');
        $config = [
            'host' => getenv('OPENWIKI_TEST_DB_HOST'), 'port' => (int) getenv('OPENWIKI_TEST_DB_PORT'),
            'username' => getenv('OPENWIKI_TEST_DB_USERNAME'), 'password' => getenv('OPENWIKI_TEST_DB_PASSWORD'),
        ];
        $admin = Database::connect($config, false);
        $name = 'openwiki_upgrade_' . bin2hex(random_bytes(5)) . '_test';
        $admin->pdo()->exec('CREATE DATABASE `' . $name . '` CHARACTER SET utf8mb4');
        try {
            $db = Database::connect($config + ['database' => $name]);
            $db->pdo()->exec('CREATE TABLE schema_migrations (version VARCHAR(191) PRIMARY KEY, applied_at DATETIME NOT NULL)');
            foreach (glob(dirname(__DIR__, 2) . '/database/migrations/00[1-4]_*.php') as $file) {
                $migration = require $file;
                foreach ($migration['up'] as $sql) $db->pdo()->exec($sql);
                $db->execute('INSERT INTO schema_migrations VALUES (?, UTC_TIMESTAMP())', [$migration['version']]);
            }
            $user = $db->insert('INSERT INTO users (username, email, password_hash, status, auth_source, created_at, updated_at) VALUES ("legacy", "legacy@example.test", "unused", "active", "local", UTC_TIMESTAMP(), UTC_TIMESTAMP())');
            $space = $db->insert('INSERT INTO spaces (name, space_key, owner_id, visibility, status, created_at, updated_at) VALUES ("Legacy", "LEGACY", ?, "private", "active", UTC_TIMESTAMP(), UTC_TIMESTAMP())', [$user]);
            $page = $db->insert('INSERT INTO pages (space_id, title, slug, content_html, content_format, author_id, owner_id, status, version, created_at, updated_at) VALUES (?, "Legacy page", "legacy", "<p>Server</p>", "visual", ?, ?, "draft", 4, UTC_TIMESTAMP(), UTC_TIMESTAMP())', [$space, $user, $user]);
            $db->execute('INSERT INTO draft_autosaves (page_id, user_id, content_html, content_markdown, content_format, base_version, updated_at) VALUES (?, ?, "<p><strong>Zażółć</strong></p>", NULL, "visual", 1, UTC_TIMESTAMP())', [$page, $user]);
            $runner = new MigrationRunner($db, dirname(__DIR__, 2) . '/database/migrations');
            self::assertSame(['005_editor_drafts_and_revision_metadata'], $runner->migrate());
            self::assertSame([], $runner->migrate());
            $service = new EditSessionService($db);
            $drafts = $service->editorDrafts($space, $user, 'page:' . $page);
            self::assertCount(1, $drafts);
            self::assertSame(1, $drafts[0]['baseVersion']);
            self::assertSame('<p><strong>Zażółć</strong></p>', $drafts[0]['html']);
            self::assertSame('Legacy page', $drafts[0]['title']);
            self::assertSame([], $service->editorDrafts($space, $user + 1, 'page:' . $page));
            $service->acknowledgeDraft($space, $user, 'page:' . $page, 'legacy', 1);
            self::assertSame([], $service->editorDrafts($space, $user, 'page:' . $page));
        } finally {
            $admin->pdo()->exec('DROP DATABASE `' . $name . '`');
        }
    }
}
