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
        $canonical = null;
        if (array_key_exists('document', $input)) {
            if (!is_array($input['document'])) throw new \InvalidArgumentException('document must be a versioned source object.');
            $canonical = (new ContentService())->document($input['document']);
            $input['content_format'] = $canonical['format'];
            $input['content_html'] = $canonical['html'];
            $input['content_markdown'] = $canonical['markdown'] ?? '';
        }
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
        if ($current !== null) PageStatusPolicy::assertAllowed($status, $current, $can);
        $candidate = array_replace($current ?? ['id' => 0, 'inherit_acl' => 1, 'space_id' => (int) $space['id']], ['parent_id' => $parentId]);
        PageStatusPolicy::assertAllowed($status, $candidate, $can);
        $format = (string) $value('content_format', 'visual');
        $sourceField = $format === 'markdown' ? 'content_markdown' : 'content_html';
        if (array_key_exists($sourceField, $input) && !is_string($input[$sourceField])) {
            throw new \InvalidArgumentException('The active content source must be a string.');
        }
        if ($current !== null && $format !== $current['content_format'] && !array_key_exists($sourceField, $input)) {
            throw new \InvalidArgumentException('Changing content_format requires the converted source content.');
        }
        $content = $canonical ?? (new ContentService())->normalize(
            $format,
            (string) $value('content_html'),
            (string) $value('content_markdown')
        );
        $metadata = new WikiMetadataService($this->database);
        $tags = $input['tags'] ?? $current['tags'] ?? ($current === null ? [] : array_column($metadata->tagsForPage((int) $current['id']), 'name'));
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
        $backup = (new ContentService())->recoveryCopy($input['conversion_backup'] ?? null);
        if (isset($input['document_key']) || isset($input['editor_id']) || isset($input['draft_sequence'])) {
            $document = $input['document_key'] ?? null;
            $editor = $input['editor_id'] ?? null;
            $sequence = filter_var($input['draft_sequence'] ?? null, FILTER_VALIDATE_INT);
            if (!is_string($document) || !is_string($editor) || !preg_match('/^[a-f0-9-]{36}$/D', $editor)
                || $sequence === false || $sequence < 1
                || ($current === null ? !preg_match('/^new:[a-f0-9-]{36}$/D', $document) : $document !== 'page:' . $current['id'])) {
                throw new \InvalidArgumentException('Invalid editor draft acknowledgement.');
            }
            if (isset($input['recovery_editor_id']) || isset($input['recovery_sequence'])) {
                $recovered = $input['recovery_editor_id'] ?? null;
                $recoverySequence = filter_var($input['recovery_sequence'] ?? null, FILTER_VALIDATE_INT);
                if (!is_string($recovered) || !preg_match('/^(?:legacy|[a-f0-9-]{36})$/D', $recovered)
                    || $recoverySequence === false || $recoverySequence < 1) {
                    throw new \InvalidArgumentException('Invalid recovered draft acknowledgement.');
                }
            }
        }
        return [
            'parent_id' => $parentId, 'title' => $title,
            'slug' => (new Slugger())->slug($slugInput === '' ? $title : $slugInput),
            'status' => $status, 'content_format' => $content['format'],
            'content_html' => $content['html'], 'content_markdown' => $content['markdown'],
            'content_text' => $content['text'], 'tags' => $tags,
            'change_summary' => $summary, 'base_version' => (int) $baseVersion,
            'conversion_backup' => $backup,
        ];
    }

    public function save(
        array $space, ?array $current, array $input, int $userId,
        ?Request $request = null, ?callable $can = null, bool $allowInlineImages = false,
        ?int $restoredRevision = null, bool $refreshLinks = true
    ): array {
        $operationId = $input['operation_id'] ?? null;
        if ($operationId !== null && (!is_string($operationId) || !preg_match('/^[a-f0-9-]{36}$/D', $operationId))) {
            throw new \InvalidArgumentException('Invalid save operation identity.');
        }
        $hashInput = $input;
        unset($hashInput['_token']);
        $operationHash = hash('sha256', json_encode([(int) $space['id'], $current['id'] ?? null, $hashInput], JSON_THROW_ON_ERROR));
        $draftSpaceId = filter_var($input['draft_space_id'] ?? $space['id'], FILTER_VALIDATE_INT);
        if ($draftSpaceId === false || $draftSpaceId < 1 || ($current !== null && $draftSpaceId !== (int) $space['id'])
            || ($draftSpaceId !== (int) $space['id'] && !preg_match('/^new:[a-f0-9-]{36}$/D', (string) ($input['document_key'] ?? '')))) {
            throw new \InvalidArgumentException('Invalid draft source space.');
        }
        $inlineImages = $this->inlineImages;
        $imageCheckpoint = $inlineImages?->trackingCheckpoint() ?? 0;
        $ownsTransaction = !$this->database->pdo()->inTransaction();
        try {
            $saved = $this->database->transaction(function () use (
                $space, $current, $input, $userId, $request, $can, $allowInlineImages, $inlineImages, $restoredRevision, $refreshLinks, $operationId, $operationHash, $draftSpaceId
            ): array {
                if ($operationId !== null) {
                    $this->database->fetchOne('SELECT id FROM users WHERE id = :id FOR UPDATE', ['id' => $userId]);
                    $receipt = $this->database->fetchOne('SELECT * FROM editor_page_operations WHERE user_id = :user AND operation_id = :operation', ['user' => $userId, 'operation' => $operationId]);
                    if ($receipt !== null) {
                        if (!hash_equals($receipt['request_hash'], $operationHash)) throw new \InvalidArgumentException('Operation identity was reused for different content.');
                        $result = json_decode($receipt['result_json'], true, 512, JSON_THROW_ON_ERROR);
                        $fresh = (new PageRepository($this->database))->findById((int) $result['id']);
                        if ($fresh === null || ($can !== null && !$can('page.edit', $fresh))) throw new \DomainException('You do not have permission to access this saved page.');
                        return $result;
                    }
                }
                // Serialize hierarchy changes as well as version checks within one space.
                $ids = array_values(array_unique([(int) $space['id'], $draftSpaceId])); sort($ids, SORT_NUMERIC);
                $lockedSpaces = [];
                foreach ($ids as $id) $lockedSpaces[$id] = $this->database->fetchOne('SELECT * FROM spaces WHERE id = ? AND deleted_at IS NULL FOR UPDATE', [$id]);
                $space = $lockedSpaces[(int) $space['id']];
                if ($space === null) throw new \DomainException('The destination space is unavailable.');
                if ($draftSpaceId !== (int) $space['id']) {
                    $source = $lockedSpaces[$draftSpaceId];
                    if ($source === null || !(new \OpenWiki\Permissions\SpaceAccessService($this->database, true))->canEditFor($source, $userId, $can !== null && $can('*', null))) {
                        throw new \DomainException('You no longer have permission to use the source draft.');
                    }
                }
                $publicBefore = null;
                if ($current !== null) {
                    $base = filter_var($input['base_version'] ?? null, FILTER_VALIDATE_INT);
                    if ($base === false || $base < 1) throw new \InvalidArgumentException('Invalid base page version.');
                    $fresh = $this->database->fetchOne('SELECT * FROM pages WHERE id = :id AND deleted_at IS NULL FOR UPDATE', ['id' => (int) $current['id']]);
                    if ($can !== null && ($fresh === null || !$can('page.edit', $fresh))) {
                        throw new \DomainException('You do not have permission to save this page.');
                    }
                    if ($fresh === null || (int) $fresh['version'] !== (int) ($input['base_version'] ?? 0)) {
                        throw new \RuntimeException('EDIT_CONFLICT');
                    }
                    $publicBefore = $fresh;
                    $publicBefore['tags'] = array_column((new WikiMetadataService($this->database))->tagsForPage((int) $fresh['id']), 'name');
                    $current = (new PageRepository($this->database))->working($fresh);
                }
                if ($can !== null && !$can($current === null ? 'page.create' : 'page.edit', $current ?? ['id' => 0, 'space_id' => (int) $space['id'], 'parent_id' => null, 'inherit_acl' => 1, 'owner_id' => $userId, 'author_id' => $userId, 'status' => 'draft'])) {
                    throw new \DomainException('You do not have permission to save this page.');
                }
                if ((bool) $space['review_workflow']) {
                    if (($input['status'] ?? null) === 'published' && $restoredRevision === null) throw new \DomainException('Zapisz szkic. Publikacja wymaga osobnej decyzji po recenzji tej rewizji.');
                    if ($restoredRevision !== null && ($input['status'] ?? null) === 'published') PageStatusPolicy::assertAllowed('published', $current, $can);
                    if (($input['status'] ?? $current['status'] ?? 'draft') === 'published') $input['status'] = 'draft';
                }
                $data = $this->validate($space, $current, $input, $can);
                // Public and working routes share one reservation under the space lock.
                if ($this->database->fetchOne('SELECT p.id FROM pages p LEFT JOIN page_revisions r ON r.page_id = p.id AND r.revision_number = p.version WHERE p.space_id = ? AND p.id <> ? AND (p.slug = ? OR r.slug = ?) LIMIT 1', [$space['id'], $current['id'] ?? 0, $data['slug'], $data['slug']]) !== null) throw new \InvalidArgumentException('Slug jest zajęty w tej przestrzeni. Wybierz inny adres dokumentu.');
                $review = new PageReviewService($this->database);
                $knowledge = $review->knowledge($input, $current, $userId);
                $assetUrls = [];
                $backupContent = $data['conversion_backup'] === null ? null : (new ContentService())->normalize(
                    $data['conversion_backup']['format'], $data['conversion_backup']['html'], $data['conversion_backup']['markdown']);
                $assets = ContentService::assetKinds($data['content_html'] . ($backupContent['html'] ?? ''));
                $hasInlineImages = $assets['inline_images'];
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
                    $assetUrls += $materialized['urls'];
                    if ($data['content_markdown'] !== null) $data['content_markdown'] = (new MarkdownRenderer())->replaceAssetUrls($data['content_markdown'], $materialized['urls']);
                    foreach (['pages' => 'id = :id', 'page_revisions' => 'page_id = :id AND revision_number = :version'] as $table => $where) {
                        $params = ['html' => $materialized['html'], 'text' => $materialized['text'], 'markdown' => $data['content_markdown'], 'id' => $id];
                        if ($table === 'page_revisions') $params['version'] = $version;
                        $this->database->execute('UPDATE ' . $table . ' SET content_html = :html, content_text = :text, content_markdown = :markdown WHERE ' . $where, $params);
                    }
                    if ($backupContent !== null && ContentService::assetKinds($backupContent['html'])['inline_images']) {
                        $backupImages = $inlineImages->materializeDataImages($backupContent['html'], $id, $userId);
                        $assetUrls += $backupImages['urls'];
                        $data['conversion_backup']['html'] = $data['conversion_backup']['format'] === 'visual' ? $backupImages['html'] : '';
                        $data['conversion_backup']['markdown'] = (new MarkdownRenderer())->replaceAssetUrls($data['conversion_backup']['markdown'], $backupImages['urls']);
                        $this->database->execute('UPDATE page_revisions SET conversion_backup_json = :backup WHERE page_id = :id AND revision_number = :version',
                            ['backup' => json_encode($data['conversion_backup'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'id' => $id, 'version' => $version]);
                    }
                }
                if ($assets['draft_files']) {
                    if ($this->basePath === null || !$allowInlineImages) throw new \DomainException('Draft files require attachment.upload permission.');
                    $files = new \OpenWiki\Attachments\DraftFileService($this->database, $this->basePath);
                    $currentHtml = $this->database->fetchOne('SELECT content_html FROM pages WHERE id = :id', ['id' => $id])['content_html'];
                    $resolved = $files->materialize($currentHtml, $id, $draftSpaceId, $userId, (string) ($input['document_key'] ?? ''));
                    $assetUrls += $resolved['urls'];
                    $markdown = $data['content_markdown'] === null ? null : (new MarkdownRenderer())->replaceAssetUrls($data['content_markdown'], $resolved['urls']);
                    foreach (['pages' => 'id = :id', 'page_revisions' => 'page_id = :id AND revision_number = :version'] as $table => $where) {
                        $params = ['html' => $resolved['html'], 'markdown' => $markdown, 'id' => $id];
                        if ($table === 'page_revisions') $params['version'] = $version;
                        $this->database->execute('UPDATE ' . $table . ' SET content_html = :html, content_markdown = :markdown WHERE ' . $where, $params);
                    }
                    if ($data['conversion_backup'] !== null) {
                        $backup = $data['conversion_backup'];
                        $backupHtml = (new ContentService())->normalize($backup['format'], $backup['html'], $backup['markdown'])['html'];
                        $restored = $files->materialize($backupHtml, $id, $draftSpaceId, $userId, (string) ($input['document_key'] ?? ''));
                        $assetUrls += $restored['urls'];
                        $backup['html'] = $backup['format'] === 'visual' ? $restored['html'] : '';
                        $backup['markdown'] = (new MarkdownRenderer())->replaceAssetUrls($backup['markdown'], $restored['urls']);
                        $this->database->execute('UPDATE page_revisions SET conversion_backup_json = :backup WHERE page_id = :id AND revision_number = :version',
                            ['backup' => json_encode($backup, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'id' => $id, 'version' => $version]);
                    }
                }
                $metadata->syncTags($id, $data['tags']);
                $metadata->syncLinks($id, (int) $space['id'], $decorated['references']);
                $event = $current === null ? 'page.created' : 'page.updated';
                $action = $restoredRevision !== null ? 'PAGE_REVISION_RESTORED' : ($current === null ? 'PAGE_CREATED' : 'PAGE_UPDATED');
                $after = ['title' => $data['title'], 'slug' => $data['slug'], 'status' => $data['status'], 'version' => $version];
                $after += $knowledge + ['tags' => $data['tags'], 'parent_id' => $data['parent_id']];
                if ($restoredRevision !== null) $after['restored_revision'] = $restoredRevision;
                (new AuditLogger($this->database))->log($action, 'page', $id, $userId,
                    $request ?? new Request('CLI', '/', [], [], [], []),
                    $current === null ? null : array_intersect_key($current, $after), $after);
                $payload = $after + ['id' => $id, 'space_id' => (int) $space['id'], 'author_id' => $userId];
                if ($data['status'] !== 'published' || (bool) $space['review_workflow']) unset($payload['title'], $payload['slug']);
                $webhooks = new WebhookService($this->database);
                $webhooks->queue($event, $payload);
                if ($data['status'] === 'published' && ($current['status'] ?? null) !== 'published') {
                    $webhooks->queue('page.published', $payload);
                }
                // Only the exact editor's acknowledged snapshot can be removed; other tabs survive.
                if (isset($input['document_key'], $input['editor_id'], $input['draft_sequence'])) {
                    (new EditSessionService($this->database))->acknowledgeDraft($draftSpaceId, $userId,
                        (string) $input['document_key'], (string) $input['editor_id'], (int) $input['draft_sequence']);
                    if (isset($input['recovery_editor_id'], $input['recovery_sequence'])) {
                        (new EditSessionService($this->database))->acknowledgeDraft($draftSpaceId, $userId,
                            (string) $input['document_key'], (string) $input['recovery_editor_id'], (int) $input['recovery_sequence']);
                    }
                }
                $saved = $this->database->fetchOne(
                    'SELECT p.*, u.username AS author_username, s.space_key, s.name AS space_name
                     FROM pages p INNER JOIN users u ON u.id = p.author_id
                     INNER JOIN spaces s ON s.id = p.space_id WHERE p.id = :id', ['id' => $id]
                ) ?? throw new \RuntimeException('Saved page cannot be resolved.');
                $document = ['version' => ContentService::SCHEMA_VERSION, 'format' => $saved['content_format'],
                    'source' => $saved['content_format'] === 'visual' ? $saved['content_html'] : $saved['content_markdown']];
                $saved['document_json'] = json_encode($document, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                $this->database->execute('UPDATE pages SET document_json = :document WHERE id = :id', ['document' => $saved['document_json'], 'id' => $id]);
                $this->database->execute('UPDATE page_revisions SET document_json = :document WHERE page_id = :id AND revision_number = :version', ['document' => $saved['document_json'], 'id' => $id, 'version' => $version]);
                $saved = $review->completeSave($space, $publicBefore, $saved, $knowledge);
                if ($refreshLinks) $metadata->refreshSpaceLinks((int) $space['id']);
                $private = $saved['workflow_state'] !== 'published';
                $publicSlug = $private && $publicBefore !== null ? $publicBefore['slug'] : $saved['slug'];
                (new PageEngagementService($this->database))->notifyWatchers($id, (int) $space['id'], $userId,
                    $event, $private ? 'Zapisano roboczą rewizję dokumentu' : ($current === null ? 'New page: ' : 'Page updated: ') . $saved['title'],
                    '/spaces/' . rawurlencode($space['space_key']) . '/pages/' . rawurlencode($publicSlug) . ($private ? '/review' : ''), $private, [$knowledge['content_owner_id']]);
                $result = $saved + ['tags' => $metadata->tagsForNames($data['tags']), 'asset_replacements' => $assetUrls, 'is_working' => true];
                if ($operationId !== null) {
                    $this->database->execute('INSERT INTO editor_page_operations (user_id, operation_id, request_hash, result_json, created_at) VALUES (:user, :operation, :hash, :result, UTC_TIMESTAMP())',
                        ['user' => $userId, 'operation' => $operationId, 'hash' => $operationHash, 'result' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
                }
                return $result;
            });
            if ($ownsTransaction) $inlineImages?->clearTracking();
            return $saved;
        } catch (\Throwable $exception) {
            $inlineImages?->cleanupCreatedFiles($imageCheckpoint);
            if ($exception instanceof \PDOException && (int) ($exception->errorInfo[1] ?? 0) === 1062
                && str_contains($exception->getMessage(), 'uq_pages_space_slug')) {
                throw new \InvalidArgumentException('A page with that slug already exists in this space.', 0, $exception);
            }
            throw $exception;
        }
    }
}
