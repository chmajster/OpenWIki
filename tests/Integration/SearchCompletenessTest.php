<?php
declare(strict_types=1);
namespace OpenWiki\Tests\Integration;

use OpenWiki\Core\Database;
use OpenWiki\Core\Request;
use OpenWiki\Database\MigrationRunner;
use OpenWiki\Search\SearchIndexer;
use OpenWiki\Search\SearchInput;
use OpenWiki\Search\SearchService;
use OpenWiki\Tests\Support\IsolatedDatabase;
use PHPUnit\Framework\TestCase;

final class SearchCompletenessTest extends TestCase
{
    private function fixture(callable $test): void
    {
        IsolatedDatabase::run(function (Database $db) use ($test): void {
            (new MigrationRunner($db, dirname(__DIR__, 2) . '/database/migrations'))->migrate();
            $users = [];
            foreach (['reader', 'owner'] as $name) $users[] = $db->insert('INSERT INTO users (username,email,password_hash,status,auth_source,created_at,updated_at) VALUES (?, ?, "fixture", "active", "local", UTC_TIMESTAMP(), UTC_TIMESTAMP())', ['needle-' . $name, $name . '@search.invalid']);
            $role = $db->insert('INSERT INTO roles (name,slug,is_system,created_at,updated_at) VALUES ("Search author","search-author",0,UTC_TIMESTAMP(),UTC_TIMESTAMP())');
            foreach (['page.view','page.edit'] as $permission) {
                $db->execute('INSERT IGNORE INTO permissions (name,description,created_at) VALUES (?, ?, UTC_TIMESTAMP())', [$permission, $permission]);
                $db->execute('INSERT INTO role_permissions (role_id,permission_id) SELECT ?,id FROM permissions WHERE name = ?', [$role, $permission]);
            }
            $db->execute('INSERT INTO user_roles (user_id,role_id) VALUES (?,?)', [$users[1], $role]);
            $viewer = $db->insert('INSERT INTO roles (name,slug,is_system,created_at,updated_at) VALUES ("Search reader","search-reader",0,UTC_TIMESTAMP(),UTC_TIMESTAMP())');
            $db->execute('INSERT INTO role_permissions (role_id,permission_id) SELECT ?,id FROM permissions WHERE name = "page.view"', [$viewer]);
            $db->execute('INSERT INTO user_roles (user_id,role_id) VALUES (?,?)', [$users[0], $viewer]);
            $space = $db->insert('INSERT INTO spaces (name,space_key,owner_id,visibility,status,created_at,updated_at) VALUES ("Search","SEARCH",?,"public","active",UTC_TIMESTAMP(),UTC_TIMESTAMP())', [$users[1]]);
            $test($db, $users[0], $users[1], $space);
        });
    }

    private function page(Database $db, int $space, int $owner, string $title, string $text = ''): int
    {
        return $db->insert('INSERT INTO pages (space_id,title,slug,content_html,content_text,content_format,author_id,owner_id,status,version,created_at,updated_at) VALUES (?, ?, ?, ?, ?, "visual", ?, ?, "published", 1, "2026-01-01 00:00:00", "2026-01-01 00:00:00")', [$space, $title, bin2hex(random_bytes(8)), '<p>' . htmlspecialchars($text) . '</p>', $text, $owner, $owner]);
    }

    private function input(string $query, array $extra = []): array
    {
        return SearchInput::fromRequest(new Request('GET', '/search', $extra + ['q' => $query, 'type' => 'page'], [], [], []));
    }

    public function testAllVisibleHitsBeyondHiddenCandidatesHaveStablePaginationAndFreshAcl(): void
    {
        $this->fixture(function (Database $db, int $reader, int $owner, int $space): void {
            for ($i = 0; $i < 220; $i++) {
                $id = $this->page($db, $space, $owner, 'Firewall');
                $db->execute('INSERT INTO page_acl (page_id,principal_type,principal_id,permission,effect,inherit_to_children) VALUES (?,"user",?,"page.view","allow",1)', [$id, $owner]);
            }
            $expected = [];
            for ($i = 0; $i < 100; $i++) $expected[] = $this->page($db, $space, $owner, 'Firewall accessible ' . $i);
            $keys = []; $ids = [];
            for ($page = 1; $page <= 6; $page++) {
                $result = (new SearchService($db))->search($this->input('Firewall', ['page' => $page, 'per_page' => 17]), $reader, ['page.view']);
                self::assertSame(100, $result['meta']['total']); self::assertSame(6, $result['meta']['total_pages']);
                array_push($keys, ...array_column($result['data'], 'key')); array_push($ids, ...array_column($result['data'], 'id'));
            }
            self::assertSame($expected, $ids); self::assertCount(100, array_unique($keys));
            $db->execute('INSERT INTO page_acl (page_id,principal_type,principal_id,permission,effect,inherit_to_children) VALUES (?,"user",?,"page.view","allow",1)', [$expected[0], $owner]);
            self::assertSame(99, (new SearchService($db))->search($this->input('Firewall'), $reader, ['page.view'])['meta']['total']);
        });
    }

