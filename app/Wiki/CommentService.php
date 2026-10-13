<?php
declare(strict_types=1);
namespace OpenWiki\Wiki;

use OpenWiki\Core\Database;
use OpenWiki\Core\Request;
use OpenWiki\Audit\AuditLogger;

final class CommentService
{
    public function __construct(private readonly Database $database) {}

    /** General comments keep their existing visibility and are not moved into the fragment panel. */
    public function listForPage(int $pageId, ?int $user = null, ?array $ceiling = null): array
    {
        (new CollaborationAccess($this->database))->context($pageId, $user, $ceiling);
        return $this->database->fetchAll('SELECT c.*, u.username, u.first_name, u.last_name FROM comments c INNER JOIN users u ON u.id = c.author_id WHERE c.page_id = ? AND c.deleted_at IS NULL AND c.visibility = "page" AND c.revision_number IS NULL ORDER BY c.created_at, c.id', [$pageId]);
    }

    public function read(int $id, ?int $user, ?array $ceiling = null, ?int $expectedPage = null, bool $mutation = false): array
    {
        $row = $this->database->fetchOne('SELECT c.*, u.username, u.first_name, u.last_name FROM comments c INNER JOIN users u ON u.id = c.author_id WHERE c.id = ? AND c.deleted_at IS NULL', [$id]);
        if ($row === null || ($expectedPage !== null && (int) $row['page_id'] !== $expectedPage)) throw new \OutOfBoundsException('Nie znaleziono dostępnego komentarza.');
        $root = $row['thread_id'] === null || (int) $row['thread_id'] === $id ? $row : $this->database->fetchOne('SELECT * FROM comments WHERE id = ? AND page_id = ?', [$row['thread_id'], $row['page_id']]);
        if ($root === null || ($root['anchor_json'] !== null && $root['deleted_at'] !== null)) throw new \OutOfBoundsException('Nie znaleziono dostępnej dyskusji.');
        (new CollaborationAccess($this->database))->context((int) $row['page_id'], $user, $ceiling, $root['revision_number'] === null ? null : (int) $root['revision_number'], $root['visibility'] === 'working', $mutation);
        if ($mutation) {
            $fresh = $this->database->fetchOne('SELECT * FROM comments WHERE id=? AND deleted_at IS NULL FOR UPDATE', [$id]);
            if ($fresh === null) throw new \OutOfBoundsException('Nie znaleziono dostępnego komentarza.');
            $row = array_replace($row, $fresh);
            $root = $this->database->fetchOne('SELECT * FROM comments WHERE id=? FOR UPDATE', [$root['id']]);
            if ($root === null || ($root['anchor_json'] !== null && $root['deleted_at'] !== null)) throw new \OutOfBoundsException('Nie znaleziono dostępnej dyskusji.');
        }
        $row['thread_status'] = $root['thread_status'];
        return $row;
    }

