const message = async response => {
    const body = await response.json();
    if (!response.ok) throw new Error(body.error?.message || `Błąd ${response.status}`);
    return body.data;
};
document.querySelectorAll('[data-principal-picker]').forEach(picker => {
    const query = picker.querySelector('[data-principal-query]'), results = picker.querySelector('[data-principal-results]');
    const type = picker.querySelector('[data-principal-type]'), status = picker.querySelector('[data-picker-status]');
    const initial = [...results.options].map(option => ({value: option.value, text: option.text}));
    let timer, controller, sequence = 0;
    const search = async () => {
        const current = ++sequence; controller?.abort(); controller = new AbortController(); status.textContent = 'Wyszukiwanie…';
        try {
            const url = new URL(picker.dataset.url, location.origin);
            url.searchParams.set('type', type?.value || picker.dataset.type || 'user'); url.searchParams.set('q', query.value);
            const rows = await message(await fetch(url, {signal: controller.signal, headers: {Accept: 'application/json'}}));
            if (current !== sequence) return;
            const selected = results.selectedOptions[0], options = new Map(initial.map(item => [item.value, item.text]));
            if (selected && selected.value) options.set(selected.value, selected.text);
            rows.forEach(row => options.set(String(row.id), row.name));
            results.replaceChildren(...[...options].map(([value, text]) => new Option(text, value, false, value === selected?.value)));
            status.textContent = rows.length ? `Wyniki: ${rows.length}. Wybierz adresata.` : 'Brak wyników.';
        } catch (error) { if (error.name !== 'AbortError' && current === sequence) status.textContent = error.message; }
    };
    query.addEventListener('input', () => { clearTimeout(timer); timer = setTimeout(search, 250); });
    type?.addEventListener('change', () => { results.replaceChildren(new Option('Wybierz adresata', '')); search(); });
    query.addEventListener('focus', () => { if (!query.value) search(); });
});
document.querySelectorAll('[data-effective-access]').forEach(panel => {
    const button = panel.querySelector('[data-check-access]'), result = panel.querySelector('[data-effective-result]');
    button.addEventListener('click', async () => {
        button.disabled = true; result.textContent = 'Sprawdzanie uprawnień…';
        try {
            const url = new URL(panel.dataset.url, location.origin), user = panel.querySelector('[data-effective-user]').value;
            if (user) url.searchParams.set('user_id', user);
            const page = panel.querySelector('[data-effective-page]').value; if (page) url.searchParams.set('page_id', page);
            const decisions = await message(await fetch(url, {headers: {Accept: 'application/json'}}));
            const list = document.createElement('ul');
            Object.entries(decisions).forEach(([permission, decision]) => {
                const item = document.createElement('li');
                item.textContent = `${permission}: ${decision.effect} — ${decision.reason}`;
                decision.rules.forEach(rule => {
                    const detail = document.createElement('div'); detail.textContent = `Źródło: ${rule.principal_type} #${rule.principal_id}, ${rule.effect}`; item.append(detail);
                }); list.append(item);
            }); result.replaceChildren(list);
        } catch (error) { result.textContent = error.message; }
        finally { button.disabled = false; }
    });
});
document.querySelectorAll('[data-space-settings]').forEach(form => form.addEventListener('submit', event => {
    const owner = form.querySelector('[name=owner_id]');
    if (owner && owner.value !== owner.dataset.original && !confirm('Przekazać własność przestrzeni? Możesz utracić dostęp.')) event.preventDefault();
}));
