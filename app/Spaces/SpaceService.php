<?php
declare(strict_types=1);
namespace OpenWiki\Spaces;

use OpenWiki\Admin\AdministrationPolicy;
use OpenWiki\Audit\AuditLogger;
use OpenWiki\Core\Application;
use OpenWiki\Core\Request;
use OpenWiki\Permissions\PageAclService;
use OpenWiki\Permissions\SpaceAccessService;
use OpenWiki\Repositories\SpaceRepository;
use OpenWiki\Webhooks\WebhookService;
use OpenWiki\Wiki\Slugger;

final class SpaceService
{
    public const PERMISSIONS = ['space.view', 'space.edit', 'space.manage_access', 'space.manage_owner', 'space.archive', 'space.delete'];

    public function __construct(private readonly Application $app) {}

    public function find(int $id): array
    {
        return $this->app->database()->fetchOne('SELECT * FROM spaces WHERE id = ? AND deleted_at IS NULL' . ($this->app->database()->pdo()->inTransaction() ? ' FOR UPDATE' : ''), [$id])
            ?? throw new \OutOfBoundsException('Nie znaleziono przestrzeni.');
    }

    public function allowed(array $space, SpaceActor $actor, string $permission): bool
    {
        $access = new SpaceAccessService($this->app, $this->app->database()->pdo()->inTransaction());
        return $access->canViewFor($space, $actor->id, $actor->can('*'))
            && $access->canManageFor($space, $actor->id, $permission, $actor->can('*'), $actor->can($permission));
    }

    public function assertAllowed(array $space, SpaceActor $actor, string $permission): void
    {
        if (!$this->allowed($space, $actor, $permission)) throw new \DomainException('Brak uprawnienia: ' . $permission . '.');
    }

    public function create(array $input, int $actorId, Request $request, ?array $ceiling = null): array
    {
        return AdministrationPolicy::guard($this->app->database(), function () use ($input, $actorId, $request, $ceiling): array {
            $actor = new SpaceActor($this->app->database(), $actorId, $ceiling);
            if (!$actor->can('space.create')) throw new \DomainException('Brak uprawnienia do tworzenia przestrzeni.');
            $name = $this->text($input, 'name', '', 191, true);
            $keyInput = $this->text($input, 'space_key', '', 50);
            $key = (new Slugger())->spaceKey($keyInput === '' ? $name : $keyInput);
            $visibility = $input['visibility'] ?? 'private';
            if (!in_array($visibility, ['public', 'private', 'restricted'], true)) throw new \InvalidArgumentException('Nieprawidłowa widoczność.');
            $owner = $this->id($input['owner_id'] ?? $actorId);
            if ($owner !== $actorId && !$actor->can('space.manage_owner')) throw new \DomainException('Brak uprawnienia do wskazania innego właściciela.');
            $this->ensureOwner($owner);
            try {
                $id = (new SpaceRepository($this->app->database()))->create(['name' => $name, 'space_key' => $key,
                    'description' => $this->text($input, 'description', '', 5000) ?: null, 'owner_id' => $owner, 'visibility' => $visibility]);
            } catch (\PDOException $error) {
                if ((int) ($error->errorInfo[1] ?? 0) === 1062) throw new SpaceConflict('Klucz przestrzeni jest już używany.', 0, $error);
                throw $error;
            }
            $this->app->database()->execute('UPDATE spaces SET icon = ? WHERE id = ?', [$this->text($input, 'icon', '', 64) ?: null, $id]);
            $space = $this->find($id);
            $this->record('SPACE_CREATED', 'space.created', $space, $actorId, $request);
            return $space;
        });
    }

