'use strict';
(() => {
    const panel = document.querySelector('[data-workspace-navigation]');
    const toggle = document.querySelector('[data-navigation-toggle]');
    if (!panel || !toggle) return;
    const body = document.body;
    const main = document.querySelector('[data-workspace-body]');
    const header = document.querySelector('.topbar');
    const backdrop = document.querySelector('[data-navigation-backdrop]');
    const close = panel.querySelector('[data-navigation-close]');
    const resize = document.querySelector('[data-navigation-resize]');
    const mobile = matchMedia('(max-width: 1023px)');
    const key = 'openwiki:navigation:' + document.documentElement.dataset.instanceId + ':' + body.dataset.userId;
    const widthKey = key + ':width';
    let collapsed = false;
    let opened = false;
    try { collapsed = localStorage.getItem(key) === 'collapsed'; } catch {}
    let navigationWidth = 264;
    try { navigationWidth = Number(localStorage.getItem(widthKey)) || navigationWidth; } catch {}

    const clampWidth = value => Math.max(240, Math.min(480, Math.min(innerWidth * .5, Math.round(value))));
    const setWidth = (value, persist = false) => {
        navigationWidth = clampWidth(value);
        body.style.setProperty('--navigation-width', navigationWidth + 'px');
        resize?.setAttribute('aria-valuenow', String(navigationWidth));
        if (persist) try { localStorage.setItem(widthKey, String(navigationWidth)); } catch {}
    };
    setWidth(navigationWidth);

    const focusable = () => [...panel.querySelectorAll('a[href], button:not(:disabled), input, select, [tabindex="0"]')].filter(node => node.tabIndex >= 0 && node.getClientRects().length);
    const render = () => {
        const visible = mobile.matches ? opened : !collapsed;
        body.dataset.navigation = visible ? 'open' : 'closed';
        toggle.setAttribute('aria-expanded', String(visible));
        panel.inert = !visible;
        backdrop.hidden = !mobile.matches || !opened;
        main.inert = header.inert = mobile.matches && opened;
        if (mobile.matches && opened) {
            panel.setAttribute('role', 'dialog');
            panel.setAttribute('aria-modal', 'true');
        } else {
            panel.removeAttribute('role');
            panel.removeAttribute('aria-modal');
        }
    };
    const hide = () => {
        opened = false;
        render();
        toggle.focus();
    };
    const show = () => {
        if (mobile.matches) opened = true;
        else { collapsed = false; try { localStorage.setItem(key, 'expanded'); } catch {} }
        render();
        if (mobile.matches) (focusable()[0] || close).focus();
    };
    body.dataset.navigationEnhanced = 'true';
    toggle.hidden = close.hidden = false;
    toggle.addEventListener('click', () => {
        if (mobile.matches) { if (opened) hide(); else show(); }
        else {
            collapsed = !collapsed;
            try { localStorage.setItem(key, collapsed ? 'collapsed' : 'expanded'); } catch {}
            render();
        }
    });
    document.querySelectorAll('[data-navigation-open]').forEach(button => {
        button.hidden = false;
        button.addEventListener('click', show);
    });
    close.addEventListener('click', hide);
    backdrop.addEventListener('click', hide);
    if (resize) {
        let pointer = null;
        resize.addEventListener('pointerdown', event => {
            if (mobile.matches || event.button !== 0) return;
            pointer = event.pointerId; resize.setPointerCapture(pointer); body.dataset.navigationResizing = 'true'; event.preventDefault();
        });
        resize.addEventListener('pointermove', event => { if (event.pointerId === pointer) setWidth(event.clientX); });
        const finishResize = event => {
            if (event.pointerId !== pointer) return;
            pointer = null; delete body.dataset.navigationResizing; setWidth(navigationWidth, true);
        };
        resize.addEventListener('pointerup', finishResize); resize.addEventListener('pointercancel', finishResize);
        resize.addEventListener('dblclick', () => setWidth(264, true));
        resize.addEventListener('keydown', event => {
            const amount = event.shiftKey ? 40 : 10;
            if (event.key === 'ArrowLeft') setWidth(navigationWidth - amount, true);
            else if (event.key === 'ArrowRight') setWidth(navigationWidth + amount, true);
            else if (event.key === 'Home') setWidth(240, true);
            else if (event.key === 'End') setWidth(480, true);
            else return;
            event.preventDefault();
        });
    }
    panel.addEventListener('click', event => {
        if (mobile.matches && event.target instanceof Element && event.target.closest('a[href]')) hide();
    });
    document.addEventListener('keydown', event => {
        const editing = event.target instanceof Element && event.target.closest('input,textarea,select,[contenteditable=true]');
        if (event.key === '[' && !event.ctrlKey && !event.metaKey && !event.altKey && !editing) {
            event.preventDefault();
            if (mobile.matches) { if (opened) hide(); else show(); }
            else { collapsed = !collapsed; try { localStorage.setItem(key, collapsed ? 'collapsed' : 'expanded'); } catch {} render(); toggle.focus(); }
            return;
        }
        if (!mobile.matches || !opened) return;
        if (document.querySelector('dialog[open]')) return;
        if ((event.ctrlKey || event.metaKey) && event.key.toLowerCase() === 'k') {
            event.preventDefault();
            hide();
            document.querySelector('#global-search')?.focus();
            return;
        }
        if (event.key === 'Escape') { event.preventDefault(); hide(); }
        if (event.key === 'Tab') {
            const items = focusable(), first = items[0], last = items.at(-1);
            if (event.shiftKey && document.activeElement === first) { event.preventDefault(); last?.focus(); }
            else if (!event.shiftKey && document.activeElement === last) { event.preventDefault(); first?.focus(); }
        }
    });
    mobile.addEventListener('change', () => {
        const wasInside = panel.contains(document.activeElement);
        opened = false;
        render();
        if (wasInside && panel.inert) toggle.focus();
    });
    window.addEventListener('resize', () => { if (!mobile.matches) setWidth(navigationWidth); });
    render();
})();
