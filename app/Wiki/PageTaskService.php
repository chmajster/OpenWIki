<?php
declare(strict_types=1);
namespace OpenWiki\Wiki;

use OpenWiki\Core\Database;
use OpenWiki\Core\Request;
use OpenWiki\Audit\AuditLogger;

final class PageTaskService
{
    public const STATUSES = ['todo', 'in_progress', 'blocked', 'done', 'cancelled'];
    public function __construct(private readonly Database $db) {}

    public function read(int $id, int $user, ?array $ceiling = null, ?int $pageId = null, bool $mutation = false): array
    {
        $row = $this->db->fetchOne('SELECT t.*,a.username AS author,u.username AS assignee FROM page_tasks t INNER JOIN users a ON a.id=t.author_id LEFT JOIN users u ON u.id=t.assignee_id WHERE t.id=?', [$id]);
        if ($row === null || ($pageId !== null && (int) $row['page_id'] !== $pageId)) throw new \OutOfBoundsException('Nie znaleziono dostępnego zadania.');
        $context = (new CollaborationAccess($this->db))->context((int) $row['page_id'], $user, $ceiling, $row['revision_number'] === null ? null : (int) $row['revision_number'], $row['visibility'] === 'working', $mutation);
        if ($mutation) $row = array_replace($row, $this->db->fetchOne('SELECT * FROM page_tasks WHERE id=? FOR UPDATE', [$id]) ?? throw new \OutOfBoundsException('Nie znaleziono zadania.'));
        return $this->decorate($row, $context, $user);
    }

    public function forPage(int $pageId, int $user, ?array $ceiling = null, int $page = 1, int $perPage = 20): array
    {
        $context = (new CollaborationAccess($this->db))->context($pageId, $user, $ceiling);
        $working = $context['acl']->canReadWorkingFor($context['page'], $context['space'], $user, $context['actor']->can('*'), $ceiling);
        $where = 't.page_id=? AND (t.visibility<>"working" OR ?=1) AND (t.revision_number IS NULL OR r.was_published=1 OR ?=1)';
        $params = [$pageId, (int) $working, (int) $working];
        $from = ' FROM page_tasks t LEFT JOIN page_revisions r ON r.page_id=t.page_id AND r.revision_number=t.revision_number';
        $count = $this->db->fetchOne('SELECT COUNT(*) AS total,SUM(t.status NOT IN ("done","cancelled")) AS open' . $from . ' WHERE ' . $where, $params);
        $page = max(1, min(1000000, $page)); $perPage = max(1, min(100, $perPage));
        $rows = $this->db->fetchAll('SELECT t.*,a.username AS author,u.username AS assignee' . $from . ' INNER JOIN users a ON a.id=t.author_id LEFT JOIN users u ON u.id=t.assignee_id WHERE ' . $where . ' ORDER BY (t.status IN ("done","cancelled")),t.due_date IS NULL,t.due_date,t.id LIMIT ' . $perPage . ' OFFSET ' . (($page-1)*$perPage), $params);
        return ['data' => array_map(fn ($row) => $this->decorate($row, $context, $user), $rows), 'meta' => ['total' => (int) $count['total'], 'open' => (int) $count['open'], 'page' => $page, 'per_page' => $perPage],
            'can_create' => $context['actor']->can('task.create') && $context['space']['status'] === 'active' && $context['page']['status'] !== 'archived'];
    }

