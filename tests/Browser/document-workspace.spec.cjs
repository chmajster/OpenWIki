const {test, expect} = require('@playwright/test');
const fs = require('node:fs');
const {publish, openPublication, openMore, clickTool} = require('./workspace-helpers.cjs');
const fixture = () => JSON.parse(fs.readFileSync('storage/temp/editor-browser.json', 'utf8'));
const editor = page => page.locator('[data-visual-editor]');
const createUrl = () => '/spaces/' + fixture().spaceKey + '/pages/create';
const unique = name => name + '-' + Date.now() + '-' + Math.random().toString(16).slice(2);
async function api(request, method, path, data) {
    const response = await request[method]('/api/v1/' + path, {headers: {Authorization: 'Bearer ' + fixture().token}, data});
    expect(response.ok(), await response.text()).toBeTruthy(); return response.status() === 204 ? null : (await response.json()).data;
}
async function login(page, index = 0) {
    await page.goto('/login'); await page.locator('[name=identifier]').fill(fixture().users[index].username);
    await page.locator('[name=password]').fill(fixture().users[index].password);
    await page.locator('form[action="/login"] button[type=submit]').click(); await expect(page).toHaveURL('/');
}
let errors;
test.beforeEach(async ({page}) => { errors = []; page.on('pageerror', error => errors.push(error.message)); await login(page); });
test.afterEach(() => expect(errors).toEqual([]));
test.use({actionTimeout: 15000});
test.describe.configure({timeout: 90000});

test('a private new draft closes, reopens in its original context and publishes only after confirmation', async ({page, request}) => {
    await page.goto(createUrl()); const url = page.url(), title = unique('Recoverable workspace');
    await page.locator('[data-document-title]').fill(title); await page.locator('[data-document-title]').press('Enter');
    await expect(editor(page)).toBeFocused(); await page.keyboard.type('Private work that is not a page yet');
    await editor(page).press('ControlOrMeta+s'); await expect(page.locator('[data-save-summary]')).toHaveText('Zapisano');
    expect((await api(request, 'get', 'pages?space=' + fixture().spaceKey)).some(row => row.title === title)).toBeFalsy();
    await page.locator('[data-editor-cancel]').click(); await expect(page).toHaveURL('/spaces/' + fixture().spaceKey);
    await page.goto(url); await expect(page.locator('[data-recovery-banner]')).toBeVisible();
    await page.locator('[data-restore-recovery]').click(); await expect(page.locator('[data-document-title]')).toHaveValue(title);
    await expect(editor(page)).toContainText('Private work that is not a page yet');
    await openPublication(page); await expect(page.locator('[data-publication-title]')).toHaveText(title);
    await expect(page.locator('[data-publication-access]')).not.toBeEmpty();
    expect((await api(request, 'get', 'pages?space=' + fixture().spaceKey)).some(row => row.title === title)).toBeFalsy();
    await page.locator('[data-confirm-publication]').click(); await expect(page.locator('[data-document-content]')).toContainText('Private work that is not a page yet');
    const rows = await api(request, 'get', 'pages?space=' + fixture().spaceKey); expect(rows.filter(row => row.title === title)).toHaveLength(1);
    expect((await api(request, 'get', 'pages/' + rows.find(row => row.title === title).id)).version).toBe(1);
});

test('templates search, preview and reversible replacement use real document commands', async ({page}) => {
    await page.goto(createUrl()); await page.locator('[data-document-title]').fill(unique('Template workspace'));
    await page.locator('[data-template-search]').fill('API');
    const card = page.locator('.template-card').filter({has: page.getByText('Dokumentacja API', {exact: true})});
    await card.getByRole('button', {name: 'Podgląd', exact: true}).click();
    await expect(page.locator('[data-template-preview-content] table')).toHaveCount(1);
    await page.locator('[data-template-preview-apply]').click(); await expect(editor(page).locator('table')).toHaveCount(1);
    await expect(editor(page).locator('code')).toHaveAttribute('data-language', 'json');
    await editor(page).press('ControlOrMeta+End'); await editor(page).press('Enter'); await page.keyboard.type('Keep the template changes');
    await openMore(page); await page.locator('[data-toggle-templates]').click();
    const before = await editor(page).innerHTML();
    page.once('dialog', dialog => dialog.dismiss()); await page.locator('.template-blank').click();
    expect(await editor(page).innerHTML()).toBe(before);
    page.once('dialog', dialog => dialog.accept()); await page.locator('.template-blank').click(); await expect(editor(page)).toHaveText('');
    await clickTool(page, '[data-undo-conversion]'); await expect(editor(page)).toContainText('Keep the template changes');
    await page.locator('[data-document-preview]').click();
    await expect(page.locator('[data-document-preview-content] table')).toHaveCount(1);
    await expect(page.locator('[data-document-preview-content] code')).toHaveAttribute('data-language', 'json');
    await page.locator('[data-document-preview-dialog] [data-dialog-close]').click();
    await publish(page); await expect(page.locator('[data-document-content] table')).toHaveCount(1);
});

