const {test, expect} = require('@playwright/test');
const fs = require('node:fs');
const fixture = () => JSON.parse(fs.readFileSync('storage/temp/editor-browser.json', 'utf8'));
const editor = (page) => page.locator('[data-visual-editor]');
const createUrl = () => '/spaces/' + fixture().spaceKey + '/pages/create';
const title = () => 'Durable-' + Date.now() + '-' + Math.random().toString(16).slice(2, 8);
const api = async (request, method, path, data) => {
    const response = await request[method]('/api/v1/' + path, {headers: {Authorization: 'Bearer ' + fixture().token}, data});
    expect(response.ok(), await response.text()).toBeTruthy(); return (await response.json()).data;
};
const errors = [];
test.beforeEach(async ({page, context}) => {
    errors.length = 0;
    context.on('page', (tab) => tab.on('pageerror', (error) => errors.push(error.message)));
    page.on('pageerror', (error) => errors.push(error.message)); page.on('dialog', (dialog) => dialog.accept());
    await page.goto('/login');
    await page.locator('[name=identifier]').fill(fixture().users[0].username);
    await page.locator('[name=password]').fill(fixture().users[0].password);
    await page.locator('form[action="/login"] button[type=submit]').click(); await expect(page).toHaveURL('/');
});
test.afterEach(() => expect(errors).toEqual([]));

test('Ctrl+S saves an incomplete new draft without creating an article and recovers after reload', async ({page, request}) => {
    await page.goto(createUrl()); const url = page.url();
    await editor(page).fill('Niedokończona dokumentacja');
    await editor(page).press('ControlOrMeta+S');
    await expect(page.locator('[data-save-state]')).toHaveAttribute('data-state', 'server');
    await expect(page).toHaveURL(url);
    const key = 'new:' + new URL(url).searchParams.get('document');
    const drafts = await page.request.get('/spaces/' + fixture().spaceKey + '/editor-drafts?document_key=' + key);
    expect((await drafts.json()).data.drafts[0].title).toBe('');
    const before = await api(request, 'get', 'pages?space=' + fixture().spaceKey);
    await page.reload(); await expect(page.locator('[data-recovery-banner]')).toBeVisible();
    await page.locator('[data-restore-recovery]').click();
    await expect(editor(page)).toContainText('Niedokończona dokumentacja');
    expect((await api(request, 'get', 'pages?space=' + fixture().spaceKey)).length).toBe(before.length);
});

test('offline work persists in IndexedDB, reconnects, and does not modify the reader version', async ({page, context, request}) => {
    const document = await api(request, 'post', 'pages', {space_id: fixture().spaceId, title: title(), status: 'published', content_format: 'visual', content_html: '<p>Reader version</p>'});
    await page.goto('/spaces/' + fixture().spaceKey + '/pages/' + document.slug + '/edit');
    await context.setOffline(true); await editor(page).fill('Offline work survives');
    await expect(page.locator('[data-local-state]')).toHaveAttribute('data-protected', 'true');
    await editor(page).press('ControlOrMeta+S');
    await expect(page.locator('[data-save-state]')).toHaveAttribute('data-state', 'offline');
    await context.setOffline(false);
    await expect(page.locator('[data-save-state]')).toHaveAttribute('data-state', 'server');
    const reader = await api(request, 'get', 'pages/' + document.id);
    expect(reader.version).toBe(1); expect(reader.content_html).toBe('<p>Reader version</p>');
    await page.reload(); await page.locator('[data-restore-recovery]').click();
    await expect(editor(page)).toContainText('Offline work survives');
});

test('a lost autosave response is retried with the same operation before newer changes', async ({page}) => {
    await page.goto(createUrl()); let first = null; const requests = []; let lost = false;
    await page.route('**/editor-drafts', async (route) => {
        if (route.request().method() !== 'POST') { await route.continue(); return; }
        const body = route.request().postDataJSON(); requests.push(body);
        if (!lost) { first = body; lost = true; await route.fetch(); await route.abort('failed'); }
        else await route.continue();
    });
    await editor(page).fill('First durable state'); await editor(page).press('ControlOrMeta+S');
    await expect(page.locator('[data-save-state]')).toHaveAttribute('data-state', 'error');
    await editor(page).fill('Later durable state'); await editor(page).press('ControlOrMeta+S');
    await expect(page.locator('[data-save-state]')).toHaveAttribute('data-state', 'server');
    expect(requests[1].operation_id).toBe(first.operation_id); expect(requests[1].draft_sequence).toBe(first.draft_sequence);
    expect(requests.at(-1).draft_sequence).toBeGreaterThan(first.draft_sequence);
    const response = await page.request.get('/spaces/' + fixture().spaceKey + '/editor-drafts?document_key=' + first.document_key);
    const data = (await response.json()).data;
    expect(data.drafts[0].html).toContain('Later durable state'); expect(data.checkpoints.length).toBe(2);
});

test('recovery points restore content deleted before the empty state autosaved', async ({page}) => {
    await page.goto(createUrl()); await editor(page).fill('Recover before accidental deletion');
    await editor(page).press('ControlOrMeta+S'); await expect(page.locator('[data-save-state]')).toHaveAttribute('data-state', 'server');
    await editor(page).fill(''); await editor(page).press('ControlOrMeta+S'); await expect(page.locator('[data-save-state]')).toHaveAttribute('data-state', 'server');
    await page.reload();
    await page.locator('[data-recovery-select]').selectOption(await page.locator('[data-recovery-select] option').evaluateAll((options) => options.filter((option) => option.value.startsWith('checkpoint:')).at(-1).value));
    await page.locator('[data-restore-recovery]').click(); await expect(editor(page)).toContainText('Recover before accidental deletion');
});

test('private draft files are uploaded before first publication and become ACL-protected attachments', async ({page, context, request}) => {
    await page.goto(createUrl()); const name = title(); await page.locator('[name=title]').fill(name);
    await page.locator('[data-attachment-file]').setInputFiles({name: 'private-notes.txt', mimeType: 'text/plain', buffer: Buffer.from('Private draft content')});
    await expect(page.locator('[data-upload-list]')).toContainText('Zapisano jako prywatny');
    const link = editor(page).locator('a[data-draft-file-id]'); const href = await link.getAttribute('href');
    expect((await page.request.get(href)).status()).toBe(200);
    const anonymous = await context.browser().newContext({baseURL: 'http://127.0.0.1:8765'});
    expect((await anonymous.request.get(href)).status()).toBe(404);
    const other = await context.browser().newContext({baseURL: 'http://127.0.0.1:8765'}); const tab = await other.newPage();
    await tab.goto('/login'); await tab.locator('[name=identifier]').fill(fixture().users[1].username); await tab.locator('[name=password]').fill(fixture().users[1].password); await tab.locator('form[action="/login"] button[type=submit]').click();
    expect((await other.request.get(href)).status()).toBe(404);
    await page.locator('[data-save-button]').click(); await expect(page.locator('[data-document-content]')).toContainText('private-notes.txt');
    const download = page.locator('[data-document-content] a'); expect(await download.getAttribute('href')).toMatch(/^\/attachments\/\d+\/download$/);
    expect((await anonymous.request.get(await download.getAttribute('href'))).status()).toBe(404);
    await anonymous.close(); await other.close();
    const documents = await api(request, 'get', 'pages?space=' + fixture().spaceKey); expect(documents.some((row) => row.title === name)).toBeTruthy();
});
