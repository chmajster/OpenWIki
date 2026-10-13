<header class="workspace-header">
    <a class="workspace-close icon-button" data-editor-cancel href="<?= $isEdit ? '/spaces/' . rawurlencode($space['space_key']) . '/pages/' . rawurlencode($page['slug']) : '/spaces/' . rawurlencode($space['space_key']) ?>" aria-label="Zamknij edytor i zachowaj szkic"><?= $editorIcon('back') ?></a>
    <button class="icon-button" type="button" data-toggle-tree aria-controls="workspace-tree" aria-expanded="true" aria-label="Pokaż lub ukryj drzewo stron"><?= $editorIcon('panel') ?></button>
    <nav class="workspace-breadcrumbs" aria-label="Lokalizacja dokumentu"><a href="/spaces/<?= rawurlencode($space['space_key']) ?>"><?= $e($space['name']) ?></a><span aria-hidden="true">/</span><span data-workspace-parent><?= $e($parentTitle ?? 'Główny poziom') ?></span><span aria-hidden="true">/</span><span data-workspace-title><?= $isEdit ? $e($titleValue) : 'Nowa strona' ?></span></nav>
    <details class="workspace-save-popover" data-close-on-escape><summary><span class="save-indicator" data-save-summary role="status" aria-live="polite">Niezapisane zmiany</span></summary><div class="workspace-popover">
        <div class="editor-save-state" data-save-state data-state="unsaved" role="status">Autozapis szkicu jest włączony.</div>
        <p class="field-help" data-local-state role="status">Kopia lokalna nie potwierdza zapisu na serwerze.</p>
        <button class="button button--secondary" type="button" data-save-draft>Zapisz szkic teraz (Ctrl/Cmd+S)</button>
        <button type="button" class="button" data-resume-save hidden>Sprawdź konto i wznów zapis</button>
        <a href="/login" target="_blank" rel="noopener" data-editor-signin>Zaloguj się ponownie w osobnej karcie</a>
        <div data-draft-conflict hidden role="alert"><p>Serwerowy szkic tego kontekstu zmienił się. Wersja bazowa pozostaje zachowana.</p><pre data-draft-conflict-current></pre><button type="button" class="button" data-draft-fork>Zachowaj pracę jako osobny szkic</button></div>
    </div></details>
    <button class="workspace-preview icon-button" type="button" data-document-preview aria-label="Podgląd strony"><?= $editorIcon('preview') ?><span>Podgląd</span></button>
    <details class="workspace-more" data-close-on-escape><summary class="icon-button" aria-label="Więcej działań">•••</summary><div class="workspace-popover">
        <button type="button" data-toggle-templates>Szablony</button><button type="button" data-open-publication>Ustawienia zapisu i dostępu</button>
        <button type="button" data-open-find>Znajdź i zamień</button><button type="button" data-focus-mode aria-pressed="false">Tryb skupienia</button>
        <button class="button" type="button" data-undo-conversion hidden>Wróć do treści sprzed konwersji</button>
        <label>Szerokość dokumentu<select data-document-width><option value="standard">Standardowa</option><option value="wide">Szeroka</option><option value="full">Pełna</option></select></label>
        <label><?= $e($t('Theme')) ?><select data-workspace-theme data-theme-select><option value="system"><?= $e($t('Follow system')) ?></option><option value="light"><?= $e($t('Light')) ?></option><option value="dark"><?= $e($t('Dark')) ?></option></select></label>
        <p class="field-help" data-version-hint><?= $isEdit ? 'Wersja bazowa: ' . (int) $field('base_version', $page['version']) : 'Pierwszy zapis utworzy rewizję 1.' ?></p>
        <span data-word-count></span>
    </div></details>
    <button class="button button--primary workspace-publish" type="submit" data-save-button><?= $isEdit ? 'Zapisz zmiany' : ($canPublish ? 'Opublikuj' : 'Zapisz stronę') ?></button>
</header>
