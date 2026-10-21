const {test, expect} = require('@playwright/test');
const fs = require('node:fs');
const fixture = () => JSON.parse(fs.readFileSync('storage/temp/editor-browser.json', 'utf8'));
const unique = prefix => prefix + ' ' + Date.now() + Math.random().toString(16).slice(2, 6);
test.use({actionTimeout: 15000});
test.describe.configure({timeout: 90000});
async function api(request, method, path, data) {
    const response = await request[method]('/api/v1/' + path, {headers: {Authorization: 'Bearer ' + fixture().token}, data});
    expect(response.ok(), await response.text()).toBeTruthy();
    return (await response.json()).data;
}
async function login(page, user = 0) {
    await page.context().addCookies([{name: 'openwiki_locale', value: 'en', url: 'http://127.0.0.1:8765'}]);
    await page.goto('/login');
    await page.locator('[name=identifier]').fill(fixture().users[user].username);
    await page.locator('[name=password]').fill(fixture().users[user].password);
    await page.locator('form[action="/login"] [type=submit]').click();
    await expect(page).toHaveURL('/');
}
const sidebar = page => page.locator('[data-workspace-navigation]');
const toggle = page => page.locator('[data-navigation-toggle]');
const createSpace = (request, visibility = 'public') => api(request, 'post', 'spaces', {name: unique('A Team knowledge'), visibility, description: 'Decisions, project guides, and everything our team learns.'});
const createPage = (request, space) => api(request, 'post', 'pages', {space_id: space.id, title: unique('Getting started'), content_html: '<h2>Welcome to the team</h2><p>Keep decisions and useful context together.</p><h2>Next steps</h2><p>Write, share, and improve our documentation.</p>', status: 'published'});
const pathFor = (space, document) => '/spaces/' + space.key + '/pages/' + document.slug;
let errors;
test.beforeEach(({page}) => { errors = []; page.on('pageerror', error => errors.push(error.message)); });
test.afterEach(() => expect(errors).toEqual([]));

test('home views follow real visits and favorites, and the desktop sidebar survives reloads', async ({page, request}) => {
    const space = await createSpace(request), document = await createPage(request, space);
    await login(page);
    await page.screenshot({path: 'storage/temp/confluence-home-desktop.png', fullPage: true});
    await sidebar(page).getByRole('link', {name: 'Spaces', exact: true}).click();
    await expect(page.locator('[data-home-view]')).toHaveAttribute('data-home-view', 'spaces');
    await page.locator('.home-space-card').filter({hasText: space.name}).click();
    await expect(sidebar(page).locator('[data-documentation-tree]')).toHaveCount(1);
    await sidebar(page).locator(`[data-page-id="${document.id}"] .page-tree__link`).click();
    await expect(page.locator('h1')).toHaveText(document.title);
    await expect(page.locator('.workspace-navigation')).toHaveCount(1);
    await page.getByRole('button', {name: 'Favorite', exact: true}).click();
    await expect(page.getByRole('button', {name: 'Unfavorite', exact: true})).toHaveAttribute('aria-pressed', 'true');
    await page.screenshot({path: 'storage/temp/confluence-reader-desktop.png', fullPage: true});
    await sidebar(page).getByRole('link', {name: 'Favorites', exact: true}).click();
    await page.locator('.home-document-list').getByRole('link', {name: new RegExp(document.title)}).click();
    await sidebar(page).getByRole('link', {name: 'Recently viewed', exact: true}).click();
    await expect(page.locator('.home-document-list')).toContainText(document.title);
    await toggle(page).click(); await expect(sidebar(page)).toBeHidden();
    await page.reload(); await expect(sidebar(page)).toBeHidden();
    await page.goto('/search?q=team'); await expect(sidebar(page)).toBeHidden();
    await toggle(page).click(); await expect(sidebar(page)).toBeVisible();
    await sidebar(page).getByRole('link', {name: 'Your work', exact: true}).click();
    await expect(page.locator('[data-work-center]')).toBeVisible();
    await page.locator('[name=type]').selectOption('task');
    await page.getByRole('button', {name: 'Apply filters', exact: true}).click();
    await expect(page).toHaveURL(/view=work.*type=task/);
    await expect(sidebar(page).getByRole('link', {name: 'Your work', exact: true})).toHaveAttribute('aria-current', 'page');
    await page.goto('/?attention=1&space=' + space.id); await page.getByRole('link', {name: 'Reset', exact: true}).click();
    await expect(page).toHaveURL('/?view=work&attention=1&space=' + space.id); await expect(page.locator('[data-work-center]')).toBeVisible();
    const invalid = await page.goto('/?attention=1'); expect(invalid.status()).toBe(422);
    await expect(page.getByRole('alert')).toContainText('Wybierz przestrzeń');
    await page.getByRole('link', {name: 'Reset filters', exact: true}).click(); await expect(page.locator('[data-work-center]')).toBeVisible();
});

