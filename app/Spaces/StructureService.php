<?php
declare(strict_types=1);
namespace OpenWiki\Spaces;

use OpenWiki\Admin\AdministrationPolicy;
use OpenWiki\Attachments\AttachmentService;
use OpenWiki\Audit\AuditLogger;
use OpenWiki\Core\Application;
use OpenWiki\Core\Env;
use OpenWiki\Core\Request;
use OpenWiki\Permissions\PageAclService;
use OpenWiki\Permissions\SpaceAccessService;
use OpenWiki\Repositories\PageRepository;
use OpenWiki\Wiki\ContentService;
use OpenWiki\Wiki\PageWriteService;
use OpenWiki\Wiki\Slugger;
use OpenWiki\Wiki\WikiMetadataService;

/** UI and API share the same locked plan, access simulation and commit. */
final class StructureService
{
    public function __construct(private readonly Application $app) {}

    public function preview(int $id, array $input, int $user, ?array $ceiling = null): array
    {
        return AdministrationPolicy::guard($this->app->database(), function () use ($id, $input, $user, $ceiling): array {
            $plan = $this->plan($id, $input, new SpaceActor($this->app->database(), $user, $ceiling));
            return $this->projection($plan, $user);
        });
    }

    public function execute(int $id, array $input, int $user, Request $request, ?array $ceiling = null): array
    {
        $operation = $input['operation_id'] ?? null;
        if (!is_string($operation) || !preg_match('/^[a-f0-9-]{36}$/D', $operation)) throw new \InvalidArgumentException('Brak identyfikatora operacji.');
        $hashInput = $input; unset($hashInput['_token'], $hashInput['preview_token']);
        $hash = hash('sha256', json_encode([$id, $hashInput], JSON_THROW_ON_ERROR));
        $createdFiles = []; $files = new AttachmentService($this->app->database(), $this->app->basePath());
        try {
            return AdministrationPolicy::guard($this->app->database(), function () use ($id, $input, $user, $request, $ceiling, $operation, $hash, &$createdFiles, $files): array {
                $db = $this->app->database(); $actor = new SpaceActor($db, $user, $ceiling);
                $receipt = $db->fetchOne('SELECT * FROM structure_operations WHERE user_id = ? AND operation_id = ?', [$user, $operation]);
                if ($receipt !== null) {
                    if (!hash_equals($receipt['request_hash'], $hash)) throw new SpaceConflict('Identyfikator operacji dotyczy innych danych.');
                    $result = json_decode($receipt['result_json'], true, 512, JSON_THROW_ON_ERROR);
                    $page = (new PageRepository($db))->findById($result['id']);
                    $space = $page === null ? null : (new SpaceService($this->app))->find((int) $page['space_id']);
                    if ($page === null || !(new PageAclService($this->app))->canViewFor($page, $space, $user, $actor->can('*'), $actor->can('page.view'))) throw new \DomainException('Brak dostępu do wyniku operacji.');
                    return $result;
                }
                $plan = $this->plan($id, $input, $actor);
                $token = $input['preview_token'] ?? null;
                if (!is_string($token) || !hash_equals($this->token($plan, $user), $token)) throw new SpaceConflict('Struktura, dokumenty lub dostęp zmieniły się. Przygotuj i zatwierdź nowy podgląd.');
                $repository = new PageRepository($db); $writer = new PageWriteService($db, $this->app->basePath());
                $destination = $plan['destination']; $source = $plan['source']; $mapping = []; $urls = [];
                if ($plan['action'] === 'copy') {
                    foreach ($plan['selected'] as $old) {
                        $original = $this->page($old);
                        $parent = $old === $id ? $plan['parent'] : $mapping[(int) $original['parent_id']];
                        $inputPage = $this->pageInput($original, $parent, $plan['slugs'][$old], 'Kopia dokumentu');
                        if ((bool) $destination['review_workflow'] && $inputPage['status'] === 'published') $inputPage['status'] = 'draft';
                        if ($old === $id) $inputPage['title'] = $plan['title'];
                        // The eventual direct ACL is included in authorization before creation.
                        $can = fn (string $permission, ?array $page): bool => $this->copyAllowed($plan, $actor, $permission, $old);
                        $saved = $writer->save($destination, null, $inputPage, $user, $request, $can, false, null, false);
                        $new = (int) $saved['id']; $mapping[$old] = $new;
                        $db->execute('UPDATE pages SET inherit_acl = ? WHERE id = ?', [$original['inherit_acl'], $new]);
                        $db->execute('INSERT INTO page_acl (page_id, principal_type, principal_id, permission, effect, inherit_to_children, created_at) SELECT ?, principal_type, principal_id, permission, effect, inherit_to_children, UTC_TIMESTAMP() FROM page_acl WHERE page_id = ?', [$new, $old]);
                        $visibleFiles = (new \OpenWiki\Attachments\AttachmentVisibility($db))->forPage($files->listForPage($old, true), $user, $actor->can('*'), $ceiling, $original['published_revision'] !== null);
                        foreach ($visibleFiles as $attachment) {
                            $versions = array_reverse($files->versions((int) $attachment['id']));
                            if (isset($attachment['_allowed_versions'])) $versions = array_values(array_filter($versions, static fn (array $v): bool => in_array((int) $v['version_number'], $attachment['_allowed_versions'], true)));
                            $newAttachment = null;
                            foreach ($versions as $version) {
                                $path = $files->versionPath((int) $attachment['id'], (int) $version['version_number'])['path'] ?? throw new \RuntimeException('Brak wersji załącznika.');
                                if (!hash_equals($version['sha256'], hash_file('sha256', $path) ?: '')) throw new \RuntimeException('Załącznik źródłowy nie przeszedł weryfikacji integralności.');
                                if ($newAttachment === null) $newAttachment = $files->createFromSource($new, $user, $path, $attachment['name']);
                                else $files->addVersionFromSource($newAttachment, $user, $path, $attachment['name']);
                                $stored = $files->find($newAttachment); $createdFiles[] = $stored['storage_key'];
                            }
                            $urls['/attachments/' . $attachment['id']] = '/attachments/' . $newAttachment;
                        }
                    }
                    $transform = new StructureContent($source, $destination, $plan['rows'], $plan['slugs'], $mapping, $urls, $plan['redirects']);
                    foreach ($mapping as $old => $new) {
                        $original = $this->page($old);
                        $content = $transform->transform($original);
                        // Complete revision 1 within the same transaction; no incomplete copy is observable.
                        $decorated = (new WikiMetadataService($db))->decorateWikiLinks((int) $destination['id'], $destination['space_key'], $content['html']);
                        $document = json_encode(['version' => 1, 'format' => $content['format'], 'source' => $content['format'] === 'visual' ? $decorated['html'] : $content['markdown']], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                        foreach (['pages' => 'id = ?', 'page_revisions' => 'page_id = ? AND revision_number = 1'] as $table => $where) $db->execute('UPDATE ' . $table . ' SET content_html = ?, content_markdown = ?, content_text = ?, document_json = ? WHERE ' . $where, [$decorated['html'], $content['markdown'], $content['text'], $document, $new]);
                        (new WikiMetadataService($db))->syncLinks($new, (int) $destination['id'], $decorated['references']);
                        $db->execute('DELETE FROM page_revision_attachments WHERE page_id = ? AND revision_number = 1', [$new]);
                        (new \OpenWiki\Wiki\PageReviewService($db))->snapshotFiles($new, 1);
                    }
                } else {
                    // A page-only move leaves its children at the original level, retaining their own IDs.
                    foreach ($plan['detached'] as $childId) {
                        $child = $this->page($childId);
                        $child = $repository->working($child);
                        if ((bool) $source['review_workflow'] && $child['status'] === 'published') $child['status'] = 'draft';
                        $writer->save($source, $child, $this->pageInput($child, $plan['root']['parent_id'] === null ? null : (int) $plan['root']['parent_id'], $child['slug'], 'Zmiana struktury: pozostawienie dzieci'), $user, $request,
                            fn (string $permission, ?array $page): bool => $this->mutationAllowed($actor, $source, $permission, $page, 'page.move'), false, null, false);
                    }
                    $transform = new StructureContent($source, $destination, $plan['rows'], $plan['slugs'], array_fill_keys($plan['selected'], 0), [], $plan['redirects']);
                    foreach ($plan['selected'] as $old) {
                        $original = $repository->working($this->page($old)); $mapping[$old] = $old;
                        if ((int) $source['id'] !== (int) $destination['id']) {
                            $db->execute('INSERT INTO page_redirects (space_id, old_slug, page_id, created_at) VALUES (?, ?, ?, UTC_TIMESTAMP()) ON DUPLICATE KEY UPDATE page_id = VALUES(page_id)', [$source['id'], $original['slug'], $old]);
                            $db->execute('UPDATE pages SET space_id = ? WHERE id = ?', [$destination['id'], $old]);
                        }
                        $current = $this->page($old);
                        $parent = $old === $id ? $plan['parent'] : ($original['parent_id'] === null ? null : (int) $original['parent_id']);
                        $pageInput = $this->pageInput($original, $parent, $plan['slugs'][$old], 'Przeniesienie dokumentu');
                        if ((bool) $destination['review_workflow'] && $pageInput['status'] === 'published') $pageInput['status'] = 'draft';
                        $content = $transform->transform($original);
                        $pageInput['content_html'] = $content['html']; $pageInput['content_markdown'] = $content['markdown'];
                        $writer->save($destination, $current, $pageInput, $user, $request,
                            fn (string $permission, ?array $page): bool => $this->mutationAllowed($actor, $destination, $permission, $page, 'page.move'), false, null, false);
                        // The explicit structure operation owns the physical route; content stays revision-bound.
                        $db->execute('UPDATE pages SET slug = ? WHERE id = ?', [$plan['slugs'][$old], $old]);
                    }
                    if ((int) $source['id'] !== (int) $destination['id']) $db->execute('UPDATE spaces SET home_page_id = NULL, management_version = management_version + 1 WHERE id = ? AND home_page_id IN (' . implode(',', array_fill(0, count($plan['selected']), '?')) . ')', [$source['id'], ...$plan['selected']]);
                }
                $resultId = $mapping[$id];
                $this->order($destination, $plan['parent'], $resultId, $plan['before']);
                $metadata = new WikiMetadataService($db); $metadata->refreshSpaceLinks((int) $source['id']);
                if ((int) $source['id'] !== (int) $destination['id']) $metadata->refreshSpaceLinks((int) $destination['id']);
                (new AuditLogger($db))->log($plan['action'] === 'copy' ? 'PAGE_COPIED' : 'PAGE_MOVED', 'page', $resultId, $user, $request,
                    ['space_id' => $source['id'], 'parent_id' => $plan['root']['parent_id']], ['space_id' => $destination['id'], 'parent_id' => $plan['parent'], 'scope' => $plan['scope'], 'mapping' => $mapping]);
                $result = ['id' => $resultId, 'url' => DocumentationTreeService::url($destination, $repository->findById($resultId)), 'mapping' => $mapping];
                $db->execute('INSERT INTO structure_operations (user_id, operation_id, request_hash, result_json) VALUES (?, ?, ?, ?)', [$user, $operation, $hash, json_encode($result, JSON_THROW_ON_ERROR)]);
                return $result;
            });
        } catch (\Throwable $error) { $files->purgeStorageKeys($createdFiles); throw $error; }
    }

    private function plan(int $id, array $input, SpaceActor $actor): array
    {
        $db = $this->app->database(); $root = $this->page($id);
        $source = (new SpaceService($this->app))->find((int) $root['space_id']);
        $destination = (new SpaceService($this->app))->find($this->id($input['destination_space_id'] ?? $source['id']));
        $locks = [(int) $source['id'], (int) $destination['id']]; sort($locks);
        foreach (array_unique($locks) as $lock) $db->fetchOne('SELECT id FROM spaces WHERE id = ? FOR UPDATE', [$lock]);
        $rows = array_column($db->fetchAll('SELECT id, space_id, parent_id, inherit_acl, title, slug, status, version, owner_id, author_id, order_index, deleted_at FROM pages WHERE space_id IN (?, ?) ORDER BY id FOR UPDATE', [$source['id'], $destination['id']]), null, 'id');
        $root = $rows[$id] ?? throw new SpaceConflict('Strona została przeniesiona. Odśwież strukturę.');
        $action = $input['action'] ?? 'move'; $scope = $input['scope'] ?? 'subtree';
        if (!in_array($action, ['move', 'copy'], true) || !in_array($scope, ['page', 'subtree'], true)) throw new \InvalidArgumentException('Nieprawidłowa operacja struktury.');
        if ($this->id($input['base_version'] ?? null) !== (int) $root['version']) throw new SpaceConflict('Wersja strony zmieniła się. Odśwież drzewo.');
        $parent = ($input['parent_id'] ?? '') === '' || ($input['parent_id'] ?? null) === null ? null : $this->id($input['parent_id']);
        $before = ($input['before_id'] ?? '') === '' || ($input['before_id'] ?? null) === null ? null : $this->id($input['before_id']);
        $active = array_filter($rows, static fn (array $row): bool => $row['deleted_at'] === null);
        $descendants = [$id]; $children = [];
        foreach ($active as $row) $children[(int) ($row['parent_id'] ?? 0)][] = (int) $row['id'];
        for ($i = 0; $i < count($descendants); $i++) foreach ($children[$descendants[$i]] ?? [] as $child) {
            if (in_array($child, $descendants, true)) throw new SpaceConflict('Wykryto cykl drzewa.');
            $descendants[] = $child;
        }
        $selected = $scope === 'subtree' ? $descendants : [$id];
        if (count($selected) > 1000) throw new \InvalidArgumentException('Jedna operacja może obejmować najwyżej 1000 stron. Przenieś mniejsze gałęzie.');
        if ($parent !== null && (!isset($active[$parent]) || (int) $active[$parent]['space_id'] !== (int) $destination['id'])) throw new \InvalidArgumentException('Nieprawidłowy rodzic docelowy.');
        if ($action === 'move' && $parent !== null && in_array($parent, $descendants, true)) throw new \InvalidArgumentException('Nie można przenieść strony pod własnego potomka.');
        if ($before !== null && (!isset($active[$before]) || (int) $active[$before]['space_id'] !== (int) $destination['id'] || (int) ($active[$before]['parent_id'] ?? 0) !== (int) ($parent ?? 0) || in_array($before, $selected, true))) throw new \InvalidArgumentException('Nieprawidłowa pozycja docelowa.');
        $acl = new PageAclService($this->app, true); $acl->snapshot($source, $actor->id, $actor->can('*'));
        $affected = $action === 'move' ? $descendants : $selected;
        foreach ($affected as $pageId) {
            $page = $active[$pageId];
            if (!$acl->canViewFor($page, $source, $actor->id, $actor->can('*'), $actor->can('page.view'))) throw new \DomainException('Nie masz dostępu do wszystkich stron objętych operacją.');
            if ($action === 'move' && !$acl->canFor($page, $source, 'page.move', $actor->id, $actor->can('*'), $actor->can('page.move'))) throw new \DomainException('Brak uprawnienia do przeniesienia wszystkich stron.');
        }
        $access = new SpaceAccessService($this->app, true);
        if (!$access->canEditFor($destination, $actor->id, $actor->can('*'))) throw new \DomainException('Przestrzeń docelowa nie pozwala na zapis.');
        if ($action === 'copy' && !$actor->can('page.create')) throw new \DomainException('Brak uprawnienia do kopiowania stron.');
        $targetAcl = new PageAclService($this->app, true); $targetAcl->snapshot($destination, $actor->id, $actor->can('*'));
        if ($before !== null && !$targetAcl->canViewFor($active[$before], $destination, $actor->id, $actor->can('*'), $actor->can('page.view'))) throw new \DomainException('Brak dostępu do wybranej pozycji.');
        if ($parent !== null && (!$targetAcl->canViewFor($active[$parent], $destination, $actor->id, $actor->can('*'), $actor->can('page.view')) || !$targetAcl->canFor($active[$parent], $destination, $action === 'copy' ? 'page.create' : 'page.move', $actor->id, $actor->can('*'), $actor->can($action === 'copy' ? 'page.create' : 'page.move')))) throw new \DomainException('Brak uprawnienia do docelowego rodzica.');
        $title = $input['title'] ?? ($root['title'] . ($action === 'copy' ? ' (kopia)' : ''));
        if (!is_string($title) || trim($title) === '' || mb_strlen($title) > 255) throw new \InvalidArgumentException('Nieprawidłowy tytuł kopii.');
        $slugs = []; $reserved = [];
        foreach ($rows as $row) if ((int) $row['space_id'] === (int) $destination['id'] && ($action === 'copy' || !in_array((int) $row['id'], $selected, true))) $reserved[$row['slug']] = true;
        foreach ($selected as $pageId) {
            $slug = $action === 'copy' && $pageId === $id ? (new Slugger())->slug($title) : $rows[$pageId]['slug']; $base = mb_substr($slug, 0, 230); $suffix = 2;
            if ($action === 'move' && isset($reserved[$slug])) throw new SpaceConflict('Slug strony jest zajęty w przestrzeni docelowej: ' . $slug);
            while (isset($reserved[$slug])) $slug = $base . '-kopia-' . $suffix++;
            $reserved[$slug] = true; $slugs[$pageId] = $slug;
        }
        $rules = $db->fetchAll('SELECT a.* FROM page_acl a INNER JOIN pages p ON p.id = a.page_id WHERE p.space_id IN (?, ?) ORDER BY a.id FOR UPDATE', [$source['id'], $destination['id']]);
        $spaceRules = $db->fetchAll('SELECT * FROM space_acl WHERE space_id IN (?, ?) ORDER BY id FOR UPDATE', [$source['id'], $destination['id']]);
        $profiles = $this->profiles();
        $redirects = $db->fetchAll('SELECT space_id, old_slug, page_id FROM page_redirects WHERE space_id IN (?, ?) ORDER BY id FOR UPDATE', [$source['id'], $destination['id']]);
        $attachments = $db->fetchAll('SELECT a.id, a.page_id, a.current_version, a.updated_at, a.size_bytes FROM attachments a INNER JOIN pages p ON p.id = a.page_id WHERE p.space_id IN (?, ?) AND a.deleted_at IS NULL ORDER BY a.id FOR UPDATE', [$source['id'], $destination['id']]);
        if ($action === 'copy') foreach ($attachments as $attachment) if (in_array((int) $attachment['page_id'], $selected, true)) {
            if (($input['copy_attachments_allowed'] ?? true) !== true) throw new \DomainException('Kopiowanie załączników wymaga scopes attachments:read i attachments:write.');
            if (!$actor->can('page.view') || !$acl->canViewFor($active[$attachment['page_id']], $source, $actor->id, $actor->can('*'), $actor->can('page.view')) || !$actor->can('attachment.upload')) throw new \DomainException('Kopiowanie załączników wymaga odczytu źródła i uprawnienia upload.');
        }
        $detached = $action === 'move' && $scope === 'page' ? ($children[$id] ?? []) : [];
        $plan = compact('root', 'source', 'destination', 'action', 'scope', 'parent', 'before', 'selected', 'affected', 'detached', 'rows', 'slugs', 'title', 'rules', 'spaceRules', 'profiles', 'attachments', 'redirects');
        $plan['changes'] = $this->impact($plan); return $plan;
    }

    private function impact(array $plan): array
    {
        $changes = []; $afterRows = $plan['rows'];
        foreach ($plan['selected'] as $id) $afterRows[$id]['space_id'] = $plan['destination']['id'];
        $afterRows[$plan['root']['id']]['parent_id'] = $plan['parent'];
        foreach ($plan['detached'] as $id) $afterRows[$id]['parent_id'] = $plan['root']['parent_id'];
        foreach ($plan['profiles'] as $profile) {
            $lost = 0; $gained = 0;
            $beforeAcl = $this->simulated($plan['source'], $plan['rows'], $plan, $profile);
            $afterSource = $this->simulated($plan['source'], $afterRows, $plan, $profile);
            $afterDestination = $this->simulated($plan['destination'], $afterRows, $plan, $profile);
            foreach ($plan['affected'] as $id) {
                $before = $beforeAcl->canViewFor($plan['rows'][$id], $plan['source'], $profile['id'], $profile['super'], $profile['view']);
                $space = in_array($id, $plan['selected'], true) ? $plan['destination'] : $plan['source'];
                $afterAcl = in_array($id, $plan['selected'], true) ? $afterDestination : $afterSource;
                $after = $afterAcl->canViewFor($afterRows[$id], $space, $profile['id'], $profile['super'], $profile['view']);
                if ($before && !$after) $lost++; if (!$before && $after) $gained++;
            }
            if ($lost || $gained) $changes[] = ['principal' => $profile['id'] === null ? 'Gość' : 'Konto #' . $profile['id'], 'lost' => $lost, 'gained' => $gained];
        }
        return $changes;
    }

    private function profiles(): array
    {
        $db = $this->app->database(); $profiles = [0 => ['id' => null, 'principals' => [], 'super' => false, 'view' => false]];
        foreach ($db->fetchAll('SELECT id FROM users WHERE status = "active" AND deleted_at IS NULL ORDER BY id FOR UPDATE') as $user) $profiles[(int) $user['id']] = ['id' => (int) $user['id'], 'principals' => ['user:' . $user['id'] => true], 'super' => false, 'view' => false];
        foreach (['role' => ['user_roles', 'role_id'], 'group' => ['group_users', 'group_id']] as $type => [$table, $column]) foreach ($db->fetchAll('SELECT user_id, ' . $column . ' AS id FROM ' . $table . ' ORDER BY user_id, ' . $column . ' FOR UPDATE') as $row) if (isset($profiles[$row['user_id']])) $profiles[$row['user_id']]['principals'][$type . ':' . $row['id']] = true;
        foreach ($db->fetchAll('SELECT ur.user_id, p.name FROM user_roles ur INNER JOIN role_permissions rp ON rp.role_id = ur.role_id INNER JOIN permissions p ON p.id = rp.permission_id ORDER BY ur.user_id, p.name FOR UPDATE') as $row) if (isset($profiles[$row['user_id']])) {
            $profiles[$row['user_id']]['permissions'][$row['name']] = true;
            if ($row['name'] === '*') $profiles[$row['user_id']]['super'] = true;
            if (in_array($row['name'], ['*', 'page.view'], true)) $profiles[$row['user_id']]['view'] = true;
        }
        return $profiles;
    }

    private function simulated(array $space, array $rows, array $plan, array $profile): PageAclService
    {
        $matched = array_filter($plan['spaceRules'], static fn (array $rule): bool => (int) $rule['space_id'] === (int) $space['id'] && isset($profile['principals'][$rule['principal_type'] . ':' . $rule['principal_id']]));
        $view = SpaceAccessService::evaluate($space, $profile['id'], 'space.view', $profile['super'], true, array_filter($matched, static fn (array $rule): bool => $rule['permission'] === 'space.view'))['allowed'];
        $edit = $space['status'] === 'active' && SpaceAccessService::evaluate($space, $profile['id'], 'space.edit', $profile['super'], true, array_filter($matched, static fn (array $rule): bool => $rule['permission'] === 'space.edit'))['allowed'];
        $acl = new PageAclService($this->app); $acl->simulate($space, $rows, $plan['rules'], $profile['id'], $profile['principals'], $view, $edit); return $acl;
    }

    private function copyAllowed(array $plan, SpaceActor $actor, string $permission, int $old): bool
    {
        $profile = $plan['profiles'][$actor->id]; $profile['super'] = $actor->can('*');
        $rows = $plan['rows'];
        foreach ($plan['selected'] as $id) $rows[$id]['space_id'] = $plan['destination']['id'];
        $rows[$plan['root']['id']]['parent_id'] = $plan['parent'];
        $candidate = $rows[$old];
        return $this->simulated($plan['destination'], $rows, $plan, $profile)->canFor($candidate, $plan['destination'], $permission, $actor->id, $actor->can('*'), $actor->can($permission));
    }

    private function mutationAllowed(SpaceActor $actor, array $space, string $permission, ?array $page, string $write): bool
    {
        if ($page === null) return false;
        if ($permission === 'page.edit') $permission = $write;
        return (new PageAclService($this->app))->canFor($page, $space, $permission, $actor->id, $actor->can('*'), $actor->can($permission));
    }

    private function pageInput(array $page, ?int $parent, string $slug, string $summary): array
    {
        return ['title' => $page['title'], 'slug' => $slug, 'parent_id' => $parent, 'status' => $page['status'], 'content_format' => $page['content_format'], 'content_html' => $page['content_html'], 'content_markdown' => $page['content_markdown'] ?? '', 'base_version' => (int) $page['version'], 'change_summary' => $summary,
            'content_owner_id' => $page['content_owner_id'], 'review_due_at' => $page['review_due_at'],
            'tags' => $page['tags'] ?? array_column((new WikiMetadataService($this->app->database()))->tagsForPage((int) $page['id']), 'name')];
    }

    private function page(int $id): array
    {
        return ContentService::storedSource($this->app->database()->fetchOne('SELECT * FROM pages WHERE id = ? AND deleted_at IS NULL FOR UPDATE', [$id])) ?? throw new \OutOfBoundsException('Nie znaleziono strony.');
    }

    private function order(array $space, ?int $parent, int $id, ?int $before): void
    {
        $db = $this->app->database();
        $siblings = array_column($db->fetchAll('SELECT id FROM pages WHERE space_id = ? AND parent_id <=> ? AND deleted_at IS NULL AND id <> ? ORDER BY order_index, id FOR UPDATE', [$space['id'], $parent, $id]), 'id');
        $position = $before === null ? count($siblings) : array_search($before, array_map('intval', $siblings), true);
        if ($position === false) throw new SpaceConflict('Pozycja docelowa zmieniła się.');
        array_splice($siblings, $position, 0, [$id]);
        foreach ($siblings as $index => $sibling) $db->execute('UPDATE pages SET order_index = ? WHERE id = ?', [($index + 1) * 10, $sibling]);
    }

    private function projection(array $plan, int $user): array
    {
        return ['preview_token' => $this->token($plan, $user), 'count' => count($plan['selected']), 'destination' => $plan['destination']['name'], 'title' => $plan['title'], 'access_changes' => $plan['changes'], 'detached_children' => count($plan['detached']),
            'effects' => $plan['action'] === 'copy' ? 'Kopia zachowuje bezpośrednie ACL i tagi. Tworzy niezależne załączniki ze wszystkimi wersjami i nową historię od wersji 1. Linki wewnątrz kopii prowadzą do kopii; inne do oryginałów. Podgląd porównuje dostęp do kopii z oryginałem. Dostęp do oryginału pozostaje bez zmian.' : 'Przeniesienie zachowuje identyfikatory, historię, załączniki i bezpośrednie ACL. Dziedziczenie dostępu wynika z nowego rodzica i przestrzeni. Stare adresy przekierowują po sprawdzeniu dostępu.'];
    }

    private function token(array $plan, int $user): string
    {
        return hash_hmac('sha256', json_encode([$user, $plan], JSON_THROW_ON_ERROR), (string) Env::get('APP_KEY'));
    }

    private function id(mixed $value): int
    {
        $id = filter_var($value, FILTER_VALIDATE_INT); if ($id === false || $id < 1) throw new \InvalidArgumentException('Nieprawidłowy identyfikator.'); return $id;
    }
}