    public function create(int $pageId, int $user, array $input, ?array $ceiling = null, ?Request $request = null): array
    {
        return (new CollaborationOperation($this->db))->run($user, 'task.create', ['page_id' => $pageId] + $input, function () use ($pageId, $user, $input, $ceiling, $request): array {
            $thread = CollaborationAccess::positive($input['thread_id'] ?? null, true);
            $revision = CollaborationAccess::positive($input['revision_number'] ?? null, true);
            $discussion = $thread === null ? null : (new CommentService($this->db))->read($thread, $user, $ceiling, $pageId, true);
            if ($discussion !== null) {
                if ((int) $discussion['thread_id'] !== $thread || $discussion['anchor_json'] === null) throw new \InvalidArgumentException('Zadanie wymaga głównego wątku dyskusji.');
                if ($revision !== null && $revision !== (int) $discussion['revision_number']) throw new \InvalidArgumentException('Zadanie i dyskusja dotyczą różnych rewizji.');
                $revision = (int) $discussion['revision_number'];
            }
            $context = (new CollaborationAccess($this->db))->context($pageId, $user, $ceiling, $revision, ($discussion['visibility'] ?? '') === 'working', true);
            if (!$context['actor']->can('task.create')) throw new \DomainException('Nie masz prawa tworzenia zadań.');
            $visibility = $discussion['visibility'] ?? ($revision === null ? 'page' : ((bool) $context['source']['was_published'] ? 'published' : 'working'));
            $data = $this->validate($input, ['title' => '', 'description' => '', 'assignee_id' => null, 'due_date' => null, 'status' => 'todo']);
            $this->checkAssignee($data['assignee_id'], $pageId, $revision, $visibility, $user);
            $id = $this->db->insert('INSERT INTO page_tasks (page_id,thread_id,revision_number,visibility,title,description,author_id,assignee_id,due_date,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?,?,?,UTC_TIMESTAMP(),UTC_TIMESTAMP())', [$pageId,$thread,$revision,$visibility,$data['title'],$data['description'],$user,$data['assignee_id'],$data['due_date'],$data['status']]);
            $this->history($id, $user, 1, ['created' => $data]);
            $row = $this->read($id, $user, $ceiling, $pageId);
            $this->notify($row, $context, $user, 'task.assigned', 'task:' . $id . ':1', [$data['assignee_id']]);
            $this->audit('TASK_CREATED', $row, $user, $request, ['assignee_id' => $data['assignee_id'], 'status' => $data['status']]);
            return $row;
        }, fn ($id) => $this->read($id, $user, $ceiling, $pageId));
    }

    public function update(int $id, int $user, array $input, ?array $ceiling = null, ?int $pageId = null, ?Request $request = null): array
    {
        return (new CollaborationOperation($this->db))->run($user, 'task.update', ['id' => $id] + $input, function () use ($id,$user,$input,$ceiling,$pageId,$request): array {
            $row = $this->read($id, $user, $ceiling, $pageId, true);
            if (CollaborationAccess::positive($input['base_version'] ?? null) !== (int) $row['version']) throw new CollaborationConflict('Zadanie zostało zmienione. Wczytaj aktualną wersję; wpisane dane pozostają w formularzu.');
            $data = $this->validate($input, $row); $changes = [];
            foreach ($data as $key => $value) if (($row[$key] === null ? null : (string) $row[$key]) !== ($value === null ? null : (string) $value)) $changes[$key] = ['from' => $row[$key], 'to' => $value];
            if (!$row['can_edit'] && (!$row['can_status'] || array_diff(array_keys($changes), ['status']) !== [])) throw new \DomainException('Możesz zmienić tylko status zadania przypisanego do Ciebie.');
            if ($changes === []) return $row;
            $context = (new CollaborationAccess($this->db))->context((int) $row['page_id'], $user, $ceiling, $row['revision_number'] === null ? null : (int) $row['revision_number'], $row['visibility'] === 'working', true);
            if (array_key_exists('assignee_id', $changes)) $this->checkAssignee($data['assignee_id'], (int) $row['page_id'], $row['revision_number'] === null ? null : (int) $row['revision_number'], $row['visibility'], $user);
            $this->db->execute('UPDATE page_tasks SET title=?,description=?,assignee_id=?,due_date=?,status=?,version=version+1,updated_at=UTC_TIMESTAMP() WHERE id=?', [$data['title'],$data['description'],$data['assignee_id'],$data['due_date'],$data['status'],$id]);
            $this->history($id, $user, (int) $row['version']+1, $changes);
            $fresh = $this->read($id, $user, $ceiling);
            $this->notify($fresh, $context, $user, isset($changes['assignee_id']) ? 'task.reassigned' : 'task.updated', 'task:' . $id . ':' . $fresh['version'], [$row['assignee_id'], $data['assignee_id'], $row['author_id']]);
            $this->audit(isset($changes['assignee_id']) ? 'TASK_ASSIGNED' : (isset($changes['status']) ? 'TASK_STATUS_CHANGED' : 'TASK_UPDATED'), $fresh, $user, $request, ['fields' => array_keys($changes), 'assignee_id' => $data['assignee_id'], 'status' => $data['status']]);
            return $fresh;
        }, fn ($resource) => $this->read($resource, $user, $ceiling, $pageId));
    }

    public function historyFor(int $id, int $user, ?array $ceiling = null, int $page = 1): array
    {
        $this->read($id, $user, $ceiling);
        $count = (int) $this->db->fetchOne('SELECT COUNT(*) AS total FROM page_task_history WHERE task_id=?', [$id])['total'];
        $rows = $this->db->fetchAll('SELECT h.*,u.username AS actor FROM page_task_history h INNER JOIN users u ON u.id=h.actor_id WHERE task_id=? ORDER BY version DESC LIMIT 25 OFFSET ' . ((max(1,min(1000000,$page))-1)*25), [$id]);
        foreach ($rows as &$row) { $row['changes'] = json_decode($row['changes_json'], true, 512, JSON_THROW_ON_ERROR); unset($row['changes_json']); }
        return ['data' => $rows, 'meta' => ['total' => $count, 'page' => $page, 'per_page' => 25]];
    }