for (const width of [390, 768]) test('navigation traps focus, closes safely, and fits ' + width + ' pixels', async ({page, request}) => {
    const space = await createSpace(request), document = await createPage(request, space);
    await page.setViewportSize({width, height: 900}); await login(page);
    await page.goto(pathFor(space, document));
    await expect(sidebar(page)).toBeHidden(); await expect(toggle(page)).toHaveAttribute('aria-expanded', 'false');
    await toggle(page).focus(); await page.keyboard.press('Enter');
    await expect(sidebar(page)).toHaveAttribute('role', 'dialog');
    expect(await page.locator('[data-workspace-body]').evaluate(node => node.inert)).toBeTruthy();
    await expect(page.locator('[data-navigation-close]')).toBeFocused();
    await page.keyboard.press('Shift+Tab'); expect(await sidebar(page).evaluate(node => node.contains(document.activeElement))).toBeTruthy();
    await page.keyboard.press('Tab'); await expect(page.locator('[data-navigation-close]')).toBeFocused();
    await page.screenshot({path: `storage/temp/confluence-navigation-${width}.png`});
    await page.keyboard.press('Escape'); await expect(sidebar(page)).toBeHidden(); await expect(toggle(page)).toBeFocused();
    expect(await page.locator('[data-workspace-body]').evaluate(node => node.inert)).toBeFalsy();
    await toggle(page).click(); await page.keyboard.press('ControlOrMeta+k'); await expect(page.locator('#global-search')).toBeFocused(); await expect(sidebar(page)).toBeHidden();
    await toggle(page).click(); await page.locator('[data-navigation-backdrop]').click({position: {x: width - 10, y: 200}}); await expect(sidebar(page)).toBeHidden();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();
    await page.screenshot({path: `storage/temp/confluence-reader-${width}.png`, fullPage: true});
    await page.setViewportSize({width: 1440, height: 900}); await expect(sidebar(page)).toBeVisible();
    await page.setViewportSize({width, height: 900}); await expect(sidebar(page)).toBeHidden();
});

test('navigation and home never disclose spaces or documents denied to a reader', async ({page, request, browser}) => {
    const privateSpace = await createSpace(request, 'private'), restricted = await createSpace(request, 'restricted');
    const allowed = await api(request, 'post', 'pages', {space_id: fixture().spaceId, title: unique('Allowed navigation'), content_html: '<p>Visible</p>', status: 'published'});
    const hidden = await api(request, 'post', 'pages', {space_id: fixture().spaceId, title: unique('Confidential navigation'), content_html: '<p>Private content</p>', status: 'published'});
    await login(page); await page.goto('/spaces/' + fixture().spaceKey + '/pages/' + hidden.slug + '/permissions');
    await page.locator('[name=principal]').selectOption('user:' + fixture().users[3].id);
    await page.locator('[name=permission]').selectOption('page.view'); await page.locator('[name=effect]').selectOption('deny');
    await page.getByRole('button', {name: 'Save ACL rule', exact: true}).click();
    const context = await browser.newContext(), reader = await context.newPage();
    try {
        await login(reader, 3);
        for (const view of ['home', 'spaces', 'recent', 'favorites']) {
            await reader.goto('/?view=' + view);
            await expect(reader.locator('body')).not.toContainText(privateSpace.name);
            await expect(reader.locator('body')).not.toContainText(restricted.name);
            await expect(reader.locator('body')).not.toContainText(hidden.title);
        }
        await reader.goto('/spaces/' + fixture().spaceKey + '/pages/' + allowed.slug);
        await expect(sidebar(reader).locator(`[data-page-id="${allowed.id}"]`)).toBeVisible();
        await expect(sidebar(reader)).not.toContainText(hidden.title);
        await expect(reader.locator('.shell-create')).toHaveCount(0);
        const denied = await reader.goto('/spaces/' + privateSpace.key); expect(denied.status()).toBe(403);
        await expect(reader.locator('body')).not.toContainText(privateSpace.name);
    } finally { await context.close(); }
});

