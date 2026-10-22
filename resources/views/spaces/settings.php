<?php
$base = '/spaces/' . rawurlencode($space['space_key']);
$value = static fn (string $key): mixed => is_scalar($old[$key] ?? null) ? $old[$key] : ($space[$key] ?? '');
$baseVersion = (int) ($old['base_version'] ?? $space['management_version']);
$labels = ['space.view' => 'Odczyt', 'space.edit' => 'Edycja', 'space.manage_access' => 'Zarządzanie dostępem', 'space.manage_owner' => 'Zmiana właściciela', 'space.archive' => 'Archiwizacja i przywracanie', 'space.delete' => 'Usuwanie'];
$principalLabels = ['user' => 'Użytkownik', 'group' => 'Grupa', 'role' => 'Rola'];
require __DIR__ . '/../partials/ui-icons.php';
?>
<div class="space-settings-page">
    <header class="page-heading space-settings-heading">
        <div class="space-settings-heading__copy">
            <div class="breadcrumbs"><a href="<?= $e($base) ?>"><?= $e($space['name']) ?></a><span>/</span><span>Ustawienia</span></div>
            <p class="eyebrow">Konfiguracja przestrzeni</p>
            <h1>Ustawienia przestrzeni</h1>
            <p class="space-settings-intro">Zarządzaj opisem, widocznością, właścicielem i dostępem do tej przestrzeni.</p>
        </div>
        <a class="button button--ghost" href="<?= $e($base) ?>">Wróć do dokumentacji</a>
    </header>

    <?php if ($formError): ?>
        <div class="alert alert--error space-settings-alert" role="alert">
            <?= $e($formError) ?>
            <?php if ($hasConflict): ?><p><a href="<?= $e($base) ?>/settings">Wczytaj aktualne ustawienia z serwera</a> — odrzuci niezapisane zmiany formularza.</p><?php endif; ?>
        </div>
    <?php endif; ?>
    <?php if ($space['status'] === 'archived'): ?><div class="alert space-settings-alert" role="status">Przestrzeń jest zarchiwizowana. Dokumenty są dostępne do odczytu zgodnie z regułami dostępu.</div><?php endif; ?>

    <section class="card panel space-settings-section" aria-labelledby="space-settings-general-title">
        <header class="space-settings-section__header">
            <span class="space-settings-section__icon"><?= $uiIcon('document') ?></span>
            <div>
                <h2 id="space-settings-general-title">Podstawowe informacje</h2>
                <p>Zmień nazwę, opis i ustawienia widoczności przestrzeni.</p>
            </div>
        </header>
        <form method="post" action="<?= $e($base) ?>/settings" class="space-settings-form" data-space-settings>
            <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
            <input type="hidden" name="base_version" value="<?= $baseVersion ?>">
            <fieldset>
                <legend class="visually-hidden">Podstawowe informacje o przestrzeni</legend>
                <div class="space-settings-fields">
                    <label class="space-settings-field">Nazwa
                        <input name="name" value="<?= $e($value('name')) ?>" maxlength="191" required <?= !$capabilities['space.edit'] ? 'disabled' : '' ?>>
                    </label>
                    <label class="space-settings-field space-settings-field--description">Opis
                        <textarea name="description" maxlength="5000" rows="3" <?= !$capabilities['space.edit'] ? 'disabled' : '' ?>><?= $e($value('description')) ?></textarea>
                    </label>
                    <label class="space-settings-field">Ikona <span class="field-help">Emoji lub krótki tekst</span>
                        <input name="icon" value="<?= $e($value('icon')) ?>" maxlength="64" <?= !$capabilities['space.edit'] ? 'disabled' : '' ?>>
                    </label>
                    <label class="space-settings-field">Strona startowa
                        <select name="home_page_id" <?= !$capabilities['space.edit'] ? 'disabled' : '' ?>><option value="">Przegląd dokumentacji</option><?php foreach ($pages as $row): ?><option value="<?= (int) $row['id'] ?>" <?= (int) $value('home_page_id') === (int) $row['id'] ? 'selected' : '' ?>><?= $e($row['title']) ?></option><?php endforeach; ?></select>
                    </label>
                    <div class="space-settings-field space-settings-visibility">
                        <label>Widoczność
                            <select name="visibility" <?= !$capabilities['space.manage_access'] ? 'disabled' : '' ?>><?php foreach (['public' => 'Publiczna', 'private' => 'Prywatna', 'restricted' => 'Ograniczona'] as $key => $label): ?><option value="<?= $e($key) ?>" <?= $value('visibility') === $key ? 'selected' : '' ?>><?= $e($label) ?></option><?php endforeach; ?></select>
                        </label>
                        <p class="field-help">Publiczna — opublikowane strony są widoczne także dla gości. Prywatna — dostęp mają właściciel oraz superadministrator. Ograniczona — dostęp mają osoby wskazane w regułach użytkowników, grup i ról. Reguły strony nadal mogą ograniczyć jej odczyt.</p>
                    </div>
                    <?php if ($capabilities['space.manage_owner']): ?>
                        <div class="space-settings-field space-settings-owner" data-principal-picker data-url="<?= $e($base) ?>/principals" data-type="user">
                            <label>Wyszukaj nowego właściciela
                                <input type="search" data-principal-query autocomplete="off">
                            </label>
                            <label>Właściciel
                                <select name="owner_id" data-principal-results data-original="<?= (int) $space['owner_id'] ?>"><option value="<?= (int) ($owner['id'] ?? $space['owner_id']) ?>"><?= $e($owner['username'] ?? 'Właściciel') ?></option></select>
                            </label>
                            <span data-picker-status role="status"></span>
                            <p class="field-help">Przekazanie własności może odebrać Ci dostęp. Nowy właściciel musi mieć aktywne konto.</p>
                        </div>
                    <?php else: ?>
                        <div class="space-settings-field space-settings-readonly-owner"><span>Właściciel</span><strong><?= $e($owner['username'] ?? 'Właściciel') ?></strong></div>
                    <?php endif; ?>
                    <label class="space-settings-field">Status
                        <select name="status" <?= !$capabilities['space.archive'] ? 'disabled' : '' ?>><option value="active" <?= $value('status') === 'active' ? 'selected' : '' ?>>Aktywna</option><option value="archived" <?= $value('status') === 'archived' ? 'selected' : '' ?>>Zarchiwizowana</option></select>
                    </label>
                </div>
            </fieldset>

            <div class="space-settings-subsection">
                <div class="space-settings-subsection__heading">
                    <div><h3>Recenzja i publikowanie</h3><p>Określ, czy zmiany w dokumentach wymagają zatwierdzenia.</p></div>
                </div>
                <div class="space-settings-fields space-settings-fields--review">
                    <label class="space-settings-field">Proces recenzji
                        <select name="review_workflow" <?= !$capabilities['space.manage_access'] ? 'disabled' : '' ?>><option value="0" <?= !(bool) $value('review_workflow') ? 'selected' : '' ?>>Wyłączony — uprawniona osoba może publikować bez recenzji</option><option value="1" <?= (bool) $value('review_workflow') ? 'selected' : '' ?>>Włączony — szkic, recenzja, akceptacja i publikacja</option></select>
                    </label>
                    <label class="space-settings-field">Zatwierdzanie własnych zmian
                        <select name="allow_self_review" <?= !$capabilities['space.manage_access'] ? 'disabled' : '' ?>><option value="0" <?= !(bool) $value('allow_self_review') ? 'selected' : '' ?>>Zabronione</option><option value="1" <?= (bool) $value('allow_self_review') ? 'selected' : '' ?>>Dozwolone dla recenzenta</option></select>
                    </label>
                </div>
                <p class="space-settings-note">Recenzja i publikowanie wymagają odpowiednich uprawnień globalnych (page.review i page.publish), dostępu do dokumentu oraz prawa do edycji w przestrzeni. Zmiana tej polityki nie publikuje istniejących szkiców.</p>
            </div>

            <footer class="space-settings-form__footer">
                <span class="field-help">Zmiany zaczną obowiązywać po zapisaniu.</span>
                <button class="button button--primary" type="submit">Zapisz ustawienia</button>
            </footer>
        </form>
    </section>

    <?php if ($capabilities['space.manage_access']): ?>
        <section class="card panel space-settings-section" id="access" aria-labelledby="space-settings-access-title">
            <header class="space-settings-section__header">
                <span class="space-settings-section__icon"><?= $uiIcon('spaces') ?></span>
                <div>
                    <h2 id="space-settings-access-title">Dostęp</h2>
                    <p>Przydzielaj uprawnienia użytkownikom, grupom i rolom.</p>
                </div>
            </header>
            <div class="space-settings-note space-settings-note--info"><strong>Jak działają reguły?</strong> Reguła odmowy ma pierwszeństwo przed zezwoleniem. Właściciel i superadministrator zachowują możliwość zarządzania. Edycja wymaga też odpowiedniego uprawnienia globalnego. W przestrzeni prywatnej reguły dostępu nie udostępniają dokumentów — zmień widoczność na „Ograniczona”, aby z nich skorzystać.</div>

            <form class="space-settings-access-form" method="post" action="<?= $e($base) ?>/access" data-principal-picker data-url="<?= $e($base) ?>/principals">
                <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>"><input type="hidden" name="base_version" value="<?= $baseVersion ?>">
                <label>Typ
                    <select name="principal_type" data-principal-type><?php foreach (['user' => 'Użytkownik', 'group' => 'Grupa', 'role' => 'Rola'] as $key => $label): ?><option value="<?= $key ?>" <?= ($old['principal_type'] ?? 'user') === $key ? 'selected' : '' ?>><?= $e($label) ?></option><?php endforeach; ?></select>
                </label>
                <label>Wyszukaj
                    <input type="search" data-principal-query autocomplete="off">
                </label>
                <label>Adresat
                    <select name="principal_id" data-principal-results required><option value="">Wyszukaj i wybierz adresata</option><?php if ($selectedPrincipal): ?><option value="<?= (int) $selectedPrincipal['id'] ?>" selected><?= $e($selectedPrincipal['name']) ?></option><?php endif; ?></select>
                </label>
                <label>Uprawnienie
                    <select name="permission"><?php foreach ($labels as $key => $label): ?><option value="<?= $e($key) ?>" <?= ($old['permission'] ?? 'space.view') === $key ? 'selected' : '' ?>><?= $e($label) ?></option><?php endforeach; ?></select>
                </label>
                <label>Decyzja
                    <select name="effect"><option value="allow" <?= ($old['effect'] ?? 'allow') === 'allow' ? 'selected' : '' ?>>Zezwól</option><option value="deny" <?= ($old['effect'] ?? '') === 'deny' ? 'selected' : '' ?>>Odmów</option></select>
                </label>
                <div class="space-settings-access-form__action"><span data-picker-status role="status"></span><button class="button button--primary" type="submit">Zapisz regułę</button></div>
            </form>

            <div class="space-settings-table-wrap">
                <table class="space-settings-table"><thead><tr><th scope="col">Adresat</th><th scope="col">Uprawnienie</th><th scope="col">Decyzja</th><th scope="col"><span class="visually-hidden">Akcje</span></th></tr></thead><tbody>
                    <?php foreach ($rules as $rule): ?><tr><td><span class="space-settings-principal-type"><?= $e($principalLabels[$rule['principal_type']] ?? $rule['principal_type']) ?></span><?= $e($rule['principal_name']) ?></td><td><?= $e($labels[$rule['permission']] ?? $rule['permission']) ?></td><td><span class="space-settings-effect space-settings-effect--<?= $e($rule['effect']) ?>"><?= $rule['effect'] === 'allow' ? 'Zezwala' : 'Odmawia' ?></span></td><td><form method="post" action="<?= $e($base) ?>/access"><input type="hidden" name="_token" value="<?= $e($csrfToken) ?>"><input type="hidden" name="base_version" value="<?= (int) $space['management_version'] ?>"><input type="hidden" name="delete_id" value="<?= (int) $rule['id'] ?>"><button type="submit" class="button button--ghost">Usuń regułę</button></form></td></tr><?php endforeach; ?>
                    <?php if ($rules === []): ?><tr><td class="space-settings-empty" colspan="4">Nie dodano jeszcze reguł dostępu.</td></tr><?php endif; ?>
                </tbody></table>
            </div>
        </section>

        <section class="card panel space-settings-section space-settings-effective" data-effective-access data-url="<?= $e($base) ?>/access" aria-labelledby="space-settings-effective-title">
            <header class="space-settings-section__header">
                <span class="space-settings-section__icon"><?= $uiIcon('eye') ?></span>
                <div><h2 id="space-settings-effective-title">Sprawdź dostęp</h2><p>Podgląd uprawnień, które otrzyma konkretna osoba.</p></div>
            </header>
            <div class="space-settings-effective__fields">
                <div data-principal-picker data-url="<?= $e($base) ?>/principals" data-type="user">
                    <label>Wyszukaj użytkownika
                        <input type="search" data-principal-query autocomplete="off">
                    </label>
                    <label>Użytkownik
                        <select data-principal-results data-effective-user><option value="">Gość</option><option value="<?= (int) $currentUser['id'] ?>">Moje konto</option></select>
                    </label>
                    <span data-picker-status role="status"></span>
                </div>
                <label>Strona <span class="field-help">Opcjonalnie — pozostaw puste, aby sprawdzić dostęp do całej przestrzeni.</span>
                    <select data-effective-page><option value="">Cała przestrzeń</option><?php foreach ($pages as $row): ?><option value="<?= (int) $row['id'] ?>"><?= $e($row['title']) ?></option><?php endforeach; ?></select>
                </label>
                <div class="space-settings-effective__action"><button type="button" class="button button--secondary" data-check-access>Sprawdź dostęp</button></div>
            </div>
            <div class="space-settings-effective__result" data-effective-result role="status" aria-live="polite"></div>
        </section>
    <?php endif; ?>
</div>
