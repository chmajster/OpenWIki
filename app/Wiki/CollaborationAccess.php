<?php

declare (strict_types=1);
namespace OpenWiki\Wiki;

use OpenWiki\Core\Database;
use OpenWiki\Permissions\PageAclService;
use OpenWiki\Repositories\PageRepository;
use OpenWiki\Spaces\SpaceActor;
final class CollaborationAccess
{
    public function __construct(private readonly Database $db)
    {
    }
    public function context(int $pageId, ?int $user, ?array $ceiling = null, ?int $revision = null, bool $working = false, bool $mutation = false): array
    {
        $actor = $user === null ? null : new SpaceActor($this->db, $user, $ceiling, $mutation);
        $repo = new PageRepository($this->db);
        $page = $repo->findById($pageId);
        $space = $page === null ? null : $this->db->fetchOne('SELECT * FROM spaces WHERE id = ? AND deleted_at IS NULL' . ($mutation ? ' FOR UPDATE' : ''), [$page['space_id']]);
        if ($mutation && $page !== null && $space !== null) {
            $fresh = $this->db->fetchOne('SELECT * FROM pages WHERE id = ? AND deleted_at IS NULL FOR UPDATE', [$pageId]);
            if ($fresh === null || (int) $fresh['space_id'] !== (int) $space['id']) {
                throw new CollaborationConflict('Dokument zmienił lokalizację. Otwórz go ponownie.');
            }
            $page = ContentService::storedSource($fresh);
        }
        $acl = new PageAclService($this->db, $mutation);
        if ($page === null || $space === null || !$acl->canViewFor($page, $space, $user, $actor?->can('*') ?? false, $actor?->can('page.view') ?? false, $ceiling) || $working && !$acl->canReadWorkingFor($page, $space, $user, $actor?->can('*') ?? false, $ceiling)) {
            throw new \OutOfBoundsException('Nie znaleziono dostępnego dokumentu.');
        }
        if ($mutation && ($space['status'] !== 'active' || $page['status'] === 'archived')) {
            throw new \DomainException('Archiwum jest tylko do odczytu.');
        }
        $source = $revision === null ? $repo->published($page) : (new RevisionAccessService($this->db))->visible($page, $space, $revision, $user, $actor?->can('*') ?? false, $ceiling);
        return ['page' => $page, 'space' => $space, 'source' => $source, 'actor' => $actor, 'acl' => $acl];
    }
    public static function positive(mixed $value, bool $optional = false): ?int
    {
        if ($optional && ($value === '' || $value === null)) {
            return null;
        }
        $id = is_int($value) || is_string($value) ? filter_var($value, FILTER_VALIDATE_INT) : false;
        if ($id === false || $id < 1) {
            throw new \InvalidArgumentException('Nieprawidłowy identyfikator.');
        }
        return $id;
    }
}
