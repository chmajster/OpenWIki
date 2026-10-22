import {TextSelection} from '@tiptap/pm/state';

const t = (text) => window.wikiTranslate(text);
export function setupEditorTools(root, editor, requestJson, snapshot, fields, canEdit) {
    const q = (selector) => root.querySelector(selector);
    const dialog = (name) => q('[data-' + name + '-dialog]');
    const close = (name) => { dialog(name).close(); editor.view.focus(); };
    const focusedChain = () => { editor.view.focus(); return editor.chain(); };
    const tableTools = q('[data-table-tools]');
    let tableToolsAnchor = null, tableToolsDismissed = false;
    const placeTableTools = (x, y) => {
        tableTools.hidden = false;
        tableTools.style.visibility = 'hidden';
        const rect = tableTools.getBoundingClientRect(), margin = 12, gap = 12;
        let left = x + gap;
        if (left + rect.width > window.innerWidth - margin) left = x - rect.width - gap;
        left = Math.max(margin, Math.min(left, window.innerWidth - rect.width - margin));
        let top = y + gap;
        if (top + rect.height > window.innerHeight - margin) top = y - rect.height - gap;
        top = Math.max(margin, Math.min(top, window.innerHeight - rect.height - margin));
        tableTools.style.left = left + 'px';
        tableTools.style.top = top + 'px';
        tableTools.style.removeProperty('visibility');
    };
    const command = (name) => {
        const chain = focusedChain();
        if (['sink', 'lift'].includes(name)) {
            const type = editor.isActive('taskItem') ? 'taskItem' : 'listItem';
            return (name === 'sink' ? chain.sinkListItem(type) : chain.liftListItem(type)).run();
        }
        const methods = {bold: 'toggleBold', italic: 'toggleItalic', underline: 'toggleUnderline', strike: 'toggleStrike',
            blockquote: 'toggleBlockquote', bulletList: 'toggleBulletList', orderedList: 'toggleOrderedList', taskList: 'toggleTaskList', undo: 'undo', redo: 'redo'};
        return chain[methods[name]]().run();
    };
    const refresh = () => {
        const inEditableTable = editor.isActive('table') && canEdit();
        tableTools.hidden = !inEditableTable || tableToolsDismissed;
        if (inEditableTable && !tableToolsDismissed) {
            if (!tableToolsAnchor) {
                const caret = editor.view.coordsAtPos(editor.state.selection.from);
                tableToolsAnchor = {x: caret.right, y: caret.top};
            }
            placeTableTools(tableToolsAnchor.x, tableToolsAnchor.y);
        } else if (!inEditableTable) {
            tableToolsAnchor = null;
            tableToolsDismissed = false;
        }
        q('[data-heading]').value = String(editor.getAttributes('heading').level || 0);
        root.querySelectorAll('[data-insert-table], [data-open-link], [data-open-code], [data-open-callout], [data-open-macro], [data-image-picker], [data-attachment-picker], [data-heading]').forEach((control) => { control.disabled = !canEdit(); });
        root.querySelectorAll('[data-rich-command]').forEach((button) => {
            const name = button.dataset.richCommand;
            const listType = editor.isActive('taskItem') ? 'taskItem' : 'listItem';
            const available = ['sink', 'lift'].includes(name)
                ? editor.can()[name === 'sink' ? 'sinkListItem' : 'liftListItem'](listType)
                : !['undo', 'redo'].includes(name) || editor.can()[name]();
            button.disabled = !canEdit() || !available;
            if (!['undo', 'redo', 'sink', 'lift'].includes(name)) button.setAttribute('aria-pressed', String(editor.isActive(name)));
        });
        root.querySelectorAll('[data-table-command]').forEach((button) => { button.disabled = !canEdit() || !editor.can()[button.dataset.tableCommand](); });
    };
    editor.on('transaction', refresh);
    editor.on('selectionUpdate', refresh);
    refresh();
    editor.view.dom.addEventListener('contextmenu', (event) => {
        const target = event.target instanceof Element ? event.target : null;
        if (!target?.closest('td, th') || !canEdit()) return;
        event.preventDefault();
        const anchor = {x: event.clientX, y: event.clientY};
        tableToolsDismissed = false;
        tableToolsAnchor = anchor;
        const position = editor.view.posAtCoords({left: anchor.x, top: anchor.y});
        if (position) {
            editor.view.dispatch(editor.state.tr.setSelection(TextSelection.near(editor.state.doc.resolve(position.pos), 1)));
            editor.view.focus();
        }
        requestAnimationFrame(() => { if (tableToolsAnchor === anchor && editor.isActive('table') && canEdit()) placeTableTools(anchor.x, anchor.y); });
    });
    document.addEventListener('pointerdown', (event) => {
        if (tableTools.hidden || tableTools.contains(event.target) || editor.view.dom.contains(event.target)) return;
        tableTools.hidden = true;
        tableToolsAnchor = null;
        tableToolsDismissed = true;
    }, true);
    window.addEventListener('resize', () => {
        if (!tableTools.hidden && tableToolsAnchor) placeTableTools(tableToolsAnchor.x, tableToolsAnchor.y);
    });
    root.querySelectorAll('[data-rich-command]').forEach((button) => button.addEventListener('click', () => { if (canEdit()) { command(button.dataset.richCommand); editor.view.focus(); } }));
    q('[data-heading]').addEventListener('change', (event) => {
        if (!canEdit()) return;
        const level = Number(event.target.value);
        level ? focusedChain().setHeading({level}).run() : focusedChain().setParagraph().run();
    });
    q('[data-insert-table]').addEventListener('click', () => { if (canEdit()) dialog('table').showModal(); });
    q('[data-table-insert]').addEventListener('click', () => {
        if (!canEdit()) return;
        const rows = Number(q('[data-table-rows]').value), cols = Number(q('[data-table-cols]').value);
        if (!Number.isInteger(rows) || rows < 1 || rows > 50 || !Number.isInteger(cols) || cols < 1 || cols > 30) { q('[data-table-error]').textContent = 'Wybierz 1–50 wierszy i 1–30 kolumn.'; return; }
        focusedChain().insertTable({rows, cols, withHeaderRow: q('[data-table-header]').checked}).run(); close('table');
    });
    root.querySelectorAll('[data-table-command]').forEach((button) => button.addEventListener('click', () => { if (canEdit()) focusedChain()[button.dataset.tableCommand]().run(); }));
    root.querySelectorAll('[data-dialog-close]').forEach((button) => button.addEventListener('click', () => button.closest('dialog').close()));
    let linkSelection = null, linkAttributes = {};
    editor.on('transaction', ({transaction}) => {
        if (linkSelection) linkSelection = linkSelection.map(transaction.mapping);
    });
    for (const name of ['table', 'link', 'code', 'callout', 'macro']) dialog(name).addEventListener('close', () => {
        if (dialog(name).open) return;
        if (name === 'link') { linkSelection = null; linkAttributes = {}; ++searchSequence; clearTimeout(searchTimer); }
        if (canEdit()) editor.view.focus();
    });
    const openLink = () => {
        if (!canEdit()) return;
        // Native arrow-key selectionchange may arrive after the shortcut keydown.
        // Read the current selection through ProseMirror's public DOM-position API.
        const native = editor.view.dom.ownerDocument.getSelection();
        if (editor.state.selection instanceof TextSelection && native?.anchorNode && native.focusNode
            && editor.view.dom.contains(native.anchorNode) && editor.view.dom.contains(native.focusNode)) {
            const doc = editor.state.doc;
            const selection = TextSelection.between(
                doc.resolve(editor.view.posAtDOM(native.anchorNode, native.anchorOffset)),
                doc.resolve(editor.view.posAtDOM(native.focusNode, native.focusOffset)));
            if (!selection.eq(editor.state.selection)) editor.view.dispatch(editor.state.tr.setSelection(selection));
        }
        // A cursor inside a link edits its entire range; an explicit selection stays explicit.
        if (editor.state.selection.empty && editor.isActive('link')) editor.commands.extendMarkRange('link');
        linkSelection = editor.state.selection.getBookmark();
        linkAttributes = editor.getAttributes('link');
        q('[data-link-url]').value = linkAttributes.href || '';
        q('[data-link-text]').value = editor.state.doc.textBetween(editor.state.selection.from, editor.state.selection.to, ' ');
        q('[data-link-remove]').disabled = !editor.isActive('link');
        q('[data-link-error]').textContent = '';
        dialog('link').showModal();
        q('[data-link-search]').focus();
        searchPages();
    };
    q('[data-open-link]').addEventListener('click', openLink);
    let searchTimer, searchSequence = 0;
    const searchPages = async () => {
        const sequence = ++searchSequence;
        q('[data-link-status]').textContent = t('Searching…');
        q('[data-link-results]').replaceChildren();
        try {
            const data = await requestJson(root.dataset.pagesUrl + '?document_key=' + encodeURIComponent(snapshot().documentKey) + '&q=' + encodeURIComponent(q('[data-link-search]').value));
            if (sequence !== searchSequence) return;
            q('[data-link-status]').textContent = data.pages.length ? '' : t('No accessible pages found.');
            for (const page of data.pages) {
                const button = document.createElement('button');
                button.type = 'button'; button.textContent = page.title;
                button.addEventListener('click', () => { q('[data-link-url]').value = page.url; q('[data-link-text]').value = page.title; });
                q('[data-link-results]').appendChild(button);
            }
        } catch (error) { if (sequence === searchSequence) q('[data-link-status]').textContent = error.message; }
    };
    q('[data-link-search]').addEventListener('input', () => {
        ++searchSequence; clearTimeout(searchTimer);
        q('[data-link-results]').replaceChildren(); q('[data-link-status]').textContent = t('Searching…');
        searchTimer = setTimeout(searchPages, 250);
    });
    q('[data-link-insert]').addEventListener('click', () => {
        if (!canEdit()) { close('link'); return; }
        const href = q('[data-link-url]').value.trim(), text = q('[data-link-text]').value;
        if (!/^(https?:\/\/|mailto:|\/[^/]|#)/i.test(href)) { q('[data-link-error]').textContent = t('Enter a safe URL or choose a page.'); return; }
        if (!linkSelection) return;
        const selection = linkSelection.resolve(editor.state.doc);
        const original = editor.state.doc.textBetween(selection.from, selection.to, ' ');
        const attrs = {...linkAttributes, href, target: linkAttributes.target || null};
        if (href !== linkAttributes.href) {
            for (const name of ['data-draft-file-id', 'data-attachment-id', 'data-file-size', 'data-file-type']) attrs[name] = null;
        }
        const chain = focusedChain().setTextSelection({from: selection.from, to: selection.to});
        if (!selection.empty && (!text || text === original)) chain.setLink(attrs).run();
        else {
            const marks = selection.$from.marks().filter(mark => mark.type.name !== 'link').map(mark => mark.toJSON());
            chain.insertContent({type: 'text', text: text || href, marks: [...marks, {type: 'link', attrs}]}).run();
        }
        close('link');
    });
    q('[data-link-remove]').addEventListener('click', () => {
        if (!canEdit() || !linkSelection) return;
        const selection = linkSelection.resolve(editor.state.doc);
        focusedChain().setTextSelection({from: selection.from, to: selection.to}).unsetLink().run();
        close('link');
    });
    q('[data-open-code]').addEventListener('click', () => { if (!canEdit()) return; q('[data-code-language]').value = editor.getAttributes('codeBlock').language || ''; dialog('code').showModal(); });
    q('[data-code-insert]').addEventListener('click', () => {
        if (!canEdit()) { close('code'); return; }
        const language = q('[data-code-language]').value.replace(/[^A-Za-z0-9_+#.-]/g, '');
        editor.isActive('codeBlock') ? focusedChain().updateAttributes('codeBlock', {language}).run() : focusedChain().toggleCodeBlock({language}).run();
        close('code');
    });
    q('[data-open-callout]').addEventListener('click', () => { if (canEdit()) { q('[data-callout-kind]').value = editor.getAttributes('callout').kind || 'info'; dialog('callout').showModal(); } });
    q('[data-callout-insert]').addEventListener('click', () => {
        if (!canEdit()) { close('callout'); return; }
        const kind = q('[data-callout-kind]').value;
        editor.isActive('callout') ? focusedChain().updateAttributes('callout', {kind}).run() : focusedChain().wrapIn('callout', {kind}).run();
        close('callout');
    });
    const argumentMacros = new Set(['status', 'info', 'warning', 'note', 'code', 'user-profile']);
    const macroChanged = () => {
        const name = q('[data-macro-name]').value;
        q('[data-macro-parameter-label]').hidden = !argumentMacros.has(name);
        q('[data-macro-example]').textContent = '{{' + name + (argumentMacros.has(name) ? ':' + q('[data-macro-parameter]').value : '') + '}}';
    };
    q('[data-open-macro]').addEventListener('click', () => { if (canEdit()) { macroChanged(); dialog('macro').showModal(); } });
    q('[data-macro-name]').addEventListener('change', macroChanged);
    q('[data-macro-parameter]').addEventListener('input', macroChanged);
    q('[data-macro-insert]').addEventListener('click', () => {
        if (!canEdit()) { close('macro'); return; }
        const name = q('[data-macro-name]').value, parameter = q('[data-macro-parameter]').value;
        if (argumentMacros.has(name) && (!parameter.trim() || /[{}]/.test(parameter))) { q('[data-macro-error]').textContent = t('Enter a parameter without braces.'); return; }
        focusedChain().insertContent({type: 'paragraph', content: [{type: 'text', text: '{{' + name + (argumentMacros.has(name) ? ':' + parameter : '') + '}}'}]}).run();
        close('macro');
    });
    let previewSequence = 0, previewTimer;
    const preview = async () => {
        const sequence = ++previewSequence, captured = snapshot();
        q('[data-preview-status]').textContent = t('Rendering preview…');
        try {
            const data = await requestJson(root.dataset.previewUrl, fields(captured));
            if (sequence !== previewSequence || captured.markdown !== snapshot().markdown) return;
            q('[data-markdown-preview-content]').innerHTML = data.html;
            q('[data-markdown-preview-content]').hidden = false;
            q('[data-preview-status]').textContent = t('Preview uses the same PHP renderer as saving.');
        } catch (error) { if (sequence === previewSequence) q('[data-preview-status]').textContent = error.message; }
    };
    q('[data-markdown-preview]').addEventListener('click', preview);
    q('[data-markdown-editor]').addEventListener('input', () => {
        ++previewSequence; clearTimeout(previewTimer);
        if (!q('[data-markdown-preview-content]').hidden) { q('[data-preview-status]').textContent = t('Preview is updating…'); previewTimer = setTimeout(preview, 500); }
    });
    root.addEventListener('keydown', (event) => {
        if (!(event.ctrlKey || event.metaKey)) return;
        if (event.key.toLowerCase() === 'k' && canEdit() && event.target.closest('[data-visual-editor]')) { event.preventDefault(); openLink(); }
    });
    root.querySelectorAll('[data-editor-mode]').forEach((tab) => tab.addEventListener('keydown', (event) => {
        if (!['ArrowLeft', 'ArrowRight'].includes(event.key)) return;
        event.preventDefault(); const other = [...root.querySelectorAll('[data-editor-mode]')].find((element) => element !== tab); other.focus(); other.click();
    }));
    refresh();
    return refresh;
}
