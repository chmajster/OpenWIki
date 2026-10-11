<?php

declare(strict_types=1);

namespace OpenWiki\Wiki;

use OpenWiki\Audit\AuditLogger;
use OpenWiki\Core\Database;
use OpenWiki\Core\Request;
use OpenWiki\Images\InlineImageService;
use OpenWiki\Repositories\PageRepository;
use OpenWiki\Webhooks\WebhookService;

/** The active format is the source; HTML/text in Markdown documents are derived. */
final class PageWriteService
{
    private readonly ?InlineImageService $inlineImages;
    public function __construct(private readonly Database $database, private readonly ?string $basePath = null)
    {
        $this->inlineImages = $basePath === null ? null : new InlineImageService($database, $basePath);
    }

    public function clearImageTracking(): void { $this->inlineImages?->clearTracking(); }
    public function cleanupImages(): void { $this->inlineImages?->cleanupCreatedFiles(); }

    public function validate(array $space, ?array $current, array $input, ?callable $can = null): array
    {
        $value = static fn (string $key, mixed $default = ''): mixed => array_key_exists($key, $input)
            ? $input[$key] : ($current[$key] ?? $default);
        foreach (['title', 'slug', 'status', 'content_format', 'content_html', 'content_markdown', 'change_summary'] as $key) {
            if (isset($input[$key]) && !is_string($input[$key])) {
                throw new \InvalidArgumentException($key . ' must be a string.');
            }
        }
        $title = trim((string) $value('title'));
        if ($title === '' || mb_strlen($title) > 255) {
            throw new \InvalidArgumentException('Page title is required and may contain at most 255 characters.');
        }
        $slugInput = trim((string) $value('slug'));
        if (mb_strlen($slugInput) > 255) {
            throw new \InvalidArgumentException('Slug may contain at most 255 characters.');
        }
        $status = (string) $value('status', 'draft');
        if (!in_array($status, ['draft', 'published', 'archived'], true)) {
            throw new \InvalidArgumentException('Invalid page status.');
        }
        if ($can !== null && in_array($status, ['published', 'archived'], true)
            && !$can($status === 'published' ? 'page.publish' : 'page.archive', $current)) {
            throw new \DomainException('You do not have permission to set this page status.');
        }
        $parentValue = $value('parent_id', null);
        $parentId = ($parentValue === null || $parentValue === '') ? null : filter_var($parentValue, FILTER_VALIDATE_INT);
        if ($parentId === false || (is_int($parentId) && $parentId < 1)) {
            throw new \InvalidArgumentException('Invalid parent page.');
        }
        $repository = new PageRepository($this->database);
        if ($parentId !== null) {
            $parent = $repository->findById($parentId);
            if ($parent === null || (int) $parent['space_id'] !== (int) $space['id']) {
                throw new \InvalidArgumentException('Parent page must belong to the same space.');
            }
            if ($current === null && $can !== null && !$can('page.create', $parent)) {
                throw new \DomainException('You cannot create a child page under the selected parent.');
            }
            if ($current !== null && $repository->wouldCreateCycle((int) $current['id'], $parentId)) {
                throw new \InvalidArgumentException('The selected parent would create a cycle.');
            }
            if ($current !== null && (int) ($current['parent_id'] ?? 0) !== $parentId
                && $can !== null && !$can('page.view', $parent)) {
                throw new \DomainException('You cannot access the selected parent.');
            }
        }
        if ($current !== null && (int) ($current['parent_id'] ?? 0) !== (int) ($parentId ?? 0)
            && $can !== null && !$can('page.move', $current)) {
            throw new \DomainException('You do not have permission to move this page.');
        }
        $format = (string) $value('content_format', 'visual');
        $sourceField = $format === 'markdown' ? 'content_markdown' : 'content_html';
        if (array_key_exists($sourceField, $input) && !is_string($input[$sourceField])) {
            throw new \InvalidArgumentException('The active content source must be a string.');
        }
        if ($current !== null && $format !== $current['content_format'] && !array_key_exists($sourceField, $input)) {
            throw new \InvalidArgumentException('Changing content_format requires the converted source content.');
        }
        $content = (new ContentService())->normalize(
            $format,
            (string) $value('content_html'),
            (string) $value('content_markdown')
        );
        $metadata = new WikiMetadataService($this->database);
        $tags = $input['tags'] ?? ($current === null ? [] : array_column($metadata->tagsForPage((int) $current['id']), 'name'));
        if (!is_string($tags) && !is_array($tags)) {
            throw new \InvalidArgumentException('tags must be a string or array.');
        }
        $tags = array_column($metadata->normalizeTags($tags), 'name');
        $summary = trim((string) ($input['change_summary'] ?? ''));
        if (mb_strlen($summary) > 500) {
            throw new \InvalidArgumentException('Change summary may contain at most 500 characters.');
        }
        $baseVersion = $current === null ? 0 : filter_var($input['base_version'] ?? null, FILTER_VALIDATE_INT);
        if ($baseVersion === false || ($current !== null && $baseVersion < 1)) {
            throw new \InvalidArgumentException('base_version is required and must be a positive integer.');
        }
        return [
            'parent_id' => $parentId, 'title' => $title,
            'slug' => (new Slugger())->slug($slugInput === '' ? $title : $slugInput),
            'status' => $status, 'content_format' => $content['format'],
            'content_html' => $content['html'], 'content_markdown' => $content['markdown'],
            'content_text' => $content['text'], 'tags' => $tags,
            'change_summary' => $summary, 'base_version' => (int) $baseVersion,
        ];
    }