test('all eight starter templates open in Visual without a lossy transformation', async ({page}) => {
    await page.goto(createUrl()); await page.locator('[data-document-title]').fill(unique('Starter templates'));
    const names = ['Dokumentacja techniczna', 'Instrukcja krok po kroku', 'Notatka ze spotkania', 'Dokumentacja API', 'Procedura operacyjna', 'Baza wiedzy', 'Opis projektu', 'Raport incydentu'];
    page.on('dialog', dialog => dialog.accept());
    for (const name of names) {
        if (!await page.locator('[data-workspace-templates]').isVisible()) { await openMore(page); await page.locator('[data-toggle-templates]').click(); }
        await page.locator('.template-card').filter({has: page.getByText(name, {exact: true})}).getByRole('button', {name: 'Użyj', exact: true}).click();
        await expect(page.locator('[data-content-format]')).toHaveValue('visual');
        await expect(editor(page).locator('h2').first()).not.toBeEmpty();
        await expect(page.locator('[data-model-review]')).toBeHidden();
    }
    // Installed system templates keep their original structure, including older instances.
    const headings = await editor(page).locator('h1,h2,h3').allTextContents();
    const tables = await editor(page).locator('table').count();
    await publish(page);
    await expect(page.locator('[data-document-content]').locator('h1,h2,h3')).toHaveText(headings);
    await expect(page.locator('[data-document-content] table')).toHaveCount(tables);
    await page.goto(page.url() + '/edit');
    await expect(editor(page).locator('h1,h2,h3')).toHaveText(headings);
    await expect(page.locator('[data-model-review]')).toBeHidden();
});

test('slash and block plus insert, transform, duplicate and undo using keyboard and context tools', async ({page}) => {
    await page.goto(createUrl()); await page.locator('[data-document-title]').fill(unique('Blocks workspace'));
    await editor(page).fill('/tab'); await expect(page.locator('[data-command-menu]')).toBeVisible();
    await editor(page).press('Enter'); await expect(page.locator('[data-table-dialog]')).toBeVisible();
    await page.locator('[data-table-insert]').click(); await expect(editor(page).locator('table')).toHaveCount(1);
    await editor(page).press('ControlOrMeta+End'); await editor(page).press('ArrowDown'); await editor(page).press('Enter');
    await page.keyboard.type('Context paragraph'); await editor(page).locator('p').filter({hasText: 'Context paragraph'}).hover();
    await page.locator('[data-block-add]').click(); await page.locator('[data-command-search]').fill('kod');
    await page.locator('[data-command-search]').press('Enter'); await expect(page.locator('[data-code-dialog]')).toBeVisible();
    await page.locator('[data-code-language]').fill('php'); await page.locator('[data-code-insert]').click(); await page.keyboard.type('echo "safe";');
    await expect(editor(page).locator('code')).toContainText('echo "safe";');
    await editor(page).locator('p').filter({hasText: 'Context paragraph'}).hover();
    await clickTool(page, '[data-block-action=duplicate]'); await expect(editor(page).locator('p').filter({hasText: 'Context paragraph'})).toHaveCount(2);
    await editor(page).press('ControlOrMeta+z'); await expect(editor(page).locator('p').filter({hasText: 'Context paragraph'})).toHaveCount(1);
    await publish(page); await expect(page.locator('[data-document-content] code')).toHaveAttribute('data-language', 'php');
});

test('quick child creation uses the visible tree parent and persists the relationship', async ({page, request}) => {
    const parent = await api(request, 'post', 'pages', {space_id: fixture().spaceId, title: unique('Zespół'), content_html: '<p>Dokumentacja zespołu</p>', status: 'published'});
    await page.goto(createUrl());
    await page.getByRole('link', {name: 'Utwórz podstronę: ' + parent.title, exact: true}).click();
    const title = unique('Podstrona'); await page.locator('[data-document-title]').fill(title);
    await editor(page).fill('Wiedza powiązana z rodzicem');
    await openPublication(page);
    await expect(page.locator('[data-publication-parent]')).toHaveValue(String(parent.id));
    await expect(page.locator('[data-workspace-parent]')).toHaveText(parent.title);
    await page.locator('[data-confirm-publication]').click();
    await expect(page.locator('[data-document-content]')).toContainText('Wiedza powiązana z rodzicem');
    const children = await api(request, 'get', 'pages?space=' + fixture().spaceKey + '&q=' + encodeURIComponent(title));
    expect(children.some(child => Number(child.parent_id) === Number(parent.id) && child.title === title)).toBeTruthy();
});

