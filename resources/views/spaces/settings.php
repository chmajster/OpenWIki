<?php
$base = '/spaces/' . rawurlencode($space['space_key']);
$value = static fn (string $key): mixed => $old[$key] ?? $space[$key] ?? '';
$baseVersion = (int) ($old['base_version'] ?? $space['management_version']);
$labels = ['space.view' => 'Odczyt', 'space.edit' => 'Edycja', 'space.manage_access' => 'Zarządzanie dostępem', 'space.manage_owner' => 'Zmiana właściciela', 'space.archive' => 'Archiwizacja i przywracanie', 'space.delete' => 'Usuwanie'];
?>
<div class="page-heading"><div><div class="breadcrumbs"><a href="<?= $e($base) ?>"><?= $e($space['name']) ?></a><span>/</span><span>Ustawienia przestrzeni</span></div><h1>Ustawienia przestrzeni</h1></div><a class="button button--ghost" href="<?= $e($base) ?>">Wróć do dokumentacji</a></div>
<?php if ($formError): ?><div class="alert alert--error" role="alert"><?= $e($formError) ?></div><?php endif; ?>
<?php if ($space['status'] === 'archived'): ?><div class="alert" role="status">Przestrzeń zarchiwizowana. Dokumenty są dostępne do odczytu zgodnie z ACL.</div><?php endif; ?>
<section class="card panel">
<h2>Informacje i widoczność</h2>
<form method="post" action="<?= $e($base) ?>/settings" class="form-grid" data-space-settings>
    <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
    <input type="hidden" name="base_version" value="<?= $baseVersion ?>">
    <fieldset>
    <label>Nazwa <input name="name" value="<?= $e($value('name')) ?>" maxlength="191" required <?= !$capabilities['space.edit'] ? 'disabled' : '' ?>></label>
    <label>Opis <textarea name="description" maxlength="5000" rows="4" <?= !$capabilities['space.edit'] ? 'disabled' : '' ?>><?= $e($value('description')) ?></textarea></label>
    <label>Ikona (emoji lub krótki tekst) <input name="icon" value="<?= $e($value('icon')) ?>" maxlength="64" <?= !$capabilities['space.edit'] ? 'disabled' : '' ?>></label>
    <label>Strona startowa <select name="home_page_id" <?= !$capabilities['space.edit'] ? 'disabled' : '' ?>><option value="">Przegląd dokumentacji</option><?php foreach ($pages as $row): ?><option value="<?= (int) $row['id'] ?>" <?= (int) $value('home_page_id') === (int) $row['id'] ? 'selected' : '' ?>><?= $e($row['title']) ?></option><?php endforeach; ?></select></label>
    <label>Widoczność <select name="visibility" <?= !$capabilities['space.manage_access'] ? 'disabled' : '' ?>><?php foreach (['public' => 'Publiczna', 'private' => 'Prywatna', 'restricted' => 'Ograniczona'] as $key => $label): ?><option value="<?= $e($key) ?>" <?= $value('visibility') === $key ? 'selected' : '' ?>><?= $e($label) ?></option><?php endforeach; ?></select></label>
    <p class="form-help">Publiczna: opublikowane strony także dla gości. Prywatna: właściciel i superadministrator. Ograniczona: osoby wskazane przez ACL użytkowników, grup i ról. ACL strony nadal może ograniczać odczyt.</p>
    <?php if ($capabilities['space.manage_owner']): ?>
    <div data-principal-picker data-url="<?= $e($base) ?>/principals" data-type="user">
        <label>Wyszukaj nowego właściciela <input type="search" data-principal-query autocomplete="off"></label>
        <label>Właściciel <select name="owner_id" data-principal-results data-original="<?= (int) $space['owner_id'] ?>"><option value="<?= (int) ($owner['id'] ?? $space['owner_id']) ?>"><?= $e($owner['username'] ?? 'Właściciel') ?></option></select></label>
        <span data-picker-status role="status"></span>
    </div>
    <p class="form-help">Przekazanie własności może odebrać Ci dostęp. Nowy właściciel musi mieć aktywne konto.</p>
    <?php else: ?><p>Właściciel: <?= $e($owner['username'] ?? 'Właściciel') ?></p><?php endif; ?>
    <label>Stan <select name="status" <?= !$capabilities['space.archive'] ? 'disabled' : '' ?>><option value="active" <?= $value('status') === 'active' ? 'selected' : '' ?>>Aktywna</option><option value="archived" <?= $value('status') === 'archived' ? 'selected' : '' ?>>Zarchiwizowana</option></select></label>
    <button class="button button--primary" type="submit">Zapisz ustawienia</button>
    </fieldset>
