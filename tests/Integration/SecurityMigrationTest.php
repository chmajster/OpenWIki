<?php
declare(strict_types=1);
namespace OpenWiki\Tests\Integration;

use OpenWiki\Core\Database;
use OpenWiki\Database\MigrationRunner;
use PHPUnit\Framework\TestCase;

final class SecurityMigrationTest extends TestCase
{
    public function testRestartingTheSecurityMigrationPreservesAclAndHistoricalSnapshots(): void
    {
        if (!getenv('OPENWIKI_TEST_DB_HOST') || !str_ends_with((string) getenv('OPENWIKI_TEST_DB_DATABASE'), '_test')) self::markTestSkipped('Dedicated database required.');
        $db = Database::connect(['host' => getenv('OPENWIKI_TEST_DB_HOST'), 'port' => (int) getenv('OPENWIKI_TEST_DB_PORT'),
            'database' => getenv('OPENWIKI_TEST_DB_DATABASE'), 'username' => getenv('OPENWIKI_TEST_DB_USERNAME'), 'password' => getenv('OPENWIKI_TEST_DB_PASSWORD')]);
        $runner = new MigrationRunner($db, dirname(__DIR__, 2) . '/database/migrations');
        $runner->migrate();
        $suffix = bin2hex(random_bytes(5));
        $user = $db->insert('INSERT INTO users (username, email, status, auth_source, created_at, updated_at) VALUES (?, ?, "active", "local", UTC_TIMESTAMP(), UTC_TIMESTAMP())', ['migration-' . $suffix, $suffix . '@example.test']);
        $space = $db->insert('INSERT INTO spaces (name, space_key, owner_id, visibility, status, created_at, updated_at) VALUES ("Migration", ?, ?, "public", "active", UTC_TIMESTAMP(), UTC_TIMESTAMP())', ['MIG-' . $suffix, $user]);
        try {
            $page = $db->insert('INSERT INTO pages (space_id, title, slug, content_html, content_format, status, author_id, owner_id, created_at, updated_at) VALUES (?, "Legacy", "legacy", ?, "visual", "published", ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())', [$space, '<article><p onclick="alert(1)">snapshot</p></article>', $user, $user]);
            $db->execute('INSERT INTO page_revisions (page_id, revision_number, title, content_html, content_format, status, author_id, created_at)
                SELECT id, 1, title, content_html, content_format, status, author_id, created_at FROM pages WHERE id = ?', [$page]);
            $db->execute('INSERT INTO page_acl (page_id, principal_type, principal_id, permission, effect, inherit_to_children) VALUES (?, "user", ?, "page.view", "allow", 1)', [$page, $user]);
            $before = $db->fetchOne('SELECT * FROM pages WHERE id = ?', [$page]);
            $acl = $db->fetchAll('SELECT * FROM page_acl WHERE page_id = ?', [$page]);
            $revision = $db->fetchOne('SELECT * FROM page_revisions WHERE page_id = ?', [$page]);
            $migration = require dirname(__DIR__, 2) . '/database/migrations/008_security_boundaries.php';
            for ($attempt = 0; $attempt < 2; $attempt++) foreach ($migration['up'] as $sql) $db->pdo()->exec($sql);
            self::assertSame($before, $db->fetchOne('SELECT * FROM pages WHERE id = ?', [$page]));
            self::assertSame($acl, $db->fetchAll('SELECT * FROM page_acl WHERE page_id = ?', [$page]));
            self::assertSame($revision, $db->fetchOne('SELECT * FROM page_revisions WHERE page_id = ?', [$page]));
            self::assertSame([], $runner->migrate());
            self::assertNotNull($db->fetchOne('SELECT name FROM security_locks WHERE name = "administration"'));
        } finally {
            $db->execute('DELETE FROM spaces WHERE id = ?', [$space]);
            $db->execute('DELETE FROM users WHERE id = ?', [$user]);
        }
    }
}