test('opening a nested document reveals its ancestors and history keeps tree actions functional', async ({page, request}) => {
    const space = await createSpace(request), parent = await createPage(request, space);
    const child = await api(request, 'post', 'pages', {space_id: space.id, parent_id: parent.id, title: unique('Working together'), content_html: '<p>Nested documentation</p>', status: 'published'});
    await login(page); await page.goto(pathFor(space, child));
    await expect(sidebar(page).locator(`[data-page-id="${parent.id}"]`)).toHaveAttribute('aria-expanded', 'true');
    await expect(sidebar(page).locator(`[data-page-id="${child.id}"] .page-tree__link`)).toHaveAttribute('aria-current', 'page');
    await page.goto(pathFor(space, child) + '/history');
    await expect(sidebar(page).locator(`[data-page-id="${parent.id}"] > .tree-row .tree-operation`)).toBeVisible();
    await sidebar(page).locator(`[data-page-id="${parent.id}"]`).focus(); await page.keyboard.press('F2');
    const dialog = page.locator('[data-structure-dialog]');
    await expect(dialog).toBeVisible(); await expect(dialog.locator('[data-structure-source]')).toContainText(parent.title);
    await dialog.locator('[data-structure-cancel]').click(); await expect(dialog).toBeHidden();
});

test('navigation tolerates denied storage and supports search without JavaScript', async ({page, request, browser}) => {
    const space = await createSpace(request), document = await createPage(request, space);
    await page.addInitScript(() => { Object.defineProperty(window, 'localStorage', {get() { throw new DOMException('Denied', 'SecurityError'); }}); });
    await login(page); await toggle(page).click(); await expect(sidebar(page)).toBeHidden(); await toggle(page).click(); await expect(sidebar(page)).toBeVisible();
    const plain = await browser.newContext({javaScriptEnabled: false, viewport: {width: 390, height: 844}}), guest = await plain.newPage();
    try {
        await guest.goto('http://127.0.0.1:8765/spaces/' + space.key);
        await expect(guest.getByRole('link', {name: 'Wyszukaj dokument', exact: true})).toBeVisible();
        await guest.getByRole('link', {name: 'Wyszukaj dokument', exact: true}).click();
        await guest.locator('main [name=q]').fill(document.title);
        await guest.locator('main [name=q]').press('Enter');
        await expect(guest.locator('main')).toContainText(document.title);
        expect(await guest.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();
    } finally { await plain.close(); }
    const invalid = await page.goto('/?view[]=home'); expect(invalid.status()).toBe(404);
});

test('a failed tree request shows a useful error and recovers on refresh in both themes', async ({page, request}) => {
    const space = await createSpace(request), document = await createPage(request, space);
    await login(page);
    await page.route('**/spaces/' + space.key + '/tree*', route => route.fulfill({status: 503, contentType: 'application/json', body: JSON.stringify({error: {message: 'Documentation is temporarily unavailable.'}})}));
    await page.goto('/spaces/' + space.key); await expect(sidebar(page).locator('[data-tree-status]')).toContainText('Documentation is temporarily unavailable.');
    await page.unroute('**/spaces/' + space.key + '/tree*'); await page.reload();
    await expect(sidebar(page).locator(`[data-page-id="${document.id}"]`)).toBeVisible();
    for (const theme of ['light', 'dark']) {
        await page.locator('.theme-menu > summary').click(); await page.locator('[data-theme-select]').selectOption(theme); await page.keyboard.press('Escape');
        await page.goto(pathFor(space, document)); await expect(page.locator('html')).toHaveAttribute('data-theme', theme);
        await expect(page.locator('[data-document-content]')).toContainText('Keep decisions');
        await page.screenshot({path: `storage/temp/confluence-reader-${theme}.png`, fullPage: true});
    }
});