    public function update(int $id, array $input, int $actorId, Request $request, ?array $ceiling = null): array
    {
        return AdministrationPolicy::guard($this->app->database(), function () use ($id, $input, $actorId, $request, $ceiling): array {
            $db = $this->app->database();
            $space = $this->find($id);
            $actor = new SpaceActor($db, $actorId, $ceiling);
            if (!array_filter(['space.edit', 'space.manage_access', 'space.manage_owner', 'space.archive'], fn (string $permission): bool => $this->allowed($space, $actor, $permission))) throw new \DomainException('Brak uprawnienia do ustawień przestrzeni.');
            $this->version($space, $input);
            $name = $this->text($input, 'name', $space['name'], 191, true);
            $description = $this->text($input, 'description', $space['description'] ?? '', 5000);
            $icon = $this->text($input, 'icon', $space['icon'] ?? '', 64);
            if ($name !== $space['name'] || $description !== ($space['description'] ?? '') || $icon !== ($space['icon'] ?? '')) $this->assertAllowed($space, $actor, 'space.edit');
            $visibility = $input['visibility'] ?? $space['visibility'];
            if (!in_array($visibility, ['public', 'private', 'restricted'], true)) throw new \InvalidArgumentException('Nieprawidłowa widoczność.');
            if ($visibility !== $space['visibility']) $this->assertAllowed($space, $actor, 'space.manage_access');
            $owner = $this->id($input['owner_id'] ?? $space['owner_id']);
            if ($owner !== (int) $space['owner_id']) { $this->assertAllowed($space, $actor, 'space.manage_owner'); $this->ensureOwner($owner); }
            $status = $input['status'] ?? $space['status'];
            if (!in_array($status, ['active', 'archived'], true)) throw new \InvalidArgumentException('Nieprawidłowy stan przestrzeni.');
            if ($status !== $space['status']) $this->assertAllowed($space, $actor, 'space.archive');
            $home = array_key_exists('home_page_id', $input) ? $input['home_page_id'] : $space['home_page_id'];
            $home = $home === null || $home === '' ? null : $this->id($home);
            if ($home !== ($space['home_page_id'] === null ? null : (int) $space['home_page_id'])) $this->assertAllowed($space, $actor, 'space.edit');
            if ($home !== null && $home !== ($space['home_page_id'] === null ? null : (int) $space['home_page_id'])) {
                $page = $db->fetchOne('SELECT * FROM pages WHERE id = ? AND space_id = ? AND deleted_at IS NULL', [$home, $id]);
                if ($page === null || !(new PageAclService($this->app))->canViewFor($page, $space, $actorId, $actor->can('*'), $actor->can('page.view'))) throw new \InvalidArgumentException('Strona startowa musi być dostępną stroną tej przestrzeni.');
            }
            $db->execute('UPDATE spaces SET name = ?, description = ?, icon = ?, owner_id = ?, visibility = ?, status = ?, home_page_id = ?, management_version = management_version + 1, updated_at = UTC_TIMESTAMP() WHERE id = ?',
                [$name, $description ?: null, $icon ?: null, $owner, $visibility, $status, $home, $id]);
            $updated = $this->find($id);
            $this->record('SPACE_UPDATED', 'space.updated', $updated, $actorId, $request, $space);
            return $updated;
        });
    }

    public function rule(int $id, array $input, int $actorId, Request $request, ?int $deleteId = null, ?array $ceiling = null): array
    {
        return AdministrationPolicy::guard($this->app->database(), function () use ($id, $input, $actorId, $request, $deleteId, $ceiling): array {
            $space = $this->find($id); $db = $this->app->database();
            $this->assertAllowed($space, new SpaceActor($db, $actorId, $ceiling), 'space.manage_access');
            $this->version($space, $input);
            $beforeRules = $this->entries($id);
            if ($deleteId !== null) {
                if ($db->execute('DELETE FROM space_acl WHERE id = ? AND space_id = ?', [$deleteId, $id]) !== 1) throw new \OutOfBoundsException('Nie znaleziono reguły.');
            } else {
                $type = $input['principal_type'] ?? ''; $principal = $this->id($input['principal_id'] ?? null);
                $permission = $input['permission'] ?? ''; $effect = $input['effect'] ?? '';
                if (!in_array($permission, self::PERMISSIONS, true) || !in_array($effect, ['allow', 'deny'], true)) throw new \InvalidArgumentException('Nieprawidłowa reguła dostępu.');
                $table = $this->principalTable($type);
                if ($db->fetchOne('SELECT id FROM ' . $table . ' WHERE id = ?' . ($type === 'user' ? ' AND deleted_at IS NULL AND status = "active"' : ''), [$principal]) === null) throw new \InvalidArgumentException('Nie znaleziono adresata reguły.');
                $db->execute('INSERT INTO space_acl (space_id, principal_type, principal_id, permission, effect) VALUES (?, ?, ?, ?, ?) ON DUPLICATE KEY UPDATE effect = VALUES(effect)', [$id, $type, $principal, $permission, $effect]);
            }
            $db->execute('UPDATE spaces SET management_version = management_version + 1, updated_at = UTC_TIMESTAMP() WHERE id = ?', [$id]);
            $this->record('SPACE_ACCESS_UPDATED', 'space.updated', $this->find($id), $actorId, $request, $space);
            (new AuditLogger($db))->log('SPACE_ACL_CHANGED', 'space', $id, $actorId, $request, ['rules' => $beforeRules], ['rules' => $this->entries($id)]);
            return $this->find($id);
        });
    }

    public function entries(int $id): array
    {
        return $this->app->database()->fetchAll('SELECT a.*, COALESCE(u.username, g.name, r.name, CONCAT("Usunięty #", a.principal_id)) AS principal_name FROM space_acl a LEFT JOIN users u ON a.principal_type = "user" AND u.id = a.principal_id LEFT JOIN user_groups g ON a.principal_type = "group" AND g.id = a.principal_id LEFT JOIN roles r ON a.principal_type = "role" AND r.id = a.principal_id WHERE a.space_id = ? ORDER BY a.permission, a.id', [$id]);
    }