    public function threads(int $pageId, int $displayRevision, ?int $user, ?array $ceiling = null, string $status = 'all', int $page = 1, int $perPage = 25): array
    {
        if (!in_array($status, ['all','open','resolved'], true)) throw new \InvalidArgumentException('Nieprawidłowy filtr dyskusji.');
        $context = (new CollaborationAccess($this->database))->context($pageId, $user, $ceiling, $displayRevision);
        $working = $context['acl']->canReadWorkingFor($context['page'], $context['space'], $user, $context['actor']?->can('*') ?? false, $ceiling);
        $where = 'c.page_id = ? AND c.deleted_at IS NULL AND c.anchor_json IS NOT NULL AND (c.visibility <> "working" OR ? = 1) AND (r.was_published = 1 OR ? = 1)';
        $params = [$pageId, (int) $working, (int) $working];
        if ($status !== 'all') { $where .= ' AND c.thread_status = ?'; $params[] = $status; }
        $join = ' FROM comments c INNER JOIN page_revisions r ON r.page_id = c.page_id AND r.revision_number = c.revision_number';
        $total = (int) $this->database->fetchOne('SELECT COUNT(*) AS total' . $join . ' WHERE ' . $where, $params)['total'];
        $perPage = max(1, min(100, $perPage)); $page = max(1, min(1000000, $page));
        $rows = $this->database->fetchAll('SELECT c.*, u.username, u.first_name, u.last_name' . $join . ' INNER JOIN users u ON u.id = c.author_id WHERE ' . $where . ' ORDER BY c.created_at, c.id LIMIT ' . $perPage . ' OFFSET ' . (($page - 1) * $perPage), $params);
        $text = CommentAnchor::text($context['source']['content_html']);
        $ids = array_column($rows, 'id'); $replies = [];
        if ($ids !== []) foreach ($this->database->fetchAll('SELECT c.*, u.username, u.first_name, u.last_name FROM comments c INNER JOIN users u ON u.id = c.author_id WHERE c.thread_id IN (' . implode(',', array_map('intval', $ids)) . ') AND c.id <> c.thread_id AND c.deleted_at IS NULL ORDER BY c.created_at, c.id') as $reply) $replies[$reply['thread_id']][] = $reply;
        $url = '/spaces/' . rawurlencode($context['space']['space_key']) . '/pages/' . rawurlencode($context['page']['slug']);
        $mutable = $context['space']['status'] === 'active' && $context['page']['status'] !== 'archived';
        foreach ($rows as &$row) {
            $row['anchor'] = json_decode($row['anchor_json'], true, 512, JSON_THROW_ON_ERROR);
            $row['location'] = CommentAnchor::locate($row['anchor'], $text);
            $row['source_url'] = $url . '/revisions/' . (int) $row['revision_number'];
            $row['replies'] = $replies[$row['id']] ?? [];
            $row['can_edit'] = $mutable && $user !== null && (int) $row['author_id'] === $user && $context['actor']->can('comment.create');
            $row['can_moderate'] = $mutable && ($context['actor']?->can('comment.delete') ?? false);
            $row['can_resolve'] = $mutable && (($context['actor']?->can('comment.resolve') ?? false) || $row['can_edit']);
        }
        unset($row);
        return ['data' => $rows, 'meta' => ['total' => $total, 'page' => $page, 'per_page' => $perPage], 'text' => $text, 'hash' => hash('sha256', $text), 'revision' => $displayRevision];
    }

    public function create(int $pageId, int $spaceId, int $authorId, string $body, ?int $parentId, string $pageTitle, string $targetUrl, array $options = [], ?array $ceiling = null, ?Request $request = null): int
    {
        $result = (new CollaborationOperation($this->database))->run($authorId, 'comment.create', ['page_id' => $pageId, 'body' => $body, 'parent_id' => $parentId] + $options, function () use ($pageId, $spaceId, $authorId, $body, $parentId, $options, $ceiling, $request): array {
            $body = $this->validateBody($body);
            $parent = $parentId === null ? null : $this->read($parentId, $authorId, $ceiling, $pageId, true);
            $root = $parent === null ? null : $this->read((int) ($parent['thread_id'] ?? $parent['id']), $authorId, $ceiling, $pageId, true);
            if ($parent !== null && isset($options['anchor'])) throw new \InvalidArgumentException('Odpowiedź dziedziczy fragment dyskusji.');
            $revision = $root['revision_number'] ?? CollaborationAccess::positive($options['revision_number'] ?? null, true);
            if ($parent === null && isset($options['anchor']) && $revision === null) throw new \InvalidArgumentException('Zaznaczenie wymaga numeru rewizji.');
            if ($parent === null && $revision !== null && !isset($options['anchor'])) throw new \InvalidArgumentException('Dyskusja rewizji wymaga zaznaczenia fragmentu.');
            if ($parent !== null && isset($options['revision_number']) && (int) $options['revision_number'] !== (int) $revision) throw new \InvalidArgumentException('Odpowiedź dotyczy innej rewizji.');
            $context = (new CollaborationAccess($this->database))->context($pageId, $authorId, $ceiling, $revision === null ? null : (int) $revision, ($root['visibility'] ?? '') === 'working', true);
            if ((int) $context['space']['id'] !== $spaceId) throw new \InvalidArgumentException('Komentarz i strona nie należą do tej samej przestrzeni.');
            if (!$context['actor']->can('comment.create')) throw new \DomainException('Nie masz prawa dodawania komentarzy.');
            $visibility = $root['visibility'] ?? ($revision === null ? 'page' : ((bool) $context['source']['was_published'] ? 'published' : 'working'));
            $anchor = isset($options['anchor']) ? CommentAnchor::create(CommentAnchor::text($context['source']['content_html']), is_array($options['anchor']) ? $options['anchor'] : []) : null;
            $id = $this->database->insert('INSERT INTO comments (page_id, parent_id, author_id, body_html, thread_id, revision_number, visibility, anchor_json, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP())', [$pageId, $parentId, $authorId, $this->renderBody($body), $root['id'] ?? null, $revision, $visibility, $anchor === null ? null : json_encode($anchor, JSON_THROW_ON_ERROR)]);
            if ($root === null) $this->database->execute('UPDATE comments SET thread_id = id WHERE id = ?', [$id]);
            $row = $this->read($id, $authorId, $ceiling, $pageId);
            $this->notify($row, $authorId, $body, $parent === null ? 'comment.created' : 'reply', 'comment:' . $id . ':create', true, $parent['author_id'] ?? null);
            $this->audit('COMMENT_CREATED', $id, $authorId, $request, ['page_id' => $pageId, 'revision' => $revision, 'thread_id' => $root['id'] ?? $id]);
            (new \OpenWiki\Webhooks\WebhookService($this->database))->queue('comment.created', ['id' => $id, 'page_id' => $pageId, 'space_id' => $spaceId, 'author_id' => $authorId, 'parent_id' => $parentId]);
            return $row;
        }, fn (int $id): array => $this->read($id, $authorId, $ceiling, $pageId));
        return (int) $result['id'];
    }

