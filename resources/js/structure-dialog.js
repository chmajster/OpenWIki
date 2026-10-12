const dialog = document.querySelector('[data-structure-dialog]');
if (dialog) {
    const form = dialog.querySelector('form'), status = dialog.querySelector('[data-structure-status]'), preview = dialog.querySelector('[data-structure-preview]');
    const confirm = dialog.querySelector('[data-structure-confirm]'), save = dialog.querySelector('[data-structure-save]'), prepare = dialog.querySelector('[data-structure-prepare]');
    const destination = form.elements.destination_space_id, parent = form.elements.parent_id, before = form.elements.before_id;
    let page, sourceTree, token, operation, preparedInput, spaces = [], generation = 0, destinationTimer, parentTimer, positionTimer, busy = false, revision = 0;
    const sequences = {spaces: 0, parents: 0, positions: 0}, loading = new Set();
    const json = async response => { const body = await response.json(); if (!response.ok) throw new Error(body.error?.message || `Błąd ${response.status}`); return body.data; };
    const invalidate = () => { revision++; token = null; preparedInput = null; operation = null; preview.hidden = true; confirm.checked = false; save.disabled = true; };
    const buttons = () => { prepare.disabled = busy || loading.size > 0; save.disabled = busy || loading.size > 0 || !token || !confirm.checked; };
    const setBusy = value => { busy = value; buttons(); form.querySelectorAll('input, select').forEach(input => input.disabled = value); dialog.querySelector('[data-structure-cancel]').disabled = value; };
    const stopSearches = () => { clearTimeout(destinationTimer); clearTimeout(parentTimer); clearTimeout(positionTimer); };
    const loadState = (name, value) => { if (value) loading.add(name); else loading.delete(name); buttons(); };
    const searchSpaces = async (query = '') => {
        const current = generation, sequence = ++sequences.spaces; loadState('spaces', true);
        try {
            const rows = await json(await fetch('/structure/destinations?q=' + encodeURIComponent(query), {headers: {Accept: 'application/json'}}));
            if (current !== generation || sequence !== sequences.spaces || query !== dialog.querySelector('[data-destination-search]').value) return;
            const previous = spaces.find(space => String(space.id) === destination.value); spaces = rows;
            if (previous && !spaces.some(space => space.id === previous.id)) spaces.unshift(previous);
            const selected = destination.value || String(page.space_id || sourceTree?.dataset.spaceId || '');
            invalidate();
            destination.replaceChildren(...spaces.map(space => new Option(`${space.name} (${space.visibility})`, space.id, false, String(space.id) === selected)));
            if (!spaces.length) status.textContent = 'Brak dostępnej przestrzeni do zapisu.';
        } catch (error) { if (current === generation && sequence === sequences.spaces) status.textContent = error.message; }
        finally { if (sequence === sequences.spaces) loadState('spaces', false); }
    };
    const branches = async (query = '', parentId = null, siblingsOnly = false) => {
        const space = spaces.find(space => String(space.id) === destination.value); if (!space) return [];
        const url = new URL(`/spaces/${encodeURIComponent(space.key)}/tree`, location.origin);
        if (siblingsOnly) url.searchParams.set('siblings_only', '1');
        if (query) url.searchParams.set('q', query); if (parentId) url.searchParams.set('parent_id', parentId);
        return (await json(await fetch(url, {headers: {Accept: 'application/json'}}))).items;
    };
    const loadParents = async (query = '', selected = null) => {
        const current = generation, spaceId = destination.value, sequence = ++sequences.parents; loadState('parents', true);
        try {
            const items = await branches(query), previous = parent.selectedOptions[0];
            if (current !== generation || spaceId !== destination.value || sequence !== sequences.parents || query !== dialog.querySelector('[data-parent-search]').value) return;
            const retained = previous?.value && selected === null ? {id: previous.value, title: previous.text} : null;
            if (retained && !items.some(item => String(item.id) === retained.id)) items.unshift(retained);
            invalidate();
            parent.replaceChildren(new Option('Główny poziom', ''), ...items.filter(item => item.id !== page.id).map(item => new Option(item.title, item.id)));
            parent.value = selected === null ? (retained?.id || '') : String(selected || ''); await loadPositions();
        } catch (error) { if (current === generation && sequence === sequences.parents) status.textContent = error.message; }
        finally { if (sequence === sequences.parents) loadState('parents', false); }
    };
    const loadPositions = async () => {
        const current = generation, spaceId = destination.value, parentId = parent.value, sequence = ++sequences.positions;
        const query = dialog.querySelector('[data-position-search]').value; loadState('positions', true);
        try {
            const items = await branches(query, parentId, true);
            if (current !== generation || spaceId !== destination.value || parentId !== parent.value || sequence !== sequences.positions || query !== dialog.querySelector('[data-position-search]').value) return;
            const scope = `${spaceId}:${parentId}`, selected = before.dataset.scope === scope ? before.selectedOptions[0] : null;
            const retained = selected?.value ? {id: selected.value, title: selected.text} : null;
            if (retained && !items.some(item => String(item.id) === retained.id)) items.unshift(retained);
            invalidate();
            before.replaceChildren(new Option('Na końcu', ''), ...items.filter(item => item.id !== page.id && (item === retained || String(item.parent_id || '') === parentId)).map(item => new Option(item.title, item.id, false, String(item.id) === retained?.id)));
            before.dataset.scope = scope;
        } catch (error) { if (current === generation && sequence === sequences.positions) status.textContent = error.message; }
        finally { if (sequence === sequences.positions) loadState('positions', false); }
    };
    const open = async detail => {
        stopSearches(); generation++; loading.clear(); page = detail.page; sourceTree = detail.tree; form.reset(); invalidate(); status.textContent = 'Ładowanie miejsc docelowych…';
        dialog.querySelector('[data-structure-source]').textContent = page.title;
        form.elements.action.options[0].disabled = !page.can_move; form.elements.action.value = page.can_move ? 'move' : 'copy';
        form.elements.action.options[1].disabled = !page.can_copy;
        form.elements.title.value = `${page.title} (kopia)`; dialog.querySelector('[data-copy-title]').hidden = form.elements.action.value !== 'copy';
        destination.replaceChildren(); parent.replaceChildren(new Option('Główny poziom', '')); before.replaceChildren(new Option('Na końcu', ''));
        dialog.showModal(); setBusy(true); await searchSpaces();
        const targetParent = detail.parent || (page.parent_id ? {id: page.parent_id, title: 'Obecny rodzic'} : null);
        await loadParents();
        if (targetParent) { if (![...parent.options].some(option => option.value === String(targetParent.id))) parent.add(new Option(targetParent.title, targetParent.id)); parent.value = String(targetParent.id); await loadPositions(); }
        if (status.textContent === 'Ładowanie miejsc docelowych…') status.textContent = ''; setBusy(false);
    };
    document.addEventListener('wiki:structure', event => { if (!busy && !dialog.open) open(event.detail); });
    document.querySelectorAll('[data-structure-page]').forEach(button => button.addEventListener('click', () => open({page: JSON.parse(button.dataset.structurePage)})));
    form.addEventListener('input', event => { if (event.target !== confirm) invalidate(); });
    form.elements.action.addEventListener('change', () => { dialog.querySelector('[data-copy-title]').hidden = form.elements.action.value !== 'copy'; });
    destination.addEventListener('change', () => { generation++; parent.value = ''; dialog.querySelector('[data-parent-search]').value = ''; dialog.querySelector('[data-position-search]').value = ''; loadParents('', ''); });
    parent.addEventListener('change', loadPositions);
    dialog.querySelector('[data-destination-search]').addEventListener('input', event => { clearTimeout(destinationTimer); sequences.spaces++; loadState('spaces', true); destinationTimer = setTimeout(() => searchSpaces(event.target.value), 250); });
    dialog.querySelector('[data-parent-search]').addEventListener('input', event => { clearTimeout(parentTimer); sequences.parents++; loadState('parents', true); parentTimer = setTimeout(() => loadParents(event.target.value), 250); });
    dialog.querySelector('[data-position-search]').addEventListener('input', () => { clearTimeout(positionTimer); sequences.positions++; loadState('positions', true); positionTimer = setTimeout(loadPositions, 250); });
    confirm.addEventListener('change', buttons);
    form.addEventListener('submit', async event => {
        event.preventDefault(); if (busy || loading.size) return;
        preparedInput = Object.fromEntries(new FormData(form)); if (preparedInput.action !== 'copy') delete preparedInput.title;
        preparedInput.base_version = page.version; const snapshot = revision; setBusy(true); status.textContent = 'Obliczanie skutków dostępu…';
        try {
            const result = await json(await fetch(`/structure/pages/${page.id}/preview`, {method: 'POST', headers: {'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': dialog.dataset.token}, body: JSON.stringify(preparedInput)}));
            if (snapshot !== revision) throw new Error('Cel zmienił się podczas wyszukiwania. Przygotuj nowy podgląd.');
            token = result.preview_token; operation = crypto.randomUUID();
            const effects = dialog.querySelector('[data-structure-effects]'), summary = document.createElement('p');
            summary.textContent = `${result.count} stron → ${result.destination}. ${result.effects}`; effects.replaceChildren(summary);
            if (result.detached_children) { const note = document.createElement('p'); note.textContent = `Dzieci pozostające na poprzednim poziomie: ${result.detached_children}.`; effects.append(note); }
            const list = document.createElement('ul');
            result.access_changes.forEach(change => { const row = document.createElement('li'); row.textContent = `${change.principal}: utrata odczytu ${change.lost}, uzyskanie odczytu ${change.gained}.`; list.append(row); });
            if (!result.access_changes.length) list.textContent = 'Brak zmiany efektywnego odczytu dla obecnych kont i gościa.';
            effects.append(list); preview.hidden = false; confirm.checked = false; status.textContent = 'Sprawdź skutki i potwierdź operację.';
        } catch (error) { invalidate(); status.textContent = error.message; }
        finally { setBusy(false); }
    });
    save.addEventListener('click', async () => {
        if (busy || !token || !confirm.checked) return; setBusy(true); status.textContent = 'Zapisywanie struktury…';
        try {
            const result = await json(await fetch(`/structure/pages/${page.id}`, {method: 'POST', headers: {'Content-Type': 'application/json', Accept: 'application/json', 'X-CSRF-Token': dialog.dataset.token}, body: JSON.stringify({...preparedInput, preview_token: token, operation_id: operation})}));
            // Only confirmed server state changes the visible tree; failure leaves it untouched.
            if (sourceTree) { sourceTree.dispatchEvent(new Event('wiki:tree-refresh')); dialog.close(); sourceTree.querySelector('[data-tree-status]').textContent = 'Struktura zapisana.'; }
            else location.assign(result.url);
        } catch (error) { status.textContent = error.message; }
        finally { setBusy(false); }
    });
    dialog.querySelector('[data-structure-cancel]').addEventListener('click', () => { if (!busy) { stopSearches(); generation++; dialog.close(); } });
    dialog.addEventListener('cancel', event => { if (busy) event.preventDefault(); else { stopSearches(); generation++; } });
}
