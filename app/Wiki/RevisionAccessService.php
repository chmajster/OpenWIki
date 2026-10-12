<?php
declare(strict_types=1);
namespace OpenWiki\Wiki;

use OpenWiki\Core\Database;
use OpenWiki\Permissions\PageAclService;
use OpenWiki\Repositories\PageRepository;

final class RevisionAccessService
{
    public function __construct(private readonly Database $db) {}

    public function visible(array $page, array $space, int $number, ?int $user, bool $super = false, ?array $ceiling = null): array
    {
        $row = (new PageRepository($this->db))->revision((int) $page['id'], $number);
        $acl = new PageAclService($this->db);
        if ($row === null || !$acl->canViewFor($page, $space, $user, $super) || (!$row['was_published'] && !$acl->canReadWorkingFor($page, $space, $user, $super, $ceiling))) throw new \OutOfBoundsException('Nie znaleziono dostępnej rewizji.');
        return $row;
    }

    public function resource(array $revision, array $space, ?int $user, bool $super, ?array $ceiling = null): array
    {
        $row = (new ContentService())->safeProjection($revision);
        return $this->metadata($row, $space, $user, $super);
    }

    public function metadata(array $row, array $space, ?int $user, bool $super): array
    {
        $parent = $row['parent_id'] === null ? null : (new PageRepository($this->db))->findById((int) $row['parent_id']);
        if ($row['parent_id'] !== null && ($parent === null || (int) $parent['space_id'] !== (int) $space['id'] || !(new PageAclService($this->db))->canViewFor($parent, $space, $user, $super))) $row['parent_id'] = null;
        unset($row['conversion_backup_json']);
        return $row;
    }

    public function restoreInput(array $page, array $revision, mixed $base, bool $conversion = false): array
    {
        $input = $revision;
        $input['slug'] = $revision['slug'] ?? $page['slug'];
        $input['parent_id'] = $revision['tags_json'] === null ? $page['parent_id'] : $revision['parent_id'];
        $input['tags'] = $revision['tags_json'] === null ? array_column((new WikiMetadataService($this->db))->tagsForPage((int) $page['id']), 'name') : json_decode($revision['tags_json'], true, 512, JSON_THROW_ON_ERROR);
        $input['base_version'] = $base;
        $input['change_summary'] = 'Restored revision ' . (int) $revision['revision_number'];
        $input += json_decode($revision['knowledge_json'] ?? '{}', true, 512, JSON_THROW_ON_ERROR);
        if ($conversion) {
            if ($revision['conversion_backup_json'] === null) throw new \InvalidArgumentException('This revision has no conversion recovery copy.');
            $backup = json_decode($revision['conversion_backup_json'], true, 512, JSON_THROW_ON_ERROR);
            $input['content_format'] = $backup['format']; $input['content_html'] = $backup['html']; $input['content_markdown'] = $backup['markdown'];
            $input['change_summary'] = 'Restored content before conversion in revision ' . (int) $revision['revision_number'];
        }
        return $input;
    }
}
