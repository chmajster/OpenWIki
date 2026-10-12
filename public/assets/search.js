(() => {
    'use strict';
    document.querySelectorAll('[data-search-form]').forEach(form => {
    const input = form.querySelector('[data-search-query]');
    const list = form.querySelector('[role=listbox]');
    const status = form.querySelector('[data-search-suggestion-status]');
    let timer, controller, generation = 0, active = -1;
    const hide = () => {
        list.hidden = true; input.setAttribute('aria-expanded', 'false');
        input.removeAttribute('aria-activedescendant'); active = -1;
    };
    const invalidate = () => { generation++; clearTimeout(timer); controller?.abort(); hide(); status.textContent = ''; };
    input.addEventListener('input', () => {
        invalidate();
        if (input.value.trim().length < 2) return;
        const current = generation;
        timer = setTimeout(async () => {
            controller = new AbortController(); status.textContent = 'Szukam podpowiedzi…';
            const query = new URLSearchParams(new FormData(form)); query.delete('page'); query.set('per_page', '8');
            try {
                const response = await fetch('/search/suggest?' + query, {signal: controller.signal, headers: {'Accept': 'application/json'}});
                if (!response.ok) throw new Error('Podpowiedzi są chwilowo niedostępne. Wyszukiwanie formularzem nadal działa.');
                const {data} = await response.json();
                if (current !== generation) return;
                list.replaceChildren();
                for (const [index, result] of data.entries()) {
                    const item = document.createElement('li'); item.setAttribute('role', 'option'); item.id = list.id + '-option-' + index;
                    const link = document.createElement('a'); link.href = result.url || ('/search?q=' + encodeURIComponent(result.title));
                    link.textContent = result.title + (result.working ? ' · wersja robocza' : '') + (result.space ? ' · ' + result.space.name : '');
                    item.append(link); list.append(item);
                }
                list.hidden = data.length === 0; input.setAttribute('aria-expanded', String(data.length > 0));
                status.textContent = data.length ? data.length + ' podpowiedzi. Strzałki wybierają, Enter otwiera, Escape zamyka.' : 'Brak podpowiedzi.';
            } catch (error) {
                if (current === generation && error.name !== 'AbortError') status.textContent = 'Podpowiedzi są chwilowo niedostępne. Wyszukiwanie formularzem nadal działa.';
            }
        }, 250);
    });
    input.addEventListener('keydown', event => {
        if (event.key === 'Escape') { invalidate(); return; }
        if (list.hidden) return;
        const options = [...list.children];
        if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
            event.preventDefault(); active = (active + (event.key === 'ArrowDown' ? 1 : -1) + options.length) % options.length;
            options.forEach((option, index) => option.setAttribute('aria-selected', String(index === active)));
            input.setAttribute('aria-activedescendant', options[active].id); options[active].scrollIntoView({block: 'nearest'});
        } else if (event.key === 'Enter' && active >= 0) { event.preventDefault(); options[active].querySelector('a').click(); }
    });
    form.addEventListener('change', event => { if (event.target !== input) invalidate(); });
    form.addEventListener('submit', invalidate);
    document.addEventListener('click', event => { if (!form.contains(event.target)) invalidate(); });
    });
})();
