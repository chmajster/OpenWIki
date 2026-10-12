<?php
$text = static fn (mixed $value): string => is_scalar($value) ? (string) $value : '';
$e = static fn (mixed $value): string => htmlspecialchars($text($value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$old = $old ?? [];
$isEdit = $page !== null;
$field = static function (string $key, mixed $fallback = '') use ($old): mixed {
    return array_key_exists($key, $old) ? $old[$key] : $fallback;
};

$titleValue = $field('title', $page['title'] ?? '');
$slugValue = $field('slug', $page['slug'] ?? '');
$parentValue = $field('parent_id', $page['parent_id'] ?? '');
$statusValue = $field('status', $page['status'] ?? 'draft');
$formatValue = $field('content_format', $page['content_format'] ?? 'visual');
$markdownValue = $field('content_markdown', $page['content_markdown'] ?? '');
$visualValue = (new \OpenWiki\Security\HtmlSanitizer())->sanitize($text($field('content_html', $page['content_html'] ?? '<p></p>')));
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
?>
<section
    class="editor-shell"
    data-wiki-editor
    data-is-edit="<?= $isEdit ? '1' : '0' ?>"
    data-user-id="<?= (int) $currentUser['id'] ?>"
    data-instance-id="<?= substr(hash('sha256', $app->basePath() . \OpenWiki\Core\Env::get('APP_URL', '')), 0, 16) ?>"
    data-space-id="<?= (int) $space['id'] ?>"
    data-page-id="<?= (int) ($page['id'] ?? 0) ?>"
    data-drafts-url="/spaces/<?= rawurlencode($space['space_key']) ?>/editor-drafts"
    data-files-url="/spaces/<?= rawurlencode($space['space_key']) ?>/editor-files"
    data-preview-url="/spaces/<?= rawurlencode($space['space_key']) ?>/editor-preview"
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
    <dialog data-recovery-dialog class="editor-dialog"><h2>Porównanie kopii odzyskiwania</h2><p data-recovery-comparison-info></p><div class="comparison-grid"><div><h3>Aktualny dokument</h3><pre data-recovery-current></pre></div><div><h3>Wybrana kopia</h3><pre data-recovery-copy></pre></div></div><button type="button" class="button" data-dialog-close>Zamknij</button></dialog>
    <dialog data-table-dialog class="editor-dialog"><h2>Wstaw tabelę</h2><label>Wiersze<input type="number" min="1" max="50" value="3" data-table-rows></label><label>Kolumny<input type="number" min="1" max="30" value="3" data-table-cols></label><label><input type="checkbox" checked data-table-header> Wiersz nagłówkowy</label><p role="alert" data-table-error></p><button class="button" type="button" data-table-insert>Wstaw tabelę</button><button class="button" type="button" data-dialog-close>Anuluj</button></dialog>
    <dialog data-block-dialog class="editor-dialog"><h2>Blok dokumentu</h2><label>Rodzaj<select data-block-kind><option value="disclosure">Zwijana sekcja</option><option value="columns2">Dwie kolumny</option><option value="columns3">Trzy kolumny</option></select></label><label>Tytuł sekcji<input maxlength="255" value="Szczegóły" data-block-title></label><button type="button" class="button" data-block-insert>Wstaw lub zmień blok</button><button type="button" class="button" data-dialog-close>Anuluj</button></dialog>
    <dialog data-diagram-dialog class="editor-dialog"><h2>Diagram Mermaid</h2><label>Źródło<textarea rows="8" maxlength="20000" spellcheck="false" data-diagram-source></textarea></label><button type="button" class="button" data-diagram-render>Podgląd</button><p role="status" data-diagram-status></p><div data-diagram-preview></div><button type="button" class="button" data-diagram-insert>Zapisz źródło w dokumencie</button><button type="button" class="button" data-dialog-close>Zamknij</button></dialog>
    <dialog data-find-dialog class="editor-dialog"><h2>Znajdź i zamień</h2><label>Szukaj<input data-find-text></label><label>Zamień na<input data-replace-text></label><label><input type="checkbox" data-find-case> Rozróżniaj wielkość liter</label><p role="status" data-find-status></p><button type="button" class="button" data-find-next>Następne</button><button type="button" class="button" data-replace-one>Zamień</button><button type="button" class="button" data-replace-all>Zamień wszystkie</button><button type="button" class="button" data-dialog-close>Zamknij</button></dialog>
    <div data-command-menu class="command-menu" role="listbox" aria-label="Polecenia edytora" hidden></div>
    <div data-text-context class="text-context" hidden role="toolbar" aria-label="Zaznaczony tekst"><button type="button" data-context-command="bold">Pogrubienie</button><button type="button" data-context-command="italic">Kursywa</button><button type="button" data-context-command="link">Link</button><button type="button" data-context-command="clear">Usuń format</button></div>
    <div class="editor-heading">
        <div>
            <div class="breadcrumbs">
                <a href="/spaces/<?= rawurlencode($space['space_key']) ?>"><?= $e($space['name']) ?></a>
                <span>/</span>
                <span data-editor-heading><?= $e($t($isEdit ? 'Edit page' : 'Create page')) ?></span>
            </div>
            <h1 data-editor-heading><?= $e($t($isEdit ? 'Edit page' : 'Create page')) ?></h1>
        </div>
        <?php if ($isEdit): ?>
            <a class="button button--ghost" data-editor-cancel href="/spaces/<?= rawurlencode($space['space_key']) ?>/pages/<?= rawurlencode($page['slug']) ?>"><?= $e($t('Cancel')) ?></a>
        <?php else: ?>
            <a class="button button--ghost" data-editor-cancel href="/spaces/<?= rawurlencode($space['space_key']) ?>"><?= $e($t('Cancel')) ?></a>
        <?php endif; ?>
    </div>

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
    <div class="alert alert--warning editor-recovery" data-recovery-banner hidden>
        <span data-recovery-text><?= $e($t('Recovered content is available.')) ?></span>
        <label><?= $e($t('Recovery copy')) ?> <select data-recovery-select></select></label>
        <div class="recovery-actions">
            <button class="button button--ghost" type="button" data-compare-recovery>Porównaj kopię</button><button class="button button--secondary" type="button" data-restore-recovery><?= $e($t('Restore recovered content')) ?></button>
            <button class="button button--ghost" type="button" data-hide-recovery><?= $e($t('Ignore')) ?></button>
        </div>
    </div>
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
        <div role="alert" data-link-error></div><button type="button" class="button" data-link-insert><?= $e($t('Insert link')) ?></button><button type="button" class="button button--ghost" data-dialog-close><?= $e($t('Cancel')) ?></button>
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
    <form method="post" action="<?= $e($formAction) ?>" class="editor-form" data-editor-form>
        <input type="hidden" name="_token" value="<?= $e($csrfToken) ?>">
        <input type="hidden" name="base_version" value="<?= $e($field('base_version', $page['version'] ?? 0)) ?>">
        <input type="hidden" name="content_format" value="<?= $e($formatValue) ?>" data-content-format>

        <div class="editor-main card">
            <details class="editor-metadata" open data-editor-metadata><summary>Metadane strony</summary>
            <div class="editor-fields">
                <label class="sr-only" for="page-title"><?= $e($t('Title')) ?></label>
                <input id="page-title" class="title-input" name="title" required maxlength="255" placeholder="<?= $e($t('Page title')) ?>" value="<?= $e($titleValue) ?>">

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
                            <?php if ($app->auth()->can('page.archive') || $statusValue === 'archived'): ?>
                                <option value="archived" <?= $statusValue === 'archived' ? 'selected' : '' ?>><?= $e($t('Archived')) ?></option>
                            <?php endif; ?>
                        </select>
                    </label>
                </div>

                <label><?= $e($t('Tags')) ?>
                    <input name="tags" maxlength="4000" value="<?= $e($tagValue) ?>" placeholder="<?= $e($t('linux, network, procedure')) ?>">
                    <span class="field-help"><?= $e($t('Separate tags with commas. Maximum 30 tags.')) ?></span>
                </label>

                <?php if (!$isEdit && $templates !== []): ?>
                    <label><?= $e($t('Start from template')) ?>
                        <select data-template-selector>
                            <option value=""><?= $e($t('Blank content')) ?></option>
                            <?php foreach ($templates as $template): ?>
                                <option
                                    value="<?= (int) $template['id'] ?>"
                                    data-html="<?= $e($template['content_html']) ?>"
                                    data-markdown="<?= $e($template['content_markdown'] ?? '') ?>"
                                ><?= $e($template['name']) ?> — <?= $e($template['description'] ?? '') ?></option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                <?php endif; ?>
            </div>

                        <label><?= $e($t('Change summary')) ?>
                <textarea name="change_summary" rows="3" maxlength="500" placeholder="<?= $e($t('Describe this change')) ?>"><?= $e($changeSummary) ?></textarea>
            </label>
</details>
            <div class="editor-tabs" role="tablist" aria-label="<?= $e($t('Editor mode')) ?>">
                <button type="button" class="editor-tab" role="tab" aria-controls="visual-pane" data-editor-mode="visual"><?= $e($t('Visual')) ?></button>
                <button type="button" class="editor-tab" role="tab" aria-controls="markdown-pane" data-editor-mode="markdown">Markdown</button>
            </div>

            <?php if ($canUploadImage): ?>
            <div class="upload-area"><button type="button" class="button" data-attachment-picker>Dodaj plik</button><input type="file" multiple hidden data-attachment-file><span>Obrazy i pliki można też przeciągnąć do edytora.</span><ul data-upload-list aria-live="polite"></ul></div>
            <?php endif; ?>
            <div class="editor-pane" id="visual-pane" data-pane="visual">
                <div class="editor-workspace-tools" role="toolbar" aria-label="Praca z dokumentem"><button type="button" class="button" data-focus-mode aria-pressed="false">Tryb skupienia</button><button type="button" class="button" data-open-find>Znajdź i zamień</button><span data-word-count></span><span data-focus-state></span></div>
                <div class="editor-toolbar" role="toolbar" aria-label="<?= $e($t('Formatting')) ?>">
                    <label class="sr-only" for="heading-level"><?= $e($t('Heading')) ?></label>
                    <select id="heading-level" data-heading><option value="0"><?= $e($t('Paragraph')) ?></option><?php for ($level = 1; $level <= 6; $level++): ?><option value="<?= $level ?>">H<?= $level ?></option><?php endfor; ?></select>
                    <?php foreach (['bold' => 'Bold', 'italic' => 'Italic', 'underline' => 'Underline', 'strike' => 'Strike', 'blockquote' => 'Quote', 'bulletList' => 'Bullet list', 'orderedList' => 'Numbered list', 'taskList' => 'Checklist', 'sink' => 'Indent list', 'lift' => 'Outdent list', 'undo' => 'Undo', 'redo' => 'Redo'] as $command => $label): ?>
                        <button type="button" data-rich-command="<?= $command ?>" title="<?= $e($t($label)) ?>"><?= $e($t($label)) ?></button>
                    <?php endforeach; ?>
                    <button type="button" data-insert-table><?= $e($t('Table')) ?></button>
                    <button type="button" data-open-link><?= $e($t('Link')) ?></button>
                    <button type="button" data-open-code><?= $e($t('Code block')) ?></button>
                    <button type="button" data-open-callout><?= $e($t('Callout')) ?></button>
                    <button type="button" data-open-macro><?= $e($t('Macro')) ?></button>
                    <details class="format-menu"><summary>Więcej formatowania i bloków</summary><div class="format-menu-content">
                    <button type="button" data-advanced-command="superscript">Indeks górny</button><button type="button" data-advanced-command="subscript">Indeks dolny</button><button type="button" data-advanced-command="inlineCode">Kod w linii</button><button type="button" data-advanced-command="separator">Separator</button><button type="button" data-advanced-command="clearFormat">Usuń formatowanie</button>
                    <label>Kolor<select data-text-color><option value="">Domyślny</option><?php foreach (['red'=>'Czerwony','blue'=>'Niebieski','green'=>'Zielony','orange'=>'Pomarańczowy','purple'=>'Fioletowy','gray'=>'Szary'] as $value=>$label): ?><option value="<?= $value ?>"><?= $label ?></option><?php endforeach; ?></select></label>
                    <label>Wyróżnienie<select data-highlight><option value="">Brak</option><option value="yellow">Żółte</option><option value="green">Zielone</option><option value="blue">Niebieskie</option><option value="pink">Różowe</option></select></label>
                    <label>Wyrównanie<select data-text-align><option value="left">Do lewej</option><option value="center">Wyśrodkowane</option><option value="right">Do prawej</option><option value="justify">Wyjustowane</option></select></label>
                    <button type="button" data-open-block>Sekcja lub kolumny</button><button type="button" data-open-diagram>Diagram</button>
                    </div></details>
                    <?php if ($canUploadImage): ?><button type="button" data-image-picker><?= $e($t('Image')) ?></button><input type="file" accept="image/png,image/jpeg,image/gif,image/webp" data-image-file hidden><?php endif; ?>
                </div>
                <div class="editor-table-tools" data-table-tools hidden role="toolbar" aria-label="<?= $e($t('Table editing')) ?>">
                    <?php foreach (['addRowBefore' => 'Row before', 'addRowAfter' => 'Row after', 'deleteRow' => 'Delete row', 'addColumnBefore' => 'Column before', 'addColumnAfter' => 'Column after', 'deleteColumn' => 'Delete column', 'toggleHeaderRow' => 'Header row', 'mergeCells' => 'Merge cells', 'splitCell' => 'Split cell', 'deleteTable' => 'Delete table'] as $command => $label): ?>
                        <button type="button" data-table-command="<?= $command ?>"><?= $e($t($label)) ?></button>
                    <?php endforeach; ?>
                    <button type="button" data-table-command="toggleHeaderColumn">Kolumna nagłówkowa</button>
                    <button type="button" data-table-move="row:-1">Wiersz w górę</button><button type="button" data-table-move="row:1">Wiersz w dół</button><button type="button" data-table-move="column:-1">Kolumna w lewo</button><button type="button" data-table-move="column:1">Kolumna w prawo</button>
                    <label>Tło komórki<select data-cell-color><option value="">Brak</option><option value="yellow">Żółte</option><option value="green">Zielone</option><option value="blue">Niebieskie</option><option value="pink">Różowe</option><option value="gray">Szare</option></select></label><label>Szerokość kolumny<input type="number" min="40" max="1600" step="10" value="160" data-column-width></label><button type="button" data-column-width-apply>Zmień szerokość</button>
                </div>
                <div data-code-tools hidden role="toolbar" class="editor-table-tools" aria-label="Blok kodu"><label><input type="checkbox" data-code-numbers> Numery linii</label><label><input type="checkbox" data-code-wrap> Zawijaj kod</label><button type="button" class="button" data-code-copy>Kopiuj kod</button></div>
                <div data-block-tools class="editor-table-tools" role="toolbar" aria-label="Operacje bloków"><button type="button" draggable="true" data-block-drag aria-label="Przeciągnij bieżący blok">↕ Blok</button><button type="button" data-block-action="copy">Kopiuj blok</button><button type="button" data-block-action="duplicate">Duplikuj blok</button><button type="button" data-block-action="up">W górę</button><button type="button" data-block-action="down">W dół</button><button type="button" data-block-action="delete">Usuń blok</button><span data-block-feedback role="status"></span></div>
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
                <button type="button" class="button button--secondary" data-markdown-preview><?= $e($t('Preview Markdown')) ?></button>
                <div role="status" data-preview-status></div><div class="document-content markdown-preview" data-markdown-preview-content hidden></div>
                <p class="field-help"><?= $e($t('Supports headings, lists, blockquotes, links, emphasis, inline code, fenced code blocks and wiki links such as [[Linux]].')) ?></p>
            </div>
        </div>

        <aside class="editor-side card">
            <details class="editor-outline" open><summary>Nagłówki dokumentu</summary><nav data-editor-outline aria-label="Nagłówki dokumentu"></nav></details>
            <h2><?= $e($t('Save')) ?></h2>
            <?php if ($isEdit): ?>
                <p class="field-help" data-version-hint><?= $e($t('Based on version')) ?> <?= (int) $field('base_version', $page['version']) ?><?= $e($t('. Concurrent changes are protected by optimistic locking.')) ?></p>
            <?php else: ?>
                <p class="field-help" data-version-hint><?= $e($t('The initial save creates revision 1.')) ?></p>
            <?php endif; ?>
            <?php if ($canUploadImage): ?>
                <div class="image-inspector" data-image-inspector hidden>
                    <h3><?= $e($t('Image')) ?></h3>
                    <label><?= $e($t('Alt text')) ?>
                        <input type="text" maxlength="500" data-image-alt placeholder="<?= $e($t('Describe the image')) ?>">
                    </label>
                    <label><?= $e($t('Caption')) ?>
                        <textarea rows="2" maxlength="1000" data-image-caption placeholder="<?= $e($t('Optional caption')) ?>"></textarea>
                    </label>
                    <label><?= $e($t('Display width')) ?>
                        <input type="number" min="64" max="1600" step="1" data-image-width>
                    </label>
                    <label>Wyrównanie obrazu<select data-image-align><option value="left">Do lewej</option><option value="center">Na środku</option><option value="right">Do prawej</option></select></label>
                    <label>Proporcje<input type="checkbox" checked data-image-ratio> Zachowaj oryginalne proporcje</label><label>Wysokość przy ręcznych proporcjach<input type="number" min="32" max="1600" data-image-height></label><a data-image-preview target="_blank" rel="noopener">Otwórz chroniony podgląd</a>
                    <div class="form-actions">
                        <button class="button button--secondary" type="button" data-image-apply><?= $e($t('Apply')) ?></button>
                        <button class="button button--ghost" type="button" data-image-remove><?= $e($t('Remove')) ?></button>
                    </div>
                    <p class="field-help"><?= $e($t('Click an image in the visual editor to edit its alt text, caption and width.')) ?></p>
                </div>
            <?php endif; ?>

            <p class="field-help">Ctrl/Cmd+S: zapisz szkic · Ctrl/Cmd+Z: cofnij · Ctrl/Cmd+Shift+Z: ponów · Tab/Shift+Tab: poziom listy · Ctrl/Cmd+K: link · Ctrl/Cmd+Enter: wyjdź z kodu</p>
            <button class="button button--secondary button--block" type="button" data-save-draft>Zapisz szkic teraz (Ctrl/Cmd+S)</button>
            <button class="button button--primary button--block" type="submit" data-save-button><?= $isEdit ? 'Zastosuj zmiany do strony' : 'Utwórz stronę ze szkicu' ?></button>
            <button class="button button--secondary" type="button" data-undo-conversion hidden><?= $e($t('Return to content before conversion')) ?></button>
            <div class="editor-save-state" data-save-state role="status" aria-live="polite"><?= $e($t('Recovery copy enabled.')) ?></div>
            <div class="field-help" data-local-state role="status">Autozapis włączony: po 2 s bezczynności, najpóźniej po 10 s pisania.</div>
            <button type="button" class="button button--secondary" data-resume-save hidden>Sprawdź konto i wznów zapis</button>
            <a href="/login" target="_blank" rel="noopener" class="field-help" data-editor-signin>Zaloguj się ponownie w osobnej karcie</a>
            <div data-draft-conflict hidden role="alert"><p>Serwerowy szkic tego kontekstu zmienił się. Oryginalna wersja bazowa pozostaje zachowana.</p><pre data-draft-conflict-current></pre><button type="button" class="button" data-draft-fork>Zachowaj pracę jako osobny szkic</button></div>
        </aside>
    </form>
</section>

<script src="/assets/wiki-editor.js" defer></script>
