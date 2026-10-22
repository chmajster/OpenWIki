import {Fragment, Slice, DOMSerializer} from '@tiptap/pm/model';
import {selectedRect, moveTableRow, moveTableColumn, handlePaste} from '@tiptap/pm/tables';
import {closeHistory} from '@tiptap/pm/history';
import {TextSelection, NodeSelection} from '@tiptap/pm/state';
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
        q('[data-block-menu]').open = false;
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
        button.closest('.format-menu')?.removeAttribute('open');
    });
    root.querySelectorAll('[data-editor-emoji]').forEach((button) => {
        button.onmousedown = (event) => event.preventDefault();
        button.onclick = () => {
            if (!canEdit()) return;
            focus().insertContent(button.dataset.editorEmoji).run();
            button.closest('.editor-toolbar-picker')?.removeAttribute('open');
        };
    });
    const toolbarPopovers = [...root.querySelectorAll('.editor-toolbar-picker, .editor-toolbar .format-menu')];
    toolbarPopovers.forEach((popover) => popover.addEventListener('toggle', () => {
        if (popover.open) toolbarPopovers.forEach((other) => { if (other !== popover) other.open = false; });
    }));
    root.querySelectorAll('[data-text-color-choice]').forEach((button) => {
        button.onmousedown = (event) => event.preventDefault();
        button.onclick = () => {
            if (!canEdit()) return;
            focus().setMark('controlledStyle', {'data-text-color': button.dataset.textColorChoice || null}).run();
            button.closest('.editor-toolbar-picker')?.removeAttribute('open');
        };
    });
    q('[data-highlight]').onchange = (event) => {
        const attribute = 'data-highlight';
        if (canEdit()) focus().setMark('controlledStyle', {[attribute]: event.target.value || null}).run();
        event.currentTarget.closest('.editor-toolbar-picker')?.removeAttribute('open');
    };
    const alignmentType = () => editor.isActive('tableHeader') ? 'tableHeader' : editor.isActive('tableCell') ? 'tableCell' : editor.isActive('heading') ? 'heading' : 'paragraph';
    root.querySelectorAll('[data-text-align-choice]').forEach((button) => {
        button.onmousedown = (event) => event.preventDefault();
        button.onclick = () => {
            if (canEdit()) focus().updateAttributes(alignmentType(), {'data-align': button.dataset.textAlignChoice}).run();
            button.closest('[data-align-picker]')?.removeAttribute('open');
        };
    });
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
        if (rows.length > 50 || rows.some((row) => row.length > 30) || text.length > 200000) { report('Wklejana tabela przekracza limit 50 × 30 komórek. Bieżąca treść pozostaje bez zmian.'); return true; }
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
        // Block handles select a whole node; inline tools must not cover its action menu.
        bubble.hidden = !(selection instanceof TextSelection) || selection.empty || !canEdit();
        if (!bubble.hidden) {
            const coordinates = editor.view.coordsAtPos(selection.from);
            bubble.style.left = Math.max(8, Math.min(window.innerWidth - 300, coordinates.left)) + 'px';
            bubble.style.top = Math.max(8, coordinates.top - 50) + 'px';
        }
        q('[data-code-tools]').hidden = !editor.isActive('codeBlock') || !canEdit();
        q('[data-code-numbers]').checked = editor.getAttributes('codeBlock')['data-line-numbers'] === 'true';
        q('[data-code-wrap]').checked = editor.getAttributes('codeBlock')['data-code-wrap'] === 'true';
        const colorValue = editor.getAttributes('controlledStyle')['data-text-color'] || '';
        const allowedColors = new Set(['red', 'orange', 'green', 'blue', 'purple', 'gray']);
        const activeColor = allowedColors.has(colorValue) ? colorValue : '';
        root.querySelectorAll('[data-text-color-choice]').forEach((button) => button.setAttribute('aria-pressed', String(button.dataset.textColorChoice === activeColor)));
        q('.editor-toolbar-color-mark').style.setProperty('--editor-selected-text-color', activeColor ? `var(--doc-${activeColor})` : 'var(--muted)');
        const currentAlignment = editor.getAttributes(alignmentType())['data-align'] || 'left';
        const activeAlignment = ['left', 'center', 'right', 'justify'].includes(currentAlignment) ? currentAlignment : 'left';
        root.querySelectorAll('[data-text-align-choice]').forEach((button) => button.setAttribute('aria-pressed', String(button.dataset.textAlignChoice === activeAlignment)));
        root.querySelectorAll('[data-advanced-command], [data-editor-emoji], [data-text-color-choice], [data-highlight], [data-text-align-choice], [data-open-block], [data-open-diagram], [data-block-action], [data-cell-color], [data-column-width-apply], [data-table-move]').forEach((control) => { control.disabled = !canEdit(); });
    };
    editor.on('transaction', refresh); refresh();
    q('[data-text-context]').onmousedown = (event) => event.preventDefault();
    root.querySelectorAll('[data-context-command]').forEach((button) => button.onclick = () => {
        if (!canEdit()) return;
        const command = button.dataset.contextCommand;
        if (command === 'link') q('[data-open-link]').click(); else if (command === 'clear') focus().unsetAllMarks().run(); else focus()[command === 'bold' ? 'toggleBold' : 'toggleItalic']().run();
    });

    // The same transaction-backed picker serves slash commands and the block + button.
    const menu = q('[data-command-menu]'), options = q('[data-command-options]'), search = q('[data-command-search]');
    let candidates = [], active = 0, range = null, querySequence = 0, manual = false, insertion = null;
    const commands = [
        ['Akapit', () => focus().setParagraph().run(), 'Tekst', 'Zwykły tekst dokumentu', '¶'], ...[1, 2, 3, 4, 5, 6].map((level) => ['Nagłówek H' + level, () => focus().setHeading({level}).run(), 'Nagłówki', 'Poziom ' + level + ' w strukturze dokumentu', 'H' + level]),
        ['Tabela', () => q('[data-insert-table]').click(), 'Bloki', 'Wybierz wiersze, kolumny i nagłówek', '▦'], ['Lista punktowana', () => focus().toggleBulletList().run(), 'Listy', 'Lista z możliwością zagnieżdżania', '☷'], ['Lista numerowana', () => focus().toggleOrderedList().run(), 'Listy', 'Uporządkowane kroki dokumentu', '1.'], ['Lista zadań', () => focus().toggleTaskList().run(), 'Listy', 'Zadania z edytowalnym stanem', '☑'],
        ['Kod', () => q('[data-open-code]').click(), 'Bloki', 'Kod z językiem, numerami wierszy i podświetleniem', '</>'],
        ['Diagram Mermaid', () => q('[data-open-diagram]').click(), 'Bloki', 'Diagram ze źródła tekstowego', '◇'],
        ['Zwijana sekcja', () => { q('[data-block-kind]').value = 'disclosure'; q('[data-open-block]').click(); }, 'Układ', 'Tytuł i rozwijana treść', '▸'],
        ['Kolumny', () => { q('[data-block-kind]').value = 'columns2'; q('[data-open-block]').click(); }, 'Układ', 'Dwie lub trzy kolumny', '▥'],
        ...[['Informacja', 'info'], ['Ostrzeżenie', 'warning'], ['Sukces', 'success']].map(([name, kind]) => [name, () => { q('[data-open-callout]').click(); q('[data-callout-kind]').value = kind; }, 'Wyróżnienia', 'Wyróżniony blok z treścią', 'ⓘ']),
        ['Cytat', () => focus().toggleBlockquote().run(), 'Bloki', 'Cytowany fragment dokumentu', '❞'],
        ['Link do strony', () => q('[data-open-link]').click(), 'Odnośniki', 'Wyszukaj stronę lub podaj adres URL', '↗'],
        ['Makro', () => q('[data-open-macro]').click(), 'Dynamiczne', 'Spis treści, dzieci, załączniki i parametry', '{}'],
        ['Separator', () => focus().setHorizontalRule().run(), 'Układ', 'Oddziel części dokumentu', '―']
    ];
    if (q('[data-image-picker]')) commands.push(['Obraz', () => q('[data-image-picker]').click(), 'Media', 'Chroniony obraz z alt, podpisem i rozmiarem', '▧']);
    if (q('[data-attachment-picker]')) commands.push(['Plik', () => q('[data-attachment-picker]').click(), 'Media', 'Prywatny plik szkicu, dostęp po publikacji według ACL', '▤']);
    const positionMenu = pos => {
        const coordinates = editor.view.coordsAtPos(Math.min(pos, editor.state.doc.content.size));
        menu.style.left = Math.max(8, Math.min(window.innerWidth - Math.min(320, window.innerWidth - 24) - 8, coordinates.left)) + 'px';
        const height = menu.scrollHeight || Math.min(440, window.innerHeight * .7);
        menu.style.top = Math.max(65, Math.min(window.innerHeight - height - 8, coordinates.bottom + 4)) + 'px';
    };
    const choose = action => {
        if (!canEdit() || (!range && !manual)) return;
        const selectedRange = range, after = insertion;
        dismissMenu();
        if (selectedRange) editor.commands.deleteRange(selectedRange);
        if (after !== null) {
            const tr = editor.state.tr.insert(after, editor.schema.nodes.paragraph.create());
            editor.view.dispatch(tr.setSelection(TextSelection.create(tr.doc, after + 1))); editor.view.focus();
        }
        action();
    };
    const renderMenu = () => {
        options.replaceChildren(); menu.hidden = false;
        const groups = new Map();
        candidates.forEach((command, index) => { const category = command[2] || 'Podstawowe'; if (!groups.has(category)) groups.set(category, []); groups.get(category).push({command, index}); });
        groups.forEach((items, category) => {
            const heading = document.createElement('div'); heading.className = 'command-category'; heading.textContent = category; heading.setAttribute('role', 'presentation'); options.append(heading);
            items.forEach(({command: [label, action, , description = 'Wstaw lub przekształć zaznaczony blok', icon = '¶'], index}) => {
                const button = document.createElement('button'); button.type = 'button'; button.role = 'option'; button.id = 'editor-command-' + index; button.setAttribute('aria-selected', String(index === active));
                const symbol = document.createElement('span'); symbol.textContent = icon; symbol.setAttribute('aria-hidden', 'true');
                const name = document.createElement('span'); name.textContent = label;
                const help = document.createElement('small'); help.textContent = description;
                button.append(symbol, name, help); button.onmousedown = event => event.preventDefault(); button.onclick = () => choose(action); options.append(button);
            });
        });
        if (!candidates.length) { const empty = document.createElement('p'); empty.textContent = 'Brak pasujących elementów.'; options.append(empty); }
        search.setAttribute('aria-activedescendant', candidates.length ? 'editor-command-' + active : '');
        const selected = options.querySelector('[aria-selected=true]');
        if (selected) { const item = selected.getBoundingClientRect(), box = options.getBoundingClientRect(); if (item.bottom > box.bottom) options.scrollTop += item.bottom - box.bottom; else if (item.top < box.top) options.scrollTop -= box.top - item.top; }
        if (range) positionMenu(range.to);
    };
    const updateMenu = async () => {
        const sequence = ++querySequence, {$from, empty} = editor.state.selection;
        const before = $from.parent.isTextblock ? $from.parent.textBetween(0, $from.parentOffset) : '';
        const match = /(?:^|\s)([/@])([^\s/@]{0,30})$/.exec(before);
        if (!canEdit() || !empty || !match || editor.isActive('codeBlock') || editor.isActive('diagram')) { menu.hidden = true; range = null; return; }
        manual = false; insertion = null; range = {from: $from.pos - match[2].length - 1, to: $from.pos}; active = 0;
        search.value = match[2]; positionMenu($from.pos);
        if (match[1] === '/') candidates = commands.filter(([label]) => label.toLocaleLowerCase().includes(match[2].toLocaleLowerCase()));
        else {
            try {
                const data = await requestJson(root.dataset.usersUrl + '?document_key=' + encodeURIComponent(snapshot().documentKey) + '&q=' + encodeURIComponent(match[2]));
                if (sequence !== querySequence) return;
                candidates = data.users.map((user) => ['@' + user.username, () => focus().insertContent({type: 'text', text: '@' + user.username + ' '}).run()]);
            } catch (error) { if (sequence !== querySequence) return; report(error.message); candidates = []; }
        }
        renderMenu();
    };
    let menuTimer = null;
    const dismissMenu = () => {
        ++querySequence; clearTimeout(menuTimer); menuTimer = null; menu.hidden = true; range = null; manual = false; insertion = null;
    };
    editor.on('transaction', ({transaction}) => {
        if (transaction.docChanged || transaction.selectionSet) {
            dismissMenu();
            menuTimer = setTimeout(() => { menuTimer = null; updateMenu(); }, 120);
        }
    });
    root.addEventListener('keydown', (event) => {
        if (!event.target.closest('[data-visual-editor], [data-command-menu]')) return;
        if (canEdit() && (event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'f') { event.preventDefault(); event.stopPropagation(); q('[data-open-find]').click(); return; }
        if (event.altKey && event.shiftKey && ['ArrowUp', 'ArrowDown'].includes(event.key)) { event.preventDefault(); event.stopPropagation(); const block = selectedBlock(); if (block) moveBlock(block.index + (event.key === 'ArrowUp' ? -1 : 1)); return; }
        if (event.key === 'Escape' && (range || menuTimer !== null || !menu.hidden)) { event.preventDefault(); event.stopPropagation(); dismissMenu(); return; }
        if (menu.hidden || event.target.closest('dialog')) return;
        if (['ArrowUp', 'ArrowDown', 'Enter'].includes(event.key)) {
            event.preventDefault(); event.stopPropagation();
            if (event.key === 'Enter') options.querySelector('[aria-selected=true]')?.click();
            else if (candidates.length) { active = (active + (event.key === 'ArrowUp' ? -1 : 1) + candidates.length) % candidates.length; renderMenu(); }
        }
    }, true);
    search.oninput = () => { active = 0; candidates = commands.filter(([label]) => label.toLocaleLowerCase('pl').includes(search.value.toLocaleLowerCase('pl'))); renderMenu(); };
    let hovered = null;
    const handles = q('[data-block-tools]'); handles.hidden = true;
    const placeHandles = pos => {
        const coordinates = editor.view.coordsAtPos(Math.min(pos + 1, editor.state.doc.content.size));
        const narrow = window.innerWidth < 900;
        const editorLeft = editor.view.dom.getBoundingClientRect().left;
        handles.style.left = Math.max(2, editorLeft - (narrow ? 0 : handles.offsetWidth + 8)) + 'px';
        handles.style.top = Math.max(65, coordinates.top - (narrow ? handles.offsetHeight + 4 : 3)) + 'px'; handles.hidden = !canEdit() || coordinates.bottom < 65 || coordinates.top > window.innerHeight;
        const panel = q('.block-action-menu'), above = coordinates.top > window.innerHeight - 300;
        panel.style.top = above ? 'auto' : '100%'; panel.style.bottom = above ? '100%' : 'auto';
    };
    const placeSelectedHandles = () => {
        const block = selectedBlock(); if (!block) { handles.hidden = true; return; }
        hovered = block.pos; placeHandles(hovered);
    };
    editor.on('selectionUpdate', placeSelectedHandles);
    editor.on('focus', placeSelectedHandles);
    requestAnimationFrame(placeSelectedHandles);
    editor.view.dom.addEventListener('pointermove', event => {
        if (manual || q('[data-block-menu]').open) return;
        const target = editor.view.posAtCoords({left: event.clientX, top: event.clientY}); if (!target) return;
        const position = editor.state.doc.resolve(target.pos); hovered = position.depth ? position.before(1) : position.pos;
        if (editor.state.doc.nodeAt(hovered)) placeHandles(hovered);
    });
    handles.addEventListener('mousedown', event => {
        if (hovered !== null && canEdit()) editor.view.dispatch(editor.state.tr.setSelection(NodeSelection.create(editor.state.doc, hovered)));
        if (!event.target.closest('select,summary,[data-block-drag]')) event.preventDefault();
    });
    q('[data-block-add]').onclick = () => {
        const block = selectedBlock(); if (!block || !canEdit()) return;
        // Selecting the hovered block on pointer down schedules slash-menu detection.
        // Cancel that stale callback so it cannot close the manually opened picker.
        clearTimeout(menuTimer); menuTimer = null; ++querySequence;
        manual = true; range = null; insertion = block.pos + block.node.nodeSize; search.value = ''; active = 0; candidates = commands;
        renderMenu(); positionMenu(block.pos); search.focus();
    };
    q('[data-block-transform]').onchange = event => {
        if (!canEdit()) return;
        const type = event.target.value;
        if (/^h[1-6]$/.test(type)) focus().setHeading({level: Number(type[1])}).run();
        else if (type === 'paragraph') focus().setParagraph().run();
        else if (type === 'codeBlock') q('[data-open-code]').click();
        else if (type === 'blockquote') focus().toggleBlockquote().run();
        event.target.value = ''; q('[data-block-menu]').open = false;
    };
    document.addEventListener('pointerdown', event => { if (!menu.hidden && !menu.contains(event.target) && !handles.contains(event.target)) dismissMenu(); });
    window.addEventListener('scroll', () => { if (hovered !== null && editor.state.doc.nodeAt(hovered)) placeHandles(hovered); else handles.hidden = true; q('[data-text-context]').hidden = true; if (!menu.hidden && range) positionMenu(range.to); }, true);
    window.addEventListener('resize', () => {
        if (hovered !== null && editor.state.doc.nodeAt(hovered)) placeHandles(hovered); else handles.hidden = true;
        if (!menu.hidden) {
            const position = range?.to ?? (insertion !== null ? Math.min(insertion, editor.state.doc.content.size) : hovered);
            if (position !== null) positionMenu(position);
        }
    });
    return refresh;
}