    public function save(
        array $space, ?array $current, array $input, int $userId,
        ?Request $request = null, ?callable $can = null, bool $allowInlineImages = false,
        ?int $restoredRevision = null
    ): array {
        $inlineImages = $this->inlineImages;
        $ownsTransaction = !$this->database->pdo()->inTransaction();
        try {
            $saved = $this->database->transaction(function () use (
                $space, $current, $input, $userId, $request, $can, $allowInlineImages, $inlineImages, $restoredRevision
            ): array {
                // Serialize hierarchy changes as well as version checks within one space.
                $this->database->fetchOne('SELECT id FROM spaces WHERE id = :id FOR UPDATE', ['id' => (int) $space['id']]);
                if ($current !== null) {
                    $base = filter_var($input['base_version'] ?? null, FILTER_VALIDATE_INT);
                    if ($base === false || $base < 1) throw new \InvalidArgumentException('Invalid base page version.');
                    $fresh = $this->database->fetchOne('SELECT * FROM pages WHERE id = :id AND deleted_at IS NULL FOR UPDATE', ['id' => (int) $current['id']]);
                    if ($fresh === null || (int) $fresh['version'] !== (int) ($input['base_version'] ?? 0)) {
                        throw new \RuntimeException('EDIT_CONFLICT');
                    }
                    $current = $fresh;
                }
                if ($can !== null && !$can($current === null ? 'page.create' : 'page.edit', $current)) {
                    throw new \DomainException('You do not have permission to save this page.');
                }
                $data = $this->validate($space, $current, $input, $can);
                $hasInlineImages = str_contains($data['content_html'], 'data:image/');
                if ($hasInlineImages && !$allowInlineImages) {
                    throw new \DomainException('Inline images require attachment.upload permission and, for API tokens, attachments:write scope.');
                }
                if ($hasInlineImages && $inlineImages === null) {
                    throw new \RuntimeException('Inline image storage is unavailable.');
                }
                $metadata = new WikiMetadataService($this->database);
                $decorated = $metadata->decorateWikiLinks((int) $space['id'], (string) $space['space_key'], $data['content_html']);
                $data['content_html'] = $decorated['html'];
                $data['space_id'] = (int) $space['id'];
                $data['author_id'] = $data['owner_id'] = $userId;
                $repository = new PageRepository($this->database);
                if ($current === null) {
                    $id = $repository->create($data);
                    $version = 1;
                } else {
                    $id = (int) $current['id'];
                    $version = $repository->update($current, $data)['version'];
                }
                if ($hasInlineImages) {
                    $materialized = $inlineImages->materializeDataImages($data['content_html'], $id, $userId);
                    foreach (['pages' => 'id = :id', 'page_revisions' => 'page_id = :id AND revision_number = :version'] as $table => $where) {
                        $params = ['html' => $materialized['html'], 'text' => $materialized['text'], 'id' => $id];
                        if ($table === 'page_revisions') $params['version'] = $version;
                        $this->database->execute('UPDATE ' . $table . ' SET content_html = :html, content_text = :text WHERE ' . $where, $params);
                    }
                }
                $metadata->syncTags($id, $data['tags']);
                $metadata->syncLinks($id, (int) $space['id'], $decorated['references']);
                $metadata->refreshSpaceLinks((int) $space['id']);
                $event = $current === null ? 'page.created' : 'page.updated';
                $action = $restoredRevision !== null ? 'PAGE_REVISION_RESTORED' : ($current === null ? 'PAGE_CREATED' : 'PAGE_UPDATED');
                $after = ['title' => $data['title'], 'slug' => $data['slug'], 'status' => $data['status'], 'version' => $version];
                if ($restoredRevision !== null) $after['restored_revision'] = $restoredRevision;
                (new AuditLogger($this->database))->log($action, 'page', $id, $userId,
                    $request ?? new Request('CLI', '/', [], [], [], []),
                    $current === null ? null : array_intersect_key($current, $after), $after);
                $payload = $after + ['id' => $id, 'space_id' => (int) $space['id'], 'author_id' => $userId];
                $webhooks = new WebhookService($this->database);
                $webhooks->queue($event, $payload);
                if ($data['status'] === 'published' && ($current['status'] ?? null) !== 'published') {
                    $webhooks->queue('page.published', $payload);
                }
                (new PageEngagementService($this->database))->notifyWatchers($id, (int) $space['id'], $userId,
                    $event, ($current === null ? 'New page: ' : 'Page updated: ') . $data['title'],
                    '/spaces/' . rawurlencode($space['space_key']) . '/pages/' . rawurlencode($data['slug']));
                // Only the exact editor's acknowledged snapshot can be removed; other tabs survive.
                if (isset($input['document_key'], $input['editor_id'], $input['draft_sequence'])) {
                    (new EditSessionService($this->database))->acknowledgeDraft((int) $space['id'], $userId,
                        (string) $input['document_key'], (string) $input['editor_id'], (int) $input['draft_sequence']);
                    if (isset($input['recovery_editor_id'], $input['recovery_sequence'])) {
                        (new EditSessionService($this->database))->acknowledgeDraft((int) $space['id'], $userId,
                            (string) $input['document_key'], (string) $input['recovery_editor_id'], (int) $input['recovery_sequence']);
                    }
                }
                return $repository->findById($id) ?? throw new \RuntimeException('Saved page cannot be resolved.');
            });
            if ($ownsTransaction) $inlineImages?->clearTracking();
            return $saved;
        } catch (\Throwable $exception) {
            $inlineImages?->cleanupCreatedFiles();
            if ($exception instanceof \PDOException && (int) ($exception->errorInfo[1] ?? 0) === 1062
                && str_contains($exception->getMessage(), 'uq_pages_space_slug')) {
                throw new \InvalidArgumentException('A page with that slug already exists in this space.', 0, $exception);
            }
            throw $exception;
        }
    }
}
