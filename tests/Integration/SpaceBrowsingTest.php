<?php

declare(strict_types=1);

namespace OpenWiki\Tests\Integration;

use OpenWiki\Core\Database;
use OpenWiki\Database\MigrationRunner;
use OpenWiki\Repositories\SpaceRepository;
use OpenWiki\Spaces\BrowseInput;
use OpenWiki\Spaces\SpaceContentService;
use OpenWiki\Tests\Support\IsolatedDatabase;
use PHPUnit\Framework\TestCase;

final class SpaceBrowsingTest extends TestCase
{
    private function fixture(callable $test): void
    {
        IsolatedDatabase::run(function (Database $db) use ($test): void {
            (new MigrationRunner($db, dirname(__DIR__, 2) . '/database/migrations'))->migrate();
            $owner = $db->insert('INSERT INTO users (username,email) VALUES ("browse-owner","owner@browse.invalid")');
            $reader = $db->insert('INSERT INTO users (username,email) VALUES ("browse-reader","reader@browse.invalid")');
            $role = $db->insert('INSERT INTO roles (name,slug) VALUES ("Browse reader","browse-reader")');
            $db->execute('INSERT IGNORE INTO permissions (name) VALUES ("page.view")');
            $db->execute('INSERT INTO role_permissions (role_id,permission_id) SELECT ?,id FROM permissions WHERE name="page.view"', [$role]);
            $db->execute('INSERT INTO user_roles VALUES (?,?)', [$reader, $role]);
            $test($db, $owner, $reader, $role);
        });
    }

    private function space(Database $db, int $owner, string $name, string $visibility = 'public'): int
    {
        return (new SpaceRepository($db))->create(['name' => $name, 'space_key' => strtoupper(bin2hex(random_bytes(5))), 'owner_id' => $owner, 'description' => 'Team handoff guides', 'visibility' => $visibility]);
    }

    private function page(Database $db, int $space, int $owner, string $title, ?int $parent = null, string $status = 'published', bool $inherit = true): int
    {
        return $db->insert('INSERT INTO pages (space_id,parent_id,title,slug,content_html,author_id,owner_id,status,inherit_acl,created_at,updated_at) VALUES (?,?,?,?,"<p>Documentation</p>",?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())', [$space, $parent, $title, bin2hex(random_bytes(8)), $owner, $owner, $status, (int) $inherit]);
    }

    public function testDirectoryCountsAndPagesApplyAclAndFiltersBeforePagination(): void
    {
        $this->fixture(function (Database $db, int $owner, int $reader): void {
            $expected = [];
            for ($index = 0; $index < 28; $index++) {
                $id = $this->space($db, $owner, sprintf('Public directory %02d', $index));
                if ($index === 0) $db->execute('INSERT INTO space_acl (space_id,principal_type,principal_id,permission,effect) VALUES (?,"user",?,"space.view","deny")', [$id, $reader]);
                elseif ($index === 26) $db->execute('UPDATE spaces SET status="archived" WHERE id=?', [$id]);
                elseif ($index === 27) $db->execute('UPDATE spaces SET deleted_at=UTC_TIMESTAMP() WHERE id=?', [$id]);
                else $expected[] = $id;
            }
            $private = $this->space($db, $owner, 'Secret directory', 'private');
            $this->space($db, $owner, 'Secret restricted', 'restricted');
            $repository = new SpaceRepository($db);
            $ids = [];
            for ($page = 1; $page <= 3; $page++) {
                $result = $repository->browse($reader, false, BrowseInput::directory(['q' => 'Public directory', 'page' => $page]));
                self::assertSame(25, $result['meta']['total']); self::assertSame(3, $result['meta']['total_pages']);
                array_push($ids, ...array_column($result['data'], 'id'));
            }
            self::assertSame($expected, $ids); self::assertCount(25, array_unique($ids));
            self::assertSame(0, $repository->browse($reader, false, BrowseInput::directory(['q' => 'Secret']))['meta']['total']);
            self::assertSame(0, $repository->browse(null, false, BrowseInput::directory(['q' => 'Secret']))['meta']['total']);
            self::assertSame($private, $repository->browse($owner, false, BrowseInput::directory(['visibility' => 'private']))['data'][0]['id']);
            self::assertSame(2, $repository->browse($reader, true, BrowseInput::directory(['q' => 'Secret']))['meta']['total']);
            $last = $repository->browse($reader, false, BrowseInput::directory(['q' => 'Public directory', 'page' => 999999]));
            self::assertSame(3, $last['meta']['page']); self::assertCount(1, $last['data']);
            self::assertSame(1, $repository->browse($reader, false, BrowseInput::directory(['state' => 'archived']))['meta']['total']);
            $literal = $this->space($db, $owner, 'Łódź 100%_!'); $this->space($db, $owner, 'Łódź 100XY');
            self::assertSame([$literal], array_column($repository->browse(null, false, BrowseInput::directory(['q' => '100%_!']))['data'], 'id'));
        });
    }