    public function testUnicodeTechnicalNamesPhrasesAndLiteralWildcardsFindMatchingContext(): void
    {
        $this->fixture(function (Database $db, int $reader, int $owner, int $space): void {
            $id = $this->page($db, $space, $owner, 'Technical reference', str_repeat('intro ', 400) . 'Zażółć gęślą jaźń IP C++ foo_bar nginx.conf router 50%');
            $this->page($db, $space, $owner, 'Other', 'fooXbar 500');
            foreach (['Zażółć', 'IP', 'C++', 'foo_bar', 'nginx.conf', '"gęślą jaźń"', 'rout*', '50%'] as $query) {
                $result = (new SearchService($db))->search($this->input($query), $reader, ['page.view']);
                self::assertSame(1, $result['meta']['total'], $query); self::assertSame($id, $result['data'][0]['id']);
                self::assertStringContainsString('Zażółć', $result['data'][0]['snippet']);
                self::assertLessThanOrEqual(260, mb_strlen($result['data'][0]['snippet']));
            }
        });
    }

    public function testPublishedAndWorkingRevisionsHaveSeparateVisibilityAndFilters(): void
    {
        $this->fixture(function (Database $db, int $reader, int $owner, int $space): void {
            $id = $this->page($db, $space, $owner, 'Public N', 'publishedword');
            $db->execute('UPDATE spaces SET review_workflow = 1 WHERE id = ?', [$space]);
            $db->execute('UPDATE pages SET published_revision = 1, version = 2 WHERE id = ?', [$id]);
            foreach ([1 => ['Public N', 'publishedword', 'published'], 2 => ['Secret N+1', 'privateword', 'draft']] as $number => [$title, $text, $status]) {
                $db->execute('INSERT INTO page_revisions (page_id,revision_number,title,slug,content_html,content_text,content_format,status,author_id,created_at) VALUES (?, ?, ?, "doc", ?, ?, "visual", ?, ?, UTC_TIMESTAMP())', [$id, $number, $title, '<p>' . $text . '</p>', $text, $status, $owner]);
            }
            $service = new SearchService($db);
            $db->execute('INSERT INTO tags (name,slug) VALUES ("Private Tag","private-tag")');
            $db->execute('UPDATE page_revisions SET tags_json = ? WHERE page_id = ? AND revision_number = 2', [json_encode(['Private Tag']),$id]);
            self::assertSame(1, $service->search($this->input('publishedword'), $reader, ['page.view'])['meta']['total']);
            self::assertSame(0, $service->search($this->input('privateword'), $reader, ['page.view'])['meta']['total']);
            $work = $service->search($this->input('privateword'), $owner, ['page.view','page.edit']);
            self::assertSame(1, $work['meta']['total']); self::assertTrue($work['data'][0]['working']); self::assertSame(2, $work['data'][0]['revision']);
            self::assertStringEndsWith('/revisions/2', $work['data'][0]['url']);
            self::assertSame(0, $service->search($this->input('privateword', ['visibility' => 'published']), $owner, ['page.view','page.edit'])['meta']['total']);
            self::assertSame(0, $service->search($this->input('publishedword', ['visibility' => 'working']), $owner, ['page.view','page.edit'])['meta']['total']);
            self::assertSame(0,$service->search($this->input('private-tag',['type'=>'tag']),$reader,['page.view'])['meta']['total']);
            $tag = $service->search($this->input('private-tag',['type'=>'tag']),$owner,['page.view','page.edit']);
            self::assertSame(1,$tag['meta']['total']); self::assertSame(1,$tag['data'][0]['working_page_count']);
            self::assertStringStartsWith('/search?', $tag['data'][0]['url']);
            self::assertSame(1,$service->search($this->input('private-tag',['tag'=>'private-tag','visibility'=>'working']),$owner,['page.view','page.edit'])['meta']['total']);
        });
    }

