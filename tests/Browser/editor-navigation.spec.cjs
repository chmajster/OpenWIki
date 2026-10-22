const {test, expect} = require('@playwright/test');
const fs = require('node:fs');
const fixture = () => JSON.parse(fs.readFileSync('storage/temp/editor-browser.json', 'utf8'));
const createUrl = () => '/spaces/' + fixture().spaceKey + '/pages/create';
const unique = name => name + ' ' + Date.now() + Math.random().toString(16).slice(2, 6);
test.describe.configure({timeout: 90000});
test.use({actionTimeout: 15000});
async function login(page) {
    await page.context().addCookies([{name: 'openwiki_locale', value: 'en', url: 'http://127.0.0.1:8765'}]);
    await page.goto('/login'); await page.locator('[name=identifier]').fill(fixture().users[0].username); await page.locator('[name=password]').fill(fixture().users[0].password);
    await page.locator('form[action="/login"] [type=submit]').click(); await expect(page).toHaveURL('/');
}
async function createPage(request, title, content_html) {
    const response = await request.post('/api/v1/pages', {headers: {Authorization: 'Bearer ' + fixture().token}, data: {space_id: fixture().spaceId, title, content_html, status: 'published'}});
    expect(response.ok(), await response.text()).toBeTruthy(); return (await response.json()).data;
}
const editor = page => page.locator('[data-visual-editor]');
const bars = page => page.evaluate(() => {
    const rect = selector => { const box = document.querySelector(selector).getBoundingClientRect(); return {top: box.top, bottom: box.bottom}; };
    return {global: rect('.topbar'), editor: rect('.workspace-header'), formatting: rect('.editor-toolbar')};
});
let errors;
test.beforeEach(({page}) => { errors = []; page.on('pageerror', error => errors.push(error.message)); });
test.afterEach(() => expect(errors).toEqual([]));

for (const width of [360, 768, 1440]) test('shared editor navbar and sticky controls fit ' + width + ' pixels', async ({page}) => {
    await page.setViewportSize({width, height: 900}); await login(page); await page.goto(createUrl());
    await expect(page.locator('.topbar')).toBeVisible();
    await expect(page.locator('.topbar #global-search')).toBeVisible();
    await expect(page.locator('#global-search')).toHaveCount(1);
    await expect(page.locator('.workspace-search-dialog')).toHaveCount(0);
    await expect(page.locator('.topbar .brand')).toHaveAttribute('href', '/');
    await expect(page.locator('.topbar .notification-link')).toHaveAttribute('href', '/notifications');
    await expect(page.locator('.topbar .shell-create')).toHaveAttribute('href', createUrl());
    if (width === 1440) {
        const treeResize = page.locator('[data-tree-resize]');
        await expect(treeResize).toBeVisible();
        const initialTreeWidth = Number(await treeResize.getAttribute('aria-valuenow'));
        await treeResize.focus(); await page.keyboard.press('ArrowRight');
        await expect(treeResize).toHaveAttribute('aria-valuenow', String(initialTreeWidth + 10));
        await page.reload();
        await expect(page.locator('[data-tree-resize]')).toHaveAttribute('aria-valuenow', String(initialTreeWidth + 10));
        await page.locator('.workspace-header').click(); await page.keyboard.press('[');
        await expect(page.locator('[data-workspace-tree]')).toBeHidden();
        await page.keyboard.press('['); await expect(page.locator('[data-workspace-tree]')).toBeVisible();
    }
    await page.locator('[data-document-title]').fill(unique('Shared navigation'));
    await editor(page).fill('Documentation stays below its editing controls. '.repeat(500));
    for (const theme of ['light', 'dark']) {
        await page.locator('.theme-menu > summary').click(); await page.locator('.theme-menu [data-theme-select]').selectOption(theme); await page.keyboard.press('Escape');
        await page.evaluate(() => window.scrollTo(0, 650));
        await expect.poll(async () => Math.round((await bars(page)).global.top)).toBe(0);
        const boxes = await bars(page);
        expect(Math.abs(boxes.editor.top - boxes.global.bottom)).toBeLessThan(2);
        expect(Math.abs(boxes.formatting.top - boxes.editor.bottom)).toBeLessThan(2);
        await page.locator('.account-menu > summary').click();
        const work = page.locator('.account-menu').getByRole('link', {name: 'Work center', exact: true});
        await expect(work).toBeVisible();
        expect(await work.evaluate(link => { const box = link.getBoundingClientRect(); return link.contains(document.elementFromPoint(box.x + box.width / 2, box.y + box.height / 2)); })).toBeTruthy();
        await page.keyboard.press('Escape');
        if (width < 900) {
            await page.locator('[data-toggle-tree]').click();
            const tree = await page.locator('[data-workspace-tree]').boundingBox();
            expect(tree.y).toBeGreaterThanOrEqual(boxes.editor.bottom - 1);
            expect(tree.y + tree.height).toBeLessThanOrEqual(901);
            await page.keyboard.press('Escape');
        }
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBeTruthy();
        await page.screenshot({path: `storage/temp/workspace-navbar-${width}-${theme}.png`});
    }
    if (width === 1440) {
        await page.setViewportSize({width: 390, height: 844});
        await expect.poll(async () => { const box = await bars(page); return Math.abs(box.editor.top - box.global.bottom); }).toBeLessThan(2);
    }
});

