(() => {
    'use strict';
    const root = document.documentElement;
    const suffix = root.dataset.instanceId + ':' + root.dataset.userId;
    const key = 'openwiki:theme:' + suffix;
    const valid = value => ['light', 'dark', 'system'].includes(value);
    const system = window.matchMedia('(prefers-color-scheme: dark)');
    const read = () => {
        try {
            const saved = localStorage.getItem(key);
            if (valid(saved)) return saved;
            // Preserve the existing account's editor preference on first use.
            const legacy = JSON.parse(localStorage.getItem('openwiki:workspace:' + suffix) || '{}');
            if (saved === null && valid(legacy?.theme)) {
                try { localStorage.setItem(key, legacy.theme); } catch { /* Apply the readable legacy choice. */ }
                return legacy.theme;
            }
        } catch { return null; }
        return 'system';
    };
    let preference = read() ?? 'system';
    const apply = () => {
        const resolved = preference === 'system' ? (system.matches ? 'dark' : 'light') : preference;
        root.dataset.theme = resolved;
        if (document.body?.classList.contains('document-workspace')) document.body.dataset.workspaceTheme = resolved;
        document.querySelectorAll('[data-theme-select]').forEach(select => { select.value = preference; });
        document.querySelectorAll('[data-theme-choice]').forEach(choice => { choice.setAttribute('aria-pressed', String(choice.dataset.themeChoice === preference)); });
        document.querySelectorAll('[data-theme-menu]').forEach(menu => { menu.hidden = false; });
    };
    const set = value => {
        if (!valid(value)) return;
        preference = value;
        try { localStorage.setItem(key, value); } catch { /* Apply without persistence. */ }
        apply();
    };
    apply();
    document.addEventListener('DOMContentLoaded', apply, {once: true});
    document.addEventListener('change', event => {
        if (event.target.matches('[data-theme-select]')) set(event.target.value);
    });
    document.addEventListener('click', event => {
        const choice = event.target instanceof Element ? event.target.closest('[data-theme-choice]') : null;
        if (!choice) return;
        set(choice.dataset.themeChoice);
        choice.closest('[data-theme-menu]')?.removeAttribute('open');
    });
    system.addEventListener('change', apply);
    window.addEventListener('storage', event => {
        if (event.key === key || event.key === null) { preference = read() ?? preference; apply(); }
    });
    window.addEventListener('pageshow', () => { preference = read() ?? preference; apply(); });
})();
