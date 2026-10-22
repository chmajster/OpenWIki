const {test, expect} = require('@playwright/test');
const fs = require('node:fs');
const fixture = () => JSON.parse(fs.readFileSync('storage/temp/editor-browser.json', 'utf8'));
const unique = name => name + ' ' + Date.now() + Math.random().toString(16).slice(2, 6);
const pathFor = page => '/spaces/' + fixture().spaceKey + '/pages/' + page.slug;
async function login(page, index = 0, locale = 'en') {
    await page.context().addCookies([{name: 'openwiki_locale', value: locale, url: 'http://127.0.0.1:8765'}]);
    await page.goto('/login'); await page.locator('[name=identifier]').fill(fixture().users[index].username);
    await page.locator('[name=password]').fill(fixture().users[index].password);
    await page.locator('form[action="/login"] [type=submit]').click(); await expect(page).toHaveURL('/');
}
async function createPage(request, title = unique('Access settings'), parent_id = null) {
    const response = await request.post('/api/v1/pages', {headers: {Authorization: 'Bearer ' + fixture().token}, data: {space_id: fixture().spaceId, title, parent_id, content_html: '<p>Access test document.</p>', status: 'published'}});
    expect(response.ok(), await response.text()).toBeTruthy(); return (await response.json()).data;
}
async function saveRule(page, principal, permission, effect, children = true) {
    const form = page.locator('[data-page-rule-form]');
    await form.locator('select[name=principal]').selectOption(principal);
    await form.locator('select[name=permission]').selectOption(permission);
    await form.locator('[name=effect]').selectOption(effect);
    await form.locator('[name=inherit_to_children]').setChecked(children);
    await form.locator('[type=submit]').click(); await expect(page.locator('.flash--success')).toBeVisible();
}
let errors;
test.beforeEach(({page}) => { errors = []; page.on('pageerror', error => errors.push(error.message)); });
test.afterEach(() => expect(errors).toEqual([]));

test('rules can be created, edited in place, cancelled and deleted with actual access changes', async ({page, request, browser}) => {
    const target = await createPage(request), path = pathFor(target);
    await login(page); await page.goto(path + '/permissions');
    await expect(page.locator('.permissions-empty')).toContainText('No direct rules yet');
    await page.getByRole('link', {name: 'Add rule', exact: true}).click();
    await expect(page).toHaveURL(path + '/permissions#page-rule-form');
    await expect(page.locator('[data-page-rule-form] [name=principal]')).toBeFocused();
    await expect(page.locator('[data-page-rule-form]')).toBeInViewport();
    await saveRule(page, 'user:' + fixture().users[2].id, 'page.edit', 'deny', false);
    const row = page.locator('[data-permission-rule]');
    await expect(row).toHaveCount(1); await expect(row).toContainText('Deny'); await expect(row).toContainText('This page only');
    const readerContext = await browser.newContext(), reader = await readerContext.newPage();
    try {
        await login(reader, 2); expect((await reader.goto(path + '/edit')).status()).toBe(403);
        await row.locator('[data-edit-page-rule]').click();
        const form = page.locator('[data-page-rule-form]');
        await expect(form.locator('select[name=principal]')).toBeDisabled(); await expect(form.locator('select[name=permission]')).toBeDisabled();
        await expect(form.locator('[name=effect]')).toBeFocused();
        await expect(form.locator('[name=inherit_to_children]')).not.toBeChecked();
        await page.locator('[data-cancel-page-rule]').click();
        await expect(form.locator('select[name=principal]')).toBeEnabled(); await expect(form.locator('select[name=permission]')).toBeEnabled();
        await expect(form.locator('[data-rule-identity]')).toHaveCount(0); await expect(row).not.toHaveClass(/is-editing/);
        await row.locator('[data-edit-page-rule]').click();
        await form.locator('[name=effect]').selectOption('allow'); await form.locator('[name=inherit_to_children]').check();
        await form.locator('[type=submit]').click();
        await expect(row).toHaveCount(1); await expect(row).toContainText('Allow'); await expect(row).toContainText('This page and child pages');
        expect((await reader.goto(path + '/edit')).status()).toBe(200);
        page.once('dialog', dialog => dialog.dismiss()); await row.locator('.permissions-delete').click(); await expect(row).toHaveCount(1);
        page.once('dialog', dialog => dialog.accept()); await row.locator('.permissions-delete').click();
        await expect(page.locator('[data-rule-count]')).toHaveText('0'); await expect(page.locator('.permissions-empty')).toBeVisible();
    } finally { await readerContext.close(); }
});

