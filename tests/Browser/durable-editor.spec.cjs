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
    await page.evaluate(() => scrollTo(0, 0));
    await page.screenshot({path: 'storage/temp/editor-offline.png', fullPage: true});
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
    await expect(page.locator('[data-recovery-select] option[value^="checkpoint:"]')).not.toHaveCount(0);
    await page.locator('[data-recovery-select]').selectOption(await page.locator('[data-recovery-select] option').evaluateAll((options) => options.filter((option) => option.value.startsWith('checkpoint:')).at(-1).value));
    await page.locator('[data-restore-recovery]').click(); await expect(editor(page)).toContainText('Recover before accidental deletion');
});

test('private draft files are uploaded before first publication and become ACL-protected attachments', async ({page, context, request}) => {
    await page.goto(createUrl()); const name = title(); await page.locator('[name=title]').fill(name);
    let failed = false;
    await page.route('**/editor-files', async route => {
        if (!failed) { failed = true; await route.fulfill({status: 500, contentType: 'application/json', body: JSON.stringify({error: {message: 'Upload interrupted; retry the retained file.'}})}); }
        else await route.continue();
    });
    await page.locator('[data-attachment-file]').setInputFiles({name: 'private-notes.txt', mimeType: 'text/plain', buffer: Buffer.from('Private draft content')});
    await expect(page.locator('[data-upload-list]')).toContainText('Upload interrupted');
    await page.locator('[data-upload-list]').getByRole('button', {name: 'Ponów upload'}).click();
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

test('continuous typing saves within ten seconds and acknowledgement preserves selection and later edits', async ({page}) => {
    await page.goto(createUrl()); const requests = [];
    page.on('request', request => { if (request.method() === 'POST' && request.url().endsWith('/editor-drafts')) requests.push({at: Date.now(), data: request.postDataJSON()}); });
    await editor(page).focus(); const started = Date.now();
    while (Date.now() - started < 11000) { await page.keyboard.type('typing '); await page.waitForTimeout(150); }
    expect(requests.length).toBeGreaterThan(0); expect(requests[0].at - started).toBeLessThan(11000);
    const selection = await page.evaluate(() => ({anchor: getSelection().anchorOffset, focus: getSelection().focusOffset, scroll: scrollY}));
    await page.keyboard.press('ControlOrMeta+s'); await expect(page.locator('[data-save-state]')).toHaveAttribute('data-state', 'server');
    expect(await page.evaluate(() => ({anchor: getSelection().anchorOffset, focus: getSelection().focusOffset, scroll: scrollY}))).toEqual(selection);
    const key = 'new:' + new URL(page.url()).searchParams.get('document');
    const response = await page.request.get('/spaces/' + fixture().spaceKey + '/editor-drafts?document_key=' + key);
    expect((await response.json()).data.drafts[0].html).toContain((await editor(page).innerText()).trim());
});

test('an ambiguous publication is reconciled before explicitly saving the latest visible document', async ({page, request}) => {
    await page.goto(createUrl()); const name = title(); await page.locator('[name=title]').fill(name); await editor(page).fill('First state');
    let ambiguous = false; const operations = [];
    await page.route('**/spaces/*/pages', async route => {
        if (route.request().method() !== 'POST') return route.continue();
        operations.push(route.request().postDataJSON().operation_id);
        if (!ambiguous) { ambiguous = true; await route.fetch(); await route.abort('failed'); } else await route.continue();
    });
    await page.locator('[data-save-button]').click(); await expect(page.locator('[data-save-state]')).toHaveAttribute('data-state', 'error');
    await editor(page).fill('Latest visible state'); await page.locator('[data-save-button]').click();
    await expect(page.locator('[data-document-content]')).toHaveText('Latest visible state');
    expect(operations[0]).toBe(operations[1]);
    const documents = await api(request, 'get', 'pages?space=' + fixture().spaceKey); const doc = documents.find(row => row.title === name);
    const stored = await api(request, 'get', 'pages/' + doc.id); expect(stored.version).toBe(2); expect(stored.content_html).toContain('Latest visible state');
});

for (const status of [401, 403, 422, 500]) test('draft HTTP ' + status + ' preserves local work and permits recovery after a server check', async ({page}) => {
    await page.goto(createUrl()); let blocked = true;
    await page.route('**/editor-drafts', async route => {
        if (route.request().method() === 'POST' && blocked) await route.fulfill({status, contentType: 'application/json', body: JSON.stringify({error: {message: 'Draft failure ' + status}})});
        else await route.continue();
    });
    await editor(page).fill('Protected document ' + status); await page.keyboard.press('ControlOrMeta+s');
    await expect(page.locator('[data-save-state]')).toHaveAttribute('data-state', 'error'); await expect(page.locator('[data-local-state]')).toHaveAttribute('data-protected', 'true');
    await expect(editor(page)).toHaveText('Protected document ' + status);
    if (status === 403) { await page.evaluate(() => scrollTo(0, 0)); await page.screenshot({path: 'storage/temp/editor-permission-error.png', fullPage: true}); }
    blocked = false;
    if ([401, 403].includes(status)) await page.locator('[data-resume-save]').click(); else await page.keyboard.press('ControlOrMeta+s');
    await expect(page.locator('[data-save-state]')).toHaveAttribute('data-state', 'server');
    await page.reload(); await page.locator('[data-restore-recovery]').click(); await expect(editor(page)).toHaveText('Protected document ' + status);
});

test('unavailable IndexedDB and full fallback report absence of protection without blocking typing', async ({page}) => {
    await page.addInitScript(() => {
        indexedDB.open = () => { throw new DOMException('Storage unavailable', 'QuotaExceededError'); };
        const original = Storage.prototype.setItem;
        Storage.prototype.setItem = function(key, value) { if (key.startsWith('openwiki:draft:')) throw new DOMException('Full', 'QuotaExceededError'); return original.call(this, key, value); };
    });
    await page.goto(createUrl()); await editor(page).fill('Document still editable');
    await expect(page.locator('[data-local-state]')).toHaveAttribute('data-protected', 'false');
    await page.keyboard.type(' despite storage failure'); await page.keyboard.press('ControlOrMeta+s'); await expect(page.locator('[data-save-state]')).toHaveAttribute('data-state', 'server');
    await page.reload(); await page.locator('[data-restore-recovery]').click(); await expect(editor(page)).toContainText('despite storage failure');
});

test('two actual users resolve a second intervening version and retain the separate local draft', async ({page, context, request}) => {
    const doc = await api(request, 'post', 'pages', {space_id: fixture().spaceId, title: title(), content_html: '<p>Original base</p>'});
    const path = '/spaces/' + fixture().spaceKey + '/pages/' + doc.slug;
    await page.goto(path + '/edit'); await editor(page).fill('Local work');
    const secondContext = await context.browser().newContext({baseURL: 'http://127.0.0.1:8765'}); const second = await secondContext.newPage(); second.on('dialog', dialog => dialog.accept());
    await second.goto('/login'); await second.locator('[name=identifier]').fill(fixture().users[1].username); await second.locator('[name=password]').fill(fixture().users[1].password); await second.locator('form[action="/login"] button[type=submit]').click();
    await second.goto(path + '/edit'); await editor(second).fill('Second user version'); await second.locator('[data-save-button]').click(); await expect(second.locator('[data-document-content]')).toHaveText('Second user version');
    await page.locator('[data-save-button]').click(); await expect(page.locator('[data-conflict-panel]')).toBeVisible(); await expect(page.locator('[name=base_version]')).toHaveValue('1');
    await page.locator('[data-conflict-fork]').click(); await expect(page.locator('[data-save-state]')).toHaveAttribute('data-state', 'server'); await expect(page.locator('[name=base_version]')).toHaveValue('1');
    await page.locator('[data-resolve-conflict]').click(); await expect(page.locator('[name=base_version]')).toHaveValue('2'); await editor(page).fill('Prepared merge');
    await second.goto(path + '/edit'); await editor(second).fill('Third intervening version'); await second.locator('[data-save-button]').click(); await expect(second.locator('[data-document-content]')).toHaveText('Third intervening version');
    await page.locator('[data-save-button]').click(); await expect(page.locator('[data-conflict-server]')).toContainText('Third intervening version'); await expect(page.locator('[name=base_version]')).toHaveValue('2');
    await page.locator('[data-use-server-version]').click(); await expect(editor(page)).toHaveText('Third intervening version'); await expect(page.locator('[name=base_version]')).toHaveValue('3');
    await page.locator('[data-compare-recovery]').click(); await expect(page.locator('[data-recovery-copy]')).toContainText(/Local work|Prepared merge/);
    await page.screenshot({path: 'storage/temp/editor-recovery-comparison.png', fullPage: true}); await page.locator('[data-recovery-dialog] [data-dialog-close]').click();
    await page.locator('[data-save-button]').click(); await expect(page.locator('[data-document-content]')).toHaveText('Third intervening version'); await secondContext.close();
});

test('publishing while typing replaces private file URLs before saving the next draft', async ({page, request}) => {
    await page.goto(createUrl()); await page.locator('[name=title]').fill(title());
    const target = await api(request, 'post', 'pages', {space_id: fixture().spaceId, title: title(), content_html: '<p>Wiki target</p>'});
    await editor(page).fill('[[' + target.title + ']]'); await editor(page).press('Enter');
    await page.locator('[data-attachment-file]').setInputFiles({name: 'pending.txt', mimeType: 'text/plain', buffer: Buffer.from('File bytes')}); await expect(page.locator('[data-upload-list]')).toContainText('Zapisano jako prywatny');
    const originalHref = await editor(page).locator('a').getAttribute('href');
    let release; const blocked = new Promise(resolve => release = resolve); let entered;
    const started = new Promise(resolve => entered = resolve);
    await page.route('**/spaces/*/pages', async route => { if (route.request().method() === 'POST') { const response = await route.fetch(); entered(); await blocked; await route.fulfill({response}); } else await route.continue(); });
    await page.locator('[data-save-button]').click(); await started; await expect(page.locator('[data-attachment-picker]')).toBeDisabled(); await editor(page).press('ControlOrMeta+End'); await editor(page).press('Enter'); await page.keyboard.type('Later state'); release();
    await expect(page).toHaveURL(/\/edit$/); await expect(editor(page).locator('a')).toHaveAttribute('href', /^\/attachments\//); await page.unroute('**/spaces/*/pages');
    await page.keyboard.press('ControlOrMeta+s'); await expect(page.locator('[data-save-state]')).toHaveAttribute('data-state', 'server');
    expect((await page.request.get(originalHref, {maxRedirects: 0})).status()).toBe(302);
    let undos = 0;
    while (await editor(page).locator('a').count() && undos < 6) { await editor(page).press('ControlOrMeta+z'); undos++; }
    await expect(editor(page).locator('a')).toHaveCount(0);
    while (undos-- > 0) await editor(page).press('ControlOrMeta+Shift+z');
    await expect(editor(page).locator('a')).toHaveCount(1);
    await editor(page).press('ControlOrMeta+s'); await expect(page.locator('[data-save-state]')).toHaveAttribute('data-state', 'server');
    await page.locator('[data-save-button]').click(); await expect(page.locator('[data-document-content]')).toContainText('Later state');
});

test('the user directory picker checks its own permission on the server', async ({context, request}) => {
    const doc = await api(request, 'post', 'pages', {space_id: fixture().spaceId, title: title(), content_html: '<p>Directory ACL</p>'});
    const limited = await context.browser().newContext({baseURL: 'http://127.0.0.1:8765'}); const tab = await limited.newPage();
    await tab.goto('/login'); await tab.locator('[name=identifier]').fill(fixture().users[2].username); await tab.locator('[name=password]').fill(fixture().users[2].password); await tab.locator('form[action="/login"] button[type=submit]').click();
    const url = '/spaces/' + fixture().spaceKey + '/editor-users?document_key=page:' + doc.id + '&q=';
    const other = await limited.request.get(url + fixture().users[0].username); expect(other.status()).toBe(200); expect((await other.json()).data.users).toEqual([]);
    const self = await limited.request.get(url + fixture().users[2].username); expect((await self.json()).data.users.map(user => user.username)).toEqual([fixture().users[2].username]);
    await limited.close();
});

test('a private image kept only in conversion recovery remains restorable after a late first publication', async ({page}) => {
    await page.goto(createUrl()); await page.locator('[name=title]').fill(title());
    await editor(page).fill('Original image');
    await page.locator('[data-image-file]').setInputFiles({name: 'conversion.png', mimeType: 'image/png', buffer: Buffer.from(fixture().imageBase64, 'base64')});
    await expect(page.locator('[data-upload-list]')).toContainText('Zapisano jako prywatny');
    await page.locator('[data-editor-mode=markdown]').click(); await expect(page.locator('[data-conversion-dialog]')).toBeVisible();
    await page.locator('[data-conversion-accept]').click(); await page.locator('[data-markdown-editor]').fill('Converted document without the image');
    let entered; const started = new Promise(resolve => entered = resolve);
    let release; const blocked = new Promise(resolve => release = resolve);
    await page.route('**/spaces/*/pages', async route => { if (route.request().method() === 'POST') { const response = await route.fetch(); entered(); await blocked; await route.fulfill({response}); } else await route.continue(); });
    await page.locator('[data-save-button]').click(); await started;
    await page.locator('[data-markdown-editor]').press('ControlOrMeta+End'); await page.keyboard.type(' with later changes'); release();
    await expect(page).toHaveURL(/\/edit$/); await page.unroute('**/spaces/*/pages');
    await page.locator('[data-undo-conversion]').click();
    await expect(editor(page).locator('img')).toHaveAttribute('src', /^\/attachments\//);
    await expect(editor(page)).toContainText('Original image');
    await editor(page).press('ControlOrMeta+s'); await expect(page.locator('[data-save-state]')).toHaveAttribute('data-state', 'server');
    await page.locator('[data-save-button]').click(); await expect(page.locator('[data-document-content] img')).toBeVisible();
    expect((await page.request.get(await page.locator('[data-document-content] img').getAttribute('src'))).status()).toBe(200);
});

test('cancelling an in-flight upload removes its journal without inserting a file', async ({page}) => {
    await page.goto(createUrl());
    let entered; const started = new Promise(resolve => entered = resolve);
    let release; const blocked = new Promise(resolve => release = resolve);
    await page.route('**/editor-files', async route => { entered(); await blocked; await route.abort('failed').catch(() => {}); });
    await page.locator('[data-attachment-file]').setInputFiles({name: 'cancel.txt', mimeType: 'text/plain', buffer: Buffer.from('Cancelled file')});
    await started; await page.locator('[data-upload-list]').getByRole('button', {name: 'Usuń z kolejki'}).click();
    await expect(page.locator('[data-upload-list] li')).toHaveCount(0); release();
    expect(await page.evaluate(() => new Promise((resolve, reject) => {
        const request = indexedDB.open('openwiki-editor', 1);
        request.onerror = reject;
        request.onsuccess = () => {
            const read = request.result.transaction('uploads').objectStore('uploads').getAll();
            read.onsuccess = () => resolve(read.result.length); read.onerror = reject;
        };
    }))).toBe(0);
    await expect(editor(page).locator('a')).toHaveCount(0);
    await editor(page).fill('Work after cancellation'); await page.keyboard.press('ControlOrMeta+s');
    await expect(page.locator('[data-save-state]')).toHaveAttribute('data-state', 'server');
});

test('IndexedDB enforces quotas across recovery instances and conditionally removes acknowledged copies', async ({page}) => {
    await page.addInitScript({content: fs.readFileSync('resources/js/local-recovery.js', 'utf8').replace('export class LocalRecovery', 'class LocalRecovery') + '\nwindow.TestRecovery = LocalRecovery;'});
    await page.goto(createUrl());
    const result = await page.evaluate(async () => {
        const first = new window.TestRecovery(), second = new window.TestRecovery();
        const records = await Promise.allSettled(Array.from({length: 51}, (_, i) => (i % 2 ? first : second).putUpload('test-upload-' + i, {file: new File(['bytes'], 'test.txt')})));
        const count = (await first.uploads('test-upload-')).length;
        await Promise.all(Array.from({length: 51}, (_, i) => first.removeUpload('test-upload-' + i)));
        await first.putCopy('ack-test', {sequence: 1});
        await Promise.all([first.conditionalRemove('ack-test', 1), second.putCopy('ack-test', {sequence: 2})]);
        await first.conditionalRemove('ack-test', 1);
        const recovered = (await first.list('ack-test'))[0];
        await first.remove('ack-test');
        const copies = await Promise.allSettled(Array.from({length: 101}, (_, i) => (i % 2 ? first : second).putCopy('quota-test-' + i, {sequence: 1})));
        await Promise.all(Array.from({length: 101}, (_, i) => first.remove('quota-test-' + i)));
        return {count, rejectedUploads: records.filter(row => row.status === 'rejected').length, sequence: recovered.sequence, rejectedCopies: copies.filter(row => row.status === 'rejected').length};
    });
    expect(result).toEqual({count: 50, rejectedUploads: 1, sequence: 2, rejectedCopies: 1});
});
