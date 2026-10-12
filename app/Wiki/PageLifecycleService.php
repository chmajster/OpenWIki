<?php

declare(strict_types=1);
namespace OpenWiki\Wiki;

use OpenWiki\Attachments\AttachmentService;
use OpenWiki\Audit\AuditLogger;
use OpenWiki\Core\Database;
use OpenWiki\Core\Request;
use OpenWiki\Webhooks\WebhookService;

/** Hierarchy, ACL, audit and events form one atomic domain operation for UI and API. */
final class PageLifecycleService
{
    public function __construct(private readonly Database $database, private readonly string $basePath) {}

    public function trash(array $space, int $pageId, int $actorId, callable $can, Request $request): array
    {
        return $this->change('trash', $space, $pageId, $actorId, $can, $request);
    }

    public function restore(array $space, int $pageId, int $actorId, callable $can, Request $request): array
    {
        return $this->change('restore', $space, $pageId, $actorId, $can, $request);
    }

    public function destroy(array $space, int $pageId, int $actorId, callable $can, Request $request): array
    {
        if ($this->database->pdo()->inTransaction()) throw new \LogicException('Permanent deletion must own its transaction before purging attachment files.');
        $result = $this->change('destroy', $space, $pageId, $actorId, $can, $request);
        (new AttachmentService($this->database, $this->basePath))->purgeStorageKeys($result['storage_keys']);
        unset($result['storage_keys']);
        return $result;
    }