    public function testHiddenAncestorsAndTagCountersDoNotRevealMetadata(): void
    {
        $this->fixture(function (Database $db, int $reader, int $owner, int $space): void {
            $parent = $this->page($db, $space, $owner, 'Hidden parent'); $child = $this->page($db, $space, $owner, 'needle visible');
            $db->execute('UPDATE pages SET parent_id = ?, inherit_acl = 0 WHERE id = ?', [$parent, $child]);
            $db->execute('INSERT INTO page_acl (page_id,principal_type,principal_id,permission,effect,inherit_to_children) VALUES (?,"user",?,"page.view","allow",1)', [$parent, $owner]);
            $tag = $db->insert('INSERT INTO tags (name,slug,created_at) VALUES ("needle-tag","needle-tag",UTC_TIMESTAMP())');
            foreach ([$parent,$child] as $id) $db->execute('INSERT INTO page_tags (page_id,tag_id) VALUES (?,?)', [$id, $tag]);
            $private = $db->insert('INSERT INTO spaces (name,space_key,owner_id,visibility,status,created_at,updated_at) VALUES ("SECRET space","PRIVATE",?,"private","active",UTC_TIMESTAMP(),UTC_TIMESTAMP())', [$owner]);
            $foreign = $this->page($db, $private, $owner, 'SECRET foreign page');
            $db->execute('INSERT INTO page_tags (page_id,tag_id) VALUES (?,?)', [$foreign, $tag]);
            $result = (new SearchService($db))->search($this->input('needle', ['type' => 'all', 'space' => 'SEARCH']), $reader, ['page.view']);
            self::assertStringNotContainsString('Hidden parent', json_encode($result, JSON_THROW_ON_ERROR));
            self::assertStringNotContainsString('SECRET', json_encode($result, JSON_THROW_ON_ERROR));
            $tags = array_values(array_filter($result['data'], static fn ($row) => $row['type'] === 'tag'));
            self::assertCount(1, $tags); self::assertSame(1, $tags[0]['page_count']);
            $guest = (new SearchService($db))->search($this->input('needle', ['type' => 'all']), null, []);
            self::assertSame(0, $guest['meta']['counts']['user']);
            self::assertSame(0, (new SearchService($db))->search($this->input('needle', ['type' => 'tag', 'author' => 'absent']), $reader, ['page.view'])['meta']['total']);
        });
    }

    public function testQueuedRebuildHasBoundedDurableProgressAndPreservesSnapshots(): void
    {
        $this->fixture(function (Database $db, int $reader, int $owner, int $space): void {
            $ids = [];
            for ($i = 0; $i < 5; $i++) {
                $id = $this->page($db, $space, $owner, 'Index ' . $i, 'stale'); $ids[] = $id;
                $db->execute('UPDATE pages SET content_html = "<p>First</p><p>Second</p><script>secret</script>" WHERE id = ?', [$id]);
            }
            $indexer = new SearchIndexer($db); $queued = $indexer->enqueue();
            self::assertSame($queued, $indexer->enqueue());
            $first = $indexer->processDue(2); self::assertSame('running', $first['state']); self::assertSame(2, $first['scanned']);
            self::assertSame($first, (new SearchIndexer($db))->enqueue());
            $db->execute('CREATE TRIGGER fail_search_update BEFORE UPDATE ON pages FOR EACH ROW SIGNAL SQLSTATE "45000" SET MESSAGE_TEXT = "Intentional index failure"');
            try { $indexer->processDue(2); self::fail('A failed batch must propagate failure.'); }
            catch (\PDOException) { self::assertSame('failed', $indexer->status()['state']); self::assertSame(2, $indexer->status()['scanned']); }
            finally { $db->execute('DROP TRIGGER fail_search_update'); }
            $db->execute('UPDATE pages SET content_html = "<p>Later change</p>" WHERE id = ?', [$ids[0]]);
            self::assertSame(2, $indexer->enqueue()['scanned']);
            $second = (new SearchIndexer($db))->processDue(2); self::assertSame(4, $second['scanned']);
            $last = $indexer->processDue(2); self::assertSame('completed', $last['state']); self::assertSame(5, $last['updated']);
            self::assertSame($last, $indexer->processDue(2));
            self::assertSame('First Second', $db->fetchOne('SELECT content_text FROM pages WHERE id = ?', [$ids[0]])['content_text']);
            self::assertStringContainsString('<script>secret</script>', $db->fetchOne('SELECT content_html FROM pages WHERE id = ?', [$ids[4]])['content_html']);
        });
    }

