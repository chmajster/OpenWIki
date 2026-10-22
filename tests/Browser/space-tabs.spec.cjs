const {test, expect} = require('@playwright/test');
const fs = require('node:fs');
const fixture = () => JSON.parse(fs.readFileSync('storage/temp/editor-browser.json', 'utf8'));
const unique = name => name + ' ' + Date.now() + Math.random().toString(16).slice(2, 6);
async function api(request, path, data) {
    const response = await request.post('/api/v1/' + path, {headers: {Authorization: 'Bearer ' + fixture().token}, data});
    expect(response.ok(), await response.text()).toBeTruthy();
    const result = (await response.json()).data;
    if (path === 'spaces') createdSpaces.push(result.id);
    return result;
}
async function login(page) {
    await page.context().addCookies([{name: 'openwiki_locale', value: 'pl', url: 'http://127.0.0.1:8765'}]);
    await page.goto('/login'); await page.locator('[name=identifier]').fill(fixture().users[0].username); await page.locator('[name=password]').fill(fixture().users[0].password);
    await page.locator('form[action="/login"] [type=submit]').click(); await expect(page).toHaveURL('/');
}
const geometry = page => page.evaluate(() => ['.topbar', '[data-space-header]', '.workspace-tabs'].map(selector => {
    const {x, y, width, height} = document.querySelector(selector).getBoundingClientRect(); return {x, y, width, height};
}));
async function stableTabs(page, base) {
    await page.goto(base); await expect(page.locator('[data-space-header]')).toBeVisible();
    const before = await page.locator('[data-space-header]').innerHTML(), boxes = await geometry(page);
    for (const name of ['Treść', 'Szablony', 'Szukaj', 'Przegląd']) {
        await page.locator('.workspace-tabs').getByRole('link', {name, exact: true}).click();
        await expect(page.locator('.workspace-tabs [aria-current=page]')).toHaveText(name);
        await expect(page.locator('[data-space-header]')).toHaveCount(1);
        expect(await page.locator('[data-space-header]').innerHTML()).toBe(before);
        const after = await geometry(page);
        for (let i = 0; i < boxes.length; i++) for (const key of ['x', 'y', 'width', 'height']) expect(Math.abs(after[i][key] - boxes[i][key]), name + ' ' + i + ' ' + key).toBeLessThan(1);
        await expect(page.locator('[data-workspace-navigation] .navigation-space-item[aria-current="location"]')).toHaveAttribute('href', base);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1)).toBeTruthy();
    }
}
let errors, createdSpaces;
test.beforeEach(({page}) => { errors = []; createdSpaces = []; page.on('pageerror', error => errors.push(error.message)); });
test.afterEach(async ({request}) => {
    try { expect(errors).toEqual([]); }
    finally {
        for (const id of createdSpaces) {
            const response = await request.delete('/api/v1/spaces/' + id, {headers: {Authorization: 'Bearer ' + fixture().token}});
            expect(response.ok(), await response.text()).toBeTruthy();
        }
    }
});

for (const width of [360, 768, 1440]) test('space header stays identical while switching tabs at ' + width + ' pixels', async ({page, request}) => {
    const space = await api(request, 'spaces', {name: unique('Dokumentacja zespołu'), visibility: 'public', description: 'Stały opis przestrzeni.\nDrugi wiersz pozostaje nad zakładkami.', icon: '📘'});
    const base = '/spaces/' + space.key;
    await page.setViewportSize({width, height: 1000}); await login(page);
    for (const theme of ['light', 'dark']) {
        await page.goto(base); await page.locator('.theme-menu > summary').click(); await page.locator('.theme-menu [data-theme-select]').selectOption(theme); await page.keyboard.press('Escape');
        await stableTabs(page, base);
        await page.locator('.workspace-tabs').getByRole('link', {name: 'Szablony', exact: true}).click();
        await page.screenshot({path: 'storage/temp/space-tabs-' + width + '-' + theme + '.png'});
    }
    await page.locator('[data-space-header] .space-action-menu > summary').click();
    await page.locator('[data-space-header]').getByRole('link', {name: 'Ustawienia przestrzeni'}).click();
    await expect(page.locator('[data-space-settings]')).toBeVisible();
});

test('scoped search keeps its header through submitting, resetting and tab navigation', async ({page, request}) => {
    const space = await api(request, 'spaces', {name: unique('Scoped search team'), visibility: 'public'});
    const other = await api(request, 'spaces', {name: unique('Other search team'), visibility: 'public'});
    const title = unique('Scoped document');
    await api(request, 'pages', {space_id: space.id, title, content_html: '<p>Visible target.</p>', status: 'published'});
    await api(request, 'pages', {space_id: other.id, title, content_html: '<p>Other space.</p>', status: 'published'});
    await login(page); const base = '/spaces/' + space.key; await page.goto(base);
    const header = await page.locator('[data-space-header]').innerHTML();
    await page.locator('.workspace-tabs').getByRole('link', {name: 'Szukaj', exact: true}).click();
    const form = page.locator('main [data-search-form]'); await form.locator('[name=q]').fill(title); await form.locator('[name=q]').press('Escape'); await form.locator('[name=q]').press('Enter');
    await expect(page.locator('[data-search-total]')).toHaveText('1');
    expect(await page.locator('[data-space-header]').innerHTML()).toBe(header);
    await page.getByRole('link', {name: 'Wyczyść filtry', exact: true}).click();
    await expect(form.locator('[name=space]')).toHaveValue(space.key); await expect(page.locator('.workspace-tabs [aria-current=page]')).toHaveText('Szukaj');
    await page.locator('.workspace-tabs').getByRole('link', {name: 'Treść', exact: true}).click();
    await expect(page).toHaveURL(base + '?tab=content');
});

test('native mobile navigation retains the shared header without JavaScript', async ({browser, request}) => {
    const space = await api(request, 'spaces', {name: unique('Native space'), visibility: 'public', description: 'Stable native header.'});
    const context = await browser.newContext({javaScriptEnabled: false, viewport: {width: 390, height: 1000}}), plain = await context.newPage();
    try { await login(plain); await stableTabs(plain, '/spaces/' + space.key); }
    finally { await context.close(); }
});

test('public readers keep their space context while hidden or unknown spaces disclose no header', async ({browser, request}) => {
    const visible = await api(request, 'spaces', {name: unique('Public tab space'), visibility: 'public'});
    const hidden = await api(request, 'spaces', {name: unique('SECRET private tab space'), visibility: 'private', description: 'SECRET header description.'});
    const context = await browser.newContext(), guest = await context.newPage();
    try {
        for (const path of ['/spaces/' + visible.key, '/spaces/' + visible.key + '?tab=content', '/search?space=' + visible.key + '&type=page']) {
            await guest.goto(path); await expect(guest.locator('[data-space-header] h1')).toHaveText(visible.name);
            await expect(guest.locator('.workspace-tabs a')).toHaveCount(3);
            await expect(guest.locator('[data-space-header] .page-actions > a')).toHaveCount(0);
        }
        for (const key of [hidden.key, 'missing-space-key']) {
            await guest.goto('/search?space=' + key + '&type=page');
            await expect(guest.locator('[data-space-header]')).toHaveCount(0);
            await expect(guest.locator('body')).not.toContainText(hidden.name); await expect(guest.locator('body')).not.toContainText(hidden.description);
        }
    } finally { await context.close(); }
});
