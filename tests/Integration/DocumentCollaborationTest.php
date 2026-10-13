<?php

declare (strict_types=1);
namespace OpenWiki\Tests\Integration;

use OpenWiki\Core\Database;
use OpenWiki\Database\MigrationRunner;
use OpenWiki\Tests\Support\IsolatedDatabase;
use OpenWiki\Wiki\CommentService;
use OpenWiki\Wiki\CommentAnchor;
use OpenWiki\Wiki\PageTaskService;
use OpenWiki\Wiki\PageEngagementService;
use OpenWiki\Wiki\CollaborationConflict;
use PHPUnit\Framework\TestCase;
final class DocumentCollaborationTest extends TestCase
{

    public function testAccessIsAppliedBeforeWorkPaginationAndRepliesAreBounded(): void
    {
        IsolatedDatabase::run(function (Database $db): void {
            [$u, $s, $p] = $this->fixture($db);
            $hidden = $db->insert('INSERT INTO pages (space_id,title,slug,content_html,content_format,author_id,owner_id,status,version,published_revision,workflow_state) VALUES (?,"Protected title","hidden","<p>Hidden</p>","visual",?,?,"published",1,1,"published")', [$s,$u['editor'],$u['editor']]);
            $db->execute('INSERT INTO page_revisions (page_id,revision_number,title,slug,content_html,content_format,status,author_id,was_published) VALUES (?,1,"Protected title","hidden","<p>Hidden</p>","visual","published",?,1)', [$hidden,$u['editor']]);
            $db->execute('INSERT INTO page_acl (page_id,principal_type,principal_id,permission,effect,inherit_to_children) VALUES (?,"user",?,"page.view","deny",1)', [$hidden,$u['reader']]);
            foreach ([$hidden=>201,$p=>23] as $page=>$count) {
                $params=[];for($i=0;$i<$count;$i++) $params=[...$params,$page,($page===$p?'Visible ':'Secret ').$i,'',$u['editor'],$u['reader']];
                $db->execute('INSERT INTO page_tasks (page_id,title,description,author_id,assignee_id) VALUES '.implode(',',array_fill(0,$count,'(?,?,?,?,?)')),$params);
            }
            $center=(new \OpenWiki\Wiki\WorkCenterService($db))->items(['type'=>'task','page'=>2],$u['reader']);
            self::assertSame(23,$center['meta']['total']);self::assertCount(3,$center['data']);
            self::assertSame([$p],array_values(array_unique(array_map('intval',array_column($center['data'],'page_id')))));
            $comments=new CommentService($db);$root=$this->discussion($db,$u,$s,$p);
            $params=[];for($i=0;$i<27;$i++)$params=[...$params,$p,$u['editor'],$root,$root,'Reply '.$i];
            $db->execute('INSERT INTO comments (page_id,author_id,parent_id,thread_id,body_html,revision_number,visibility) VALUES '.implode(',',array_fill(0,27,'(?,?,?,?,?,1,"published")')),$params);
            $thread=$comments->threads($p,1,$u['reader'])['data'][0];
            self::assertSame(27,$thread['replies_meta']['total']);self::assertCount(25,$thread['replies']);
            self::assertCount(2,$comments->replies($root,$u['reader'],null,$p,2)['data']);
            self::assertCount(1,$comments->threads($p,1,$u['reader'],null,'all',1,25,$root)['data']);
        });
    }
    public function testReplayCanonicalizationAndDeletedGeneralParentPreserveReplies(): void
    {
        IsolatedDatabase::run(function (Database $db): void {
            [$u,$s,$p]=$this->fixture($db);$tasks=new PageTaskService($db);
            $input=['title'=>'Canonical retry','operation_id'=>'ABCDEF12-1234-1234-1234-123456789012'];
            $task=$tasks->create($p,$u['editor'],$input);
            $retry=$tasks->create($p,$u['editor'],['operation_id'=>strtolower($input['operation_id']),'title'=>$input['title']]);
            self::assertSame($task['id'],$retry['id']);self::assertTrue($retry['replayed']);
            $comments=new CommentService($db);
            $root=$comments->create($p,$s,$u['editor'],'General parent',null,'','');
            $child=$comments->create($p,$s,$u['reader'],'General reply',$root,'','');
            $comments->delete($root,$u['editor'],false,$p);
            $reply=$comments->create($p,$s,$u['editor'],'Reply to remaining child @reader',$child,'','');
            self::assertSame($root,(int)$comments->read($reply,$u['reader'])['thread_id']);
            $visible=(new PageEngagementService($db))->notificationsForUser($u['reader']);
            self::assertContains($reply,array_map('intval',array_column($visible,'resource_id')));
            self::assertSame(1,(new \OpenWiki\Wiki\WorkCenterService($db))->items(['type'=>'discussion'],$u['reader'])['meta']['total']);
            $anchored=$this->discussion($db,$u,$s,$p);
            $linked=$tasks->create($p,$u['editor'],['title'=>'Linked source','thread_id'=>$anchored]);
            self::assertStringEndsWith('#discussion-'.$anchored,$linked['discussion_url']);
            $comments->delete($anchored,$u['editor'],false,$p);
            self::assertNull($tasks->read((int)$linked['id'],$u['editor'])['discussion_url']);
            self::assertStringEndsWith('/revisions/1',$tasks->read((int)$linked['id'],$u['editor'])['source_url']);
            $db->execute('UPDATE collaboration_operations SET created_at=DATE_SUB(UTC_TIMESTAMP(), INTERVAL 31 DAY)');
            (new \OpenWiki\Wiki\CollaborationOperation($db))->prune();
            self::assertSame(0,(int)$db->fetchOne('SELECT COUNT(*) total FROM collaboration_operations')['total']);
            self::assertSame(2,(int)$db->fetchOne('SELECT COUNT(*) total FROM page_tasks')['total']);
        });
    }
    private function fixture(Database $db): array
    {
        (new MigrationRunner($db, dirname(__DIR__, 2) . '/database/migrations'))->migrate();
        $users = [];
        foreach (['editor' => ['page.view', 'page.edit', 'comment.create', 'comment.resolve', 'task.create'], 'reader' => ['page.view', 'comment.create', 'task.update_own'], 'outsider' => ['page.view']] as $kind => $permissions) {
            $id = $db->insert('INSERT INTO users (username,email,status,auth_source) VALUES (?, ?, "active", "local")', [$kind, $kind . '@example.test']);
            $users[$kind] = $id;
            $role = $db->insert('INSERT INTO roles (name,slug) VALUES (?,?)', [$kind, $kind]);
            foreach ($permissions as $name) {
                $db->execute('INSERT IGNORE INTO permissions (name) VALUES (?)', [$name]);
                $db->execute('INSERT INTO role_permissions SELECT ?,id FROM permissions WHERE name=?', [$role, $name]);
            }
            $db->execute('INSERT INTO user_roles VALUES (?,?)', [$id, $role]);
        }
        $space = $db->insert('INSERT INTO spaces (name,space_key,owner_id,visibility,status,review_workflow) VALUES ("Collaboration","COLLAB",?,"public","active",1)', [$users['editor']]);
        $page = $db->insert('INSERT INTO pages (space_id,title,slug,content_html,content_text,content_format,author_id,owner_id,status,version,published_revision,workflow_state) VALUES (?,"Public title","public","<p>Published fragment remains.</p>","Published fragment remains.","visual",?,?,"published",2,1,"in_review")', [$space, $users['editor'], $users['editor']]);
        foreach ([1 => ['Public title', '<p>Published fragment remains.</p>', 'published', 1], 2 => ['Private title', '<p>Secret fragment remains.</p>', 'draft', 0]] as $number => [$title, $html, $status, $published]) {
            $db->execute('INSERT INTO page_revisions (page_id,revision_number,title,slug,content_html,content_text,content_format,status,author_id,was_published) VALUES (?,?,?,"public",?,?,"visual",?,?,?)', [$page, $number, $title, $html, CommentAnchor::text($html), $status, $users['editor'], $published]);
        }
        return [$users, $space, $page];
    }
    private function discussion(Database $db, array $users, int $space, int $page, int $revision = 1): int
    {
        $text = $revision === 1 ? 'Published fragment remains.' : 'Secret fragment remains.';
        $quote = $revision === 1 ? 'Published fragment' : 'Secret fragment';
        return (new CommentService($db))->create($page, $space, $users['editor'], 'Please check @reader', null, 'ignored', '/ignored', ['revision_number' => $revision, 'anchor' => ['quote' => $quote, 'start' => 0, 'hash' => hash('sha256', $text)]]);
    }
    public function testDiscussionReplyResolutionAndPrivateRevisionBoundaries(): void
    {
        IsolatedDatabase::run(function (Database $db): void {
            [$u, $s, $p] = $this->fixture($db);
            $comments = new CommentService($db);
            $events = new PageEngagementService($db);
            $events->setWatch($u['reader'], 'page', $p, true);
            $root = $this->discussion($db, $u, $s, $p);
            self::assertSame(1, $events->unreadCount($u['reader']));
            $reply = $comments->create($p, $s, $u['reader'], 'Reply @editor', $root, '', '');
            self::assertSame($root, (int) $comments->read($reply, $u['reader'])['thread_id']);
            $resolved = $comments->resolve($root, $u['editor'], ['base_version' => 1, 'status' => 'resolved']);
            self::assertSame('resolved', $resolved['thread_status']);
            $comments->resolve($root, $u['editor'], ['base_version' => 2, 'status' => 'open']);
            $public = $comments->threads($p, 1, $u['reader']);
            self::assertSame(1, $public['meta']['total']);
            self::assertSame('open', $public['data'][0]['thread_status']);
            self::assertCount(1, $public['data'][0]['replies']);
            self::assertNotNull($public['data'][0]['location']);
            $private = $this->discussion($db, $u, $s, $p, 2);
            $query = \OpenWiki\Search\SearchInput::fromRequest(new \OpenWiki\Core\Request('GET', '/search', ['q'=>'Please check', 'type'=>'comment', 'visibility'=>'working'], [], [], []));
            $search = new \OpenWiki\Search\SearchService($db);
            self::assertSame(0, $search->search($query, $u['reader'], ['page.view'])['meta']['total']);
            $result = $search->search($query, $u['editor'], ['page.view','page.edit']);
            self::assertSame(1, $result['meta']['total']); self::assertStringEndsWith('/revisions/2#discussion-'.$private, $result['data'][0]['url']);
            self::assertSame(1, $comments->threads($p, 1, $u['reader'])['meta']['total']);
            self::assertSame(3, $events->unreadCount($u['reader']));
            try {
                $comments->read($private, $u['reader']);
                self::fail('Private quote exposed.');
            } catch (\OutOfBoundsException) {
            }
            try {
                $comments->create($p, $s, $u['reader'], 'Guess id', $private, '', '');
                self::fail('Private discussion accepted reply.');
            } catch (\OutOfBoundsException) {
            }
            self::assertSame(2, $comments->threads($p, 2, $u['editor'])['meta']['total']);
            $db->execute('UPDATE page_revisions SET was_published=1 WHERE page_id=? AND revision_number=2', [$p]);
            self::assertSame(1, $comments->threads($p, 2, $u['reader'])['meta']['total'], 'Working discussion remains private after publication.');
        });
    }
    public function testTasksOwnStatusHistoryConflictReplayAndAccessRevocation(): void
    {
        IsolatedDatabase::run(function (Database $db): void {
            [$u, $s, $p] = $this->fixture($db);
            $tasks = new PageTaskService($db);
            $comments = new CommentService($db);
            $events = new PageEngagementService($db);
            $root = $this->discussion($db, $u, $s, $p);
            $input = ['title' => 'Check recovery', 'description' => 'Details', 'thread_id' => $root, 'assignee_id' => $u['reader'], 'due_date' => '2026-12-10', 'operation_id' => '12345678-1234-1234-1234-123456789012'];
            $task = $tasks->create($p, $u['editor'], $input);
            $replayed = $tasks->create($p, $u['editor'], $input);
            self::assertSame($task['id'], $replayed['id']);
            self::assertTrue($replayed['replayed']);
            self::assertSame(1, (int) $db->fetchOne('SELECT COUNT(*) n FROM notifications WHERE resource_type="task"')['n']);
            $own = $tasks->read((int) $task['id'], $u['reader']);
            self::assertTrue($own['can_status']);
            self::assertFalse($own['can_edit']);
            $updated = $tasks->update((int) $task['id'], $u['reader'], ['base_version' => 1, 'status' => 'in_progress']);
            self::assertSame(2, (int) $updated['version']);
            try {
                $tasks->update((int) $task['id'], $u['editor'], ['base_version' => 1, 'due_date' => '2026-12-11']);
                self::fail('Stale update overwritten.');
            } catch (CollaborationConflict) {
            }
            try {
                $tasks->update((int) $task['id'], $u['reader'], ['base_version' => 2, 'title' => 'Unauthorized']);
                self::fail('Assignee changed task details.');
            } catch (\DomainException) {
            }
            self::assertSame(2, $tasks->historyFor((int) $task['id'], $u['reader'])['meta']['total']);
            $center = new \OpenWiki\Wiki\WorkCenterService($db);
            $work = $center->items(['type' => 'task'], $u['reader']);
            self::assertSame(1, $work['meta']['total']);
            self::assertSame('Check recovery', $work['data'][0]['title']);
            self::assertSame(1, $center->items(['attention' => '1', 'space' => $s, 'type' => 'task'], $u['editor'])['meta']['total']);
            $db->execute('UPDATE page_tasks SET due_date="2020-01-01" WHERE id=?', [$task['id']]);
            $reminders = new \OpenWiki\Wiki\TaskReminderService($db);
            self::assertSame(1, $reminders->run());
            self::assertSame(0, $reminders->run());
            $db->execute('INSERT INTO page_acl (page_id,principal_type,principal_id,permission,effect,inherit_to_children) VALUES (?,"user",?,"page.view","deny",1)', [$p, $u['reader']]);
            self::assertSame(0, $events->unreadCount($u['reader']));
            self::assertSame([], $events->notificationsForUser($u['reader']));
            self::assertSame(0, $center->items([], $u['reader'])['meta']['total']);
            try {
                $tasks->read((int) $task['id'], $u['reader']);
                self::fail('Task bypassed revoked ACL.');
            } catch (\OutOfBoundsException) {
            }
            try {
                $comments->read($root, $u['reader']);
                self::fail('Comment bypassed revoked ACL.');
            } catch (\OutOfBoundsException) {
            }
            $db->execute('UPDATE pages SET status="archived" WHERE id=?', [$p]);
            self::assertTrue($tasks->read((int) $task['id'], $u['editor'])['read_only']);
            try {
                $tasks->update((int) $task['id'], $u['editor'], ['base_version' => 2, 'status' => 'done']);
                self::fail('Archived task modified.');
            } catch (\DomainException) {
            }
        });
    }
    public function testContextValidationAndTokenPermissionCeilings(): void
    {
        IsolatedDatabase::run(function (Database $db): void {
            [$u, $s, $p] = $this->fixture($db);
            $comments = new CommentService($db);
            $tasks = new PageTaskService($db);
            $root = $this->discussion($db, $u, $s, $p);
            try {
                $comments->read($root, $u['editor'], null, $p + 1);
                self::fail('Context mismatch accepted.');
            } catch (\OutOfBoundsException) {
            }
            foreach ([true, 1.5, []] as $invalid) {
                try {
                    $tasks->create($p, $u['editor'], ['title'=>'Invalid assignee', 'assignee_id'=>$invalid]);
                    self::fail('A non-integer identifier was accepted.');
                } catch (\InvalidArgumentException) {
                }
            }
            try {
                $tasks->create($p, $u['editor'], ['title' => 'No token right'], ['page.view']);
                self::fail('Token bypassed RBAC.');
            } catch (\DomainException) {
            }
            try {
                $tasks->create($p, $u['editor'], ['title' => 'Bad date', 'due_date' => '2026-02-30']);
                self::fail('Invalid date accepted.');
            } catch (\InvalidArgumentException) {
            }
            $private = $this->discussion($db, $u, $s, $p, 2);
            try {
                $tasks->create($p, $u['editor'], ['title' => 'Private', 'thread_id' => $private, 'assignee_id' => $u['reader']]);
                self::fail('Assignment granted access.');
            } catch (\InvalidArgumentException) {
            }
            self::assertSame([$u['editor']], array_map('intval', array_column($tasks->assignees($p, $u['editor'], 2), 'id')));
        });
    }
}