test('inheritance controls save and change access inherited from a parent', async ({page, request, browser}) => {
    const parent = await createPage(request), child = await createPage(request, unique('Child access'), parent.id);
    await login(page); await page.goto(pathFor(parent) + '/permissions');
    await saveRule(page, 'user:' + fixture().users[2].id, 'page.edit', 'deny');
    await page.goto(pathFor(child) + '/permissions');
    await expect(page.locator('[data-rule-count]')).toHaveText('0');
    const readerContext = await browser.newContext(), reader = await readerContext.newPage();
    try {
        await login(reader, 2); expect((await reader.goto(pathFor(child) + '/edit')).status()).toBe(403);
        await page.locator('[name=inherit_acl]').uncheck(); await page.getByRole('button', {name: 'Save inheritance', exact: true}).click();
        await expect(page.locator('.permissions-inheritance .permissions-badge')).toHaveText('Disabled');
        expect((await reader.goto(pathFor(child) + '/edit')).status()).toBe(200);
        await page.locator('[name=inherit_acl]').check(); await page.getByRole('button', {name: 'Save inheritance', exact: true}).click();
        await expect(page.locator('.permissions-inheritance .permissions-badge')).toHaveText('Enabled');
        expect((await reader.goto(pathFor(child) + '/edit')).status()).toBe(403);
        expect((await reader.request.get(pathFor(child) + '/permissions')).status()).toBe(403);
    } finally { await readerContext.close(); }
});

for (const width of [360, 768, 1440]) test('Polish permission settings fit ' + width + ' pixels in both themes', async ({page, request}) => {
    const target = await createPage(request, unique('Uprawnienia dokumentacji zespołu ' + 'DługiTytuł'.repeat(12)));
    await page.setViewportSize({width, height: 1000}); await login(page, 0, 'pl'); await page.goto(pathFor(target) + '/permissions');
    await saveRule(page, 'user:' + fixture().users[2].id, 'page.edit', 'deny', false);
    await saveRule(page, 'role:' + fixture().limitedRoleId, 'page.view', 'allow');
    await expect(page.getByRole('heading', {name: 'Uprawnienia strony', exact: true})).toBeVisible();
    await expect(page.locator('[data-permission-rule]').first()).toContainText('Edycja strony');
    await expect(page.locator('[data-permission-rule]').last()).toContainText('Rola');
    await expect(page.locator('[data-permission-rule]').last()).toContainText('Ta strona i strony podrzędne');
    for (const theme of ['light', 'dark']) {
        await page.locator('.theme-menu > summary').click(); await page.locator('.theme-menu [data-theme-select]').selectOption(theme); await page.keyboard.press('Escape');
        await expect(page.locator('.topbar #global-search')).toBeVisible();
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBeTruthy();
        const checkbox = await page.locator('[name=inherit_acl]').boundingBox(); expect(checkbox.width).toBeLessThanOrEqual(20);
        await page.screenshot({path: 'storage/temp/page-permissions-' + width + '-' + theme + '.png', fullPage: true});
    }
    await page.getByText('Jak działają uprawnienia strony?', {exact: true}).click();
    await expect(page.locator('.permissions-help')).toContainText('listę dostępu');
    await page.getByRole('link', {name: 'Wróć do strony', exact: true}).click(); await expect(page).toHaveURL(pathFor(target));
});

test('native mobile forms retain saving, upserting and removing rules without JavaScript', async ({browser, request}) => {
    const target = await createPage(request), path = pathFor(target);
    const context = await browser.newContext({javaScriptEnabled: false, viewport: {width: 390, height: 844}}), plain = await context.newPage();
    try {
        await login(plain); await plain.goto(path + '/permissions');
        await expect(plain.locator('.topbar #global-search')).toBeVisible();
        await saveRule(plain, 'user:' + fixture().users[2].id, 'page.edit', 'deny', false);
        await expect(plain.locator('[data-edit-page-rule]')).toBeHidden();
        await saveRule(plain, 'user:' + fixture().users[2].id, 'page.edit', 'allow');
        await expect(plain.locator('[data-permission-rule]')).toHaveCount(1);
        await expect(plain.locator('[data-permission-rule]')).toContainText('Allow');
        await plain.locator('[name=inherit_acl]').uncheck(); await plain.getByRole('button', {name: 'Save inheritance', exact: true}).click();
        await expect(plain.locator('[name=inherit_acl]')).not.toBeChecked();
        expect(await plain.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBeTruthy();
        await plain.locator('.permissions-delete').click(); await expect(plain.locator('.permissions-empty')).toBeVisible();
    } finally { await context.close(); }
});
