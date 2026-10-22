<?php
$text = static fn (mixed $value): string => is_scalar($value) ? (string) $value : '';
$e = static fn (mixed $value): string => htmlspecialchars($text($value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$old = $old ?? [];
require __DIR__ . '/../partials/editor-icons.php';
$isEdit = $page !== null;
$field = static function (string $key, mixed $fallback = '') use ($old): mixed {
    return array_key_exists($key, $old) ? $old[$key] : $fallback;
};

$titleValue = $field('title', $page['title'] ?? '');
$slugValue = $field('slug', $page['slug'] ?? '');
$parentValue = $field('parent_id', $page['parent_id'] ?? $initialParentId ?? '');
$statusValue = $field('status', $page['status'] ?? ($canPublish ? 'published' : 'draft'));
if ((bool) $space['review_workflow'] && $statusValue === 'published') $statusValue = 'draft';
$formatValue = $field('content_format', $page['content_format'] ?? $initialTemplate['content_format'] ?? 'visual');
$markdownValue = $field('content_markdown', $page['content_markdown'] ?? $initialTemplate['content_markdown'] ?? '');
$visualValue = (new \OpenWiki\Security\HtmlSanitizer())->sanitize($text($field('content_html', $page['content_html'] ?? $initialTemplate['content_html'] ?? '<p></p>')));
$changeSummary = $field('change_summary', '');
$tagValue = $field('tags', isset($tags) ? implode(', ', array_column($tags, 'name')) : '');
if (is_array($tagValue)) $tagValue = implode(', ', array_filter($tagValue, 'is_string'));
$canUploadImage = (bool) ($canUploadImage ?? false);
$imageUploadUrl = $imageUploadUrl ?? null;

$children = [];
$visibleIds = array_fill_keys(array_map(static fn (array $row): int => (int) $row['id'], $pages), true);
foreach ($pages as $candidate) {
    $parentKey = isset($visibleIds[(int) ($candidate['parent_id'] ?? 0)]) ? (int) $candidate['parent_id'] : 0;
    $children[$parentKey][] = $candidate;
}

$blocked = [];
if ($isEdit) {
    $collectBlocked = function (int $id) use (&$collectBlocked, &$blocked, $children): void {
        $blocked[$id] = true;
        foreach ($children[$id] ?? [] as $child) {
            $collectBlocked((int) $child['id']);
        }
    };
    $collectBlocked((int) $page['id']);
}

$options = [];
$walk = function (int $parentId, int $depth) use (&$walk, &$options, $children, $blocked): void {
    foreach ($children[$parentId] ?? [] as $candidate) {
        if (isset($blocked[(int) $candidate['id']])) {
            continue;
        }
        $candidate['_depth'] = $depth;
        $options[] = $candidate;
        $walk((int) $candidate['id'], $depth + 1);
    }
};
$walk(0, 0);
$preserveParent = $isEdit && (int) $parentValue > 0
    && (string) $parentValue === (string) $page['parent_id']
    && !in_array((int) $parentValue, array_map(static fn (array $row): int => (int) $row['id'], $options), true);
$parentTitle = null;
foreach ($pages as $candidate) if ((int) $candidate['id'] === (int) $parentValue) $parentTitle = $candidate['title'];
?>
<section
    class="editor-shell"
    data-wiki-editor
    data-is-edit="<?= $isEdit ? '1' : '0' ?>"
    data-initial-template="<?= $e($initialTemplate['id'] ?? '') ?>"
    data-user-id="<?= (int) $currentUser['id'] ?>"
    data-instance-id="<?= substr(hash('sha256', $app->basePath() . \OpenWiki\Core\Env::get('APP_URL', '')), 0, 16) ?>"
    data-space-id="<?= (int) $space['id'] ?>"
    data-page-id="<?= (int) ($page['id'] ?? 0) ?>"
    data-drafts-url="/spaces/<?= rawurlencode($space['space_key']) ?>/editor-drafts"
    data-files-url="/spaces/<?= rawurlencode($space['space_key']) ?>/editor-files"
    data-preview-url="/spaces/<?= rawurlencode($space['space_key']) ?>/editor-preview"
    data-workspace-url="/spaces/<?= rawurlencode($space['space_key']) ?>/editor-workspace"
    data-pages-url="/spaces/<?= rawurlencode($space['space_key']) ?>/editor-pages"
    data-users-url="/spaces/<?= rawurlencode($space['space_key']) ?>/editor-users"
    data-convert-url="/spaces/<?= rawurlencode($space['space_key']) ?>/editor-convert"
    data-can-upload-image="<?= $canUploadImage ? '1' : '0' ?>"
    <?php if ($imageUploadUrl !== null): ?>
        data-image-upload-url="<?= $e($imageUploadUrl) ?>"
    <?php endif; ?>
    <?php if ($isEdit): ?>
        data-lock-url="/spaces/<?= rawurlencode($space['space_key']) ?>/pages/<?= rawurlencode($page['slug']) ?>/edit-lock"
        data-unlock-url="/spaces/<?= rawurlencode($space['space_key']) ?>/pages/<?= rawurlencode($page['slug']) ?>/edit-unlock"
    <?php endif; ?>
>
    <textarea hidden data-html-policy><?= $e(json_encode(\OpenWiki\Security\HtmlSanitizer::policy(), JSON_THROW_ON_ERROR)) ?></textarea>
    <textarea hidden data-template-catalog><?= $e(json_encode($templates, JSON_THROW_ON_ERROR)) ?></textarea>
    <dialog data-document-preview-dialog class="editor-dialog document-preview-dialog"><h2 data-document-preview-title>Podgląd strony</h2><p data-document-preview-status role="status"></p><div class="document-content" data-document-preview-content></div><button class="button" type="button" data-dialog-close>Zamknij podgląd</button></dialog>
    <dialog data-template-preview-dialog class="editor-dialog"><h2 data-template-preview-title></h2><div class="document-content" data-template-preview-content></div><button class="button button--primary" type="button" data-template-preview-apply>Użyj szablonu</button><button class="button" type="button" data-dialog-close>Zamknij</button></dialog>
    <dialog data-recovery-dialog class="editor-dialog"><h2>Porównanie kopii odzyskiwania</h2><p data-recovery-comparison-info></p><div class="comparison-grid"><div><h3>Aktualny dokument</h3><pre data-recovery-current></pre></div><div><h3>Wybrana kopia</h3><pre data-recovery-copy></pre></div></div><button type="button" class="button" data-dialog-close>Zamknij</button></dialog>
    <dialog data-table-dialog class="editor-dialog"><h2>Wstaw tabelę</h2><label>Wiersze<input type="number" min="1" max="50" value="3" data-table-rows></label><label>Kolumny<input type="number" min="1" max="30" value="3" data-table-cols></label><label><input type="checkbox" checked data-table-header> Wiersz nagłówkowy</label><p role="alert" data-table-error></p><button class="button" type="button" data-table-insert>Wstaw tabelę</button><button class="button" type="button" data-dialog-close>Anuluj</button></dialog>
    <dialog data-block-dialog class="editor-dialog"><h2>Blok dokumentu</h2><label>Rodzaj<select data-block-kind><option value="disclosure">Zwijana sekcja</option><option value="columns2">Dwie kolumny</option><option value="columns3">Trzy kolumny</option></select></label><label>Tytuł sekcji<input maxlength="255" value="Szczegóły" data-block-title></label><button type="button" class="button" data-block-insert>Wstaw lub zmień blok</button><button type="button" class="button" data-dialog-close>Anuluj</button></dialog>
    <dialog data-diagram-dialog class="editor-dialog"><h2>Diagram Mermaid</h2><label>Źródło<textarea rows="8" maxlength="20000" spellcheck="false" data-diagram-source></textarea></label><button type="button" class="button" data-diagram-render>Podgląd</button><p role="status" data-diagram-status></p><div data-diagram-preview></div><button type="button" class="button" data-diagram-insert>Zapisz źródło w dokumencie</button><button type="button" class="button" data-dialog-close>Zamknij</button></dialog>
    <dialog data-find-dialog class="editor-dialog"><h2>Znajdź i zamień</h2><label>Szukaj<input data-find-text></label><label>Zamień na<input data-replace-text></label><label><input type="checkbox" data-find-case> Rozróżniaj wielkość liter</label><p role="status" data-find-status></p><button type="button" class="button" data-find-next>Następne</button><button type="button" class="button" data-replace-one>Zamień</button><button type="button" class="button" data-replace-all>Zamień wszystkie</button><button type="button" class="button" data-dialog-close>Zamknij</button></dialog>
    <div data-command-menu class="command-menu" hidden><label class="command-search">Wstaw element<input type="search" data-command-search placeholder="Szukaj elementu…" aria-controls="editor-command-options" aria-autocomplete="list"></label><div id="editor-command-options" data-command-options role="listbox" aria-label="Polecenia edytora"></div><small>↑ ↓ wybór · Enter wstaw · Esc zamknij</small></div>
    <div data-text-context class="text-context" hidden role="toolbar" aria-label="Zaznaczony tekst"><button type="button" data-context-command="bold">Pogrubienie</button><button type="button" data-context-command="italic">Kursywa</button><button type="button" data-context-command="link">Link</button><button type="button" data-context-command="clear">Usuń format</button></div>

    <?php if (!empty($formError)): ?>
        <div class="alert alert--error" role="alert"><?= $e($t($formError)) ?></div>
    <?php endif; ?>

    <div class="alert alert--warning" data-edit-lock-warning role="alert" hidden></div>
    <section class="card panel" data-model-review hidden aria-label="<?= $e($t('Review document transformation')) ?>">
        <h2><?= $e($t('Review document transformation')) ?></h2>
        <p><?= $e($t('The structured editor needs to normalize this HTML. Review the result before editing. The original remains available in recovery and revision history.')) ?></p>
        <div class="editor-comparison"><div><h3><?= $e($t('Original document')) ?></h3><div data-model-before></div></div><div><h3><?= $e($t('Editor document')) ?></h3><div data-model-after></div></div></div>
        <button type="button" class="button button--secondary" data-model-keep><?= $e($t('Keep original HTML')) ?></button>
        <button type="button" class="button button--primary" data-model-accept><?= $e($t('Accept transformation and keep original')) ?></button>
    </section>
    <section class="card panel editor-conflict" data-conflict-panel hidden aria-label="<?= $e($t('Resolve edit conflict')) ?>">
        <h2><?= $e($t('Resolve edit conflict')) ?></h2>
        <p><?= $e($t('Your original base version is retained. Compare the base, your document and the server version, then edit your document to resolve the differences.')) ?></p>
        <div class="editor-comparison">
            <div><h3><?= $e($t('Base version')) ?></h3><pre data-conflict-base></pre></div>
            <div><h3><?= $e($t('Your document')) ?></h3><pre data-conflict-local></pre></div>
            <div><h3><?= $e($t('Current server version')) ?></h3><pre data-conflict-server></pre></div>
        </div>
        <button type="button" class="button button--secondary" data-resolve-conflict><?= $e($t('I reviewed and resolved the differences; prepare my document for saving')) ?></button>
        <button type="button" class="button button--ghost" data-conflict-fork>Zachowaj lokalną pracę jako osobny szkic</button>
        <button type="button" class="button button--ghost" data-use-server-version>Przejmij wersję serwera, zachowując lokalną kopię</button>
    </section>
    <?php if (!empty($conflict)): ?>
        <textarea hidden data-initial-conflict><?= $e(json_encode($conflict, JSON_THROW_ON_ERROR)) ?></textarea>
    <?php endif; ?>
    <dialog data-conversion-dialog class="editor-conversion" aria-labelledby="conversion-heading">
        <h2 id="conversion-heading"><?= $e($t('Review conversion differences')) ?></h2>
        <p><?= $e($t('Markdown supports headings, emphasis, lists, quotes, code and links. Other HTML formatting and image metadata may be lost. The original document remains recoverable after conversion.')) ?></p>
        <div class="editor-comparison">
            <div><h3><?= $e($t('Before')) ?></h3><div data-conversion-before class="document-content"></div></div>
            <div><h3><?= $e($t('After')) ?></h3><div data-conversion-after class="document-content"></div></div>
        </div>
        <h3><?= $e($t('Source differences')) ?></h3><pre data-conversion-diff></pre>
        <div class="form-actions">
            <button type="button" class="button button--secondary" data-conversion-cancel><?= $e($t('Keep original')) ?></button>
            <button type="button" class="button button--primary" data-conversion-accept><?= $e($t('Convert and keep recovery copy')) ?></button>
        </div>
    </dialog>

    <dialog data-link-dialog aria-labelledby="link-heading">
        <h2 id="link-heading"><?= $e($t('Insert link')) ?></h2>
        <label><?= $e($t('Find a page')) ?><input type="search" maxlength="100" data-link-search autocomplete="off"></label>
        <div role="status" data-link-status></div><div data-link-results class="link-results"></div>
        <label><?= $e($t('Link text')) ?><input maxlength="255" data-link-text></label><label><?= $e($t('URL')) ?><input data-link-url type="text"></label>
        <div role="alert" data-link-error></div><button type="button" class="button" data-link-insert><?= $e($t('Insert link')) ?></button><button type="button" class="button button--secondary" data-link-remove><?= $e($t('Remove link')) ?></button><button type="button" class="button button--ghost" data-dialog-close><?= $e($t('Cancel')) ?></button>
    </dialog>
    <dialog data-code-dialog aria-labelledby="code-heading"><h2 id="code-heading"><?= $e($t('Code block')) ?></h2>
        <label><?= $e($t('Language')) ?><input data-code-language maxlength="40" list="code-languages"></label><datalist id="code-languages"><option value="php"><option value="javascript"><option value="python"><option value="sql"><option value="bash"><option value="json"><option value="powershell"><option value="yaml"><option value="xml"><option value="text"></datalist>
        <button type="button" class="button" data-code-insert><?= $e($t('Apply code block')) ?></button><button type="button" class="button button--ghost" data-dialog-close><?= $e($t('Cancel')) ?></button>
    </dialog>
    <dialog data-callout-dialog aria-labelledby="callout-heading"><h2 id="callout-heading"><?= $e($t('Callout')) ?></h2>
        <label><?= $e($t('Type')) ?><select data-callout-kind><option value="info"><?= $e($t('Information')) ?></option><option value="success">Sukces</option><option value="warning"><?= $e($t('Warning')) ?></option><option value="note"><?= $e($t('Note')) ?></option></select></label>
        <button type="button" class="button" data-callout-insert><?= $e($t('Apply callout')) ?></button><button type="button" class="button button--ghost" data-dialog-close><?= $e($t('Cancel')) ?></button>
    </dialog>
    <dialog data-macro-dialog aria-labelledby="macro-heading"><h2 id="macro-heading"><?= $e($t('Insert macro')) ?></h2>
        <label><?= $e($t('Macro')) ?><select data-macro-name><?php foreach (['toc' => 'Table of contents', 'child-pages' => 'Child pages', 'page-properties' => 'Page properties', 'attachments' => 'Attachments', 'recent-updates' => 'Recent updates', 'user-profile' => 'User profile', 'status' => 'Status', 'info' => 'Information', 'warning' => 'Warning', 'note' => 'Note', 'code' => 'Code'] as $name => $label): ?><option value="<?= $name ?>"><?= $e($t($label)) ?></option><?php endforeach; ?></select></label>
        <label data-macro-parameter-label><?= $e($t('Parameter')) ?><textarea maxlength="1000" data-macro-parameter></textarea></label><p data-macro-example></p><div role="alert" data-macro-error></div>
        <button type="button" class="button" data-macro-insert><?= $e($t('Insert macro')) ?></button><button type="button" class="button button--ghost" data-dialog-close><?= $e($t('Cancel')) ?></button>
    </dialog>
    <form method="post" action="<?= $e($formAction) ?>" class="editor-form workspace-form" id="page-editor" data-editor-form>
        <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
        <input type="hidden" name="base_version" value="<?= $e($field('base_version', $page['version'] ?? 0)) ?>">
        <input type="hidden" name="content_format" value="<?= $e($formatValue) ?>" data-content-format>
        <input type="hidden" name="publication_space_id" disabled value="<?= $e($field('publication_space_id', $space['id'])) ?>">
        <input type="hidden" name="publication_parent_id" disabled value="<?= $e($field('publication_parent_id', $parentValue)) ?>">
        <?php require __DIR__ . '/../partials/editor-header.php'; ?>
        <dialog data-publication-dialog class="editor-dialog publication-dialog" aria-labelledby="publication-heading">
            <header class="publication-heading">
                <div><div class="eyebrow"><?= $isEdit ? 'Zapis strony' : 'Nowa strona' ?></div>
                    <h2 id="publication-heading"><?= $isEdit ? 'Zapisz zmiany' : 'Opublikuj stronę' ?></h2>
                    <p class="field-help">Sprawdź lokalizację, metadane i dostęp przed zapisem.</p>
                </div>
                <span class="publication-title-preview" data-publication-title><?= $e($titleValue ?: 'Tytuł strony') ?></span>
            </header>
            <div class="publication-location-grid">
                <label>Przestrzeń<select data-publication-space <?= $isEdit ? 'disabled' : '' ?>><?php foreach ($workspaceSpaces ?? [] as $target): ?><option value="<?= (int) $target['id'] ?>" <?= (int) $target['id'] === (int) $space['id'] ? 'selected' : '' ?>><?= $e($target['name']) ?></option><?php endforeach; ?></select></label>
                <label>Strona nadrzędna<select data-publication-parent><option value="">Główny poziom przestrzeni</option></select></label>
            </div>
            <?php if ($isEdit): ?><p class="field-help publication-move-help">Przenoszenie do innej przestrzeni jest dostępne w działaniach strony.</p><?php endif; ?>
            <section class="publication-access" aria-labelledby="publication-access-heading"><div class="publication-access__heading"><span aria-hidden="true">🔒</span><div><h3 id="publication-access-heading">Dostęp odziedziczony</h3><p data-publication-access></p></div></div><p class="publication-access__permission" data-publication-permission></p></section>
            <?php if (!empty($canManagePageAcl)): ?>
                <?php
                    $aclPrincipals = $pageAclPrincipals ?? ['users' => [], 'groups' => [], 'roles' => []];
                    $accessPrincipalValue = (string) $field('access_principal', '');
                    $accessPermissionValue = (string) $field('access_permission', 'page.view');
                    $accessChildrenValue = (string) $field('access_inherit_to_children', '0');
                ?>
                <section class="publication-share" aria-labelledby="publication-share-heading">
                    <div class="publication-share__heading"><div><h3 id="publication-share-heading">Przydziel dostęp</h3><p>Dodaj regułę dla osoby, grupy albo roli.</p></div><?php if ($isEdit): ?><a href="/spaces/<?= rawurlencode((string) $space['space_key']) ?>/pages/<?= rawurlencode((string) $page['slug']) ?>/permissions">Więcej ustawień</a><?php else: ?><span>Więcej ustawień po zapisie</span><?php endif; ?></div>
                    <div class="publication-share__fields">
                        <label>Odbiorca
                            <select name="access_principal">
                                <option value="" <?= $accessPrincipalValue === '' ? 'selected' : '' ?>>Nie dodawaj reguły</option>
                                <?php if (($aclPrincipals['users'] ?? []) !== []): ?><optgroup label="Osoby"><?php foreach ($aclPrincipals['users'] as $recipient): $recipientValue = 'user:' . (int) $recipient['id']; ?><option value="<?= $e($recipientValue) ?>" <?= $accessPrincipalValue === $recipientValue ? 'selected' : '' ?>><?= $e($recipient['username']) ?> · <?= $e($recipient['email']) ?></option><?php endforeach; ?></optgroup><?php endif; ?>
                                <?php if (($aclPrincipals['groups'] ?? []) !== []): ?><optgroup label="Grupy"><?php foreach ($aclPrincipals['groups'] as $recipient): $recipientValue = 'group:' . (int) $recipient['id']; ?><option value="<?= $e($recipientValue) ?>" <?= $accessPrincipalValue === $recipientValue ? 'selected' : '' ?>><?= $e($recipient['name']) ?></option><?php endforeach; ?></optgroup><?php endif; ?>
                                <?php if (($aclPrincipals['roles'] ?? []) !== []): ?><optgroup label="Role"><?php foreach ($aclPrincipals['roles'] as $recipient): $recipientValue = 'role:' . (int) $recipient['id']; ?><option value="<?= $e($recipientValue) ?>" <?= $accessPrincipalValue === $recipientValue ? 'selected' : '' ?>><?= $e($recipient['name']) ?></option><?php endforeach; ?></optgroup><?php endif; ?>
                            </select>
                        </label>
                        <label>Uprawnienie
                            <select name="access_permission">
                                <option value="page.view" <?= $accessPermissionValue === 'page.view' ? 'selected' : '' ?>>Może wyświetlać stronę</option>
                                <option value="page.edit" <?= $accessPermissionValue === 'page.edit' ? 'selected' : '' ?>>Może edytować stronę</option>
                            </select>
                        </label>
                        <label class="publication-share__children"><input type="checkbox" name="access_inherit_to_children" value="1" <?= $accessChildrenValue === '1' ? 'checked' : '' ?>><span>Uwzględnij też strony podrzędne</span></label>
                    </div>
                    <p class="publication-share__help">Reguła nie zastępuje dostępu do przestrzeni ani uprawnień globalnych. Odczyt i edycja tworzą listę uprawnionych do danej czynności; inni mogą utracić dostęp. Zapis odrzucimy, jeśli reguła zablokuje Ci zarządzanie stroną.</p>
                </section>
            <?php endif; ?>
            <div data-publication-metadata></div><p data-publication-error role="alert"></p>
            <div class="form-actions"><button class="button button--primary" type="submit" data-confirm-publication>Potwierdź zapis</button><button class="button" type="button" data-dialog-close>Wróć do edycji</button></div>
        </dialog>
        <div class="workspace-body">
        <aside class="workspace-tree" id="workspace-tree" data-workspace-tree aria-label="Drzewo przestrzeni">
            <a class="workspace-main-menu" href="/" aria-label="<?= $e($t('Return to dashboard')) ?>" title="<?= $e($t('Return to dashboard')) ?>"><?= $editorIcon('home') ?><span><?= $e($t('Home')) ?></span></a>
            <a class="workspace-space-name" href="/spaces/<?= rawurlencode($space['space_key']) ?>"><?= $editorIcon('document') ?><strong><?= $e($space['name']) ?></strong></a>
            <div class="workspace-tree-heading"><span>Strony</span><a href="/spaces/<?= rawurlencode($space['space_key']) ?>/pages/create" aria-label="Utwórz stronę w tej przestrzeni">+</a></div>
            <p class="workspace-location" data-current-location><?= $e($parentTitle ?? 'Główny poziom') ?> → <?= $isEdit ? 'Edytowana strona' : 'Nowa strona' ?></p>
            <?php $activePageId = $page['id'] ?? $parentValue; require __DIR__ . '/../partials/page-tree.php'; ?>
            <details class="editor-outline" open><summary>Nagłówki dokumentu</summary><nav data-editor-outline aria-label="Nagłówki dokumentu"></nav></details>
        </aside>
        <div class="workspace-tree-resizer" data-tree-resize role="separator" aria-orientation="vertical" aria-label="Zmień szerokość drzewa stron" aria-valuemin="200" aria-valuemax="420" aria-valuenow="236" tabindex="0"><span aria-hidden="true"></span></div>

        <div class="editor-main workspace-canvas">
            <?php require __DIR__ . '/../partials/editor-recovery.php'; ?>
            <div class="document-title-area">
                <?php if (!empty($initialTemplate)): ?><p class="editor-template-source" data-initial-template-name><?= $e($t('Started from template')) ?>: <strong><?= $e($initialTemplate['display_name']) ?></strong></p><?php endif; ?>
                <div class="document-decoration"><details class="emoji-menu"><summary aria-label="Dodaj emoji do tytułu">☺ <span>Ikona</span></summary><div><?php foreach (['📄', '📘', '🛠️', '💡', '✅', '🚀', '📋', '🔍'] as $emoji): ?><button type="button" data-title-emoji="<?= $e($emoji) ?>" aria-label="Dodaj <?= $e($emoji) ?> do tytułu"><?= $emoji ?></button><?php endforeach; ?></div></details><?php if ($canUploadImage): ?><button type="button" data-add-cover><?= $editorIcon('image') ?> Obraz nagłówkowy</button><?php endif; ?></div>
                <label class="sr-only" for="page-title"><?= $e($t('Title')) ?></label>
                <textarea id="page-title" class="title-input" name="title" required maxlength="255" rows="1" placeholder="Tytuł strony" data-document-title><?= $e($titleValue) ?></textarea>
                <div class="document-byline"><span class="author-avatar" aria-hidden="true"><?= $e(mb_strtoupper(mb_substr($currentUser['username'], 0, 1))) ?></span><span><?= $e($currentUser['username']) ?></span><span class="document-draft-label">Szkic prywatny</span></div>
            </div>
            <details class="editor-metadata" open data-editor-metadata><summary>Metadane strony</summary>
            <div class="editor-fields">

                <div class="editor-meta-grid">
                    <label><?= $e($t('Slug')) ?>
                        <input name="slug" maxlength="240" value="<?= $e($slugValue) ?>" placeholder="<?= $e($t('generated-from-title')) ?>">
                    </label>
                    <label><?= $e($t('Parent page')) ?>
                        <select name="parent_id">
                            <option value=""><?= $e($t('No parent')) ?></option>
                            <?php if ($preserveParent): ?><option value="<?= (int) $parentValue ?>" selected><?= $e($t('Current parent (restricted)')) ?></option><?php endif; ?>
                            <?php foreach ($options as $option): ?>
                                <option value="<?= (int) $option['id'] ?>" <?= (string) $parentValue === (string) $option['id'] ? 'selected' : '' ?>>
                                    <?= $e(str_repeat('— ', (int) $option['_depth']) . $option['title']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label><?= $e($t('Status')) ?>
                        <select name="status">
                            <option value="draft" <?= $statusValue === 'draft' ? 'selected' : '' ?>><?= $e($t('Draft')) ?></option>
                            <?php if ($canPublish || $statusValue === 'published'): ?>
                                <option value="published" <?= $statusValue === 'published' ? 'selected' : '' ?>><?= $e($t('Published')) ?></option>
                            <?php endif; ?>
                            <?php if (($canArchive ?? false) || $statusValue === 'archived'): ?>
                                <option value="archived" <?= $statusValue === 'archived' ? 'selected' : '' ?>><?= $e($t('Archived')) ?></option>
                            <?php endif; ?>
                        </select>
                    </label>
                    <label>Właściciel merytoryczny
                        <select name="content_owner_id" required><?php foreach (($pageAclPrincipals['users'] ?? []) as $recipient): ?><option value="<?= (int) $recipient['id'] ?>" <?= (string) $field('content_owner_id', $page['content_owner_id'] ?? $currentUser['id']) === (string) $recipient['id'] ? 'selected' : '' ?>><?= $e($recipient['username']) ?></option><?php endforeach; ?><?php if ($page !== null && !in_array((int) ($page['content_owner_id'] ?? 0), array_map(static fn (array $row): int => (int) $row['id'], $pageAclPrincipals['users'] ?? []), true)): ?><option value="<?= (int) $page['content_owner_id'] ?>" selected>Nieaktywne konto (<?= (int) $page['content_owner_id'] ?>)</option><?php endif; ?></select>
                    </label>
                    <label>Termin przeglądu wiedzy
                        <input type="date" name="review_due_at" value="<?= $e($field('review_due_at', $page['review_due_at'] ?? '')) ?>">
                    </label>
                </div>

                <label><?= $e($t('Tags')) ?>
                    <input name="tags" maxlength="4000" value="<?= $e($tagValue) ?>" placeholder="<?= $e($t('linux, network, procedure')) ?>">
                    <span class="field-help"><?= $e($t('Separate tags with commas. Maximum 30 tags.')) ?></span>
                </label>

            </div>

                <label><?= $e($t('Change summary')) ?>
                <textarea name="change_summary" rows="3" maxlength="500" placeholder="<?= $e($t('Describe this change')) ?>"><?= $e($changeSummary) ?></textarea>
            </label>
</details>
            <div class="editor-mode-row">
            <?php if ($canUploadImage): ?><button type="button" class="icon-button workspace-file-button" data-attachment-picker title="Dodaj plik lub obraz"><?= $editorIcon('document') ?><span>Dodaj plik</span></button><?php endif; ?>
            <div class="editor-tabs" role="tablist" aria-label="<?= $e($t('Editor mode')) ?>">
                <button type="button" class="editor-tab" role="tab" aria-controls="visual-pane" data-editor-mode="visual"><?= $e($t('Visual')) ?></button>
                <button type="button" class="editor-tab" role="tab" aria-controls="markdown-pane" data-editor-mode="markdown">Markdown</button>
            </div>
            </div>

            <?php if ($canUploadImage): ?>
            <div class="upload-area"><input type="file" multiple hidden data-attachment-file><ul data-upload-list aria-live="polite"></ul></div>
            <?php endif; ?>
            <div class="editor-pane" id="visual-pane" data-pane="visual">
                <div class="editor-toolbar" role="toolbar" aria-label="<?= $e($t('Formatting')) ?>">
                    <div class="editor-toolbar-group editor-toolbar-history" aria-label="Historia zmian">
                        <?php foreach (['undo' => 'Undo', 'redo' => 'Redo'] as $command => $label): ?><button type="button" data-rich-command="<?= $command ?>" aria-label="<?= $e($t($label)) ?>" title="<?= $e($t($label)) ?>"><?= $editorIcon($command) ?></button><?php endforeach; ?>
                    </div>
                    <div class="editor-toolbar-group">
                        <label class="sr-only" for="heading-level"><?= $e($t('Heading')) ?></label>
                        <select id="heading-level" data-heading><option value="0"><?= $e($t('Paragraph')) ?></option><?php for ($level = 1; $level <= 6; $level++): ?><option value="<?= $level ?>">H<?= $level ?></option><?php endfor; ?></select>
                    </div>
                    <div class="editor-toolbar-group" aria-label="Format tekstu">
                        <?php foreach (['bold' => ['Bold', '<b>B</b>'], 'italic' => ['Italic', '<i>I</i>'], 'underline' => ['Underline', '<u>U</u>'], 'strike' => ['Strike', '<s>S</s>']] as $command => [$label, $symbol]): ?><button type="button" data-rich-command="<?= $command ?>" aria-label="<?= $e($t($label)) ?>" title="<?= $e($t($label)) ?>"><span aria-hidden="true"><?= $symbol ?></span></button><?php endforeach; ?>
                    </div>
                    <div class="editor-toolbar-group" aria-label="Wyróżnienie tekstu">
                        <details class="editor-toolbar-picker">
                            <summary aria-label="Kolor tekstu" title="Kolor tekstu"><span class="editor-toolbar-color-mark" aria-hidden="true">A</span><span class="editor-toolbar-picker-chevron" aria-hidden="true"></span></summary>
                            <div class="editor-toolbar-picker__panel editor-color-palette" role="group" aria-label="Paleta kolorów tekstu">
                                <strong>Kolor tekstu</strong>
                                <div class="editor-color-palette__swatches">
                                    <button type="button" data-text-color-choice="" aria-label="Kolor domyślny" title="Kolor domyślny" aria-pressed="true"><span class="editor-color-swatch editor-color-swatch--default" aria-hidden="true"></span></button>
                                    <?php foreach (['red' => ['Czerwony', 'var(--doc-red)'], 'orange' => ['Pomarańczowy', 'var(--doc-orange)'], 'green' => ['Zielony', 'var(--doc-green)'], 'blue' => ['Niebieski', 'var(--doc-blue)'], 'purple' => ['Fioletowy', 'var(--doc-purple)'], 'gray' => ['Szary', 'var(--doc-gray)']] as $value => [$label, $swatch]): ?>
                                        <button type="button" data-text-color-choice="<?= $e($value) ?>" style="--editor-color-swatch:<?= $e($swatch) ?>" aria-label="Kolor tekstu: <?= $e($label) ?>" title="<?= $e($label) ?>" aria-pressed="false"><span class="editor-color-swatch" aria-hidden="true"></span></button>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        </details>
                        <details class="editor-toolbar-picker">
                            <summary aria-label="Wyróżnienie tekstu" title="Wyróżnienie tekstu"><span class="editor-toolbar-highlight-mark" aria-hidden="true">▰</span><span class="editor-toolbar-picker-chevron" aria-hidden="true"></span></summary>
                            <div class="editor-toolbar-picker__panel"><label>Wyróżnienie<select data-highlight aria-label="Wyróżnienie tekstu"><option value="">Brak</option><option value="yellow">Żółte</option><option value="green">Zielone</option><option value="blue">Niebieskie</option><option value="pink">Różowe</option></select></label></div>
                        </details>
                    </div>
                    <div class="editor-toolbar-group" aria-label="Struktura tekstu">
                        <button type="button" data-rich-command="blockquote" aria-label="<?= $e($t('Quote')) ?>" title="<?= $e($t('Quote')) ?>"><span aria-hidden="true">❝</span></button>
                        <button type="button" data-rich-command="bulletList" aria-label="<?= $e($t('Bullet list')) ?>" title="<?= $e($t('Bullet list')) ?>"><?= $editorIcon('list') ?></button>
                        <button type="button" data-rich-command="orderedList" aria-label="<?= $e($t('Numbered list')) ?>" title="<?= $e($t('Numbered list')) ?>"><span aria-hidden="true">1.</span></button>
                        <button type="button" data-rich-command="taskList" aria-label="<?= $e($t('Checklist')) ?>" title="<?= $e($t('Checklist')) ?>"><?= $editorIcon('check') ?></button>
                        <button type="button" data-rich-command="lift" aria-label="<?= $e($t('Outdent list')) ?>" title="<?= $e($t('Outdent list')) ?>"><?= $editorIcon('outdent') ?></button>
                        <button type="button" data-rich-command="sink" aria-label="<?= $e($t('Indent list')) ?>" title="<?= $e($t('Indent list')) ?>"><?= $editorIcon('indent') ?></button>
                    </div>
                    <div class="editor-toolbar-group" aria-label="Wyrównanie tekstu">
                        <details class="editor-toolbar-picker" data-align-picker>
                            <summary aria-label="Wyrównanie tekstu" title="Wyrównanie tekstu"><?= $editorIcon('align') ?><span class="editor-toolbar-picker-chevron" aria-hidden="true"></span></summary>
                            <div class="editor-toolbar-picker__panel editor-align-picker" role="group" aria-label="Wyrównanie tekstu">
                                <header class="editor-align-picker__heading"><span class="editor-align-picker__icon" aria-hidden="true"><?= $editorIcon('align') ?></span><span><strong>Wyrównanie</strong><small>Ustaw położenie tekstu</small></span></header>
                                <div class="editor-align-picker__options">
                                    <button type="button" data-text-align-choice="left" aria-label="Do lewej" title="Do lewej" aria-pressed="true"><?= $editorIcon('align-left') ?><span>Do lewej</span></button>
                                    <button type="button" data-text-align-choice="center" aria-label="Wyśrodkuj" title="Wyśrodkuj" aria-pressed="false"><?= $editorIcon('align-center') ?><span>Wyśrodkuj</span></button>
                                    <button type="button" data-text-align-choice="right" aria-label="Do prawej" title="Do prawej" aria-pressed="false"><?= $editorIcon('align-right') ?><span>Do prawej</span></button>
                                    <button type="button" data-text-align-choice="justify" aria-label="Wyjustuj" title="Wyjustuj" aria-pressed="false"><?= $editorIcon('align-justify') ?><span>Wyjustuj</span></button>
                                </div>
                            </div>
                        </details>
                    </div>
                    <div class="editor-toolbar-group" aria-label="Wstaw element">
                        <button type="button" data-insert-table aria-label="<?= $e($t('Table')) ?>" title="<?= $e($t('Table')) ?>"><?= $editorIcon('table') ?></button>
                        <button type="button" data-open-link aria-label="<?= $e($t('Link')) ?>" title="<?= $e($t('Link')) ?>"><?= $editorIcon('link') ?></button>
                        <button type="button" data-open-code aria-label="<?= $e($t('Code block')) ?>" title="<?= $e($t('Code block')) ?>"><?= $editorIcon('code') ?></button>
                        <button type="button" data-open-callout aria-label="<?= $e($t('Callout')) ?>" title="<?= $e($t('Callout')) ?>"><?= $editorIcon('callout') ?></button>
                        <button type="button" data-open-macro aria-label="<?= $e($t('Macro')) ?>" title="<?= $e($t('Macro')) ?>"><?= $editorIcon('macro') ?></button>
                        <?php if ($canUploadImage): ?><button type="button" data-image-picker aria-label="<?= $e($t('Image')) ?>" title="<?= $e($t('Image')) ?>"><?= $editorIcon('image') ?></button><input type="file" accept="image/png,image/jpeg,image/gif,image/webp" data-image-file hidden><?php endif; ?>
                        <details class="editor-toolbar-picker editor-emoji-picker">
                            <summary aria-label="Wstaw emoji" title="Wstaw emoji"><?= $editorIcon('emoji') ?><span class="editor-toolbar-picker-chevron" aria-hidden="true"></span></summary>
                            <div class="editor-toolbar-picker__panel editor-emoji-picker__panel" role="group" aria-label="Wybierz emoji">
                                <?php foreach (['🙂' => 'Uśmiech', '👍' => 'Kciuk w górę', '❤️' => 'Serce', '✅' => 'Gotowe', '🎉' => 'Świętowanie', '🔥' => 'Ogień', '🚀' => 'Start', '💡' => 'Pomysł'] as $emoji => $label): ?><button type="button" data-editor-emoji="<?= $e($emoji) ?>" aria-label="Wstaw emoji: <?= $e($label) ?>" title="<?= $e($label) ?>"><?= $e($emoji) ?></button><?php endforeach; ?>
                            </div>
                        </details>
                        <details class="format-menu"><summary aria-label="Więcej opcji edycji" title="Dodaj element"><?= $editorIcon('plus') ?></summary><div class="format-menu-content">
                        <?php foreach (['superscript' => ['Indeks górny', 'superscript'], 'subscript' => ['Indeks dolny', 'subscript'], 'inlineCode' => ['Kod w linii', 'inline-code'], 'separator' => ['Separator', 'separator'], 'clearFormat' => ['Usuń formatowanie', 'clear-format']] as $command => [$label, $icon]): ?>
                            <button type="button" class="format-menu__action" data-advanced-command="<?= $e($command) ?>" aria-label="<?= $e($label) ?>"><span class="format-menu__icon" aria-hidden="true"><?= $editorIcon($icon) ?></span><span><?= $e($label) ?></span></button>
                        <?php endforeach; ?>
                        <button type="button" class="format-menu__action" data-open-block aria-label="Sekcja lub kolumny"><span class="format-menu__icon" aria-hidden="true"><?= $editorIcon('columns') ?></span><span>Sekcja lub kolumny</span></button>
                        <button type="button" class="format-menu__action" data-open-diagram aria-label="Diagram"><span class="format-menu__icon" aria-hidden="true"><?= $editorIcon('diagram') ?></span><span>Diagram</span></button>
                        </div></details>
                    </div>
                </div>
                <div class="editor-table-tools" data-table-tools hidden role="toolbar" aria-label="<?= $e($t('Table editing')) ?>">
                    <header class="table-tools__heading"><span aria-hidden="true"><?= $editorIcon('table') ?></span><span><strong>Edytuj tabelę</strong><small>Dodawaj, usuwaj i porządkuj wiersze oraz kolumny</small></span></header>
                    <div class="table-tools__groups">
                        <section class="table-tools__group" aria-label="Wiersze">
                            <h3>Wiersze</h3>
                            <div class="table-tools__buttons">
                                <?php foreach (['addRowBefore' => ['Row before', 'row-before'], 'addRowAfter' => ['Row after', 'row-after'], 'deleteRow' => ['Delete row', 'trash']] as $command => [$label, $icon]): ?>
                                    <button type="button" class="table-tools__action<?= $command === 'deleteRow' ? ' table-tools__action--danger' : '' ?>" data-table-command="<?= $command ?>" title="<?= $e($t($label)) ?>"><?= $editorIcon($icon) ?><span><?= $e($t($label)) ?></span></button>
                                <?php endforeach; ?>
                                <button type="button" class="table-tools__action" data-table-move="row:-1" title="Wiersz w górę"><?= $editorIcon('move-up') ?><span>W górę</span></button>
                                <button type="button" class="table-tools__action" data-table-move="row:1" title="Wiersz w dół"><?= $editorIcon('move-down') ?><span>W dół</span></button>
                            </div>
                        </section>
                        <section class="table-tools__group" aria-label="Kolumny">
                            <h3>Kolumny</h3>
                            <div class="table-tools__buttons">
                                <?php foreach (['addColumnBefore' => ['Column before', 'column-before'], 'addColumnAfter' => ['Column after', 'column-after'], 'deleteColumn' => ['Delete column', 'trash']] as $command => [$label, $icon]): ?>
                                    <button type="button" class="table-tools__action<?= $command === 'deleteColumn' ? ' table-tools__action--danger' : '' ?>" data-table-command="<?= $command ?>" title="<?= $e($t($label)) ?>"><?= $editorIcon($icon) ?><span><?= $e($t($label)) ?></span></button>
                                <?php endforeach; ?>
                                <button type="button" class="table-tools__action" data-table-move="column:-1" title="Kolumna w lewo"><?= $editorIcon('move-left') ?><span>W lewo</span></button>
                                <button type="button" class="table-tools__action" data-table-move="column:1" title="Kolumna w prawo"><?= $editorIcon('move-right') ?><span>W prawo</span></button>
                            </div>
                        </section>
                        <section class="table-tools__group table-tools__group--cells" aria-label="Komórki">
                            <h3>Komórki</h3>
                            <div class="table-tools__buttons">
                                <button type="button" class="table-tools__action" data-table-command="toggleHeaderRow" title="Wiersz nagłówka"><?= $editorIcon('header-row') ?><span>Wiersz nagłówka</span></button>
                                <button type="button" class="table-tools__action" data-table-command="toggleHeaderColumn" title="Kolumna nagłówkowa"><?= $editorIcon('header-column') ?><span>Kolumna nagłówkowa</span></button>
                                <button type="button" class="table-tools__action" data-table-command="mergeCells" title="Scal komórki"><?= $editorIcon('merge') ?><span>Scal komórki</span></button>
                                <button type="button" class="table-tools__action" data-table-command="splitCell" title="Podziel komórkę"><?= $editorIcon('split') ?><span>Podziel komórkę</span></button>
                            </div>
                        </section>
                        <section class="table-tools__group table-tools__group--appearance" aria-label="Wygląd komórki i kolumny">
                            <h3>Wygląd</h3>
                            <label class="table-tools__field">Tło komórki<select data-cell-color><option value="">Brak</option><option value="yellow">Żółte</option><option value="green">Zielone</option><option value="blue">Niebieskie</option><option value="pink">Różowe</option><option value="gray">Szare</option></select></label>
                            <label class="table-tools__field">Szerokość kolumny<span class="table-tools__width"><input type="number" min="40" max="1600" step="10" value="160" data-column-width><span>px</span></span></label>
                            <button type="button" class="table-tools__action" data-column-width-apply><?= $editorIcon('resize') ?><span>Zmień szerokość</span></button>
                        </section>
                    </div>
                    <footer class="table-tools__footer"><span>Zmiany można cofnąć przyciskiem Cofnij.</span><button type="button" class="table-tools__action table-tools__action--danger" data-table-command="deleteTable" title="Usuń całą tabelę"><?= $editorIcon('trash') ?><span>Usuń tabelę</span></button></footer>
                </div>
                <div data-code-tools hidden role="toolbar" class="editor-table-tools" aria-label="Blok kodu"><label><input type="checkbox" data-code-numbers> Numery linii</label><label><input type="checkbox" data-code-wrap> Zawijaj kod</label><button type="button" class="button" data-code-copy>Kopiuj kod</button></div>
                <div data-block-tools class="block-handles" role="toolbar" aria-label="Operacje bloków">
                    <button type="button" class="block-handle-add" data-block-add aria-label="Dodaj blok poniżej" title="Dodaj element"><?= $editorIcon('plus') ?></button>
                    <button type="button" class="block-handle-drag" draggable="true" data-block-drag aria-label="Przeciągnij bieżący blok" title="Przeciągnij blok"><?= $editorIcon('drag') ?></button>
                    <details data-block-menu>
                        <summary aria-label="Działania bloku" title="Więcej działań"><?= $editorIcon('more') ?></summary>
                        <div class="block-action-menu"><button type="button" data-block-action="copy">Kopiuj blok</button><button type="button" data-block-action="duplicate">Duplikuj blok</button><button type="button" data-block-action="up">W górę</button><button type="button" data-block-action="down">W dół</button><button type="button" data-block-action="delete">Usuń blok</button><label>Format<select data-block-transform><option value="">Format</option><option value="paragraph">Akapit</option><option value="h1">H1</option><option value="h2">H2</option><option value="h3">H3</option><option value="h4">H4</option><option value="h5">H5</option><option value="h6">H6</option><option value="codeBlock">Kod</option><option value="blockquote">Cytat</option></select></label></div>
                    </details>
                </div><span class="sr-only" data-block-feedback role="status"></span>
                <div data-rich-host data-label="<?= $e($t('Page content (Visual)')) ?>"><?= $visualValue ?></div>
                <div data-retained-html hidden class="panel">
                    <p><?= $e($t('Original HTML is retained for metadata-only editing.')) ?></p>
                    <button type="button" class="button button--secondary" data-reopen-model-review><?= $e($t('Review transformation again')) ?></button>
                    <div class="document-content" data-retained-content></div>
                </div>
                <textarea name="content_html" class="hidden-field" data-html-field><?= $e($visualValue) ?></textarea>
            </div>

            <div class="editor-pane" id="markdown-pane" data-pane="markdown">
                <textarea name="content_markdown" class="markdown-editor" aria-label="<?= $e($t('Page content (Markdown)')) ?>" rows="24" data-markdown-editor spellcheck="false"><?= $e($markdownValue) ?></textarea>
                <div class="markdown-editor-actions">
                    <button type="button" class="button button--secondary" data-markdown-preview><?= $e($t('Preview Markdown')) ?></button>
                    <p class="field-help"><?= $e($t('Supports headings, lists, blockquotes, links, emphasis, inline code, fenced code blocks and wiki links such as [[Linux]].')) ?></p>
                </div>
                <p class="markdown-editor-status" role="status" data-preview-status></p>
                <div class="document-content markdown-preview" data-markdown-preview-content hidden></div>
            </div>
        </div>

        <aside class="workspace-inspector" aria-label="Właściwości obrazu">
            <?php if ($canUploadImage): ?>
                <div class="image-inspector" data-image-inspector hidden>
                    <header class="image-inspector__heading">
                        <span class="image-inspector__icon"><?= $editorIcon('image') ?></span>
                        <h3><?= $e($t('Image')) ?></h3>
                    </header>
                    <div class="image-inspector__fields">
                    <label><?= $e($t('Alt text')) ?>
                        <input type="text" maxlength="500" data-image-alt placeholder="<?= $e($t('Describe the image')) ?>">
                    </label>
                    <label><?= $e($t('Caption')) ?>
                        <textarea rows="2" maxlength="1000" data-image-caption placeholder="<?= $e($t('Optional caption')) ?>"></textarea>
                    </label>
                    <label><?= $e($t('Display width')) ?>
                        <span class="image-inspector__number"><input type="number" min="64" max="1600" step="1" data-image-width><span aria-hidden="true">px</span></span>
                    </label>
                    <label><?= $e($t('Image alignment')) ?><select data-image-align><option value="left">Do lewej</option><option value="center">Na środku</option><option value="right">Do prawej</option></select></label>
                    <div class="image-inspector__ratio-field">
                        <span class="image-inspector__field-label"><?= $e($t('Proportions')) ?></span>
                        <label class="image-inspector__ratio"><input type="checkbox" checked data-image-ratio><span><?= $e($t('Keep original proportions')) ?></span></label>
                    </div>
                    <label><?= $e($t('Height for custom proportions')) ?><span class="image-inspector__number"><input type="number" min="32" max="1600" data-image-height><span aria-hidden="true">px</span></span></label>
                    </div>
                    <a class="image-inspector__preview" data-image-preview target="_blank" rel="noopener"><?= $editorIcon('preview') ?><span><?= $e($t('Open protected preview')) ?></span></a>
                    <div class="form-actions">
                        <button class="button button--ghost" type="button" data-image-remove><?= $e($t('Remove')) ?></button>
                        <button class="button button--primary" type="button" data-image-apply><?= $e($t('Apply')) ?></button>
                    </div>
                    <p class="field-help"><?= $e($t('Set image alt text, caption, size and alignment.')) ?></p>
                </div>
            <?php endif; ?>

        </aside>
        <aside class="workspace-templates" data-workspace-templates aria-labelledby="workspace-templates-heading" <?= $isEdit ? 'hidden' : '' ?>>
            <div class="template-panel-heading"><h2 id="workspace-templates-heading">Szablony</h2><button type="button" class="icon-button" data-close-templates aria-label="Zamknij panel szablonów"><?= $editorIcon('close') ?></button></div>
            <p class="field-help">Zacznij od struktury i dopasuj ją do swoich potrzeb.</p><label class="sr-only" for="template-search">Szukaj szablonu</label><input id="template-search" type="search" data-template-search placeholder="Szukaj szablonu…">
            <label>Kategoria<select data-template-category><option value="">Wszystkie</option><option>Techniczne</option><option>Wiedza</option><option>Zespół</option><option>Operacje</option><option>Własne</option></select></label>
            <label class="template-pin"><input type="checkbox" data-pin-templates> Pozostaw panel podczas pisania</label><div data-template-list></div><p data-template-status role="status"></p>
        </aside>
        </div>
    </form>
</section>

<noscript><p class="alert editor-no-script">JavaScript jest wyłączony. Zapis formularza działa bez narzędzi dokumentu i autozapisu.</p></noscript>
<script src="/assets/wiki-editor.js?v=<?= $e((string) (@filemtime($app->basePath('public/assets/wiki-editor.js')) ?: 1)) ?>" defer></script>
