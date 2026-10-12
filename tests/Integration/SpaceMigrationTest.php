<?php
declare(strict_types=1);
namespace OpenWiki\Tests\Integration;
use OpenWiki\Core\Database;
use OpenWiki\Database\MigrationRunner;
use OpenWiki\Tests\Support\IsolatedDatabase;
use PHPUnit\Framework\TestCase;

final class SpaceMigrationTest extends TestCase
{
    public function testUpgradeMakesLegacyGrantsExplicitWithoutChangingSnapshotsOrAcl(): void
    {
        IsolatedDatabase::run(function (Database $db): void {
            $base = dirname(__DIR__, 2); $directory = sys_get_temp_dir() . '/wiki-space-migrations-' . bin2hex(random_bytes(8)); mkdir($directory); $copied = [];
            try {
                foreach (glob($base . '/database/migrations/*.php') as $file) if ((int) basename($file) <= 8) { $target = $directory . '/' . basename($file); copy($file, $target); $copied[] = $target; }
                (new MigrationRunner($db, $directory))->migrate();
                $owner = $db->insert('INSERT INTO users (username, email) VALUES ("owner", "owner@example.test")');
                $reader = $db->insert('INSERT INTO users (username, email) VALUES ("reader", "reader@example.test")');
                $group = $db->insert('INSERT INTO user_groups (name, slug) VALUES ("Team", "team")'); $db->execute('INSERT INTO group_users VALUES (?, ?)', [$group, $reader]);
                $space = $db->insert('INSERT INTO spaces (name, space_key, owner_id, visibility) VALUES ("Legacy", "LEGACY", ?, "private")', [$owner]);
                $private = $db->insert('INSERT INTO spaces (name, space_key, owner_id, visibility) VALUES ("Private", "PRIVATE", ?, "private")', [$owner]);
                $db->execute('INSERT INTO space_acl (space_id, principal_type, principal_id, permission, effect) VALUES (?, "group", ?, "space.view", "allow")', [$space, $group]);
                $page = $db->insert('INSERT INTO pages (space_id, title, slug, content_html, content_format, status, author_id, owner_id) VALUES (?, "Old", "old", "<p>Old snapshot</p>", "visual", "published", ?, ?)', [$space, $owner, $owner]);
                $db->execute('INSERT INTO page_revisions (page_id, revision_number, title, content_html, content_format, status, author_id) SELECT id, 1, title, content_html, content_format, status, author_id FROM pages WHERE id = ?', [$page]);
                $beforePage = $db->fetchOne('SELECT * FROM pages WHERE id = ?', [$page]); $beforeRevision = $db->fetchAll('SELECT * FROM page_revisions'); $beforeAcl = $db->fetchAll('SELECT * FROM space_acl');
                $runner = new MigrationRunner($db, $base . '/database/migrations'); self::assertSame(['009_space_management', '010_structure_operations'], $runner->migrate()); self::assertSame([], $runner->migrate());
                self::assertSame('restricted', $db->fetchOne('SELECT visibility FROM spaces WHERE id = ?', [$space])['visibility']); self::assertSame('private', $db->fetchOne('SELECT visibility FROM spaces WHERE id = ?', [$private])['visibility']);
                self::assertSame($beforePage, $db->fetchOne('SELECT * FROM pages WHERE id = ?', [$page])); self::assertSame($beforeRevision, $db->fetchAll('SELECT * FROM page_revisions')); self::assertSame($beforeAcl, $db->fetchAll('SELECT * FROM space_acl'));
                self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) AS total FROM user_roles ur INNER JOIN roles r ON r.id = ur.role_id WHERE ur.user_id = ? AND r.slug = "space-owner"', [$owner])['total']);
                $db->execute('DELETE FROM schema_migrations WHERE version IN ("009_space_management", "010_structure_operations")'); self::assertSame(['009_space_management', '010_structure_operations'], $runner->migrate()); self::assertTrue($runner->schemaState()['ready']);
                self::assertSame($beforeRevision, $db->fetchAll('SELECT * FROM page_revisions')); self::assertSame($beforeAcl, $db->fetchAll('SELECT * FROM space_acl'));
            } finally { foreach ($copied as $file) unlink($file); rmdir($directory); }
        });
    }
}