    public function testGroupMembershipChangesTheNextSearchWithoutAStaleAclCache(): void
    {
        $this->fixture(function (Database $db, int $reader, int $owner, int $space): void {
            $id = $this->page($db,$space,$owner,'Group-only');
            $group = $db->insert('INSERT INTO user_groups (name,slug) VALUES ("Search group","search-group")');
            $db->execute('INSERT INTO page_acl (page_id,principal_type,principal_id,permission,effect,inherit_to_children) VALUES (?,"group",?,"page.view","allow",1)',[$id,$group]);
            $service = new SearchService($db);
            self::assertSame(0,$service->search($this->input('Group-only'),$reader,['page.view'])['meta']['total']);
            $db->execute('INSERT INTO group_users (group_id,user_id) VALUES (?,?)',[$group,$reader]);
            self::assertSame(1,$service->search($this->input('Group-only'),$reader,['page.view'])['meta']['total']);
            $db->execute('DELETE FROM group_users WHERE group_id = ? AND user_id = ?',[$group,$reader]);
            self::assertSame(0,$service->search($this->input('Group-only'),$reader,['page.view'])['meta']['total']);
        });
    }

    public function testAttachmentSearchUsesPublicSnapshotsAndKeepsPrivateVersionsPrivate(): void
    {
        $this->fixture(function (Database $db, int $reader, int $owner, int $space): void {
            $id = $this->page($db,$space,$owner,'Files');
            $file = $db->insert('INSERT INTO attachments (page_id,uploader_id,name,storage_key,mime_type,size_bytes,current_version) VALUES (?, ?, "report-private.txt", "search-file", "text/plain", 200, 2)',[$id,$owner]);
            foreach ([1,2] as $version) $db->execute('INSERT INTO attachment_versions (attachment_id,version_number,storage_key,mime_type,size_bytes,sha256,uploader_id) VALUES (?, ?, ?, "text/plain", ?, ?, ?)',[$file,$version,'search-file-' . $version,$version * 100,str_repeat((string) $version,64),$owner]);
            $db->execute('UPDATE spaces SET review_workflow = 1 WHERE id = ?',[$space]);
            $db->execute('UPDATE pages SET published_revision = 1,version = 2 WHERE id = ?',[$id]);
            $db->execute('INSERT INTO page_revision_attachments (page_id,revision_number,attachment_id,version_number,name) VALUES (?,1,?,1,"report-public.txt")',[$id,$file]);
            $service = new SearchService($db);
            $public = $service->search($this->input('report', ['type'=>'attachment']),$reader,['page.view']);
            self::assertSame(1,$public['meta']['total']); self::assertSame('report-public.txt',$public['data'][0]['name']); self::assertSame(100,$public['data'][0]['size_bytes']);
            self::assertSame(0,$service->search($this->input('private',['type'=>'attachment']),$reader,['page.view'])['meta']['total']);
            self::assertSame(1,$service->search($this->input('private',['type'=>'attachment']),$owner,['page.view','page.edit'])['meta']['total']);
            $db->execute('UPDATE attachments SET deleted_at = UTC_TIMESTAMP() WHERE id = ?',[$file]);
            self::assertSame(1,$service->search($this->input('report',['type'=>'attachment']),$reader,['page.view'])['meta']['total']);
            self::assertSame(0,$service->search($this->input('private',['type'=>'attachment']),$owner,['page.view','page.edit'])['meta']['total']);
        });
    }

    public function testFulltextNeedsNoServerAdministrationOrProcessPrivilege(): void
    {
        $this->fixture(function (Database $db, int $reader, int $owner, int $space): void {
            $this->page($db,$space,$owner,'Ordinary title','privilegeterm');
            $username = 'search_ro_' . bin2hex(random_bytes(6)); $password = bin2hex(random_bytes(16));
            $name = $db->fetchOne('SELECT DATABASE() AS name')['name'];
            $db->pdo()->exec("CREATE USER '$username'@'%' IDENTIFIED BY '$password'");
            try {
                $db->pdo()->exec("GRANT SELECT ON `$name`.* TO '$username'@'%'");
                $limited = Database::connect(['host'=>getenv('OPENWIKI_TEST_DB_HOST'),'port'=>(int)getenv('OPENWIKI_TEST_DB_PORT'),'database'=>$name,'username'=>$username,'password'=>$password]);
                self::assertSame(1,(new SearchService($limited))->search($this->input('privilegeterm'),$reader,['page.view'])['meta']['total']);
            } finally { $db->pdo()->exec("DROP USER '$username'@'%'"); }
        });
    }
}
