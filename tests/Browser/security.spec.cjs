const {test, expect} = require('@playwright/test');
const {execFileSync} = require('node:child_process');
const {phpArguments} = require('./php-command.cjs');
const fs = require('node:fs');
const fixture = () => JSON.parse(fs.readFileSync('storage/temp/editor-browser.json', 'utf8'));
const unique = (prefix) => prefix + '-' + Date.now() + '-' + Math.random().toString(16).slice(2, 7);
const pathFor = (document) => '/spaces/' + fixture().spaceKey + '/pages/' + document.slug;
const login = async (page, index = 0) => {
    await page.context().addCookies([{name: 'openwiki_locale', value: 'en', url: 'http://127.0.0.1:8765'}]);
    await page.goto('/login');
    await page.locator('[name=identifier]').fill(fixture().users[index].username);
    await page.locator('[name=password]').fill(fixture().users[index].password);
    await page.getByRole('button', {name: 'Sign in', exact: true}).click();
    await expect(page).toHaveURL('/');
};
const create = async (request, extra = {}) => {
    const response = await request.post('/api/v1/pages', {headers: {Authorization: 'Bearer ' + fixture().token},
        data: {space_id: fixture().spaceId, title: unique('Security'), content_html: '<p>Safe document</p>', ...extra}});
    expect(response.status(), await response.text()).toBe(201);
    return (await response.json()).data;
};
const rule = async (page, document, userIndex, permission, effect = 'deny', inherited = true) => {
    await page.goto(pathFor(document) + '/permissions');
    await page.locator('[name=principal]').selectOption('user:' + fixture().users[userIndex].id);
    await page.locator('[name=permission]').selectOption(permission);
    await page.locator('[name=effect]').selectOption(effect);
    await page.locator('[name=inherit_to_children]').setChecked(inherited);
    await page.getByRole('button', {name: 'Save ACL rule', exact: true}).click();
};

test('sanitized fragments remain inert after browser parsing without CSP', async ({page}) => {
    const corpus = JSON.parse(execFileSync('php', [...phpArguments, 'tests/Browser/html-security.php'], {encoding: 'utf8'}));
    // about:blank has no response headers, including CSP.
    await page.goto('about:blank');
    for (const html of corpus.html) {
        await page.evaluate((html) => { window.__xss = 0; document.body.innerHTML = html; }, html);
        await page.waitForTimeout(50);
        const result = await page.evaluate((policy) => ({
            executed: window.__xss,
            text: document.body.textContent,
            unsafe: [...document.body.querySelectorAll('*')].flatMap((node) => {
                const tag = node.localName;
                const allowed = policy.global.concat(policy.attributes[tag] || []);
                return !policy.tags.includes(tag) ? [tag] : [...node.attributes]
                    .filter((attr) => !allowed.includes(attr.name) || /^on/i.test(attr.name)
                        || (['href', 'src'].includes(attr.name) && /javascript:|[\x00-\x20\\]/i.test(attr.value)))
                    .map((attr) => attr.name);
            })
        }), corpus.policy);
        expect(result.executed).toBe(0);
        if (html !== '') expect(result.text).toContain('retained');
        expect(result.unsafe).toEqual([]);
    }
});

test('delegated administrator cannot take over protected accounts using API or real administration forms', async ({page, request}) => {
    await login(page, 4);
    const data = fixture();
    const headers = {Authorization: 'Bearer ' + data.delegateToken};
    for (const [method, path, body] of [
        ['post', '/api/v1/roles', {name: unique('Escalation'), permission_ids: [data.wildcardPermissionId]}],
        ['patch', '/api/v1/roles/' + data.roleId, {permission_ids: []}],
        ['post', '/api/v1/users', {username: unique('escalation'), email: unique('escalation') + '@example.test', password: 'BrowserRegression!42', role_ids: [data.roleId]}],
        ['patch', '/api/v1/users/' + data.users[0].id, {status: 'disabled'}],
        ['delete', '/api/v1/users/' + data.users[0].id, undefined]
    ]) expect((await request[method](path, {headers, data: body})).status()).toBe(403);
    await page.goto('/admin/roles/create');
    await page.locator('[name=name]').fill(unique('Forbidden role form'));
    await page.locator('[name="permission_ids[]"][value="' + data.wildcardPermissionId + '"]').check();
    const denied = page.waitForResponse((response) => response.url().endsWith('/admin/roles') && response.status() === 403);
    await page.getByRole('button', {name: 'Save role', exact: true}).click(); await denied;
    await expect(page.locator('.alert--error')).toContainText('delegate permissions');
    for (const label of ['Reset password', 'Reset MFA']) {
        await page.goto('/admin/users/' + data.users[0].id + '/edit');
        page.once('dialog', (dialog) => dialog.accept());
        const response = page.waitForResponse((response) => response.request().method() === 'POST' && response.status() === 403);
        await page.getByRole('button', {name: label, exact: true}).click(); await response;
    }
    for (const path of ['/admin/ldap', '/admin/mfa', '/admin/backups']) expect((await page.request.get(path)).status()).toBe(403);
    expect((await request.get('/api/v1/users/' + data.users[0].id, {headers: {Authorization: 'Bearer ' + data.token}})).status()).toBe(200);
});

