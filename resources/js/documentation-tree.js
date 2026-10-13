document.querySelectorAll('[data-documentation-tree]').forEach(tree => {
    const root = tree.querySelector('[data-tree-root]'), search = tree.querySelector('[data-tree-search]'), status = tree.querySelector('[data-tree-status]');
    const key = `openwiki.tree.${tree.dataset.userId}.${tree.dataset.spaceId}`;
    let expanded = new Set(), searchTimer, generation = 0;
    try { expanded = new Set(JSON.parse(localStorage.getItem(key) || '[]')); } catch {}
    const remember = () => { try { localStorage.setItem(key, JSON.stringify([...expanded])); } catch {} };
    const visible = () => [...tree.querySelectorAll('[role=treeitem]')].filter(item => !item.closest('ul[hidden]'));
    const focus = item => { tree.querySelectorAll('[role=treeitem]').forEach(node => node.tabIndex = -1); if (item) { item.tabIndex = 0; item.focus(); } };
    const load = async (group, parent = null, offset = 0, current = generation) => {
        group.setAttribute('aria-busy', 'true'); status.textContent = 'Ładowanie gałęzi…';
        try {
            const url = new URL(tree.dataset.url, location.origin); if (parent) url.searchParams.set('parent_id', parent);
            if (search.value.trim()) url.searchParams.set('q', search.value.trim()); url.searchParams.set('offset', offset);
            const response = await fetch(url, {headers: {Accept: 'application/json'}}), body = await response.json();
            if (!response.ok) throw new Error(body.error?.message || `Błąd ${response.status}`);
            if (current !== generation || !group.isConnected) return;
            if (!offset) group.replaceChildren();
            group.querySelector(':scope > .tree-more')?.remove();
            for (const page of body.data.items) {
                const item = document.createElement('li'); item.role = 'treeitem'; item.tabIndex = -1; item.dataset.pageId = page.id; item.dataset.page = JSON.stringify(page);
                const row = document.createElement('div'); row.className = 'tree-row';
                const toggle = document.createElement('button'); toggle.type = 'button'; toggle.className = 'tree-toggle'; toggle.textContent = page.has_children ? '▸' : '·'; toggle.disabled = !page.has_children || Boolean(search.value.trim()); toggle.setAttribute('aria-label', `Rozwiń: ${page.title}`);
                const link = document.createElement('a'); link.className = 'page-tree__link'; link.href = page.url; link.textContent = page.title; link.tabIndex = -1;
                if (String(page.id) === tree.dataset.activePage) { link.classList.add('is-active'); link.setAttribute('aria-current', 'page'); }
                row.append(toggle, link); item.append(row); group.append(item);
                if (page.can_create && tree.closest('[data-wiki-editor]')) {
                    const create = document.createElement('a'); create.className = 'tree-create'; create.href = tree.dataset.url.replace(/\/tree$/, '/pages/create') + '?parent=' + page.id;
                    create.textContent = '+'; create.setAttribute('aria-label', 'Utwórz podstronę: ' + page.title); row.append(create);
                }
                if (page.has_children) {
                    const children = document.createElement('ul'); children.role = 'group'; children.className = 'page-tree'; children.hidden = true; item.append(children); item.setAttribute('aria-expanded', 'false');
                    toggle.addEventListener('click', event => { event.stopPropagation(); expand(item); });
                    if (!search.value.trim() && expanded.has(String(page.id))) await expand(item, true, current);
                }
                if ((page.can_move || page.can_copy) && !tree.closest('[data-wiki-editor]')) {
                    const operations = document.createElement('button'); operations.type = 'button'; operations.textContent = '⋯'; operations.className = 'tree-operation'; operations.setAttribute('aria-label', `Przenieś lub kopiuj: ${page.title}`);
                    operations.addEventListener('click', () => document.dispatchEvent(new CustomEvent('wiki:structure', {detail: {page, tree}}))); row.append(operations);
                    row.draggable = true; row.addEventListener('dragstart', event => { event.dataTransfer.setData('application/openwiki-page', JSON.stringify({page, space: tree.dataset.spaceId})); });
                }
                if (!tree.closest('[data-wiki-editor]')) {
                    row.addEventListener('dragover', event => { if (event.dataTransfer.types.includes('application/openwiki-page')) event.preventDefault(); });
                    row.addEventListener('drop', event => { event.preventDefault(); try { const source = JSON.parse(event.dataTransfer.getData('application/openwiki-page')); document.dispatchEvent(new CustomEvent('wiki:structure', {detail: {page: source.page, tree, parent: page}})); } catch {} });
                }
            }
            if (body.data.next_offset !== null) {
                const more = document.createElement('li'); more.className = 'tree-more'; more.role = 'none'; const button = document.createElement('button'); button.type = 'button'; button.className = 'button button--ghost'; button.textContent = 'Wczytaj kolejne strony';
                button.addEventListener('click', () => load(group, parent, body.data.next_offset, current)); more.append(button); group.append(more);
            }
            if (!tree.querySelector('[role=treeitem][tabindex="0"]')) { const first = visible()[0]; if (first) first.tabIndex = 0; }
            status.textContent = body.data.items.length ? '' : 'Brak dostępnych stron.';
        } catch (error) { if (current === generation) { status.textContent = error.message; const retry = document.createElement('button'); retry.type = 'button'; retry.textContent = 'Ponów'; retry.addEventListener('click', () => load(group, parent, offset, current)); status.append(retry); } }
        finally { group.removeAttribute('aria-busy'); }
    };
    const expand = async (item, force = false, current = generation) => {
        const children = item.querySelector(':scope > ul'); if (!children) return;
        const open = force || children.hidden; children.hidden = !open; item.setAttribute('aria-expanded', String(open)); item.querySelector('.tree-toggle').textContent = open ? '▾' : '▸';
        if (open) { expanded.add(item.dataset.pageId); if (!children.children.length) await load(children, item.dataset.pageId, 0, current); } else expanded.delete(item.dataset.pageId); remember();
    };
    tree.addEventListener('keydown', async event => {
        const item = event.target.closest('[role=treeitem]'); if (!item || event.target !== item) return;
        const rows = visible(), index = rows.indexOf(item); let target;
        if (event.key === 'ArrowDown') target = rows[index + 1];
        else if (event.key === 'ArrowUp') target = rows[index - 1];
        else if (event.key === 'Home') target = rows[0];
        else if (event.key === 'End') target = rows.at(-1);
        else if (event.key === 'ArrowRight') { if (item.getAttribute('aria-expanded') === 'false') await expand(item); else target = item.querySelector(':scope > ul > [role=treeitem]'); }
        else if (event.key === 'ArrowLeft') { if (item.getAttribute('aria-expanded') === 'true') await expand(item); else target = item.parentElement.closest('[role=treeitem]'); }
        else if (event.key === 'Enter') item.querySelector('.page-tree__link').click();
        else if (event.key === 'F2') item.querySelector('.tree-operation')?.click();
        else return; event.preventDefault(); if (target) focus(target);
    });
    tree.addEventListener('focusin', event => { const item = event.target.closest('[role=treeitem]'); if (item && event.target === item) tree.querySelectorAll('[role=treeitem]').forEach(node => node.tabIndex = node === item ? 0 : -1); });
    search.addEventListener('input', () => { clearTimeout(searchTimer); generation++; searchTimer = setTimeout(() => load(root), 250); });
    tree.addEventListener('wiki:tree-refresh', () => { generation++; load(root); }); load(root);
});