    private function change(string $operation, array $space, int $pageId, int $actorId, callable $can, Request $request): array
    {
        return $this->database->transaction(function (Database $db) use ($operation, $space, $pageId, $actorId, $can, $request): array {
            $space = $db->fetchOne('SELECT * FROM spaces WHERE id = ? AND deleted_at IS NULL FOR UPDATE', [(int) $space['id']]);
            if ($space === null) throw new \InvalidArgumentException('Space not found.');
            $rows = $db->fetchAll('SELECT id, space_id, parent_id, inherit_acl, owner_id, author_id, title, slug, status, deleted_at, trash_batch_id
                FROM pages WHERE space_id = ? ORDER BY id LIMIT 50001 FOR UPDATE', [(int) $space['id']]);
            if (count($rows) > 50000) throw new \DomainException('This space exceeds the supported size for an atomic subtree operation.');
            $pages = array_column($rows, null, 'id');
            $root = $pages[$pageId] ?? null;
            if ($root === null || ($operation === 'trash') !== ($root['deleted_at'] === null)) {
                throw new \InvalidArgumentException('Page not found in the required state.');
            }
            $children = [];
            foreach ($pages as $page) $children[(int) ($page['parent_id'] ?? 0)][] = (int) $page['id'];
            $subtree = []; $stack = [$pageId];
            while ($stack !== []) {
                $id = array_pop($stack);
                if (isset($subtree[$id])) throw new \DomainException('The page hierarchy contains a cycle.');
                $subtree[$id] = $pages[$id];
                foreach ($children[$id] ?? [] as $child) $stack[] = $child;
            }
            $affected = array_filter($subtree, static fn (array $page): bool => match ($operation) {
                'trash' => $page['deleted_at'] === null,
                'restore' => $page['deleted_at'] !== null && ($root['trash_batch_id'] === null
                    ? (int) $page['id'] === $pageId : $page['trash_batch_id'] === $root['trash_batch_id']),
                default => true,
            });
            $restoredSlugs = [];
            if ($operation === 'restore') {
                foreach ($pages as $other) {
                    if ($other['deleted_at'] === null || isset($affected[(int) $other['id']])) {
                        $key = mb_strtolower($other['slug']);
                        $restoredSlugs[$key] = ($restoredSlugs[$key] ?? 0) + 1;
                    }
                }
            }
            foreach ($affected as $page) {
                if (!$can('page.delete', $page)) throw new \DomainException('You do not have permission to manage every page in this subtree.');
                if ($operation === 'restore') {
                    PageStatusPolicy::assertAllowed((string) $page['status'], $page, $can);
                    $parentId = (int) ($page['parent_id'] ?? 0); $visited = [];
                    while ($parentId > 0) {
                        if (isset($visited[$parentId]) || !isset($pages[$parentId])) throw new \DomainException('The parent hierarchy cannot be restored safely.');
                        $visited[$parentId] = true;
                        if ($pages[$parentId]['deleted_at'] !== null && !isset($affected[$parentId])) {
                            throw new \InvalidArgumentException('Restore the parent subtree first.');
                        }
                        $parentId = (int) ($pages[$parentId]['parent_id'] ?? 0);
                    }
                    if (($restoredSlugs[mb_strtolower($page['slug'])] ?? 0) > 1) {
                        throw new \InvalidArgumentException('Cannot restore this subtree because a slug is already in use.');
                    }
                }
                if ($operation === 'destroy' && $page['deleted_at'] === null) {
                    throw new \DomainException('Move every descendant to trash before permanent deletion.');
                }
            }
            // Reject legacy cross-space children rather than letting a foreign key detach them.
            $ids = array_keys($subtree);
            foreach (array_chunk($ids, 500) as $chunk) {
                if ($db->fetchOne('SELECT id FROM pages WHERE parent_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ') AND space_id <> ? LIMIT 1 FOR UPDATE', [...$chunk, (int) $space['id']]) !== null) {
                    throw new \DomainException('The subtree contains an invalid cross-space relationship.');
                }
            }
            $batch = $operation === 'trash' ? $this->batchId() : $root['trash_batch_id'];
            $keys = [];
            if ($operation === 'destroy') {
                foreach (array_chunk(array_keys($affected), 500) as $chunk) {
                    $keys = array_merge($keys, array_column($db->fetchAll('SELECT av.storage_key FROM attachment_versions av INNER JOIN attachments a ON a.id = av.attachment_id WHERE a.page_id IN (' . implode(',', array_fill(0, count($chunk), '?')) . ')', $chunk), 'storage_key'));
                }
            }
            $audit = new AuditLogger($db); $events = new WebhookService($db);
            // Descendants precede their parent when purging, so no surviving node is detached.
            foreach (array_reverse($affected, true) as $page) {
                $id = (int) $page['id'];
                if ($operation === 'trash') $db->execute('UPDATE pages SET deleted_at = UTC_TIMESTAMP(), trash_batch_id = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?', [$batch, $id]);
                elseif ($operation === 'restore') $db->execute('UPDATE pages SET deleted_at = NULL, trash_batch_id = NULL, updated_at = UTC_TIMESTAMP() WHERE id = ?', [$id]);
                else $db->execute('DELETE FROM pages WHERE id = ?', [$id]);
                $action = match ($operation) { 'trash' => 'PAGE_DELETED', 'restore' => 'PAGE_RESTORED', default => 'PAGE_PERMANENTLY_DELETED' };
                $audit->log($action, 'page', $id, $actorId, $request,
                    ['title' => $page['title'], 'slug' => $page['slug'], 'status' => $page['status'], 'deleted_at' => $page['deleted_at']],
                    ['deleted' => $operation !== 'restore', 'permanent' => $operation === 'destroy', 'trash_batch_id' => $batch, 'subtree_root_id' => $pageId]);
                $events->queue($operation === 'restore' ? 'page.updated' : 'page.deleted',
                    ['id' => $id, 'space_id' => (int) $space['id'], 'title' => $page['title'], 'slug' => $page['slug'],
                        'operation' => $operation, 'actor_id' => $actorId, 'trash_batch_id' => $batch, 'subtree_root_id' => $pageId]);
            }
            (new WikiMetadataService($db))->refreshSpaceLinks((int) $space['id']);
            return ['id' => $pageId, 'affected_ids' => array_keys($affected), 'trash_batch_id' => $batch, 'storage_keys' => $keys];
        });
    }

    private function batchId(): string
    {
        $bytes = random_bytes(16); $bytes[6] = chr((ord($bytes[6]) & 15) | 64); $bytes[8] = chr((ord($bytes[8]) & 63) | 128);
        $hex = bin2hex($bytes);
        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-' . substr($hex, 16, 4) . '-' . substr($hex, 20);
    }
}