    public function update(int $id, int $actor, string $body, ?int $expectedPage = null, ?array $ceiling = null, array $input = [], ?Request $request = null): bool
    {
        try { $this->read($id, $actor, $ceiling, $expectedPage); } catch (\OutOfBoundsException) { return false; }
        (new CollaborationOperation($this->database))->run($actor, 'comment.update', ['id' => $id, 'body' => $body] + $input, function () use ($id, $actor, $body, $expectedPage, $ceiling, $input, $request): array {
            $row = $this->read($id, $actor, $ceiling, $expectedPage, true);
            if ((int) $row['author_id'] !== $actor) throw new \RuntimeException('COMMENT_EDIT_FORBIDDEN');
            $context = (new CollaborationAccess($this->database))->context((int) $row['page_id'], $actor, $ceiling, null, false, true);
            if (!$context['actor']->can('comment.create')) throw new \DomainException('Nie masz prawa edytowania komentarzy.');
            $this->checkVersion($row, $input, $row['revision_number'] !== null);
            $validated = $this->validateBody($body);
            $old = html_entity_decode(strip_tags(str_replace('<br>', "\n", $row['body_html'])), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $this->database->execute('UPDATE comments SET body_html = ?, version = version + 1, updated_at = UTC_TIMESTAMP() WHERE id = ?', [$this->renderBody($validated), $id]);
            $newMentions = array_diff($this->mentions($validated), $this->mentions($old));
            if ($newMentions !== []) $this->notify($row, $actor, implode(' ', array_map(fn ($name) => '@' . $name, $newMentions)), 'mention', 'comment:' . $id . ':edit:' . ($row['version'] + 1), false);
            $this->audit('COMMENT_UPDATED', $id, $actor, $request, ['page_id' => (int) $row['page_id'], 'version' => $row['version'] + 1]);
            return $this->read($id, $actor, $ceiling, $expectedPage);
        }, fn (int $resource): array => $this->read($resource, $actor, $ceiling, $expectedPage));
        return true;
    }

    public function delete(int $id, int $actor, bool $canModerate, ?int $expectedPage = null, ?array $ceiling = null, ?Request $request = null): bool
    {
        return $this->database->transaction(function () use ($id, $actor, $expectedPage, $ceiling, $request): bool {
            try { $row = $this->read($id, $actor, $ceiling, $expectedPage, true); } catch (\OutOfBoundsException) { return false; }
            $context = (new CollaborationAccess($this->database))->context((int) $row['page_id'], $actor, $ceiling, null, false, true);
            if ((int) $row['author_id'] !== $actor && !$context['actor']->can('comment.delete')) throw new \RuntimeException('COMMENT_DELETE_FORBIDDEN');
            if ((int) $row['author_id'] === $actor && !$context['actor']->can('comment.create') && !$context['actor']->can('comment.delete')) throw new \DomainException('Nie masz prawa usuwania komentarzy.');
            $this->database->execute('UPDATE comments SET deleted_at = UTC_TIMESTAMP(), updated_at = UTC_TIMESTAMP(), version = version + 1 WHERE id = ?', [$id]);
            $this->audit('COMMENT_DELETED', $id, $actor, $request, ['page_id' => (int) $row['page_id']]);
            return true;
        });
    }

    public function resolve(int $id, int $actor, array $input, ?array $ceiling = null, ?Request $request = null): array
    {
        return (new CollaborationOperation($this->database))->run($actor, 'comment.resolve', ['id' => $id] + $input, function () use ($id, $actor, $input, $ceiling, $request): array {
            $row = $this->read($id, $actor, $ceiling, null, true);
            if ($row['anchor_json'] === null || (int) $row['thread_id'] !== $id) throw new \InvalidArgumentException('Status można zmienić dla głównej dyskusji fragmentu.');
            $context = (new CollaborationAccess($this->database))->context((int) $row['page_id'], $actor, $ceiling, null, false, true);
            if (!$context['actor']->can('comment.resolve') && !((int) $row['author_id'] === $actor && $context['actor']->can('comment.create'))) throw new \DomainException('Nie masz prawa zmiany statusu dyskusji.');
            $this->checkVersion($row, $input, true); $status = $input['status'] ?? '';
            if (!in_array($status, ['open','resolved'], true)) throw new \InvalidArgumentException('Nieprawidłowy status dyskusji.');
            if ($status !== $row['thread_status']) {
                $this->database->execute('UPDATE comments SET thread_status = ?, version = version + 1, updated_at = UTC_TIMESTAMP() WHERE id = ?', [$status, $id]);
                $this->notify($row, $actor, '', 'discussion.' . $status, 'thread:' . $id . ':' . ($row['version'] + 1));
                $this->audit($status === 'resolved' ? 'DISCUSSION_RESOLVED' : 'DISCUSSION_REOPENED', $id, $actor, $request, ['page_id' => (int) $row['page_id'], 'version' => $row['version'] + 1]);
            }
            return $this->read($id, $actor, $ceiling);
        }, fn (int $resource): array => $this->read($resource, $actor, $ceiling));
    }

    private function checkVersion(array $row, array $input, bool $required): void
    {
        if (!$required && !isset($input['base_version'])) return;
        if (CollaborationAccess::positive($input['base_version'] ?? null) !== (int) $row['version']) throw new CollaborationConflict('Komentarz został zmieniony. Wczytaj aktualną dyskusję; wpisana treść pozostaje w formularzu.');
    }

    private function notify(array $row, int $actor, string $body, string $event, string $key, bool $watchers = true, ?int $parentAuthor = null): void
    {
        $context = (new CollaborationAccess($this->database))->context((int) $row['page_id'], $actor, null, $row['revision_number'] === null ? null : (int) $row['revision_number'], $row['visibility'] === 'working');
        $recipients = $parentAuthor === null ? [] : [$parentAuthor]; $names = $this->mentions($body);
        if ($names !== []) $recipients = [...$recipients, ...array_column($this->database->fetchAll('SELECT id FROM users WHERE username IN (' . implode(',', array_fill(0, count($names), '?')) . ') AND status = "active" AND deleted_at IS NULL', $names), 'id')];
        if ($watchers && $row['thread_id'] !== null) $recipients = [...$recipients, ...array_column($this->database->fetchAll('SELECT DISTINCT author_id FROM comments WHERE thread_id = ? AND deleted_at IS NULL', [$row['thread_id']]), 'author_id')];
        $url = '/spaces/' . rawurlencode($context['space']['space_key']) . '/pages/' . rawurlencode($context['page']['slug']);
        if ($row['visibility'] === 'working') $url .= '/review';
        $url .= $row['revision_number'] === null ? '#comment-' . $row['id'] : '#discussion-' . ($row['thread_id'] ?? $row['id']);
        (new PageEngagementService($this->database))->notifyWatchers((int) $row['page_id'], (int) $context['space']['id'], $actor, $event, 'Dyskusja: ' . $context['source']['title'], $url, $row['visibility'] === 'working', $recipients,
            ['revision_number' => $row['revision_number'], 'resource_type' => 'comment', 'resource_id' => (int) $row['id'], 'event_key' => $key, 'include_watchers' => $watchers]);
    }

    private function validateBody(string $body): string
    {
        $body = trim($body);
        if ($body === '' || mb_strlen($body) > 10000) throw new \InvalidArgumentException('Komentarz musi zawierać od 1 do 10000 znaków.');
        return $body;
    }
    private function renderBody(string $body): string { return nl2br(htmlspecialchars($body, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false); }
    private function mentions(string $body): array { preg_match_all('/(?<![A-Za-z0-9._-])@([A-Za-z0-9._-]{3,100})/u', $body, $matches); return array_slice(array_values(array_unique($matches[1] ?? [])), 0, 20); }
    private function audit(string $action, int $id, int $actor, ?Request $request, array $data): void
    {
        (new AuditLogger($this->database))->log($action, 'comment', $id, $actor, $request ?? new Request('INTERNAL', '/', [], [], [], []), null, $data);
    }
}
