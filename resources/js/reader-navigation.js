const catalog = JSON.parse(document.querySelector('[data-ui-translations]')?.value || '{}');
const t = value => catalog[value] || value;

const share = document.querySelector('[data-reader-share]');
if (share) {
    const link = share.querySelector('[data-reader-page-link]');
    const field = share.querySelector('[data-reader-share-field]');
    const input = share.querySelector('[data-reader-share-url]');
    const copy = share.querySelector('[data-reader-copy]');
    const feedback = share.querySelector('[data-reader-copy-status]');
    input.value = link.href;
    field.hidden = false;
    copy.hidden = false;
    share.addEventListener('toggle', () => { if (share.open) feedback.textContent = ''; });
    input.addEventListener('focus', () => input.select());
    copy.addEventListener('click', async () => {
        let copied = false;
        if (navigator.clipboard?.writeText) {
            try { await navigator.clipboard.writeText(input.value); copied = true; } catch (_) { /* Try the selection fallback. */ }
        }
        if (!copied) {
            input.select();
            try { copied = document.execCommand('copy'); } catch (_) { copied = false; }
        }
        feedback.textContent = t(copied ? 'Link copied' : 'Select the link to copy it.');
        if (!copied) input.focus();
    });
}

const outline = document.querySelector('[data-reader-outline]');
if (outline) {
    if (window.matchMedia('(max-width: 760px)').matches) outline.open = false;
    const links = [...outline.querySelectorAll('a[href^="#"]')]
        .map(link => {
            try { return {link, target: document.getElementById(decodeURIComponent(link.hash.slice(1)))}; }
            catch (_) { return {link, target: null}; }
        })
        .filter(({target}) => target !== null);
    let scheduled = false;
    const update = () => {
        scheduled = false;
        if (!links.length) return;
        const threshold = Math.max(125, document.querySelector('.topbar')?.getBoundingClientRect().height + 30 || 125);
        let current = links[0].link;
        for (const entry of links) {
            if (entry.target.getBoundingClientRect().top > threshold) break;
            current = entry.link;
        }
        for (const {link} of links) {
            if (link === current) link.setAttribute('aria-current', 'location');
            else link.removeAttribute('aria-current');
        }
    };
    const schedule = () => { if (!scheduled) { scheduled = true; requestAnimationFrame(update); } };
    window.addEventListener('scroll', schedule, {passive: true});
    window.addEventListener('resize', schedule);
    window.addEventListener('hashchange', schedule);
    schedule();
}
