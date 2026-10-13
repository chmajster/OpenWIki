<?php

declare (strict_types=1);
namespace OpenWiki\Wiki;

use OpenWiki\Core\Database;
use OpenWiki\Search\SearchAccess;
use OpenWiki\Spaces\SpaceActor;
/** Only candidate metadata is streamed through ACL; totals and page details come from the allowed SQL relation. */
final class WorkCenterService
{
    public const TYPES = ['task', 'review', 'freshness', 'discussion', 'draft', 'ownership'];
    public function __construct(private readonly Database $db)
    {
    }
    public function items(array $input, int $user, ?array $ceiling = null): array
    {
        $filters = $this->filters($input);
        $actor = new SpaceActor($this->db, $user, $ceiling, false);
        return $this->db->transaction(function () use ($filters, $actor, $user): array {
            $all = 'work_candidates_' . bin2hex(random_bytes(8));
            $visible = 'work_visible_' . bin2hex(random_bytes(8));
            $this->db->execute('CREATE TEMPORARY TABLE ' . $all . ' (item_key VARCHAR(150) PRIMARY KEY,type VARCHAR(20) NOT NULL,resource_id BIGINT UNSIGNED NULL,page_id BIGINT UNSIGNED NULL,space_id BIGINT UNSIGNED NOT NULL,working TINYINT NOT NULL,revision_number INT UNSIGNED NULL,status VARCHAR(20) NOT NULL,due_date DATE NULL,updated_at DATETIME NOT NULL,priority INT NOT NULL,document_key VARCHAR(80) NULL,editor_id VARCHAR(64) NULL,can_action TINYINT NOT NULL DEFAULT 0) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci');
            try {
                $this->db->execute('CREATE TEMPORARY TABLE ' . $visible . ' LIKE ' . $all);
                $this->candidates($all, $filters, $user);
                if ($filters['space'] !== null) {
                    $this->db->execute('DELETE FROM ' . $all . ' WHERE space_id<>?', [$filters['space']]);
                }
                $cursor = '';
                do {
                    $rows = $this->db->fetchAll('SELECT * FROM ' . $all . ' WHERE item_key>? ORDER BY item_key LIMIT 200', [$cursor]);
                    if ($rows === []) {
                        break;
                    }
                    $ids = array_filter(array_column($rows, 'page_id'));
                    $access = new SearchAccess($this->db, array_column($rows, 'space_id'), $ids, $ids, $user, $actor->permissions(), true, true);
                    $allowed = [];
                    foreach ($rows as $row) {
                        $page = (int) $row['page_id'];
                        $space = $access->spaces[(int) $row['space_id']] ?? null;
                        if ($space === null || $space['status'] !== 'active') {
                            continue;
                        }
                        if ($page !== 0 && (!$access->visible($page, (bool) $row['working']) || ($access->pages[$page]['status'] ?? 'archived') === 'archived')) {
                            continue;
                        }
                        if ($row['type'] === 'review') {
                            $permission = $row['status'] === 'in_review' ? 'page.review' : 'page.publish';
                            if (!$access->action($page, $permission)) {
                                continue;
                            }
                            $row['can_action'] = 1;
                        } elseif ($row['type'] === 'draft') {
                            if ($page !== 0) {
                                if (!$access->action($page, 'page.edit')) {
                                    continue;
                                }
                            } elseif (!($space['search_visible'] ?? false) || !$actor->can('page.create') || !($space['search_editable'] ?? false)) {
                                continue;
                            }
                            $row['can_action'] = 1;
                        } elseif ($row['type'] === 'freshness') {
                            $row['can_action'] = (int) $access->action($page, 'page.verify');
                        }
                        $allowed[] = $row;
                    }
                    if ($allowed !== []) {
                        $values = [];
                        foreach ($allowed as $row) {
                            $values = [...$values, ...array_values($row)];
                        }
                        $this->db->execute('INSERT IGNORE INTO ' . $visible . ' VALUES ' . implode(',', array_fill(0, count($allowed), '(' . implode(',', array_fill(0, 14, '?')) . ')')), $values);
                    }
                    $cursor = $rows[count($rows) - 1]['item_key'];
                } while (count($rows) === 200);
                $spaces = $this->db->fetchAll('SELECT DISTINCT s.id,s.name,s.space_key FROM ' . $visible . ' v INNER JOIN spaces s ON s.id=v.space_id ORDER BY s.name,s.id');
                [$where, $params] = $this->where($filters);
                $total = (int) $this->db->fetchOne('SELECT COUNT(*) total FROM ' . $visible . ' v WHERE ' . $where, $params)['total'];
                $counts = array_fill_keys(self::TYPES, 0);
                foreach ($this->db->fetchAll('SELECT type,COUNT(*) total FROM ' . $visible . ' v WHERE ' . $where . ' GROUP BY type', $params) as $row) {
                    $counts[$row['type']] = (int) $row['total'];
                }
                $sort = match ($filters['sort']) {
                    'due' => 'v.due_date IS NULL,v.due_date,v.priority,v.item_key',
                    'updated' => 'v.updated_at DESC,v.item_key',
                    'title' => 'title,v.item_key',
                    default => 'v.priority,v.due_date IS NULL,v.due_date,v.updated_at DESC,v.item_key',
                };
                $title = 'CASE WHEN v.type="task" THEN task.title WHEN v.type="draft" THEN COALESCE(JSON_UNQUOTE(JSON_EXTRACT(d.payload_json,"$.title")),r.title,p.title,"Private draft") WHEN v.working=1 THEN COALESCE(r.title,p.title) ELSE p.title END';
                $data = $this->db->fetchAll('SELECT v.*,' . $title . ' AS title,p.title AS document_title,p.slug,s.space_key,s.name AS space_name,task.assignee_id,task.author_id,root.anchor_json IS NOT NULL AS anchored FROM ' . $visible . ' v INNER JOIN spaces s ON s.id=v.space_id LEFT JOIN pages p ON p.id=v.page_id LEFT JOIN page_revisions r ON r.page_id=v.page_id AND r.revision_number=v.revision_number LEFT JOIN page_tasks task ON v.type="task" AND task.id=v.resource_id LEFT JOIN comments root ON v.type="discussion" AND root.id=v.resource_id LEFT JOIN page_editor_drafts d ON v.type="draft" AND d.space_id=v.space_id AND d.user_id=? AND d.document_key=v.document_key AND d.editor_id=v.editor_id WHERE ' . $where . ' ORDER BY ' . $sort . ' LIMIT ' . $filters['per_page'] . ' OFFSET ' . ($filters['page'] - 1) * $filters['per_page'], [$user, ...$params]);
                foreach ($data as &$row) {
                    $base = '/spaces/' . rawurlencode($row['space_key']);
                    $url = $base . '/pages/' . rawurlencode($row['slug'] ?? '');
                    $row['action'] = 'Open document';
                    if ($row['type'] === 'task') {
                        $url .= ((bool) $row['working'] ? '/review' : '') . '#task-' . $row['resource_id'];
                        $row['action'] = 'Open task';
                        $row['can_action'] = (int) ($actor->can('task.manage') || (int) $row['author_id'] === $user && $actor->can('task.create') || (int) $row['assignee_id'] === $user && $actor->can('task.update_own'));
                    } elseif ($row['type'] === 'review') {
                        $url .= '/review';
                        $row['action'] = $row['status'] === 'in_review' ? 'Review document' : 'Publish document';
                    } elseif ($row['type'] === 'freshness' && (bool) $row['can_action']) {
                        $url .= '/review';
                        $row['action'] = 'Verify freshness';
                    } elseif ($row['type'] === 'discussion') {
                        $url .= ((bool) $row['working'] ? '/review' : '') . '#' . ((bool) $row['anchored'] ? 'discussion-' : 'comment-') . $row['resource_id'];
                        $row['action'] = 'Open discussion';
                    } elseif ($row['type'] === 'draft') {
                        $url = $row['page_id'] === null ? $base . '/pages/create?document=' . rawurlencode(substr($row['document_key'], 4)) : $url . '/edit';
                        $row['action'] = 'Resume draft';
                    }
                    $row['url'] = $url;
                    $row['working'] = (bool) $row['working'];
                    $row['can_action'] = (bool) $row['can_action'];
                    unset($row['document_key'], $row['editor_id'], $row['anchored']);
                }
                unset($row);
                return ['data' => $data, 'meta' => ['total' => $total, 'page' => $filters['page'], 'per_page' => $filters['per_page'], 'counts' => $counts], 'filters' => $filters, 'spaces' => $spaces];
            } finally {
                $this->db->execute('DROP TEMPORARY TABLE IF EXISTS ' . $visible);
                $this->db->execute('DROP TEMPORARY TABLE IF EXISTS ' . $all);
            }
        });
    }
    private function candidates(string $table, array $filters, int $user): void
    {
        $today = $this->db->pdo()->quote(date('Y-m-d'));
        $scope = $filters['attention'] ? '' : ' AND t.assignee_id=?';
        $params = $filters['attention'] ? [] : [$user];
        if ($filters['type'] === 'all' || $filters['type'] === 'task') {
            $this->db->execute('INSERT INTO ' . $table . ' (item_key,type,resource_id,page_id,space_id,working,revision_number,status,due_date,updated_at,priority) SELECT CONCAT("task:",t.id),"task",t.id,t.page_id,p.space_id,(t.visibility="working" OR (t.revision_number IS NOT NULL AND r.was_published=0)),t.revision_number,t.status,t.due_date,t.updated_at,IF(t.due_date<' . $today . ',0,3) FROM page_tasks t INNER JOIN pages p ON p.id=t.page_id AND p.deleted_at IS NULL LEFT JOIN page_revisions r ON r.page_id=t.page_id AND r.revision_number=t.revision_number WHERE (t.revision_number IS NULL OR r.id IS NOT NULL)' . $scope, $params);
        }
        if ($filters['type'] === 'all' || $filters['type'] === 'review') {
            $this->db->execute('INSERT INTO ' . $table . ' (item_key,type,resource_id,page_id,space_id,working,revision_number,status,updated_at,priority) SELECT CONCAT("review:",p.id),"review",p.id,p.id,p.space_id,1,p.version,p.workflow_state,p.updated_at,1 FROM pages p WHERE p.deleted_at IS NULL AND p.workflow_state IN ("in_review","approved")');
        }
        if ($filters['type'] === 'all' || $filters['type'] === 'freshness') {
            $this->db->execute('INSERT INTO ' . $table . ' (item_key,type,resource_id,page_id,space_id,working,revision_number,status,due_date,updated_at,priority) SELECT CONCAT("freshness:",p.id),"freshness",p.id,p.id,p.space_id,0,p.published_revision,"review_due",p.review_due_at,p.updated_at,IF(p.review_due_at<' . $today . ',2,4) FROM pages p WHERE p.deleted_at IS NULL AND p.published_revision IS NOT NULL AND p.review_due_at IS NOT NULL');
        }
        if ($filters['attention']) {
            if ($filters['type'] === 'all' || $filters['type'] === 'discussion') {
                $this->db->execute('INSERT INTO ' . $table . ' (item_key,type,resource_id,page_id,space_id,working,revision_number,status,updated_at,priority) SELECT CONCAT("discussion:",c.id),"discussion",c.id,c.page_id,p.space_id,(c.visibility="working" OR r.was_published=0),c.revision_number,c.thread_status,c.updated_at,3 FROM comments c INNER JOIN pages p ON p.id=c.page_id AND p.deleted_at IS NULL INNER JOIN page_revisions r ON r.page_id=c.page_id AND r.revision_number=c.revision_number WHERE c.anchor_json IS NOT NULL AND c.deleted_at IS NULL AND c.thread_status="open"');
            }
        } else {
            if ($filters['type'] === 'all' || $filters['type'] === 'discussion') {
                $this->db->execute('INSERT INTO ' . $table . ' (item_key,type,resource_id,page_id,space_id,working,revision_number,status,updated_at,priority) SELECT CONCAT("discussion:",root.id),"discussion",root.id,root.page_id,p.space_id,(root.visibility="working" OR (root.revision_number IS NOT NULL AND r.was_published=0)),root.revision_number,root.thread_status,MAX(n.created_at),3 FROM notifications n INNER JOIN comments c ON n.resource_type="comment" AND n.resource_id=c.id AND c.deleted_at IS NULL INNER JOIN comments original_root ON original_root.id=COALESCE(c.thread_id,c.id) INNER JOIN comments root ON root.id=IF(original_root.deleted_at IS NOT NULL AND original_root.anchor_json IS NULL,c.id,original_root.id) AND root.deleted_at IS NULL INNER JOIN pages p ON p.id=root.page_id AND p.deleted_at IS NULL LEFT JOIN page_revisions r ON r.page_id=root.page_id AND r.revision_number=root.revision_number WHERE n.user_id=? AND n.event_type IN ("reply","mention","comment.created","discussion.open","discussion.resolved") AND (root.revision_number IS NULL OR r.id IS NOT NULL) GROUP BY root.id,root.page_id,p.space_id,root.visibility,root.revision_number,r.was_published,root.thread_status', [$user]);
            }
        }
        if (!$filters['attention']) {
            if ($filters['type'] === 'all' || $filters['type'] === 'draft') {
                $this->db->execute('INSERT INTO ' . $table . ' (item_key,type,resource_id,page_id,space_id,working,revision_number,status,updated_at,priority,document_key) SELECT CONCAT("draft-page:",p.id),"draft",p.id,p.id,p.space_id,1,p.version,p.workflow_state,p.updated_at,5,CONCAT("page:",p.id) FROM pages p WHERE p.deleted_at IS NULL AND p.author_id=? AND p.workflow_state IN ("draft","rejected") AND NOT EXISTS(SELECT 1 FROM page_editor_drafts d WHERE d.space_id=p.space_id AND d.user_id=? AND d.document_key=CONCAT("page:",p.id) AND d.sequence>d.acknowledged_sequence)', [$user, $user]);
            }
        }
        if (!$filters['attention']) {
            if ($filters['type'] === 'all' || $filters['type'] === 'draft') {
                $this->db->execute('INSERT IGNORE INTO ' . $table . ' (item_key,type,page_id,space_id,working,status,updated_at,priority,document_key,editor_id) SELECT CONCAT("draft:",d.space_id,":",d.document_key),"draft",IF(d.document_key LIKE "page:%",CAST(SUBSTRING(d.document_key,6) AS UNSIGNED),NULL),d.space_id,1,"draft",d.updated_at,5,d.document_key,d.editor_id FROM page_editor_drafts d WHERE d.user_id=? AND d.sequence>d.acknowledged_sequence ORDER BY d.updated_at DESC,d.editor_id', [$user]);
            }
        }
        if ($filters['attention']) {
            if ($filters['type'] === 'all' || $filters['type'] === 'ownership') {
                $this->db->execute('INSERT INTO ' . $table . ' (item_key,type,resource_id,page_id,space_id,working,revision_number,status,updated_at,priority) SELECT CONCAT("ownership:",p.id),"ownership",p.id,p.id,p.space_id,0,p.published_revision,"no_owner",p.updated_at,3 FROM pages p LEFT JOIN users u ON u.id=p.content_owner_id AND u.status="active" AND u.deleted_at IS NULL WHERE p.deleted_at IS NULL AND p.published_revision IS NOT NULL AND u.id IS NULL');
            }
        }
    }
    private function filters(array $input): array
    {
        $scalar = static fn($value) => is_scalar($value) ? (string) $value : '';
        $type = $scalar($input['type'] ?? 'all');
        $deadline = $scalar($input['deadline'] ?? 'all');
        $sort = $scalar($input['sort'] ?? 'priority');
        $status = $scalar($input['status'] ?? 'active');
        if (!in_array($type, ['all', ...self::TYPES], true) || !in_array($deadline, ['all', 'overdue', 'today', 'week', 'none'], true) || !in_array($sort, ['priority', 'due', 'updated', 'title'], true) || !in_array($status, ['all', 'active', 'todo', 'in_progress', 'blocked', 'done', 'cancelled', 'open', 'resolved', 'in_review', 'approved', 'draft', 'review_due', 'no_owner'], true)) {
            throw new \InvalidArgumentException('Nieprawidłowy filtr centrum pracy.');
        }
        $space = CollaborationAccess::positive($input['space'] ?? null, true);
        $attention = ($input['attention'] ?? '') === '1';
        if ($attention && $space === null) {
            throw new \InvalidArgumentException('Wybierz przestrzeń wymagającą uwagi.');
        }
        return ['type' => $type, 'status' => $status, 'deadline' => $deadline, 'sort' => $sort, 'space' => $space, 'attention' => $attention, 'page' => max(1, min(1000000, (int) ($input['page'] ?? 1))), 'per_page' => max(1, min(100, (int) ($input['per_page'] ?? 20)))];
    }
    private function where(array $filters): array
    {
        $today = $this->db->pdo()->quote(date('Y-m-d'));
        $where = ['1=1'];
        $params = [];
        if ($filters['space'] !== null) {
            $where[] = 'v.space_id=?';
            $params[] = $filters['space'];
        }
        if ($filters['type'] !== 'all') {
            $where[] = 'v.type=?';
            $params[] = $filters['type'];
        }
        if ($filters['status'] === 'active') {
            $where[] = 'v.status NOT IN ("done","cancelled","resolved")';
        } elseif ($filters['status'] !== 'all') {
            $where[] = 'v.status=?';
            $params[] = $filters['status'];
        }
        if ($filters['attention']) {
            $where[] = '(v.type NOT IN ("task","discussion","freshness") OR (v.type="task" AND v.status NOT IN ("done","cancelled")) OR (v.type="discussion" AND v.status="open") OR (v.type="freshness" AND v.due_date<' . $today . '))';
        }
        $where[] = match ($filters['deadline']) {
            'overdue' => 'v.due_date<' . $today . '',
            'today' => 'v.due_date=' . $today . '',
            'week' => 'v.due_date BETWEEN ' . $today . ' AND DATE_ADD(' . $today . ',INTERVAL 7 DAY)',
            'none' => 'v.due_date IS NULL',
            default => '1=1',
        };
        return [implode(' AND ', $where), $params];
    }
}
