const {test, expect} = require('@playwright/test');
const fs = require('node:fs');
test.describe.configure({timeout: 240000});
test.use({actionTimeout: 30000});
let errors;
test.beforeEach(() => { errors = []; });
test.afterEach(() => { expect(errors).toEqual([]); });
const fixture = () => JSON.parse(fs.readFileSync('storage/temp/editor-browser.json', 'utf8'));
const headers = (index = null) => ({Authorization: 'Bearer ' + (index === null ? fixture().token : fixture().workflowTokens[index])});
const call = async (request, method, path, data, index = null) => {
    const response = await request[method]('/api/v1/' + path, {headers: headers(index), data});
    expect(response.ok(), await response.text()).toBeTruthy();
    if (response.status() === 204) return null;
    const body = await response.text();
    try { return JSON.parse(body).data; } catch { throw new Error('Invalid API response: ' + body.slice(0, 3000)); }
};
const login = async (browser, index, viewport) => {
    const context = await browser.newContext({viewport, locale: 'pl-PL'});
    await context.addCookies([{name: 'openwiki_locale', value: 'pl', url: 'http://127.0.0.1:8765'}]);
    const page = await context.newPage();
    page.on('pageerror', error => errors.push(error.message));
    await page.goto('/login');
    await page.locator('[name=identifier]').fill(fixture().users[index].username);
    await page.locator('[name=password]').fill(fixture().users[index].password);
    await page.getByRole('button', {name: 'Zaloguj', exact: true}).click();
    await expect(page).toHaveURL('/');
    return {context, page};
};
const space = async (request) => {
    const value = await call(request, 'post', 'spaces', {name: 'Review ' + Date.now(), visibility: 'public'});
    let version = value.version;
    for (const index of [5, 6, 7]) {
        const changed = await call(request, 'post', 'spaces/' + value.id + '/access', {base_version: version, principal_type: 'user', principal_id: fixture().users[index].id, permission: 'space.edit', effect: 'allow'});
        version = changed.space.version;
    }
    return {...value, management_version: version};
};

test('real author, reviewer, publisher and reader forms preserve N until an approved exact revision is published', async ({browser, request}) => {
    const s = await space(request);
    const doc = await call(request, 'post', 'pages', {space_id: s.id, title: 'Public N', slug: 'knowledge', status: 'published', content_html: '<p>Published N</p>', tags: ['public'], review_due_at: '2000-01-01'});
    const administrator = await login(browser, 0, {width: 1280, height: 900});
    await administrator.page.goto('/spaces/' + s.key + '/settings');
    await administrator.page.locator('[name=review_workflow]').selectOption('1');
    await administrator.page.locator('[name=allow_self_review]').selectOption('0');
    await administrator.page.getByRole('button', {name: 'Zapisz ustawienia'}).click();
    expect((await call(request, 'get', 'spaces/' + s.id)).review_workflow).toBe(true);
    const author = await login(browser, 5, {width: 1280, height: 900});
    const reviewer = await login(browser, 6, {width: 1280, height: 900});
    const publisher = await login(browser, 7, {width: 390, height: 844});
    const reader = await login(browser, 8, {width: 390, height: 844});
    const url = '/spaces/' + s.key + '/pages/knowledge';
    try {
        await author.page.goto(url + '/edit');
        await expect(author.page.locator('[name=status]')).toHaveValue('draft');
        await author.page.locator('[name=title]').fill('Private title N+1');
        await author.page.locator('[name=tags]').fill('private');
        await author.page.locator('[data-visual-editor]').fill('Private N+1');
        await author.page.locator('[data-save-button]').click();
        await expect(author.page.locator('[data-review-content]')).toContainText('Private N+1', {timeout: 20000});
        await reader.page.goto(url);
        await expect(reader.page.locator('[data-document-content]')).toContainText('Published N');
        await expect(reader.page.locator('h1')).toHaveText('Public N');
        await expect(reader.page.locator('body')).not.toContainText('Private title');
        await author.page.getByRole('button', {name: 'Przekaż do recenzji'}).click();
        await expect(author.page.locator('body')).toContainText('Do recenzji');
        await reviewer.page.goto(url + '/review');
        await reviewer.page.locator('textarea[name=comment]').fill('Sprawdzono merytorycznie N+1');
        await reviewer.page.getByRole('button', {name: 'Zatwierdź rewizję'}).click();
        await expect(reviewer.page.locator('body')).toContainText('Zatwierdzony');
        await publisher.page.goto(url + '/review');
        // Keep the publisher's revision 2 form while the author creates revision 3.
        await author.page.goto(url + '/edit');
        await author.page.locator('[data-visual-editor]').fill('Private N+2');
        await author.page.locator('[data-save-button]').click();
        await expect(author.page.locator('[data-review-content]')).toContainText('Private N+2', {timeout: 20000});
        await publisher.page.getByRole('button', {name: 'Opublikuj rewizję 2'}).click();
        await expect(publisher.page.locator('[role=alert]')).toContainText('Rewizja zmieniła się');
        const rejected = await request.post('/api/v1/pages/' + doc.id + '/review', {headers: headers(7), data: {action: 'publish', revision: 3}});
        expect(rejected.status()).toBe(403);
        await reader.page.reload();
        await expect(reader.page.locator('[data-document-content]')).toContainText('Published N');
        await author.page.getByRole('button', {name: 'Przekaż do recenzji'}).click();
        await reviewer.page.goto(url + '/review');
        await reviewer.page.locator('textarea[name=comment]').fill('Sprawdzono N+2');
        await reviewer.page.getByRole('button', {name: 'Zatwierdź rewizję'}).click();
        await expect(reviewer.page.locator('body')).toContainText('Zatwierdzony');
        await publisher.page.goto(url + '/review');
        await publisher.page.getByRole('button', {name: 'Opublikuj rewizję 3'}).focus();
        await publisher.page.keyboard.press('Enter');
        await expect(publisher.page.locator('body')).toContainText('Opublikowany');
        await reader.page.reload();
        await expect(reader.page.locator('[data-document-content]')).toContainText('Private N+2');
        await reader.page.goto(url + '/history');
        await expect(reader.page.locator('[name=from] option')).toHaveCount(2);
        await reader.page.locator('[name=from]').selectOption('1');
        await reader.page.locator('[name=to]').selectOption('3');
        await reader.page.getByRole('button', {name: 'Porównaj rewizje'}).click();
        await expect(reader.page.locator('[data-revision-diff]')).toContainText('Public N');
        const hidden = await reader.page.goto(url + '/revisions/2'); expect(hidden.status()).toBe(404);
        await reviewer.page.goto('/knowledge/overdue');
        await expect(reviewer.page.locator('body')).toContainText('Private title N+1');
        await reviewer.page.goto(url + '/review');
        await reviewer.page.locator('input[name=review_due_at]').fill('2099-01-01');
        await reviewer.page.getByRole('button', {name: 'Potwierdź aktualność'}).click();
        await expect(reviewer.page.locator('body')).toContainText('Zapisano decyzję');
        await reader.page.goto('/knowledge/overdue');
        await expect(reader.page.locator('body')).not.toContainText('Private title N+1');
    } finally {
        for (const session of [administrator, author, reviewer, publisher, reader]) {
            await expect(session.page.locator('body')).not.toContainText('Undefined variable');
            await session.context.close();
        }
        await request.delete('/api/v1/spaces/' + s.id, {headers: headers(), data: {base_version: s.management_version + 1}});
    }
});