    public function principals(string $type, string $query): array
    {
        $table = $this->principalTable($type);
        $name = $type === 'user' ? 'username' : 'name';
        return $this->app->database()->fetchAll('SELECT id, ' . $name . ' AS name FROM ' . $table . ' WHERE ' . $name . ' LIKE ?' . ($type === 'user' ? ' AND status = "active" AND deleted_at IS NULL' : '') . ' ORDER BY ' . $name . ', id LIMIT 30', ['%' . mb_substr(trim($query), 0, 100) . '%']);
    }

    public function effective(array $space, ?int $id, ?int $pageId = null, ?SpaceActor $viewer = null): array
    {
        return $this->app->database()->transaction(function () use ($space, $id, $pageId, $viewer): array {
        $actor = $id === null ? null : new SpaceActor($this->app->database(), $id);
        $result = [];
        $view = (new SpaceAccessService($this->app))->explainFor($space, $id, 'space.view', $actor?->can('*') ?? false);
        foreach (self::PERMISSIONS as $permission) {
            $result[$permission] = (new SpaceAccessService($this->app))->explainFor($space, $id, $permission, $actor?->can('*') ?? false, $actor?->can($permission) ?? false);
            if ($permission !== 'space.view' && !$view['allowed']) $result[$permission] = ['allowed' => false, 'effect' => 'deny', 'reason' => 'Brak dostępu do przestrzeni. ' . $view['reason'], 'rules' => $view['rules']];
            if ($permission === 'space.edit' && $space['status'] === 'archived') $result[$permission]['reason'] .= ' Archiwum: dokumenty są tylko do odczytu; uprawnienie edycji dotyczy ustawień.';
        }
        if ($pageId !== null) {
            $page = $this->app->database()->fetchOne('SELECT * FROM pages WHERE id = ? AND space_id = ? AND deleted_at IS NULL', [$pageId, $space['id']]);
            $acl = new PageAclService($this->app);
            if ($viewer === null || $page === null || !$acl->canViewFor($page, $space, $viewer->id, $viewer->can('*'), $viewer->can('page.view'))) throw new \OutOfBoundsException('Nie znaleziono strony.');
            $acl->snapshot($space, $id, $actor?->can('*') ?? false);
            foreach (['page.view', 'page.edit', 'page.create', 'page.move', 'page.publish', 'page.archive', 'attachment.view', 'attachment.upload'] as $permission) $result[$permission] = $acl->explainFor($page, $space, $permission, $id, $actor?->can('*') ?? false, $actor?->can($permission) ?? false);
        }
        return $result;
        });
    }

    public function delete(int $id, int $actorId, Request $request, ?array $ceiling = null): void
    {
        AdministrationPolicy::guard($this->app->database(), function () use ($id, $actorId, $request, $ceiling): void {
            $space = $this->find($id);
            $this->assertAllowed($space, new SpaceActor($this->app->database(), $actorId, $ceiling), 'space.delete');
            $this->app->database()->execute('UPDATE spaces SET deleted_at = UTC_TIMESTAMP(), management_version = management_version + 1 WHERE id = ?', [$id]);
            $this->record('SPACE_DELETED', 'space.deleted', $space, $actorId, $request, $space);
        });
    }

    private function version(array $space, array $input): void
    {
        if (array_key_exists('base_version', $input) && $this->id($input['base_version']) !== (int) $space['management_version']) throw new SpaceConflict('Ustawienia zmieniły się. Odśwież panel przed ponownym zapisem.');
    }

    private function ensureOwner(int $id): void
    {
        $db = $this->app->database();
        $actor = new SpaceActor($db, $id);
        if (!$actor->can('space.manage_access')) $db->execute('INSERT IGNORE INTO user_roles (user_id, role_id) SELECT ?, id FROM roles WHERE slug = "space-owner"', [$id]);
    }

    private function principalTable(mixed $type): string
    {
        return match ($type) { 'user' => 'users', 'group' => 'user_groups', 'role' => 'roles', default => throw new \InvalidArgumentException('Nieprawidłowy typ adresata.') };
    }

    private function id(mixed $value): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT);
        if ($id === false || $id < 1) throw new \InvalidArgumentException('Nieprawidłowy identyfikator.');
        return $id;
    }

    private function text(array $input, string $name, string $default, int $length, bool $required = false): string
    {
        $value = $input[$name] ?? $default;
        if (!is_string($value) || mb_strlen($value) > $length || ($required && trim($value) === '')) throw new \InvalidArgumentException('Nieprawidłowe pole: ' . $name . '.');
        return trim($value);
    }

    private function record(string $action, string $event, array $space, int $actor, Request $request, ?array $before = null): void
    {
        $fields = array_flip(['name', 'space_key', 'description', 'icon', 'visibility', 'status', 'owner_id', 'home_page_id', 'management_version']);
        $after = array_intersect_key($space, $fields);
        (new AuditLogger($this->app->database()))->log($action, 'space', (int) $space['id'], $actor, $request, $before === null ? null : array_intersect_key($before, $fields), $after);
        (new WebhookService($this->app->database()))->queue($event, ['id' => (int) $space['id'], 'actor_id' => $actor] + $after);
    }
}