</form>
</section>
<?php if ($capabilities['space.manage_access']): ?>
<section class="card panel" id="access">
<h2>Dostęp użytkowników, grup i ról</h2>
<p>Dopasowane deny ma pierwszeństwo przed allow. Właściciel i superadministrator zachowują zarządzanie. Uprawnienia do zmian wymagają także uprawnienia globalnego. W prywatnej przestrzeni granty ACL są nieaktywne; zmień widoczność na ograniczoną, aby udostępnić dokumenty.</p>
<form class="form-grid" method="post" action="<?= $e($base) ?>/access" data-principal-picker data-url="<?= $e($base) ?>/principals">
    <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>"><input type="hidden" name="base_version" value="<?= $baseVersion ?>">
    <label>Typ <select name="principal_type" data-principal-type><?php foreach (['user' => 'Użytkownik', 'group' => 'Grupa', 'role' => 'Rola'] as $key => $label): ?><option value="<?= $key ?>" <?= ($old['principal_type'] ?? 'user') === $key ? 'selected' : '' ?>><?= $e($label) ?></option><?php endforeach; ?></select></label>
    <label>Wyszukaj <input type="search" data-principal-query autocomplete="off"></label>
    <label>Adresat <select name="principal_id" data-principal-results required><option value="">Wyszukaj i wybierz adresata</option><?php if ($selectedPrincipal): ?><option value="<?= (int) $selectedPrincipal['id'] ?>" selected><?= $e($selectedPrincipal['name']) ?></option><?php endif; ?></select></label>
    <span data-picker-status role="status"></span>
    <label>Uprawnienie <select name="permission"><?php foreach ($labels as $key => $label): ?><option value="<?= $e($key) ?>" <?= ($old['permission'] ?? 'space.view') === $key ? 'selected' : '' ?>><?= $e($label) ?></option><?php endforeach; ?></select></label>
    <label>Decyzja <select name="effect"><option value="allow" <?= ($old['effect'] ?? 'allow') === 'allow' ? 'selected' : '' ?>>Allow — zezwól</option><option value="deny" <?= ($old['effect'] ?? '') === 'deny' ? 'selected' : '' ?>>Deny — odmów</option></select></label>
    <button class="button button--primary" type="submit">Zapisz regułę</button>
</form>
<div class="table-scroll"><table><thead><tr><th>Adresat</th><th>Uprawnienie</th><th>Decyzja</th><th>Akcja</th></tr></thead><tbody>
<?php foreach ($rules as $rule): ?><tr><td><?= $e($rule['principal_type'] . ': ' . $rule['principal_name']) ?></td><td><?= $e($labels[$rule['permission']] ?? $rule['permission']) ?></td><td><?= $e($rule['effect']) ?></td><td><form method="post" action="<?= $e($base) ?>/access"><input type="hidden" name="_token" value="<?= $e($csrfToken) ?>"><input type="hidden" name="base_version" value="<?= (int) $space['management_version'] ?>"><input type="hidden" name="delete_id" value="<?= (int) $rule['id'] ?>"><button type="submit" class="button button--ghost">Usuń regułę</button></form></td></tr><?php endforeach; ?>
<?php if ($rules === []): ?><tr><td colspan="4">Nie dodano reguł dostępu.</td></tr><?php endif; ?>
</tbody></table></div>
</section>
<section class="card panel" data-effective-access data-url="<?= $e($base) ?>/access">
<h2>Podgląd efektywnych uprawnień</h2>
<div data-principal-picker data-url="<?= $e($base) ?>/principals" data-type="user">
<label>Wyszukaj użytkownika <input type="search" data-principal-query autocomplete="off"></label>
<label>Sprawdź jako <select data-principal-results data-effective-user><option value="">Gość</option><option value="<?= (int) $currentUser['id'] ?>">Moje konto</option></select></label>
<span data-picker-status role="status"></span></div>
<label>Strona (opcjonalnie) <select data-effective-page><option value="">Uprawnienia przestrzeni</option><?php foreach ($pages as $row): ?><option value="<?= (int) $row['id'] ?>"><?= $e($row['title']) ?></option><?php endforeach; ?></select></label>
<button type="button" class="button button--secondary" data-check-access>Sprawdź dostęp</button>
<div data-effective-result role="status" aria-live="polite"></div>
</section>
<?php endif; ?>
