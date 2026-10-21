import {formatDocument} from './reader-format.js';
/** Workspace controls operate outside the ProseMirror document. Content changes use editor commands. */
export function setupDocumentWorkspace({root, editor, form, requestJson, snapshot, fields, safeHtml, applyTemplate, preserve, saveDraft}) {
    const q = selector => root.querySelector(selector);
    const title = form.elements.title;
    form.elements.publication_space_id.disabled = form.elements.publication_parent_id.disabled = false;
    const publication = q('[data-publication-dialog]');
    const confirm = q('[data-confirm-publication]');
    const space = q('[data-publication-space]');
    const parent = q('[data-publication-parent]');
    const error = q('[data-publication-error]');
    const metadata = q('[data-editor-metadata]');
    q('[data-publication-metadata]').append(metadata);
    metadata.open = true;
    form.elements.parent_id.closest('label').hidden = true;
    let contextSequence = 0, ready = false, previewSequence = 0;
    let documentContext = snapshot().documentKey + ':' + root.dataset.spaceId;
    const changed = input => input.dispatchEvent(new Event('input', {bubbles: true}));
    const growTitle = () => {
        title.style.height = 'auto'; title.style.height = title.scrollHeight + 'px';
        q('[data-workspace-title]').textContent = title.value || 'Nowa strona';
        q('[data-publication-title]').textContent = title.value || 'Strona bez tytułu';
    };
    title.addEventListener('input', growTitle);
    title.addEventListener('keydown', event => {
        if (event.key === 'Enter') {
            event.preventDefault();
            if (snapshot().format === 'markdown') q('[data-markdown-editor]').focus();
            else { editor.commands.focus('start'); editor.view.focus(); }
        }
    });
    root.querySelectorAll('[data-title-emoji]').forEach(button => button.onclick = () => {
        title.value = (button.dataset.titleEmoji + ' ' + title.value).slice(0, 255);
        changed(title); growTitle(); button.closest('details').open = false; title.focus();
    });
    const preferenceKey = 'openwiki:workspace:' + root.dataset.instanceId + ':' + root.dataset.userId;
    let preferences = {};
    try { preferences = JSON.parse(localStorage.getItem(preferenceKey) || '{}'); } catch { /* Storage is optional. */ }
    const store = () => { try { localStorage.setItem(preferenceKey, JSON.stringify(preferences)); } catch { /* Draft storage has its own feedback. */ } };
    const mobile = window.matchMedia('(max-width: 900px)');
    const tree = q('[data-workspace-tree]'), templates = q('[data-workspace-templates]');
    const showTree = value => {
        tree.hidden = !value; root.dataset.treeOpen = String(value);
        q('[data-toggle-tree]').setAttribute('aria-expanded', String(value));
    };
    showTree(!mobile.matches && preferences.tree !== false);
    q('[data-toggle-tree]').onclick = () => { showTree(tree.hidden); preferences.tree = !tree.hidden; store(); };
    mobile.addEventListener('change', () => showTree(!mobile.matches && preferences.tree !== false));
    let manualTemplates = false;
    const showTemplates = value => { templates.hidden = !value; root.dataset.templatesOpen = String(value); };
    showTemplates(root.dataset.isEdit !== '1' && !root.dataset.initialTemplate && !mobile.matches);
    q('[data-toggle-templates]').onclick = () => { manualTemplates = true; showTemplates(templates.hidden); closeMenus(); };
    q('[data-close-templates]').onclick = () => { manualTemplates = false; showTemplates(false); };
    editor.on('update', () => {
        if (!q('[data-pin-templates]').checked && !manualTemplates && editor.state.doc.textContent.trim()) showTemplates(false);
    });
    const width = q('[data-document-width]'); width.value = preferences.width || 'standard';
    const setWidth = () => { root.dataset.documentWidth = width.value; preferences.width = width.value; store(); };
    width.onchange = setWidth; setWidth();
    const closeMenus = () => root.querySelectorAll('[data-close-on-escape]').forEach(details => { details.open = false; });
    root.addEventListener('keydown', event => {
        if (event.key === 'Escape' && !event.target.closest('dialog')) { closeMenus(); if (mobile.matches) { showTree(false); showTemplates(false); } }
    });
    const globalHeader = document.querySelector('.topbar'), editorHeader = q('.workspace-header');
    const measureHeaders = () => {
        document.body.style.setProperty('--editor-global-offset', globalHeader.getBoundingClientRect().height + 'px');
        document.body.style.setProperty('--editor-bar-offset', editorHeader.getBoundingClientRect().height + 'px');
    };
    if (window.ResizeObserver) {
        const headers = new ResizeObserver(measureHeaders);
        headers.observe(globalHeader); headers.observe(editorHeader);
    }
    window.addEventListener('resize', measureHeaders); measureHeaders();
    q('[data-focus-mode]').onclick = () => {
        const active = root.dataset.workspaceFocus !== 'true'; root.dataset.workspaceFocus = String(active);
        q('[data-focus-mode]').setAttribute('aria-pressed', String(active)); closeMenus();
    };
    const state = q('[data-save-state]');
    const summarize = () => {
        const names = {unsaved: 'Niezapisane zmiany', saving: 'Zapisywanie…', server: 'Zapisano', saved: 'Zapisano', error: 'Błąd zapisu', conflict: 'Konflikt wersji', offline: 'Brak połączenia'};
        q('[data-save-summary]').textContent = navigator.onLine ? (names[state.dataset.state] || 'Niezapisane zmiany') : 'Brak połączenia';
        q('[data-save-summary]').dataset.state = navigator.onLine ? state.dataset.state : 'offline';
        if (publication.open && ['error', 'conflict'].includes(state.dataset.state)) error.textContent = state.textContent;
        if (['error', 'conflict'].includes(state.dataset.state)) q('.workspace-save-popover').open = true;
    };
    new MutationObserver(summarize).observe(state, {childList: true, attributes: true, attributeFilter: ['data-state'], subtree: true});
    window.addEventListener('online', summarize); window.addEventListener('offline', summarize); summarize();
    const loadContext = async () => {
        const sequence = ++contextSequence; ready = false; confirm.disabled = true; error.textContent = 'Sprawdzanie lokalizacji i uprawnień…';
        try {
            const query = new URLSearchParams({document_key: snapshot().documentKey, space_id: space.value, parent_id: parent.value});
            const data = await requestJson(root.dataset.workspaceUrl + '?' + query);
            if (sequence !== contextSequence) return;
            const selected = parent.value;
            parent.replaceChildren(new Option('Główny poziom przestrzeni', ''));
            data.parents.forEach(row => parent.add(new Option(row.title, String(row.id))));
            if (selected && !data.parents.some(row => String(row.id) === selected)) parent.add(new Option('Obecny rodzic (ograniczony dostęp)', selected));
            parent.value = selected;
            parent.disabled = data.can_change_parent === false;
            q('[data-workspace-parent]').textContent = data.parent?.title || (selected ? 'Rodzic z ograniczonym dostępem' : 'Główny poziom');
            q('[data-publication-access]').textContent = data.access;
            q('[data-publication-permission]').textContent = data.review_workflow ? 'Ta przestrzeń wymaga recenzji. Zapisz wersję roboczą, a następnie przekaż ją do recenzji.' : data.publish_reason;
            const status = form.elements.status;
            for (const option of status.options) option.disabled = option.value === 'published' ? !data.can_publish : option.value === 'archived' ? !data.can_archive : false;
            if (status.selectedOptions[0]?.disabled) { status.value = 'draft'; changed(status); }
            ready = true; error.textContent = ''; refresh();
        } catch (failure) {
            if (sequence !== contextSequence) return;
            error.textContent = failure.message;
            state.textContent = failure.message + ' Dokument i jego wersja bazowa pozostają w edytorze.';
            state.dataset.state = 'error';
            preserve(); refresh();
        }
    };
    const rememberLocation = () => {
        const before = [form.elements.publication_space_id.value, form.elements.publication_parent_id.value, form.elements.parent_id.value].join('|');
        form.elements.publication_space_id.value = space.value;
        form.elements.publication_parent_id.value = parent.value;
        if (root.dataset.isEdit === '1' || space.value === root.dataset.spaceId) form.elements.parent_id.value = parent.value;
        if (before !== [form.elements.publication_space_id.value, form.elements.publication_parent_id.value, form.elements.parent_id.value].join('|')) changed(form.elements.publication_space_id);
    };
    space.onchange = () => { parent.value = ''; rememberLocation(); loadContext(); };
    parent.onchange = () => { rememberLocation(); loadContext(); };
    const openPublication = () => {
        closeMenus(); growTitle();
        space.value = form.elements.publication_space_id.value || root.dataset.spaceId;
        parent.replaceChildren(new Option('Główny poziom przestrzeni', ''), new Option('Wybrany rodzic', form.elements.publication_parent_id.value || form.elements.parent_id.value));
        parent.value = form.elements.publication_parent_id.value || form.elements.parent_id.value;
        publication.showModal(); loadContext();
    };
    q('[data-save-button]').onclick = event => { event.preventDefault(); openPublication(); };
    q('[data-open-publication]').onclick = openPublication;
    form.addEventListener('submit', event => {
        if (root.dataset.publicationReplay === '1') return;
        if (!publication.open || !ready || q('[data-save-button]').disabled) { event.preventDefault(); event.stopImmediatePropagation(); return; }
        rememberLocation(); error.textContent = 'Zapisywanie dokumentu…'; publication.close();
    }, true);
    root.querySelectorAll('[data-dialog-close]').forEach(button => button.addEventListener('click', () => button.closest('dialog').close()));
    q('[data-editor-cancel]').addEventListener('click', async event => {
        event.preventDefault(); const target = event.currentTarget.href;
        if (!await preserve()) { state.textContent = 'Nie można zachować kopii lokalnej. Zapisz szkic przed zamknięciem.'; state.dataset.state = 'error'; closeMenus(); q('.workspace-save-popover').open = true; return; }
        await saveDraft();
        // Typing while the request was in flight must also have a durable copy before leaving.
        let sequence;
        do {
            sequence = snapshot().sequence;
            if (!await preserve()) { state.textContent = 'Nie można zachować najnowszych zmian. Pozostajesz w edytorze.'; state.dataset.state = 'error'; return; }
        } while (snapshot().sequence !== sequence);
        root.dataset.recoveryClose = String(sequence); window.location.assign(target);
    });
    q('[data-document-preview]').onclick = async () => {
        const dialog = q('[data-document-preview-dialog]'), status = q('[data-document-preview-status]'), captured = snapshot(), sequence = ++previewSequence;
        q('[data-document-preview-title]').textContent = captured.title || 'Strona bez tytułu'; q('[data-document-preview-content]').replaceChildren(); status.textContent = 'Przygotowywanie podglądu…'; dialog.showModal();
        try {
            const data = await requestJson(root.dataset.previewUrl, {...fields(captured), document_preview: true});
            if (sequence !== previewSequence || !dialog.open) return;
            q('[data-document-preview-content]').innerHTML = safeHtml(data.html); formatDocument(q('[data-document-preview-content]')); status.textContent = 'Podgląd bieżącej treści. Dokument nie został opublikowany.';
        } catch (failure) { if (sequence === previewSequence) status.textContent = failure.message; }
    };
    const catalog = JSON.parse(q('[data-template-catalog]').value), categories = [...new Set(catalog.map(row => row.category))];
    const category = q('[data-template-category]'); category.replaceChildren(new Option('Wszystkie', '')); categories.forEach(name => category.add(new Option(name, name)));
    let previewTemplate = null;
    const useTemplate = async row => {
        const current = snapshot();
        const nonempty = current.format === 'markdown' ? current.markdown.trim() : editor.state.doc.textContent.trim() || editor.state.doc.childCount > 1 || editor.state.doc.firstChild?.type.name !== 'paragraph';
        if (nonempty && !window.confirm('Zastąpić treść szablonem? Obecny dokument pozostanie w kopii odzyskiwania i będzie można do niego wrócić.')) return;
        if (!await preserve()) { q('[data-template-status]').textContent = 'Nie można zachować poprzedniej treści. Spróbuj ponownie po zapisaniu szkicu.'; return; }
        applyTemplate(row); showTemplates(false); q('[data-template-preview-dialog]').close(); editor.commands.focus();
    };
    const renderTemplates = () => {
        const list = q('[data-template-list]'), query = q('[data-template-search]').value.toLocaleLowerCase('pl'); list.replaceChildren();
        const blank = document.createElement('button'); blank.type = 'button'; blank.className = 'template-blank'; blank.textContent = '+ Pusta strona'; blank.onclick = () => useTemplate({content_html: '<p></p>', content_markdown: '', content_format: 'visual'}); list.append(blank);
        const rows = catalog.filter(row => (!category.value || category.value === row.category) && (row.display_name + ' ' + row.summary).toLocaleLowerCase('pl').includes(query));
        rows.forEach(row => {
            const card = document.createElement('article'); card.className = 'template-card';
            const icon = document.createElement('span'); icon.className = 'template-icon'; icon.textContent = ({technical: '⚙', guide: '➜', meeting: '☷', api: '{}', operations: '☑', knowledge: '◇', project: '▤', incident: '⚑'})[row.icon] || '▤';
            const heading = document.createElement('strong'); heading.textContent = row.display_name;
            const description = document.createElement('p'); description.textContent = row.summary;
            const thumbnail = document.createElement('div'); thumbnail.className = 'template-thumbnail'; thumbnail.setAttribute('aria-hidden', 'true');
            const source = new DOMParser().parseFromString(safeHtml(row.content_html), 'text/html');
            for (const block of [...source.body.querySelectorAll('h1,h2,h3,table,pre,ul,ol')].slice(0, 3)) {
                const sample = document.createElement(block.tagName === 'TABLE' ? 'div' : block.tagName === 'PRE' ? 'pre' : 'p');
                if (block.tagName === 'TABLE') {
                    sample.className = 'template-mini-table';
                    for (const cell of [...block.querySelectorAll('th,td')].slice(0, 8)) { const part = document.createElement('span'); part.textContent = cell.textContent.slice(0, 20); sample.append(part); }
                } else { sample.textContent = block.textContent.slice(0, 80); sample.className = 'template-mini-' + block.tagName.toLowerCase(); }
                thumbnail.append(sample);
            }
            const preview = document.createElement('button'); preview.type = 'button'; preview.textContent = 'Podgląd'; preview.onclick = () => {
                previewTemplate = row; q('[data-template-preview-title]').textContent = row.display_name;
                q('[data-template-preview-content]').innerHTML = safeHtml(row.content_html); formatDocument(q('[data-template-preview-content]')); q('[data-template-preview-dialog]').showModal();
            };
            const use = document.createElement('button'); use.type = 'button'; use.textContent = 'Użyj'; use.onclick = () => useTemplate(row);
            card.append(icon, heading, description, thumbnail, preview, use); list.append(card);
        });
        q('[data-template-status]').textContent = rows.length ? '' : 'Brak pasujących szablonów.';
    };
    q('[data-template-search]').oninput = renderTemplates; category.onchange = renderTemplates;
    q('[data-template-preview-apply]').onclick = () => { if (previewTemplate) useTemplate(previewTemplate); }; renderTemplates();
    const refresh = () => {
        space.disabled = root.dataset.isEdit === '1';
        const cover = q('[data-add-cover]');
        if (cover) cover.disabled = q('[data-image-picker]').disabled || q('[data-save-button]').disabled;
        const current = snapshot().documentKey + ':' + root.dataset.spaceId;
        if (documentContext !== current) { documentContext = current; ++contextSequence; ready = false; space.value = root.dataset.spaceId; if (publication.open) loadContext(); }
        confirm.disabled = !ready || q('[data-save-button]').disabled || !title.value.trim(); growTitle();
    };
    root.addEventListener('wiki:document-state', refresh); growTitle();
    return refresh;
}
