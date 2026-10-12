<?php
declare(strict_types=1);
namespace OpenWiki\Attachments;

use OpenWiki\Core\Database;
use OpenWiki\Permissions\PageAclService;
use OpenWiki\Repositories\PageRepository;

/** A published revision exposes only its frozen file versions. */
final class AttachmentVisibility
{
    private array $contexts = [];
    public function __construct(private readonly Database $db) {}

    public function project(array $file, ?int $user, bool $super = false, ?array $ceiling = null, bool $publishedOnly = false): ?array
    {
        $id = (int) $file['page_id'];
        $key = $id . ':' . ($user ?? 0) . ':' . hash('sha256', json_encode([$super, $ceiling, $publishedOnly]));
        if (!array_key_exists($key, $this->contexts)) {
            $page = (new PageRepository($this->db))->findById($id);
            $space = $page === null ? null : $this->db->fetchOne('SELECT * FROM spaces WHERE id = ?', [$page['space_id']]);
            $context = null;
            if ($page !== null && $space !== null) {
                $acl = new PageAclService($this->db);
                $working = $acl->canReadWorkingFor($page, $space, $user, $super, $ceiling);
                $legacy = !(bool) $space['review_workflow'] && $page['status'] === 'published' && ($page['published_revision'] === null || (int) $page['published_revision'] === (int) $page['version']);
                $context = ['unrestricted' => (!$publishedOnly && $working) || $legacy || ($page['published_revision'] === null && $working), 'files' => [], 'versions' => []];
                if (!$context['unrestricted'] && $page['published_revision'] !== null) {
                    $rows = $this->db->fetchAll('SELECT f.attachment_id, f.name, f.version_number AS current_version, v.storage_key, v.mime_type, v.size_bytes, v.sha256, v.created_at AS updated_at FROM page_revision_attachments f INNER JOIN attachment_versions v ON v.attachment_id = f.attachment_id AND v.version_number = f.version_number WHERE f.page_id = ? AND f.revision_number = ?', [$id, $page['published_revision']]);
                    $context['files'] = array_column($rows, null, 'attachment_id');
                    foreach ($this->db->fetchAll('SELECT DISTINCT f.attachment_id, f.version_number FROM page_revision_attachments f INNER JOIN page_revisions r ON r.page_id = f.page_id AND r.revision_number = f.revision_number AND r.was_published = 1 WHERE f.page_id = ?', [$id]) as $row) $context['versions'][(int) $row['attachment_id']][] = (int) $row['version_number'];
                }
            }
            $this->contexts[$key] = $context;
        }
        $context = $this->contexts[$key];
        if ($context === null) return null;
        if ($context['unrestricted']) return $file;
        $published = $context['files'][(int) $file['id']] ?? null;
        return $published === null ? null : array_replace($file, $published, ['_allowed_versions' => $context['versions'][(int) $file['id']] ?? []]);
    }

    public function forPage(array $files, ?int $user, bool $super = false, ?array $ceiling = null, bool $publishedOnly = false): array
    {
        return array_values(array_filter(array_map(fn (array $file): ?array => $this->project($file, $user, $super, $ceiling, $publishedOnly), $files)));
    }
}
