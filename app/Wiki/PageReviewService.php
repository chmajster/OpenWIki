<?php
declare(strict_types=1);
namespace OpenWiki\Wiki;

use OpenWiki\Audit\AuditLogger;
use OpenWiki\Core\Database;
use OpenWiki\Core\Request;
use OpenWiki\Permissions\PageAclService;
use OpenWiki\Repositories\PageRepository;
use OpenWiki\Spaces\SpaceActor;
use OpenWiki\Webhooks\WebhookService;

/** pages contains the public projection; page_revisions contains the working head. */
final class PageReviewService
{
    public const STATES = ['draft' => 'Szkic', 'in_review' => 'Do recenzji', 'approved' => 'Zatwierdzony', 'published' => 'Opublikowany', 'rejected' => 'Odrzucony', 'archived' => 'Zarchiwizowany'];
    public function __construct(private readonly Database $db) {}

    public function knowledge(array $input, ?array $head, int $author): array
    {
        $owner = $input['content_owner_id'] ?? $head['content_owner_id'] ?? $author;
        $owner = filter_var($owner, FILTER_VALIDATE_INT);
        if ($owner === false || $owner < 1 || $this->db->fetchOne('SELECT id FROM users WHERE id = ? AND status = "active" AND deleted_at IS NULL', [$owner]) === null) throw new \InvalidArgumentException('Właściciel merytoryczny musi mieć aktywne konto.');
        $date = $input['review_due_at'] ?? $head['review_due_at'] ?? null;
        if ($date === '') $date = null;
        if ($date !== null && (!is_string($date) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $date) || ($parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date)) === false || $parsed->format('Y-m-d') !== $date)) throw new \InvalidArgumentException('Nieprawidłowy termin przeglądu.');
        return ['content_owner_id' => (int) $owner, 'review_due_at' => $date];
    }

    public function snapshotFiles(int $id, int $version): void
    {
        $this->db->execute('INSERT INTO page_revision_attachments (page_id, revision_number, attachment_id, version_number, name) SELECT page_id, ?, id, current_version, name FROM attachments WHERE page_id = ? AND deleted_at IS NULL', [$version, $id]);
    }

    public function completeSave(array $space, ?array $previous, array $head, array $knowledge): array
    {
        $id = (int) $head['id']; $version = (int) $head['version'];
        $published = !(bool) $space['review_workflow'] && $head['status'] === 'published';
        $state = $published ? 'published' : ($head['status'] === 'archived' ? 'archived' : 'draft');
        $this->snapshotFiles($id, $version);
        $this->db->execute('UPDATE page_revisions SET knowledge_json = ?, was_published = ? WHERE page_id = ? AND revision_number = ?', [json_encode($knowledge, JSON_THROW_ON_ERROR), (int) $published, $id, $version]);
        $pointer = $published ? $version : ($previous['published_revision'] ?? null);
        $this->db->execute('UPDATE pages SET published_revision = ?, workflow_state = ?, content_owner_id = ?, review_due_at = ? WHERE id = ?', [$pointer, $state, $knowledge['content_owner_id'], $knowledge['review_due_at'], $id]);
        if (!$published && $pointer !== null) {
            // Restore only public document fields. Structure and ACL are separate operations.
            $public = $this->db->fetchOne('SELECT * FROM page_revisions WHERE page_id = ? AND revision_number = ?', [$id, $pointer]) ?? throw new \RuntimeException('Brak opublikowanej rewizji.');
            $this->project($id, $public, $head['status'] === 'archived' ? 'archived' : 'published', $previous);
            $this->db->execute('UPDATE pages SET content_owner_id = ?, review_due_at = ? WHERE id = ?', [$previous['content_owner_id'], $previous['review_due_at'], $id]);
            $this->db->execute('UPDATE pages SET published_at = ?, updated_at = ? WHERE id = ?', [$previous['published_at'], $previous['updated_at'], $id]);
        }
        $head['published_revision'] = $pointer; $head['workflow_state'] = $state;
        if (!$published && $pointer !== null) $head['published_at'] = $previous['published_at'];
        return array_replace($head, $knowledge);
    }

    private function project(int $id, array $revision, string $status = 'published', ?array $fallback = null): void
    {
        $fallback ??= $this->db->fetchOne('SELECT * FROM pages WHERE id = ?', [$id]);
        $this->db->execute('UPDATE pages SET title = ?, slug = ?, content_html = ?, content_markdown = ?, content_text = ?, content_format = ?, document_json = ?, status = ? WHERE id = ?', [$revision['title'], $revision['slug'] ?? $fallback['slug'], $revision['content_html'], $revision['content_markdown'], $revision['content_text'], $revision['content_format'], $revision['document_json'], $status, $id]);
        $metadata = new WikiMetadataService($this->db);
        if ($revision['tags_json'] !== null) $metadata->syncTags($id, json_decode($revision['tags_json'], true, 512, JSON_THROW_ON_ERROR));
        elseif (isset($fallback['tags'])) $metadata->syncTags($id, $fallback['tags']);
        $space = $this->db->fetchOne('SELECT s.* FROM spaces s INNER JOIN pages p ON p.space_id = s.id WHERE p.id = ?', [$id]);
        $references = $metadata->decorateWikiLinks((int) $space['id'], $space['space_key'], $revision['content_html'])['references'];
        $metadata->syncLinks($id, (int) $space['id'], $references);
    }

    public function transition(int $id, array $input, int $user, Request $request, ?array $ceiling = null): array
    {
        $number = filter_var($input['revision'] ?? null, FILTER_VALIDATE_INT);
        $action = $input['action'] ?? null;
        $comment = $input['comment'] ?? '';
        if ($number === false || $number < 1 || !in_array($action, ['submit', 'approve', 'reject', 'publish', 'verify'], true) || !is_string($comment) || mb_strlen($comment) > 5000) throw new \InvalidArgumentException('Nieprawidłowa decyzja lub numer rewizji.');
        if (in_array($action, ['approve', 'reject'], true) && trim($comment) === '') throw new \InvalidArgumentException('Decyzja recenzji wymaga komentarza.');
        return $this->db->transaction(function () use ($id, $input, $number, $action, $comment, $user, $request, $ceiling): array {
            $actor = new SpaceActor($this->db, $user, $ceiling);
            $row = $this->db->fetchOne('SELECT space_id FROM pages WHERE id = ? AND deleted_at IS NULL', [$id]) ?? throw new \OutOfBoundsException('Nie znaleziono dokumentu.');
            $space = $this->db->fetchOne('SELECT * FROM spaces WHERE id = ? AND deleted_at IS NULL FOR UPDATE', [$row['space_id']]) ?? throw new \OutOfBoundsException('Nie znaleziono przestrzeni.');
            $page = $this->db->fetchOne('SELECT * FROM pages WHERE id = ? AND deleted_at IS NULL FOR UPDATE', [$id]) ?? throw new \OutOfBoundsException('Nie znaleziono dokumentu.');
            $acl = new PageAclService($this->db, true);
            $permission = match ($action) { 'submit' => 'page.edit', 'approve', 'reject' => 'page.review', 'publish' => 'page.publish', 'verify' => 'page.verify' };
            if (!$acl->canFor($page, $space, $permission, $user, $actor->can('*'), $actor->can($permission))) throw new \DomainException('Brak uprawnienia: ' . $permission . '.');
            $state = $page['workflow_state'];
            if ($action === 'verify') {
                if ($number !== (int) $page['published_revision']) throw new \RuntimeException('EDIT_CONFLICT');
                $date = $input['review_due_at'] ?? null;
                $knowledge = $this->knowledge(['review_due_at' => $date], $page, $user);
                if ($knowledge['review_due_at'] === null || $knowledge['review_due_at'] <= gmdate('Y-m-d')) throw new \InvalidArgumentException('Wskaż przyszły termin kolejnego przeglądu.');
                $this->db->execute('UPDATE pages SET verified_at = UTC_TIMESTAMP(), verified_revision = ?, review_due_at = ?, updated_at = updated_at WHERE id = ?', [$number, $knowledge['review_due_at'], $id]);
            } else {
                if ($number !== (int) $page['version']) throw new \RuntimeException('EDIT_CONFLICT');
                $revision = (new PageRepository($this->db))->revision($id, $number) ?? throw new \OutOfBoundsException('Nie znaleziono rewizji.');
                if ($action === 'submit' && !in_array($state, ['draft', 'rejected'], true)) throw new \InvalidArgumentException('Zgłosić można szkic lub odrzuconą rewizję.');
                if (in_array($action, ['approve', 'reject'], true)) {
                    if ($state !== 'in_review') throw new \InvalidArgumentException('Rewizja nie oczekuje na recenzję.');
                    if ($action === 'approve' && !(bool) $space['allow_self_review'] && (int) $revision['author_id'] === $user) throw new \DomainException('Autor nie może zatwierdzić własnej rewizji.');
                }
                if ($action === 'publish') {
                    if ((bool) $space['review_workflow'] && $state !== 'approved') throw new \DomainException('Publikacja wymaga zatwierdzenia tej rewizji.');
                    if ($state === 'archived') throw new \DomainException('Najpierw przywróć dokument do edycji.');
                    if ($state === 'published') throw new \InvalidArgumentException('Rewizja jest już opublikowana.');
                    $this->project($id, $revision);
                    $knowledge = json_decode($revision['knowledge_json'] ?? '{}', true, 512, JSON_THROW_ON_ERROR);
                    $this->db->execute('UPDATE pages SET published_revision = ?, published_at = UTC_TIMESTAMP(), content_owner_id = ?, review_due_at = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', [$number, $knowledge['content_owner_id'] ?? $page['owner_id'], $knowledge['review_due_at'] ?? null, $id]);
                    $this->db->execute('UPDATE page_revisions SET was_published = 1 WHERE page_id = ? AND revision_number = ?', [$id, $number]);
                    (new WikiMetadataService($this->db))->refreshSpaceLinks((int) $space['id']);
                }
                $state = match ($action) { 'submit' => 'in_review', 'approve' => 'approved', 'reject' => 'rejected', 'publish' => 'published' };
                $this->db->execute('UPDATE pages SET workflow_state = ?, updated_at = updated_at WHERE id = ?', [$state, $id]);
            }
            $this->db->execute('INSERT INTO page_review_decisions (page_id, revision_number, action, actor_id, comment) VALUES (?, ?, ?, ?, ?)', [$id, $number, $action, $user, trim($comment)]);
            $after = ['revision' => $number, 'action' => $action, 'state' => $state];
            if ($action === 'verify') $after['review_due_at'] = $knowledge['review_due_at'];
            (new AuditLogger($this->db))->log('PAGE_REVIEW_' . strtoupper($action), 'page', $id, $user, $request, null, $after);
            (new WebhookService($this->db))->queue('page.' . ($action === 'publish' ? 'published' : 'review'), ['id' => $id, 'space_id' => (int) $space['id']] + $after);
            $fresh = (new PageRepository($this->db))->findById($id);
            (new PageEngagementService($this->db))->notifyWatchers($id, (int) $space['id'], $user, 'page.' . $action, $action === 'verify' ? 'Potwierdzono aktualność dokumentu' : 'Dokument: ' . self::STATES[$state], '/spaces/' . rawurlencode($space['space_key']) . '/pages/' . rawurlencode($fresh['slug']) . ($action === 'publish' || $action === 'verify' ? '' : '/review'), $action !== 'publish' && $action !== 'verify', [$revision['author_id'] ?? $page['author_id'], $fresh['content_owner_id']]);
            return $fresh;
        });
    }

    public function decisionCount(int $id): int
    {
        return (int) $this->db->fetchOne('SELECT COUNT(*) AS total FROM page_review_decisions WHERE page_id = ?', [$id])['total'];
    }

    public function decisions(int $id, int $limit = 50, int $offset = 0): array
    {
        $limit = max(1, min(100, $limit)); $offset = max(0, $offset);
        return $this->db->fetchAll('SELECT d.*, u.username FROM page_review_decisions d INNER JOIN users u ON u.id = d.actor_id WHERE d.page_id = ? ORDER BY d.id DESC LIMIT ' . $limit . ' OFFSET ' . $offset, [$id]);
    }
}