test('draft files and later file versions remain private through real API downloads, macros, history and export', async ({request}) => {
    const s = await space(request);
    await call(request, 'patch', 'spaces/' + s.id, {base_version: s.management_version, review_workflow: 1});
    const doc = await call(request, 'post', 'pages', {space_id: s.id, title: 'Files', content_html: '<p>{{attachments}}</p>'}, 5);
    const upload = await request.post('/api/v1/attachments', {headers: headers(5), multipart: {page_id: String(doc.id), attachment: {name: 'private.txt', mimeType: 'text/plain', buffer: Buffer.from('Private v1')}}});
    expect(upload.ok(), await upload.text()).toBeTruthy(); const file = (await upload.json()).data;
    expect((await request.get('/api/v1/attachments/' + file.id + '/download', {headers: headers(8)})).status()).toBe(404);
    const head = await call(request, 'patch', 'pages/' + doc.id, {base_version: 1, content_html: '<p>{{attachments}}</p>'}, 5);
    await call(request, 'post', 'pages/' + doc.id + '/review', {revision: head.version, action: 'submit'}, 5);
    await call(request, 'post', 'pages/' + doc.id + '/review', {revision: head.version, action: 'approve', comment: 'Files checked'}, 6);
    const changedFile = await request.post('/api/v1/attachments/' + file.id + '/version', {headers: headers(5), multipart: {attachment: {name: 'later-secret.txt', mimeType: 'text/plain', buffer: Buffer.from('Private v2')}}});
    expect(changedFile.ok(), await changedFile.text()).toBeTruthy();
    await call(request, 'post', 'pages/' + doc.id + '/review', {revision: head.version, action: 'publish'}, 7);
    const downloaded = await request.get('/api/v1/attachments/' + file.id + '/download', {headers: headers(8)});
    expect(downloaded.ok()).toBeTruthy(); expect(await downloaded.text()).toBe('Private v1');
    expect((await request.get('/api/v1/attachments/' + file.id + '/versions/2/download', {headers: headers(8)})).status()).toBe(404);
    const files = await call(request, 'get', 'attachments', null, 8);
    expect(files.find(value => value.id === file.id).name).toBe('private.txt');
    const anon = await request.get('/spaces/' + s.key + '/pages/' + doc.slug);
    expect(await anon.text()).toContain('private.txt'); expect(await anon.text()).not.toContain('later-secret');
    const exported = await request.get('/spaces/' + s.key + '/pages/' + doc.slug + '/export/html');
    expect(await exported.text()).not.toContain('later-secret');
    await call(request, 'delete', 'attachments/' + file.id, {}, 5);
    const retained = await request.get('/api/v1/attachments/' + file.id + '/download', {headers: headers(8)});
    expect(retained.status()).toBe(200); expect(await retained.text()).toBe('Private v1');
    const retainedFiles = await call(request, 'get', 'attachments', null, 8);
    expect(retainedFiles.find(value => value.id === file.id).name).toBe('private.txt');
});