test('shared search shortcuts and navbar navigation preserve a saved private draft', async ({page, request}) => {
    const target = await createPage(request, unique('Navbar search target'), '<p>A visible search result</p>');
    await login(page); await page.goto(createUrl()); const draftUrl = page.url(), title = unique('Navbar draft');
    await page.locator('[data-document-title]').fill(title); await editor(page).fill('Keep this private draft when using navigation.');
    await editor(page).press('ControlOrMeta+k'); await expect(page.locator('[data-link-dialog]')).toBeVisible();
    await page.locator('[data-link-dialog] [data-dialog-close]').click();
    await page.locator('[data-document-title]').press('ControlOrMeta+k'); await expect(page.locator('.topbar #global-search')).toBeFocused();
    await editor(page).press('ControlOrMeta+s'); await expect(page.locator('[data-save-summary]')).toHaveText('Zapisano');
    await page.locator('.topbar .brand').click(); await expect(page).toHaveURL('/');
    await page.goto(draftUrl); await expect(page.locator('[data-recovery-banner]')).toBeVisible(); await page.locator('[data-restore-recovery]').click();
    await expect(page.locator('[data-document-title]')).toHaveValue(title); await expect(editor(page)).toHaveText('Keep this private draft when using navigation.');
    await editor(page).press('ControlOrMeta+s'); await expect(page.locator('[data-save-summary]')).toHaveText('Zapisano');
    await page.locator('.topbar #global-search').fill(target.title);
    await page.locator('#global-search-suggestions').getByRole('option').first().getByRole('link').click();
    await expect(page.locator('[data-document-content]')).toHaveText('A visible search result');
});

test('the shared navbar works in an existing page editor without JavaScript', async ({page, browser, request}) => {
    const target = await createPage(request, unique('Native navbar'), '<p>Original document source.</p>'.repeat(60));
    const context = await browser.newContext({javaScriptEnabled: false, viewport: {width: 390, height: 844}}), plain = await context.newPage();
    try {
        await login(plain); await plain.goto('/spaces/' + fixture().spaceKey + '/pages/' + target.slug + '/edit');
        await expect(plain.locator('.topbar #global-search')).toBeVisible();
        await expect(plain.locator('.topbar .brand')).toBeVisible();
        await plain.locator('.account-menu > summary').click(); await expect(plain.locator('.account-menu').getByRole('link', {name: 'Work center'})).toBeVisible(); await plain.locator('.account-menu > summary').click();
        await plain.evaluate(() => window.scrollTo(0, 500));
        const boxes = await bars(plain); expect(Math.abs(boxes.editor.top - boxes.global.bottom)).toBeLessThan(2);
        expect(await plain.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBeTruthy();
        await plain.locator('.topbar #global-search').fill(target.title); await plain.locator('.topbar #global-search').press('Enter');
        await expect(plain.locator('main')).toContainText(target.title);
    } finally { await context.close(); }
});