    public function testDirectoryGroupAndRoleGrantsRespectExplicitDenyAndPrivateOwnership(): void
    {
        $this->fixture(function (Database $db, int $owner, int $reader, int $role): void {
            $space = $this->space($db, $owner, 'Role directory', 'restricted');
            $db->execute('INSERT INTO space_acl (space_id,principal_type,principal_id,permission,effect) VALUES (?,"role",?,"space.view","allow")', [$space, $role]);
            $repository = new SpaceRepository($db); $filters = BrowseInput::directory(['q' => 'Role directory']);
            self::assertSame(1, $repository->browse($reader, false, $filters)['meta']['total']);
            $group = $db->insert('INSERT INTO user_groups (name,slug) VALUES ("Browse denied","browse-denied")');
            $db->execute('INSERT INTO group_users VALUES (?,?)', [$group, $reader]);
            $db->execute('INSERT INTO space_acl (space_id,principal_type,principal_id,permission,effect) VALUES (?,"group",?,"space.view","deny")', [$space, $group]);
            self::assertSame(0, $repository->browse($reader, false, $filters)['meta']['total']);
            self::assertSame(1, $repository->browse($owner, false, $filters)['meta']['total']);
            $db->execute('UPDATE spaces SET visibility="private" WHERE id=?', [$space]);
            $db->execute('DELETE FROM space_acl WHERE effect="deny" AND space_id=?', [$space]);
            self::assertSame(0, $repository->browse($reader, false, $filters)['meta']['total']);
        });
    }

    public function testContentPagesSkipHiddenAncestorsAndKeepThePublishedProjection(): void
    {
        $this->fixture(function (Database $db, int $owner, int $reader): void {
            $space = $this->space($db, $owner, 'Content');
            $db->execute('UPDATE spaces SET review_workflow=1 WHERE id=?', [$space]);
            $parent = $this->page($db, $space, $owner, 'SECRET parent', null, 'draft');
            $db->execute('INSERT INTO page_acl (page_id,principal_type,principal_id,permission,effect,inherit_to_children) VALUES (?,"user",?,"page.view","allow",1)', [$parent, $owner]);
            for ($index = 0; $index < 220; $index++) $this->page($db, $space, $owner, sprintf('A hidden %03d', $index), $parent);
            $expected = [];
            for ($index = 0; $index < 13; $index++) $expected[] = $this->page($db, $space, $owner, sprintf('Visible %02d', $index));
            $expected[] = $this->page($db, $space, $owner, 'Visible 13', $parent, 'published', false);
            $other = $this->space($db, $owner, 'Content extra'); $this->page($db, $other, $owner, 'Visible outsider');
            $service = new SpaceContentService($db); $ids = [];
            foreach ([1, 2] as $page) {
                $result = $service->browse($space, BrowseInput::content(['page' => $page]), $reader, ['page.view']);
                self::assertSame(14, $result['meta']['total']); self::assertSame(2, $result['meta']['total_pages']);
                self::assertStringNotContainsString('SECRET', json_encode($result)); self::assertStringNotContainsString('A hidden', json_encode($result));
                array_push($ids, ...array_column($result['data'], 'id'));
            }
            self::assertSame($expected, $ids);
            self::assertSame(14, $service->browse($space, BrowseInput::content([]), null, [])['meta']['total']);
            $public = $this->page($db, $space, $owner, 'Public projection');
            $db->execute('UPDATE pages SET published_revision=1,version=2,updated_at="2099-01-01 00:00:00" WHERE id=?', [$public]);
            foreach ([1 => ['Public projection', 'published', '2020-01-01 00:00:00'], 2 => ['SECRET working title', 'draft', '2099-01-01 00:00:00']] as $revision => [$title, $status, $date]) {
                $db->execute('INSERT INTO page_revisions (page_id,revision_number,title,slug,content_html,content_text,content_format,status,author_id,created_at) VALUES (?,?,?,"projection","<p>Source</p>","Source","visual",?,?,?)', [$public, $revision, $title, $status, $owner, $date]);
            }
            $projection = $service->browse($space, BrowseInput::content(['q' => 'Public projection']), $reader, ['page.view']);
            self::assertSame('2020-01-01 00:00:00', $projection['data'][0]['updated_at']);
            self::assertSame(0, $service->browse($space, BrowseInput::content(['q' => 'SECRET']), $reader, ['page.view'])['meta']['total']);
            $db->execute('INSERT INTO page_acl (page_id,principal_type,principal_id,permission,effect) VALUES (?,"user",?,"page.view","deny")', [$expected[0], $reader]);
            self::assertSame(14, $service->browse($space, BrowseInput::content([]), $reader, ['page.view'])['meta']['total']);
            $private = $this->space($db, $owner, 'Private content', 'private');
            $this->expectException(\OutOfBoundsException::class);
            $service->browse($private, BrowseInput::content([]), $reader, ['page.view']);
        });
    }
}
