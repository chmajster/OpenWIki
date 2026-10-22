(() => {
    'use strict';

    const translations = JSON.parse(document.querySelector('[data-ui-translations]')?.value || '{}');
    const t = value => translations[value] || value;
    const typeLabels = {page: 'Pages', space: 'Spaces', user: 'People', comment: 'Comments', attachment: 'Attachments', tag: 'Tags'};
    const typeIcons = {page: '▤', space: 'S', user: '@', comment: '•', attachment: '↗', tag: '#'};
    const localDate = date => {
        const year = date.getFullYear();
        const month = String(date.getMonth() + 1).padStart(2, '0');
        const day = String(date.getDate()).padStart(2, '0');
        return `${year}-${month}-${day}`;
    };

    document.querySelectorAll('[data-search-form]').forEach(form => {
        const input = form.querySelector('[data-search-query]');
        const globalPanel = form.querySelector('[data-global-search-panel]');
        const globalMode = Boolean(globalPanel);
        const list = globalMode
            ? globalPanel.querySelector('[data-global-search-results]')
            : form.querySelector('[role=listbox]');
        const status = form.querySelector('[data-search-suggestion-status]');
        if (!input || !list || !status) return;

        const clearButton = form.querySelector('[data-search-clear]');
        if (clearButton) {
            const updateClearButton = () => { clearButton.hidden = input.value.length === 0; };
            input.addEventListener('input', updateClearButton);
            clearButton.addEventListener('click', () => {
                input.value = '';
                updateClearButton();
                input.dispatchEvent(new Event('input', {bubbles: true}));
                input.focus();
            });
        }

        const updatedPeriod = form.querySelector('[data-search-updated-period]');
        const customUpdatedDates = [...form.querySelectorAll('[data-search-custom-updated]')];
        updatedPeriod?.addEventListener('change', () => {
            if (updatedPeriod.value !== 'custom') customUpdatedDates.forEach(field => { field.value = ''; });
        });
        customUpdatedDates.forEach(field => field.addEventListener('change', () => {
            if (updatedPeriod) updatedPeriod.value = 'custom';
        }));
        form.querySelectorAll('[data-search-auto-submit]').forEach(control => control.addEventListener('change', () => form.requestSubmit()));

        let timer, controller, generation = 0, active = -1;
        const state = {type: 'all', date: 'any', space: '', author: '', spacesExpanded: false, authorsExpanded: false};
        let latestPayload = {data: [], meta: {total: 0, counts: {}}, facets: {spaces: [], authors: []}};
        const optionNodes = () => [...list.querySelectorAll('[role="option"]')];

        const dateRange = () => {
            const today = new Date();
            const todayString = localDate(today);
            if (state.date === 'today') return {updated_from: todayString, updated_to: todayString};
            if (state.date === 'yesterday') {
                const yesterday = new Date(today);
                yesterday.setDate(yesterday.getDate() - 1);
                const value = localDate(yesterday);
                return {updated_from: value, updated_to: value};
            }
            const days = Number(state.date);
            if (Number.isFinite(days) && days > 0) {
                const start = new Date(today);
                start.setDate(start.getDate() - days + 1);
                return {updated_from: localDate(start), updated_to: todayString};
            }
            return {};
        };

        const allResultsUrl = () => {
            const query = new URLSearchParams(new FormData(form));
            if (globalMode) {
                query.set('q', input.value.trim());
                if (state.type === 'all') query.delete('type'); else query.set('type', state.type);
                if (state.space) query.set('space', state.space); else query.delete('space');
                if (state.author) query.set('author', state.author); else query.delete('author');
                query.delete('updated_from'); query.delete('updated_to');
                for (const [key, value] of Object.entries(dateRange())) query.set(key, value);
            }
            query.delete('page');
            query.delete('per_page');
            return form.action + '?' + query.toString();
        };

        const hide = () => {
            list.hidden = true;
            if (globalMode) globalPanel.hidden = true;
            input.setAttribute('aria-expanded', 'false');
            input.removeAttribute('aria-activedescendant');
            active = -1;
        };

        const invalidate = () => {
            generation++;
            clearTimeout(timer);
            controller?.abort();
            hide();
            status.textContent = '';
        };

        const appendResult = (result, index) => {
            const item = document.createElement('li');
            item.setAttribute('role', 'option');
            item.setAttribute('aria-selected', 'false');
            item.id = list.id + '-option-' + index;

            const link = document.createElement('a');
            link.className = globalMode ? 'global-search-result' : 'search-suggestion';
            link.href = result.url || ('/search?q=' + encodeURIComponent(result.title));

            const icon = document.createElement('span');
            icon.className = globalMode ? 'global-search-result__icon' : 'search-suggestion__icon';
            icon.setAttribute('aria-hidden', 'true');
            icon.textContent = typeIcons[result.type] || '•';

            const content = document.createElement('span');
            content.className = globalMode ? 'global-search-result__content' : 'search-suggestion__content';
            const heading = document.createElement('span');
            heading.className = globalMode ? 'global-search-result__heading' : 'search-suggestion__heading';
            const title = document.createElement('strong');
            title.className = globalMode ? 'global-search-result__title' : 'search-suggestion__title';
            title.textContent = result.title;
            heading.append(title);

            if (!globalMode) {
                const category = document.createElement('small');
                category.className = 'search-suggestion__category';
                category.textContent = t(typeLabels[result.type] || 'Search');
                heading.append(category);
            }
            content.append(heading);

            const details = [];
            if (result.author) details.push(result.author);
            if (result.meta || result.space?.name) details.push(result.meta || result.space.name);
            if (result.updated_at) {
                const date = new Date(String(result.updated_at).replace(' ', 'T') + 'Z');
                const formattedDate = Number.isNaN(date.getTime())
                    ? result.updated_at
                    : new Intl.DateTimeFormat(document.documentElement.lang || 'pl', {day: 'numeric', month: 'short', year: 'numeric'}).format(date);
                details.push(t('Updated') + ' ' + formattedDate);
            }
            if (result.working) details.push(t('Draft') + (result.revision ? ' · ' + t('Version') + ' ' + result.revision : ''));
            if (details.length) {
                const meta = document.createElement('small');
                meta.className = globalMode ? 'global-search-result__meta' : 'search-suggestion__meta';
                meta.textContent = details.join(' · ');
                content.append(meta);
            }
            if (!globalMode && result.snippet) {
                const snippet = document.createElement('span');
                snippet.className = 'search-suggestion__snippet';
                snippet.textContent = result.snippet;
                content.append(snippet);
            }
            link.append(icon, content);
            item.append(link);
            list.append(item);
        };

        const renderFacetGroup = (container, moreButton, values, kind, selected, expanded) => {
            container.replaceChildren();
            const shown = expanded ? values : values.slice(0, 4);
            for (const facet of shown) {
                const value = kind === 'space' ? facet.key : facet.name;
                const label = kind === 'space' ? facet.name : facet.name;
                const button = document.createElement('button');
                button.type = 'button';
                button.className = 'global-search-facet';
                button.dataset[kind === 'space' ? 'searchSpace' : 'searchAuthor'] = value;
                button.setAttribute('aria-pressed', String(selected === value));

                const check = document.createElement('span');
                check.className = 'global-search-facet__check';
                check.setAttribute('aria-hidden', 'true');
                const avatar = document.createElement('span');
                avatar.className = 'global-search-facet__avatar';
                avatar.setAttribute('aria-hidden', 'true');
                avatar.textContent = label.trim().slice(0, 1).toUpperCase();
                const name = document.createElement('span');
                name.className = 'global-search-facet__name';
                name.textContent = label;
                const count = document.createElement('small');
                count.textContent = String(facet.count);
                button.append(check, avatar, name, count);
                container.append(button);
            }
            if (values.length === 0) {
                const empty = document.createElement('span');
                empty.className = 'global-search-facet-empty';
                empty.textContent = t('No matching filters');
                container.append(empty);
            }
            moreButton.hidden = values.length <= 4;
            moreButton.textContent = expanded ? t('Show less') : t('Show more');
        };

        const renderGlobalFilters = payload => {
            const spaces = globalPanel.querySelector('[data-search-spaces]');
            const authors = globalPanel.querySelector('[data-search-authors]');
            renderFacetGroup(spaces, globalPanel.querySelector('[data-search-spaces-more]'), payload.facets?.spaces || [], 'space', state.space, state.spacesExpanded);
            renderFacetGroup(authors, globalPanel.querySelector('[data-search-authors-more]'), payload.facets?.authors || [], 'author', state.author, state.authorsExpanded);
            for (const button of globalPanel.querySelectorAll('[data-search-date]')) {
                button.setAttribute('aria-pressed', String(button.dataset.searchDate === state.date));
            }
            for (const tab of globalPanel.querySelectorAll('[data-search-tab]')) {
                tab.setAttribute('aria-selected', String(tab.dataset.searchTab === state.type));
                const type = tab.dataset.searchTab;
                const count = type === 'all' ? payload.meta?.total : payload.meta?.counts?.[type];
                let badge = tab.querySelector('.global-search-tab__count');
                if (!badge) {
                    badge = document.createElement('span');
                    badge.className = 'global-search-tab__count';
                    tab.append(badge);
                }
                badge.textContent = Number.isFinite(Number(count)) ? String(count) : '0';
            }
            const visibleCount = state.type === 'all' ? payload.meta?.total : payload.meta?.counts?.[state.type];
            globalPanel.querySelector('[data-global-search-count]').textContent = Number(visibleCount || 0);
            globalPanel.querySelector('[data-global-search-advanced]').href = allResultsUrl();
        };

        const render = payload => {
            latestPayload = payload;
            list.replaceChildren();
            active = -1;
            const allResults = payload.data || [];
            const visibleResults = state.type === 'all' ? allResults : allResults.filter(result => result.type === state.type);
            if (!visibleResults.length) {
                const empty = document.createElement('li');
                empty.className = 'global-search-empty';
                empty.textContent = t('No matching results');
                list.append(empty);
            } else {
                visibleResults.forEach(appendResult);
            }

            if (globalMode) {
                renderGlobalFilters(payload);
                list.hidden = false;
                globalPanel.hidden = false;
                input.setAttribute('aria-expanded', 'true');
            } else {
                const item = document.createElement('li');
                item.className = 'search-suggestions__all';
                item.setAttribute('role', 'option');
                item.setAttribute('aria-selected', 'false');
                item.id = list.id + '-all-results';
                const link = document.createElement('a');
                link.href = allResultsUrl();
                link.textContent = t('See all results for') + ' “' + input.value.trim() + '”';
                item.append(link);
                list.append(item);
                list.hidden = allResults.length === 0;
                input.setAttribute('aria-expanded', String(allResults.length > 0));
            }
            status.textContent = visibleResults.length
                ? visibleResults.length + ' ' + t('suggestions available')
                : t('No matching results');
        };

        const requestSuggestions = () => {
            invalidate();
            const value = input.value.trim();
            if (value.length < 2) return;
            if (globalMode) {
                globalPanel.querySelector('[data-global-search-advanced]').href = allResultsUrl();
                const loading = document.createElement('li');
                loading.className = 'global-search-empty';
                loading.textContent = t('Searching…');
                list.replaceChildren(loading);
                list.hidden = false;
                globalPanel.hidden = false;
                input.setAttribute('aria-expanded', 'true');
            }
            const current = generation;
            timer = setTimeout(async () => {
                controller = new AbortController();
                status.textContent = t('Searching…');
                const query = globalMode
                    ? new URLSearchParams({q: value, type: state.type, per_page: '30', panel: '1'})
                    : new URLSearchParams(new FormData(form));
                query.delete('page');
                if (globalMode) {
                    if (state.space) query.set('space', state.space);
                    if (state.author) query.set('author', state.author);
                    for (const [key, date] of Object.entries(dateRange())) query.set(key, date);
                } else {
                    query.set('per_page', '8');
                }
                try {
                    const response = await fetch('/search/suggest?' + query, {signal: controller.signal, headers: {'Accept': 'application/json'}});
                    if (!response.ok) throw new Error('Suggestions are temporarily unavailable.');
                    const result = await response.json();
                    if (current !== generation) return;
                    if (globalMode) render(result);
                    else render({...result, meta: {total: result.data?.length || 0, counts: {}}, facets: {spaces: [], authors: []}});
                } catch (error) {
                    if (current === generation && error.name !== 'AbortError') {
                        const message = t('Suggestions are temporarily unavailable. Full search still works.');
                        status.textContent = message;
                        if (globalMode) {
                            list.replaceChildren();
                            const errorItem = document.createElement('li');
                            errorItem.className = 'global-search-empty';
                            errorItem.textContent = message;
                            list.append(errorItem);
                            list.hidden = false;
                            globalPanel.hidden = false;
                            input.setAttribute('aria-expanded', 'true');
                        }
                    }
                }
            }, 220);
        };

        if (globalMode) {
            globalPanel.querySelector('[data-global-search-results]').id = 'global-search-suggestions';
            const current = new URLSearchParams(window.location.search);
            if (current.get('q') === input.value.trim()) {
                state.type = ['page', 'space', 'user'].includes(current.get('type')) ? current.get('type') : 'all';
                state.space = current.get('space') || '';
                state.author = current.get('author') || '';
            }

            globalPanel.addEventListener('click', event => {
                const tab = event.target.closest('[data-search-tab]');
                if (tab) {
                    state.type = tab.dataset.searchTab;
                    requestSuggestions();
                    return;
                }
                const date = event.target.closest('[data-search-date]');
                if (date) { state.date = date.dataset.searchDate; requestSuggestions(); return; }
                const space = event.target.closest('[data-search-space]');
                if (space) { state.space = state.space === space.dataset.searchSpace ? '' : space.dataset.searchSpace; requestSuggestions(); return; }
                const author = event.target.closest('[data-search-author]');
                if (author) { state.author = state.author === author.dataset.searchAuthor ? '' : author.dataset.searchAuthor; requestSuggestions(); return; }
                const moreSpaces = event.target.closest('[data-search-spaces-more]');
                if (moreSpaces) { state.spacesExpanded = !state.spacesExpanded; renderGlobalFilters(latestPayload); return; }
                const moreAuthors = event.target.closest('[data-search-authors-more]');
                if (moreAuthors) { state.authorsExpanded = !state.authorsExpanded; renderGlobalFilters(latestPayload); }
            });

            input.addEventListener('input', () => {
                state.type = 'all'; state.date = 'any'; state.space = ''; state.author = '';
                state.spacesExpanded = false; state.authorsExpanded = false;
                requestSuggestions();
            });
            input.addEventListener('focus', () => { if (input.value.trim().length >= 2 && globalPanel.hidden) requestSuggestions(); });
            form.addEventListener('submit', event => { event.preventDefault(); window.location.assign(allResultsUrl()); });
        } else {
            input.addEventListener('input', requestSuggestions);
            form.addEventListener('change', event => {
                if (event.target === input) return;
                invalidate();
                if (event.target.matches('[name="my_work"], [name="updated_period"], [name="type"], [name="visibility"], [name="per_page"]')
                    && !(event.target.matches('[name="updated_period"]') && event.target.value === 'custom')) form.requestSubmit();
            });
            form.addEventListener('submit', invalidate);
        }

        input.addEventListener('keydown', event => {
            if (event.key === 'Escape') { event.preventDefault(); invalidate(); return; }
            if (list.hidden) return;
            const options = optionNodes();
            if (!options.length) return;
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                active = active < 0
                    ? (event.key === 'ArrowDown' ? 0 : options.length - 1)
                    : (active + (event.key === 'ArrowDown' ? 1 : -1) + options.length) % options.length;
                options.forEach((option, index) => option.setAttribute('aria-selected', String(index === active)));
                input.setAttribute('aria-activedescendant', options[active].id);
                options[active].scrollIntoView({block: 'nearest'});
            } else if (event.key === 'Enter' && active >= 0) {
                event.preventDefault();
                options[active].querySelector('a')?.click();
            }
        });

        document.addEventListener('click', event => {
            if (!form.contains(event.target)) invalidate();
        });
    });
})();
