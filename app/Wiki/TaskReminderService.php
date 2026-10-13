<?php

declare (strict_types=1);
namespace OpenWiki\Wiki;

use OpenWiki\Core\Database;
use OpenWiki\Repositories\PageRepository;
/** A daily business event; unique recipient/event keys make interrupted cron runs safe to repeat. */
final class TaskReminderService
{
    public function __construct(private readonly Database $db)
    {
    }
    public function run(): int
    {
        $today = date('Y-m-d');
        $cursor = 0;
        $processed = 0;
        do {
            $rows = $this->db->fetchAll('SELECT id FROM page_tasks WHERE id>? AND assignee_id IS NOT NULL AND due_date<=? AND status NOT IN ("done","cancelled") ORDER BY id LIMIT 100', [$cursor, $today]);
            foreach ($rows as $row) {
                $cursor = (int) $row['id'];
                $processed += (int) $this->db->transaction(function () use ($cursor, $today): bool {
                    $initial = $this->db->fetchOne('SELECT page_id FROM page_tasks WHERE id=?', [$cursor]);
                    if ($initial === null) {
                        return false;
                    }
                    $repo = new PageRepository($this->db);
                    $page = $repo->findById((int) $initial['page_id']);
                    if ($page === null) {
                        return false;
                    }
                    $space = $this->db->fetchOne('SELECT * FROM spaces WHERE id=? AND deleted_at IS NULL FOR UPDATE', [$page['space_id']]);
                    $page = $this->db->fetchOne('SELECT * FROM pages WHERE id=? AND deleted_at IS NULL FOR UPDATE', [$initial['page_id']]);
                    if ($space === null || $page === null || (int) $page['space_id'] !== (int) $space['id'] || $space['status'] !== 'active' || $page['status'] === 'archived') {
                        return false;
                    }
                    $task = $this->db->fetchOne('SELECT * FROM page_tasks WHERE id=? FOR UPDATE', [$cursor]);
                    if ($task === null || $task['assignee_id'] === null || $task['due_date'] === null || $task['due_date'] > $today || in_array($task['status'], ['done', 'cancelled'], true)) {
                        return false;
                    }
                    $key = 'reminder:' . $task['id'] . ':' . $task['due_date'] . ':' . $task['assignee_id'] . ':' . $today;
                    if ($this->db->fetchOne('SELECT id FROM notifications WHERE user_id=? AND event_key=?', [$task['assignee_id'], $key]) !== null) {
                        return false;
                    }
                    $url = '/spaces/' . rawurlencode($space['space_key']) . '/pages/' . rawurlencode($page['slug']) . ($task['visibility'] === 'working' ? '/review' : '') . '#task-' . $task['id'];
                    (new PageEngagementService($this->db))->notifyWatchers((int) $page['id'], (int) $space['id'], 0, 'task.reminder', 'Termin zadania: ' . $task['title'], $url, $task['visibility'] === 'working', [(int) $task['assignee_id']], ['include_watchers' => false, 'resource_type' => 'task', 'resource_id' => $task['id'], 'revision_number' => $task['revision_number'], 'event_key' => $key]);
                    return $this->db->fetchOne('SELECT id FROM notifications WHERE user_id=? AND event_key=?', [$task['assignee_id'], $key]) !== null;
                });
            }
        } while (count($rows) === 100);
        return $processed;
    }
}
