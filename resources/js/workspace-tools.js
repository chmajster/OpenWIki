import {Fragment, Slice, DOMSerializer} from '@tiptap/pm/model';
import {selectedRect, moveTableRow, moveTableColumn, handlePaste} from '@tiptap/pm/tables';
import {closeHistory} from '@tiptap/pm/history';
import {TextSelection} from '@tiptap/pm/state';
import {diagramPreview} from './diagram-preview.js';

export function setupWorkspace(root, editor, canEdit, requestJson, snapshot) {
    const q = (selector) => root.querySelector(selector);
    const report = (message) => { q('[data-block-feedback]').textContent = message; };
    const focus = () => { editor.view.focus(); return editor.chain(); };
    const documentAction = tr => { editor.view.dispatch(closeHistory(tr)); editor.view.dispatch(closeHistory(editor.state.tr)); };
    const selectedBlock = () => {
        const {$from} = editor.state.selection;
        const pos = $from.depth ? $from.before(1) : $from.pos;
        const index = editor.state.doc.resolve(pos).index();
        const node = editor.state.doc.nodeAt(pos);
        return node ? {pos, index, node} : null;
    };
    const blockAt = (index) => { let pos = 0; for (let i = 0; i < index; i++) pos += editor.state.doc.child(i).nodeSize; return pos; };
    const moveBlock = (to) => {
        const block = selectedBlock(); if (!block || !canEdit() || to < 0 || to >= editor.state.doc.childCount || to === block.index) return;
        let destination = blockAt(to);
        if (to > block.index) destination += editor.state.doc.child(to).nodeSize - block.node.nodeSize;
        const tr = editor.state.tr.delete(block.pos, block.pos + block.node.nodeSize).insert(destination, block.node);
        documentAction(tr.setSelection(TextSelection.near(tr.doc.resolve(destination + 1)))); editor.view.focus(); report('Przeniesiono blok. Undo pozwala wrócić.');
    };
    root.querySelectorAll('[data-block-action]').forEach((button) => button.onclick = async () => {
        const block = selectedBlock(); if (!block || !canEdit()) return;
        const action = button.dataset.blockAction;
        if (action === 'up' || action === 'down') { moveBlock(block.index + (action === 'up' ? -1 : 1)); return; }
        if (action === 'duplicate') documentAction(editor.state.tr.insert(block.pos + block.node.nodeSize, block.node));
        if (action === 'delete') documentAction(editor.state.tr.delete(block.pos, block.pos + block.node.nodeSize));
        if (action === 'copy') {
            try {
                const container = document.createElement('div'); container.append(DOMSerializer.fromSchema(editor.schema).serializeNode(block.node));
                await navigator.clipboard.write([new ClipboardItem({'text/html': new Blob([container.innerHTML], {type: 'text/html'}), 'text/plain': new Blob([block.node.textContent], {type: 'text/plain'})})]);
                report('Skopiowano blok do schowka.');
            } catch { report('Nie udało się skopiować bloku. Sprawdź dostęp do schowka.'); }
        } else { editor.view.focus(); report('Operacja bloku wykonana. Można ją cofnąć.'); }
    });
    const dragIdentity = Math.random().toString(36);
    q('[data-block-drag]').ondragstart = (event) => {
        const block = selectedBlock(); if (!block || !canEdit()) { event.preventDefault(); return; }
        event.dataTransfer.setData('application/x-openwiki-block', JSON.stringify({identity: dragIdentity, index: block.index})); event.dataTransfer.effectAllowed = 'move';
    };
    editor.view.dom.addEventListener('dragover', (event) => { if ([...event.dataTransfer.types].includes('application/x-openwiki-block')) event.preventDefault(); });
    editor.view.dom.addEventListener('drop', (event) => {
        const data = event.dataTransfer.getData('application/x-openwiki-block'); if (!data) return;
        event.preventDefault(); event.stopPropagation();
        try {
            const payload = JSON.parse(data); if (payload.identity !== dragIdentity || !canEdit()) return;
            const target = editor.view.posAtCoords({left: event.clientX, top: event.clientY}); if (!target) return;
            const resolved = editor.state.doc.resolve(target.pos), to = resolved.depth ? editor.state.doc.resolve(resolved.before(1)).index() : resolved.index();
            editor.commands.setNodeSelection(blockAt(payload.index)); moveBlock(Math.min(to, editor.state.doc.childCount - 1));
        } catch { report('Nie udało się przenieść bloku. Użyj przycisków lub Alt+Shift+strzałka.'); }
    }, true);

    root.querySelectorAll('[data-advanced-command]').forEach((button) => button.onclick = () => {
        if (!canEdit()) return;
        const name = button.dataset.advancedCommand;
        if (['superscript', 'subscript'].includes(name)) focus()[name]().run();
        if (name === 'inlineCode') focus().toggleCode().run();
        if (name === 'separator') focus().setHorizontalRule().run();
        if (name === 'clearFormat') focus().unsetAllMarks().clearNodes().run();
    });
    for (const [selector, attribute] of [['[data-text-color]', 'data-text-color'], ['[data-highlight]', 'data-highlight']]) q(selector).onchange = (event) => {
        if (canEdit()) focus().setMark('controlledStyle', {[attribute]: event.target.value || null}).run();
    };
    q('[data-text-align]').onchange = (event) => {
        if (!canEdit()) return;
        const type = editor.isActive('tableHeader') ? 'tableHeader' : editor.isActive('tableCell') ? 'tableCell' : editor.isActive('heading') ? 'heading' : 'paragraph';
        focus().updateAttributes(type, {'data-align': event.target.value}).run();
    };
    q('[data-cell-color]').onchange = (event) => { if (canEdit()) focus().setCellAttribute('data-cell-color', event.target.value || null).run(); };
    root.querySelectorAll('[data-table-move]').forEach((button) => button.onclick = () => {
        if (!canEdit() || !editor.isActive('table')) return;
        try {
            const rect = selectedRect(editor.state), [axis, amount] = button.dataset.tableMove.split(':');
            const from = axis === 'row' ? rect.top : rect.left, to = from + Number(amount), max = axis === 'row' ? rect.map.height : rect.map.width;
            if (to < 0 || to >= max) { report('Wiersz lub kolumna jest na brzegu tabeli.'); return; }
            const command = axis === 'row' ? moveTableRow : moveTableColumn;
            command({from, to})(editor.state, editor.view.dispatch); editor.view.focus();
        } catch { report('Nie można przenieść tak zaznaczonych komórek. Sprawdź scalenia.'); }
    });
    q('[data-column-width-apply]').onclick = () => {
        const width = Number(q('[data-column-width]').value);
        if (!canEdit() || !editor.isActive('table') || !Number.isInteger(width) || width < 40 || width > 1600) { report('Szerokość kolumny: 40–1600 px.'); return; }
        const rect = selectedRect(editor.state), seen = new Set(); let tr = editor.state.tr;
        for (let row = 0; row < rect.map.height; row++) {
            const offset = rect.map.map[row * rect.map.width + rect.left]; if (seen.has(offset)) continue; seen.add(offset);
            const cell = rect.table.nodeAt(offset), widths = cell.attrs.colwidth?.slice() || Array(cell.attrs.colspan).fill(0);
            widths[rect.left - rect.map.findCell(offset).left] = width;
            tr = tr.setNodeMarkup(rect.tableStart + offset, undefined, {...cell.attrs, colwidth: widths});
        }
        editor.view.dispatch(tr); editor.view.focus();
    };
    const priorPaste = editor.options.editorProps.handlePaste;
    editor.setOptions({editorProps: {...editor.options.editorProps, handlePaste(view, event, slice) {
        const text = event.clipboardData?.getData('text/plain') || '';
        if (!canEdit() || editor.isActive('codeBlock') || editor.isActive('diagram') || !text.includes('\t') || event.clipboardData.getData('text/html')) return priorPaste?.(view, event, slice) || false;
        const rows = text.replace(/\r\n?/g, '\n').replace(/\n$/, '').split('\n').map((line) => line.split('\t'));
        if (rows.length > 50 || rows.some((row) => row.length > 30) || text.length > 200000) { report('Wklejana tabela przekracza limit 50 × 30 komórek.'); return false; }
        const cols = Math.max(...rows.map((row) => row.length));
        const table = {type: 'table', content: rows.map((row) => ({type: 'tableRow', content: Array.from({length: cols}, (_, index) => ({type: 'tableCell', content: [{type: 'paragraph', content: row[index] ? [{type: 'text', text: row[index]}] : []}]}))}))};
        if (editor.isActive('table')) return handlePaste(view, event, new Slice(Fragment.from(editor.schema.nodeFromJSON(table)), 0, 0));
        editor.commands.insertContent(table); return true;
    }}});

    q('[data-open-block]').onclick = () => {
        if (!canEdit()) return;
        if (editor.isActive('disclosure')) q('[data-block-title]').value = editor.getAttributes('disclosure').title;
        q('[data-block-dialog]').showModal();
    };
    q('[data-block-insert]').onclick = () => {
        if (!canEdit()) return;
        const kind = q('[data-block-kind]').value;
        if (kind === 'disclosure') {
            const title = q('[data-block-title]').value || 'Szczegóły';
            editor.isActive('disclosure') ? focus().updateAttributes('disclosure', {title}).run() : focus().wrapIn('disclosure', {title}).run();
        } else {
            const count = kind === 'columns3' ? 3 : 2;
            focus().insertContent({type: 'columns', attrs: {count}, content: Array.from({length: count}, () => ({type: 'column', content: [{type: 'paragraph'}]}))}).run();
        }
        q('[data-block-dialog]').close(); editor.view.focus();
    };
    let renderDiagram = null;
    q('[data-open-diagram]').onclick = () => {
        if (!canEdit()) return;
        const {$from} = editor.state.selection;
        q('[data-diagram-source]').value = editor.isActive('diagram') ? $from.parent.textContent : 'flowchart LR\n  A[Dokumentacja] --> B[Zespół]';
        q('[data-diagram-dialog]').showModal();
        if (!renderDiagram) renderDiagram = diagramPreview(q('[data-diagram-preview]'), q('[data-diagram-status]'));
        renderDiagram(q('[data-diagram-source]').value);
    };
    q('[data-diagram-render]').onclick = () => renderDiagram?.(q('[data-diagram-source]').value);
    q('[data-diagram-insert]').onclick = () => {
        if (!canEdit()) return;
        const source = q('[data-diagram-source]').value;
        const node = editor.schema.nodes.diagram.create(null, source ? editor.schema.text(source) : null), {$from} = editor.state.selection;
        if (editor.isActive('diagram')) editor.view.dispatch(editor.state.tr.replaceWith($from.before(), $from.after(), node)); else editor.commands.insertContent(node.toJSON());
        q('[data-diagram-dialog]').close(); editor.view.focus();
    };
    for (const [selector, name] of [['input[data-code-numbers]', 'data-line-numbers'], ['input[data-code-wrap]', 'data-code-wrap']]) q(selector).onchange = (event) => { const checked = event.target.checked; if (canEdit()) focus().updateAttributes('codeBlock', {[name]: String(checked)}).run(); };
    q('[data-code-copy]').onclick = async () => {
        try { await navigator.clipboard.writeText(editor.state.selection.$from.parent.textContent); report('Skopiowano kod.'); }
        catch { report('Nie udało się skopiować kodu.'); }
    };

    let findIndex = -1;
    const matches = () => {
        const query = q('[data-find-text]').value, sensitive = q('[data-find-case]').checked, found = [];
        if (!query) return found;
        editor.state.doc.descendants((node, pos) => {
            if (!node.isTextblock) return;
            const text = node.textBetween(0, node.content.size, '\n', '\uFFFC'), haystack = sensitive ? text : text.toLocaleLowerCase(), needle = sensitive ? query : query.toLocaleLowerCase();
            for (let at = haystack.indexOf(needle); at !== -1; at = haystack.indexOf(needle, at + Math.max(needle.length, 1))) found.push({from: pos + 1 + at, to: pos + 1 + at + query.length});
        });
        q('[data-find-status]').textContent = 'Wyniki: ' + found.length; return found;
    };
    q('[data-open-find]').onclick = () => { q('[data-find-dialog]').showModal(); q('[data-find-text]').focus(); findIndex = -1; };
    q('[data-find-text]').oninput = () => { findIndex = -1; matches(); };
    q('[data-find-next]').onclick = () => {
        const found = matches(); if (!found.length) return;
        findIndex = (findIndex + 1) % found.length; editor.commands.setTextSelection(found[findIndex]); editor.commands.scrollIntoView();
    };
    const replace = (all) => {
        if (!canEdit()) return;
        const found = matches(); if (!found.length) return;
        const selected = all ? found : [found[Math.max(0, findIndex) % found.length]]; let tr = editor.state.tr;
        for (const range of selected.reverse()) tr = tr.insertText(q('[data-replace-text]').value, range.from, range.to);
        documentAction(tr); findIndex = -1; matches();
    };
    q('[data-replace-one]').onclick = () => replace(false); q('[data-replace-all]').onclick = () => replace(true);
    q('[data-focus-mode]').onclick = () => {
        const active = root.classList.toggle('is-focused'); document.body.classList.toggle('editor-focused', active);
        q('[data-focus-mode]').setAttribute('aria-pressed', String(active));
    };
    new MutationObserver(() => { q('[data-focus-state]').textContent = q('[data-save-state]').textContent; }).observe(q('[data-save-state]'), {childList: true});

    let statsTimer;
    const statistics = () => {
        const text = editor.state.doc.textBetween(0, editor.state.doc.content.size, ' ');
        q('[data-word-count]').textContent = 'Słowa: ' + (text.trim().match(/\S+/g)?.length || 0) + ' · Znaki: ' + text.length;
        const outline = q('[data-editor-outline]'); outline.replaceChildren();
        editor.state.doc.descendants((node, pos) => {
            if (node.type.name !== 'heading') return;
            const button = document.createElement('button'); button.type = 'button'; button.textContent = 'H' + node.attrs.level + ' ' + (node.textContent || '(pusty nagłówek)');
            button.onclick = () => { editor.commands.setTextSelection(pos + 1); editor.commands.scrollIntoView(); editor.view.focus(); }; outline.append(button);
        });
    };
    editor.on('update', () => { clearTimeout(statsTimer); statsTimer = setTimeout(statistics, 300); }); statistics();
    const refresh = () => {
        const bubble = q('[data-text-context]'), selection = editor.state.selection;
        bubble.hidden = selection.empty || !canEdit();
        if (!bubble.hidden) {
            const coordinates = editor.view.coordsAtPos(selection.from);
            bubble.style.left = Math.max(8, Math.min(window.innerWidth - 300, coordinates.left)) + 'px';
            bubble.style.top = Math.max(8, coordinates.top - 50) + 'px';
        }
        q('[data-code-tools]').hidden = !editor.isActive('codeBlock') || !canEdit();
        q('[data-code-numbers]').checked = editor.getAttributes('codeBlock')['data-line-numbers'] === 'true';
        q('[data-code-wrap]').checked = editor.getAttributes('codeBlock')['data-code-wrap'] === 'true';
        root.querySelectorAll('[data-advanced-command], [data-text-color], [data-highlight], [data-text-align], [data-open-block], [data-open-diagram], [data-block-action], [data-cell-color], [data-column-width-apply], [data-table-move]').forEach((control) => { control.disabled = !canEdit(); });
    };
    editor.on('transaction', refresh); refresh();
    q('[data-text-context]').onmousedown = (event) => event.preventDefault();
    root.querySelectorAll('[data-context-command]').forEach((button) => button.onclick = () => {
        if (!canEdit()) return;
        const command = button.dataset.contextCommand;
        if (command === 'link') q('[data-open-link]').click(); else if (command === 'clear') focus().unsetAllMarks().run(); else focus()[command === 'bold' ? 'toggleBold' : 'toggleItalic']().run();
    });

    // Slash commands and mentions share a keyboard-operated list, with no HTML insertion.
    const menu = q('[data-command-menu]'); let candidates = [], active = 0, range = null, querySequence = 0;
    const commands = [
        ['Akapit', () => focus().setParagraph().run()], ...[1, 2, 3, 4, 5, 6].map((level) => ['Nagłówek H' + level, () => focus().setHeading({level}).run()]),
        ['Tabela', () => q('[data-insert-table]').click()], ['Lista punktowana', () => focus().toggleBulletList().run()], ['Lista numerowana', () => focus().toggleOrderedList().run()], ['Lista zadań', () => focus().toggleTaskList().run()],
        ['Kod', () => q('[data-open-code]').click()], ['Diagram Mermaid', () => q('[data-open-diagram]').click()], ['Sekcja i kolumny', () => q('[data-open-block]').click()], ['Callout', () => q('[data-open-callout]').click()], ['Makro', () => q('[data-open-macro]').click()], ['Separator', () => focus().setHorizontalRule().run()]
    ];
    const renderMenu = () => {
        menu.replaceChildren(); menu.hidden = !candidates.length;
        candidates.forEach(([label, action], index) => {
            const button = document.createElement('button'); button.type = 'button'; button.role = 'option'; button.textContent = label; button.setAttribute('aria-selected', String(index === active));
            button.onclick = () => { if (!canEdit() || !range) return; editor.commands.deleteRange(range); menu.hidden = true; range = null; action(); }; menu.append(button);
        });
    };
    const updateMenu = async () => {
        const sequence = ++querySequence, {$from, empty} = editor.state.selection;
        const before = $from.parent.isTextblock ? $from.parent.textBetween(0, $from.parentOffset) : '';
        const match = /(?:^|\s)([/@])([^\s/@]{0,30})$/.exec(before);
        if (!canEdit() || !empty || !match || editor.isActive('codeBlock') || editor.isActive('diagram')) { menu.hidden = true; range = null; return; }
        range = {from: $from.pos - match[2].length - 1, to: $from.pos}; active = 0;
        const coordinates = editor.view.coordsAtPos($from.pos); menu.style.left = Math.max(8, Math.min(window.innerWidth - 290, coordinates.left)) + 'px'; menu.style.top = Math.min(window.innerHeight - 220, coordinates.bottom + 4) + 'px';
        if (match[1] === '/') candidates = commands.filter(([label]) => label.toLocaleLowerCase().includes(match[2].toLocaleLowerCase()));
        else {
            try {
                const data = await requestJson(root.dataset.usersUrl + '?document_key=' + encodeURIComponent(snapshot().documentKey) + '&q=' + encodeURIComponent(match[2]));
                if (sequence !== querySequence) return;
                candidates = data.users.map((user) => ['@' + user.username, () => focus().insertContent({type: 'text', text: '@' + user.username + ' '}).run()]);
            } catch (error) { report(error.message); candidates = []; }
        }
        renderMenu();
    };
    let menuTimer;
    editor.on('transaction', ({transaction}) => { if (transaction.docChanged || transaction.selectionSet) { clearTimeout(menuTimer); menuTimer = setTimeout(updateMenu, 120); } });
    root.addEventListener('keydown', (event) => {
        if (canEdit() && !event.target.closest('dialog') && (event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'f') { event.preventDefault(); event.stopPropagation(); q('[data-open-find]').click(); return; }
        if (event.altKey && event.shiftKey && ['ArrowUp', 'ArrowDown'].includes(event.key)) { event.preventDefault(); event.stopPropagation(); const block = selectedBlock(); if (block) moveBlock(block.index + (event.key === 'ArrowUp' ? -1 : 1)); return; }
        if (menu.hidden || event.target.closest('dialog')) return;
        if (['ArrowUp', 'ArrowDown', 'Enter', 'Escape'].includes(event.key)) {
            event.preventDefault(); event.stopPropagation();
            if (event.key === 'Escape') { menu.hidden = true; range = null; }
            else if (event.key === 'Enter') menu.children[active]?.click();
            else { active = (active + (event.key === 'ArrowUp' ? -1 : 1) + candidates.length) % candidates.length; renderMenu(); }
        }
    }, true);
    return refresh;
}