test('block movement, transformation and deletion can be undone before publication', async ({page}) => {
    await page.goto(createUrl()); await page.locator('[data-document-title]').fill(unique('Block history'));
    await editor(page).fill('First block'); await editor(page).press('End'); await editor(page).press('Enter'); await page.keyboard.type('Second block');
    await editor(page).press('Alt+Shift+ArrowUp');
    await expect(editor(page).locator('p')).toHaveText(['Second block', 'First block']);
    await editor(page).locator('p').filter({hasText: 'Second block'}).hover();
    await clickTool(page, '[data-block-transform]');
    await expect(page.locator('[data-text-context]')).toBeHidden();
    await page.locator('[data-block-transform]').selectOption('h2');
    await expect(editor(page).locator('h2')).toHaveText('Second block');
    await editor(page).locator('h2').hover(); await clickTool(page, '[data-block-action=delete]');
    await expect(editor(page).locator('h2')).toHaveCount(0);
    await editor(page).press('ControlOrMeta+z'); await expect(editor(page).locator('h2')).toHaveText('Second block');
    await publish(page); await expect(page.locator('[data-document-content] h2')).toHaveText('Second block');
});

test('publication across spaces adopts a private cover image and respects destination permissions', async ({page, request, browser}) => {
    const target = await api(request, 'post', 'spaces', {name: unique('Workspace destination'), visibility: 'private'});
    await page.goto(createUrl()); await page.locator('[data-document-title]').fill(unique('Cross-space cover'));
    await page.getByRole('tab', {name: 'Markdown', exact: true}).click();
    await expect(page.locator('[data-content-format]')).toHaveValue('markdown');
    await expect(page.locator('[data-add-cover]')).toBeDisabled();
    await page.locator('[data-editor-mode=visual]').click();
    await expect(page.locator('[data-add-cover]')).toBeEnabled();
    const chooser = page.waitForEvent('filechooser'); await page.locator('[data-add-cover]').click();
    await (await chooser).setFiles({name: 'cover.png', mimeType: 'image/png', buffer: Buffer.from(fixture().imageBase64, 'base64')});
    await expect(editor(page).locator('figure.page-cover img[data-draft-file-id]')).toHaveCount(1);
    await openPublication(page); await page.locator('[data-publication-space]').selectOption(String(target.id));
    await expect(page.locator('[data-confirm-publication]')).toBeEnabled(); await page.locator('[data-confirm-publication]').click();
    await expect(page).toHaveURL(new RegExp('/spaces/' + target.key + '/pages/'));
    const image = page.locator('[data-document-content] figure.page-cover img'); await expect(image).toHaveAttribute('data-attachment-id', /[1-9][0-9]*/);
    expect((await page.request.get(await image.getAttribute('src'))).status()).toBe(200);
    expect((await request.get(await image.getAttribute('src'))).status()).toBe(404);
    const context = await browser.newContext(); const limited = await context.newPage();
    try {
        await login(limited, 2); await limited.goto(createUrl()); await limited.locator('[data-document-title]').fill(unique('Limited publication'));
        await openPublication(limited); await expect(limited.locator('[data-publication-space] option[value="' + target.id + '"]')).toHaveCount(0);
        const response = await limited.request.get('/spaces/' + fixture().spaceKey + '/editor-workspace?document_key=new:10000000-1000-4000-8000-100000000000&space_id=' + target.id);
        expect(response.status()).toBe(403); expect(await response.text()).not.toContain(target.name);
    } finally { await context.close(); }
});

for (const viewport of [{width:1920,height:1080},{width:1440,height:900},{width:390,height:844}]) test('workspace presentation and keyboard on ' + viewport.width + 'x' + viewport.height, async ({page}) => {
    await page.setViewportSize(viewport); await page.goto(createUrl());
    await expect(page.locator('[data-tree-status]')).not.toContainText('Ładowanie');
    if (viewport.width < 900) { await page.locator('[data-toggle-tree]').click(); await expect(page.locator('[data-workspace-tree]')).toBeVisible(); await page.keyboard.press('Escape'); await expect(page.locator('[data-workspace-tree]')).toBeHidden(); }
    await page.screenshot({path: 'storage/temp/workspace-' + viewport.width + 'x' + viewport.height + '-light.png'});
    await page.locator('[data-document-title]').fill('Dokumentacja zespołu'); await page.locator('[data-document-title]').press('Enter'); await page.keyboard.type('Wiedza, do której łatwo wrócić.');
    await expect(page.locator('[data-document-title]')).toHaveValue('Dokumentacja zespołu');
    await expect(editor(page)).toHaveText('Wiedza, do której łatwo wrócić.');
    await editor(page).press('ControlOrMeta+s'); await expect(page.locator('[data-save-summary]')).toHaveText('Zapisano');
    await openMore(page); await page.locator('select[data-workspace-theme]').selectOption('dark'); await page.keyboard.press('Escape');
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBeTruthy();
    await page.screenshot({path: 'storage/temp/workspace-' + viewport.width + 'x' + viewport.height + '-dark.png'});
    await openPublication(page); await expect(page.locator('[data-publication-dialog]')).toBeVisible();
    await page.locator('[name=slug]').fill('dokumentacja-zespolu-' + viewport.width);
    await page.screenshot({path: 'storage/temp/workspace-' + viewport.width + 'x' + viewport.height + '-publication.png'});
    await page.locator('[data-publication-dialog] [data-dialog-close]').click(); await publish(page);
    await expect(page.locator('[data-document-content]')).toContainText('Wiedza, do której łatwo wrócić.');
});
