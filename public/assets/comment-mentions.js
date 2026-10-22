(() => {
    'use strict';

    const isPolish = document.documentElement.lang === 'pl';
    const message = {
        searching: isPolish ? 'Wyszukiwanie użytkowników…' : 'Searching users…',
        empty: isPolish ? 'Nie znaleziono aktywnych użytkowników.' : 'No active users found.',
        failed: isPolish ? 'Podpowiedzi użytkowników są chwilowo niedostępne.' : 'User suggestions are temporarily unavailable.',
        count: count => isPolish
            ? `Użytkowników: ${count}. Użyj strzałek i Enter, aby wybrać.`
            : `${count} users. Use the arrow keys and Enter to select.`,
    };

    document.querySelectorAll('[data-user-mention-field]').forEach(field => {
        const input = field.querySelector('[data-user-mention]');
        const list = field.querySelector('[role="listbox"]');
        const status = field.querySelector('[data-user-mention-status]');
        if (!input || !list || !status) return;

        let timer = 0;
        let controller = null;
        let generation = 0;
        let active = -1;

        const options = () => [...list.querySelectorAll('[role="option"]')];
        const hide = (clearStatus = true) => {
            generation++;
            window.clearTimeout(timer);
            controller?.abort();
            controller = null;
            list.hidden = true;
            input.setAttribute('aria-expanded', 'false');
            input.removeAttribute('aria-activedescendant');
            active = -1;
            if (clearStatus) status.textContent = '';
        };
        const mention = () => {
            const caret = input.selectionStart ?? input.value.length;
            const beforeCaret = input.value.slice(0, caret);
            const match = beforeCaret.match(/(^|[\s([{])@([A-Za-z0-9._-]{4,100})$/);
            if (!match) return null;
            const query = match[2];
            return {query, start: caret - query.length - 1, end: caret};
        };
        const selectUser = username => {
            const current = mention();
            if (!current) return hide();
            input.setRangeText(`@${username} `, current.start, current.end, 'end');
            hide();
            input.focus();
            input.dispatchEvent(new Event('input', {bubbles: true}));
        };
        const render = users => {
            list.replaceChildren();
            users.forEach((user, index) => {
                const username = String(user.username || user.title || '');
                if (!username) return;

                const item = document.createElement('li');
                item.id = `${list.id}-option-${index}`;
                item.setAttribute('role', 'option');
                item.setAttribute('aria-selected', 'false');
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'comment-mention-option';
                const name = document.createElement('strong');
                name.textContent = `@${username}`;
                button.append(name);

                const fullName = [user.first_name, user.last_name].filter(Boolean).join(' ').trim();
                if (fullName) {
                    const detail = document.createElement('small');
                    detail.textContent = fullName;
                    button.append(detail);
                }

                button.addEventListener('mousedown', event => event.preventDefault());
                button.addEventListener('click', () => selectUser(username));
                item.append(button);
                list.append(item);
            });

            const count = options().length;
            list.hidden = count === 0;
            input.setAttribute('aria-expanded', String(count > 0));
            status.textContent = count ? message.count(count) : message.empty;
        };

        input.addEventListener('input', () => {
            hide();
            const current = mention();
            if (!current || current.query.length < 4) return;

            const requestGeneration = generation;
            const requestedQuery = current.query;
            status.textContent = message.searching;
            timer = window.setTimeout(async () => {
                controller = new AbortController();
                const query = new URLSearchParams({q: requestedQuery, type: 'user', per_page: '8'});
                try {
                    const response = await fetch(`/search/suggest?${query}`, {
                        signal: controller.signal,
                        headers: {'Accept': 'application/json'},
                    });
                    if (!response.ok) throw new Error('User suggestions request failed.');
                    const result = await response.json();
                    if (requestGeneration !== generation || mention()?.query !== requestedQuery) return;
                    const users = (Array.isArray(result.data) ? result.data : [])
                        .filter(user => user.type === 'user' && user.status === 'active');
                    render(users);
                } catch (error) {
                    if (requestGeneration === generation && error.name !== 'AbortError') {
                        status.textContent = message.failed;
                    }
                }
            }, 220);
        });

        input.addEventListener('keydown', event => {
            if (event.key === 'Escape' && !list.hidden) {
                event.preventDefault();
                hide();
                return;
            }
            if (list.hidden) return;

            const items = options();
            if ((event.key === 'ArrowDown' || event.key === 'ArrowUp') && items.length) {
                event.preventDefault();
                active = active < 0
                    ? (event.key === 'ArrowDown' ? 0 : items.length - 1)
                    : (active + (event.key === 'ArrowDown' ? 1 : -1) + items.length) % items.length;
                items.forEach((item, index) => item.setAttribute('aria-selected', String(index === active)));
                input.setAttribute('aria-activedescendant', items[active].id);
                items[active].scrollIntoView({block: 'nearest'});
            } else if (event.key === 'Enter' && active >= 0) {
                event.preventDefault();
                items[active]?.querySelector('button')?.click();
            }
        });

        input.addEventListener('blur', () => window.setTimeout(() => hide(), 100));
    });
})();