test('guest, reader, editor, delegate and Super Admin receive consistent page ACL decisions', async ({page, request, browser}) => {
    await login(page);
    const document = await create(request, {status: 'published'});
    for (const index of [2, 3, 4]) await rule(page, document, index, 'page.view');
    const guest = await browser.newContext();
    expect((await guest.request.get(pathFor(document))).status()).toBe(403); await guest.close();
    for (const index of [2, 3, 4]) {
        const context = await browser.newContext(); const member = await context.newPage();
        await login(member, index);
        expect((await member.request.get(pathFor(document))).status()).toBe(403);
        expect((await member.request.get(pathFor(document) + '/edit')).status()).toBe(403);
        await context.close();
    }
    await page.goto(pathFor(document)); await expect(page.locator('[data-document-content]')).toContainText('Safe document');
});

test('native error form retains safe structured HTML and excludes hidden metadata', async ({page, request, browser}) => {
    await login(page);
    const visible = await create(request);
    const hidden = await create(request, {title: unique('SECRET-TITLE'), slug: unique('secret-slug')});
    await rule(page, hidden, 2, 'page.view');
    const context = await browser.newContext({javaScriptEnabled: false});
    const member = await context.newPage(); await login(member, 2);
    await member.goto(pathFor(visible) + '/edit');
    await member.locator('[name=title]').fill('');
    await member.locator('[data-editor-form]').evaluate((form) => {
        form.noValidate = true;
        form.querySelector('[name=content_html]').value = '<article><table><tr><td><strong>Recovered format</strong></td></tr></table><script>window.__xss=1</script></article>';
        form.querySelector('[name=tags]').value = 'recovered-tag';
        form.querySelector('[name=change_summary]').value = 'Recovered summary';
    });
    const failed = member.waitForResponse((response) => response.request().method() === 'POST' && response.status() === 422);
    await member.locator('[data-save-button]').click(); await failed;
    expect(await member.content()).not.toContain(hidden.title); expect(await member.content()).not.toContain(hidden.slug);
    await expect(member.locator('[name=content_html]')).toHaveValue('<table><tr><td><strong>Recovered format</strong></td></tr></table>');
    await expect(member.locator('[name=tags]')).toHaveValue('recovered-tag');
    await expect(member.locator('[name=change_summary]')).toHaveValue('Recovered summary');
    await context.close();
});

test('API deletion and real trash restore preserve subtree ACL and independent deletion batches', async ({page, request, browser}) => {
    await login(page);
    const parent = await create(request, {status: 'published'});
    const child = await create(request, {parent_id: parent.id, status: 'published'});
    const prior = await create(request, {parent_id: parent.id, status: 'published'});
    await rule(page, parent, 2, 'page.view');
    const headers = {Authorization: 'Bearer ' + fixture().token};
    expect((await request.delete('/api/v1/pages/' + prior.id, {headers})).status()).toBe(204);
    expect((await request.delete('/api/v1/pages/' + parent.id, {headers})).status()).toBe(204);
    await page.goto('/trash');
    await page.locator('form[action="/trash/' + parent.id + '/restore"] button').click();
    await expect(page).toHaveURL(pathFor(parent));
    expect((await request.get('/api/v1/pages/' + child.id, {headers})).status()).toBe(200);
    expect((await request.get('/api/v1/pages/' + prior.id, {headers})).status()).toBe(404);
    const context = await browser.newContext(); const member = await context.newPage(); await login(member, 2);
    expect((await member.request.get(pathFor(child))).status()).toBe(403); await context.close();
});

for (const format of ['visual', 'markdown']) test(`legacy ${format} page, revision, API and export reads are safe without CSP and leave snapshots intact`, async ({request, browser}) => {
    const document = await create(request);
    const snapshot = execFileSync('php', [...phpArguments, 'tests/Browser/fixture.php', format === 'markdown' ? 'legacy-markdown' : 'legacy-html', String(document.id)], {encoding: 'utf8'});
    const context = await browser.newContext({bypassCSP: true}); const page = await context.newPage();
    await login(page);
    for (const path of [pathFor(document), pathFor(document) + '/revisions/1']) {
        await page.goto(path); await expect(page.locator('body')).toContainText('Legacy safe');
        expect(await page.evaluate(() => window.__xss)).toBeUndefined();
        expect(await page.locator('article[onclick], p[onclick], svg').count()).toBe(0);
        if (format === 'markdown') await expect(page.locator('table')).toContainText('Historical table');
    }
    const response = await request.get('/api/v1/pages/' + document.id, {headers: {Authorization: 'Bearer ' + fixture().token}});
    const safe = (await response.json()).data;
    expect(safe.content_html).toBe('<p>Legacy safe</p>' + (format === 'markdown' ? '<table><tbody><tr><td>Historical table</td></tr></tbody></table>' : ''));
    expect(safe.document.source).toBe(format === 'markdown' ? '# Original Markdown source' : safe.content_html);
    const exported = await page.request.get(pathFor(document) + '/export/html');
    expect(exported.status()).toBe(200);
    expect(await exported.text()).toContain('Legacy safe'); expect(await exported.text()).not.toContain('window.__xss');
    if (format === 'markdown') expect(await exported.text()).toContain('Historical table');
    expect(execFileSync('php', [...phpArguments, 'tests/Browser/fixture.php', 'legacy-snapshot', String(document.id)], {encoding: 'utf8'})).toBe(snapshot);
    await context.close();
});