    public function assignees(int $pageId, int $user, ?int $revision = null, ?array $ceiling = null, string $query = ''): array
    {
        $context = (new CollaborationAccess($this->db))->context($pageId, $user, $ceiling, $revision);
        if (!$context['actor']->can('task.create') && !$context['actor']->can('task.manage')) throw new \DomainException('Nie masz prawa przypisywania zadań.');
        // The existing authenticated search catalog exposes active usernames, never email addresses or account secrets.
        return (new CollaborationAudience($this->db))->eligible($context, $revision, $revision !== null && !(bool) $context['source']['was_published'], $query);
    }

    private function decorate(array $row, array $context, int $user): array
    {
        $mutable = $context['space']['status'] === 'active' && $context['page']['status'] !== 'archived';
        $row['can_edit'] = $mutable && ($context['actor']->can('task.manage') || ((int) $row['author_id'] === $user && $context['actor']->can('task.create')));
        $row['can_status'] = $row['can_edit'] || ($mutable && (int) $row['assignee_id'] === $user && $context['actor']->can('task.update_own'));
        $row['read_only'] = !$mutable;
        return $row;
    }
    private function validate(array $input, array $old): array
    {
        $title = $input['title'] ?? $old['title']; $description = $input['description'] ?? $old['description']; $status = $input['status'] ?? $old['status'];
        if (!is_string($title) || trim($title) === '' || mb_strlen(trim($title)) > 255 || !is_string($description) || mb_strlen($description) > 10000 || !in_array($status, self::STATUSES, true)) throw new \InvalidArgumentException('Sprawdź tytuł (1–255 znaków), opis (do 10000 znaków) i status.');
        $due = array_key_exists('due_date', $input) ? $input['due_date'] : $old['due_date'];
        if ($due === '') $due = null;
        if ($due !== null && (!is_string($due) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $due) || !checkdate((int) substr($due,5,2), (int) substr($due,8,2), (int) substr($due,0,4)))) throw new \InvalidArgumentException('Termin musi być poprawną datą YYYY-MM-DD.');
        return ['title' => trim($title), 'description' => trim($description), 'status' => $status, 'due_date' => $due, 'assignee_id' => CollaborationAccess::positive(array_key_exists('assignee_id',$input) ? $input['assignee_id'] : $old['assignee_id'], true)];
    }
    private function checkAssignee(?int $assignee, int $page, ?int $revision, string $visibility, int $actor): void
    {
        if ($assignee === null) return;
        try { (new CollaborationAccess($this->db))->context($page, $assignee, null, $revision, $visibility === 'working'); }
        catch (\OutOfBoundsException|\DomainException) { throw new \InvalidArgumentException('Osoba odpowiedzialna musi mieć aktywne konto i dostęp do tej strony oraz rewizji.'); }
    }
    private function history(int $id, int $actor, int $version, array $changes): void
    {
        $this->db->execute('INSERT INTO page_task_history (task_id,actor_id,version,changes_json,created_at) VALUES (?,?,?,?,UTC_TIMESTAMP())', [$id,$actor,$version,json_encode($changes, JSON_THROW_ON_ERROR)]);
    }
    private function notify(array $row, array $context, int $actor, string $event, string $key, array $recipients): void
    {
        $url = '/spaces/' . rawurlencode($context['space']['space_key']) . '/pages/' . rawurlencode($context['page']['slug']) . ($row['visibility'] === 'working' ? '/review' : '') . '#task-' . $row['id'];
        (new PageEngagementService($this->db))->notifyWatchers((int) $row['page_id'], (int) $context['space']['id'], $actor, $event, 'Zadanie: ' . $row['title'], $url, $row['visibility'] === 'working', array_filter($recipients), ['resource_type' => 'task', 'resource_id' => $row['id'], 'revision_number' => $row['revision_number'], 'event_key' => $key]);
    }
    private function audit(string $event, array $row, int $user, ?Request $request, array $data): void
    {
        (new AuditLogger($this->db))->log($event, 'task', (int) $row['id'], $user, $request ?? new Request('INTERNAL','/',[],[],[],[]), null, ['page_id' => (int) $row['page_id'], 'version' => (int) $row['version']] + $data);
    }
}
