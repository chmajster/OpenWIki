const {test, expect} = require('@playwright/test');
test.describe.configure({timeout: 90000});
const fs = require('node:fs');
const fixture = () => JSON.parse(fs.readFileSync('storage/temp/editor-browser.json', 'utf8'));
const unique = prefix => prefix + '-' + Date.now() + '-' + Math.random().toString(16).slice(2, 6);
const api = async (request, method, path, data) => {
    const response = await request[method]('/api/v1/' + path, {headers: {Authorization: 'Bearer ' + fixture().token}, data});
    expect(response.ok(), await response.text()).toBeTruthy(); return response.status() === 204 ? null : (await response.json()).data;
};
const login = async (page, index = 0) => {
    await page.context().addCookies([{name: 'openwiki_locale', value: 'pl', url: 'http://127.0.0.1:8765'}]);
    await page.goto('/login'); await page.locator('[name=identifier]').fill(fixture().users[index].username); await page.locator('[name=password]').fill(fixture().users[index].password);
    await page.getByRole('button', {name: 'Zaloguj', exact: true}).click(); await expect(page).toHaveURL('/');
};
const createSpace = (request, visibility = 'public') => api(request, 'post', 'spaces', {name: unique('space-ui'), visibility});
const createPage = (request, space, title, extra = {}) => api(request, 'post', 'pages', {space_id: space.id, title, content_html: '<p>Dokumentacja</p>', status: 'published', ...extra});
const pathFor = (space, page) => `/spaces/${space.key}/pages/${page.slug}`;
const node = (page, id) => page.locator(`[role=treeitem][data-page-id="${id}"]`);
const dialog = page => page.locator('[data-structure-dialog]');
const prepare = async page => {
    await dialog(page).getByRole('button', {name: 'Przygotuj podgląd'}).click();
    await expect(dialog(page).locator('[data-structure-preview]')).toBeVisible();
    await dialog(page).locator('[data-structure-confirm]').check();
};
const commit = async page => {
    const pending = page.waitForResponse(response => /\/structure\/pages\/\d+$/.test(new URL(response.url()).pathname) && response.request().method() === 'POST', {timeout: 60000});
    await dialog(page).getByRole('button', {name: 'Zatwierdź operację'}).click(); const response = await pending; expect(response.ok(), await response.text()).toBeTruthy(); await expect(dialog(page)).not.toBeVisible();
};
let errors;
test.beforeEach(async ({page}) => { errors = []; page.on('pageerror', error => errors.push(error.message)); page.on('dialog', native => native.accept()); await login(page); });
test.afterEach(async ({page}) => { expect(errors).toEqual([]); await expect(page.locator('body')).not.toContainText('Undefined variable'); });

test('settings form, group picker, effective decisions and archived reads use the same domain as API', async ({page, request, browser}) => {
    const space = await createSpace(request, 'restricted'), document = await createPage(request, space, 'Start');
    const groupName = unique('space-browser');
    const group = await api(request, 'post', 'groups', {name: groupName, slug: groupName, user_ids: [fixture().users[3].id]});
    await page.goto(`/spaces/${space.key}/settings`);
    await page.locator('[name=name]').fill('Codzienna dokumentacja'); await page.locator('[name=description]').fill('Opis przestrzeni'); await page.locator('[name=icon]').fill('📚');
    await page.locator('[name=home_page_id]').selectOption(String(document.id)); await page.getByRole('button', {name: 'Zapisz ustawienia'}).click();
    const saved = await api(request, 'get', `spaces/${space.id}`); expect(saved.name).toBe('Codzienna dokumentacja'); expect(saved.home_page_id).toBe(document.id); expect(saved.icon).toBe('📚');
    const acl = page.locator('#access form[data-principal-picker]'); await acl.locator('[name=principal_type]').selectOption('group'); await acl.locator('[data-principal-query]').fill(groupName);
    await expect(acl.locator(`[name=principal_id] option[value="${group.id}"]`)).toHaveCount(1); await acl.locator('[name=principal_id]').selectOption(String(group.id)); await acl.getByRole('button', {name: 'Zapisz regułę'}).click();
    await expect(page.locator('#access table')).toContainText(groupName);
    const effective = page.locator('[data-effective-access]'); await effective.locator('[data-principal-query]').fill(fixture().users[3].username);
    await expect(effective.locator(`option[value="${fixture().users[3].id}"]`)).toHaveCount(1); await effective.locator('[data-effective-user]').selectOption(String(fixture().users[3].id)); await effective.getByRole('button', {name: 'Sprawdź dostęp'}).click();
    await expect(effective.locator('[data-effective-result]')).toContainText(`group #${group.id}`);
    await page.screenshot({path: 'storage/temp/space-settings-desktop.png', fullPage: true});
    const context = await browser.newContext(), reader = await context.newPage(); await login(reader, 3); await reader.goto(pathFor(space, document)); await expect(reader.locator('[data-document-content]')).toContainText('Dokumentacja');
    await page.locator('[name=status]').selectOption('archived'); await page.getByRole('button', {name: 'Zapisz ustawienia'}).click();
    await reader.reload(); await expect(reader.locator('[data-document-content]')).toContainText('Dokumentacja');
    const denied = await request.put(`/api/v1/pages/${document.id}`, {headers: {Authorization: 'Bearer ' + fixture().token}, data: {base_version: 1, title: 'Forbidden'}}); expect(denied.status()).toBe(403);
    await page.locator('[name=status]').selectOption('active'); await page.getByRole('button', {name: 'Zapisz ustawienia'}).click(); expect((await api(request, 'get', `spaces/${space.id}`)).status).toBe('active');
    await context.close();
});

test('lazy desktop tree remembers expansion and keyboard dialog persists ordering after reload', async ({page, request}) => {
    const space = await createSpace(request), root = await createPage(request, space, 'Root'), sibling = await createPage(request, space, 'Sibling'), child = await createPage(request, space, 'Child', {parent_id: root.id}), deep = await createPage(request, space, 'Deep', {parent_id: child.id});
    await page.goto(`/spaces/${space.key}`); await expect(node(page, root.id)).toBeVisible(); await expect(node(page, child.id)).toHaveCount(0);
    await node(page, root.id).focus(); await page.keyboard.press('ArrowRight'); await expect(node(page, child.id)).toBeVisible(); await page.keyboard.press('ArrowRight'); await expect(node(page, child.id)).toBeFocused();
    await page.keyboard.press('ArrowRight'); await expect(node(page, deep.id)).toBeVisible(); await page.reload(); await expect(node(page, deep.id)).toBeVisible();
    await node(page, sibling.id).focus(); await page.keyboard.press('F2'); await expect(dialog(page)).toBeVisible(); await expect(dialog(page).locator('[name=before_id]')).toBeEnabled();
    await dialog(page).locator('[name=before_id]').selectOption(String(root.id)); await prepare(page); await commit(page);
    await page.reload(); await expect(node(page, sibling.id)).toBeVisible(); const ids = await page.locator('[data-tree-root] > [role=treeitem]').evaluateAll(rows => rows.map(row => Number(row.dataset.pageId))); expect(ids).toEqual([sibling.id, root.id]);
    await page.screenshot({path: 'storage/temp/space-tree-desktop.png'});
    await page.goto(pathFor(space, deep)); await expect(page.locator('.breadcrumbs')).toContainText('Root'); await expect(page.locator('.breadcrumbs')).toContainText('Child');
});

test('drag and drop requires a preview and denied commit leaves the visible tree intact', async ({page, request}) => {
    const space = await createSpace(request), root = await createPage(request, space, 'Root'), sibling = await createPage(request, space, 'Sibling');
    await page.goto(`/spaces/${space.key}`); await expect(node(page, sibling.id)).toBeVisible();
    await node(page, sibling.id).locator('.tree-row').dragTo(node(page, root.id).locator('.tree-row'));
    await expect(dialog(page)).toBeVisible(); await expect(dialog(page).locator('[name=parent_id]')).toHaveValue(String(root.id)); await prepare(page);
    await page.route(`**/structure/pages/${sibling.id}`, route => route.fulfill({status: 403, contentType: 'application/json', body: JSON.stringify({error: {message: 'Odmowa ACL'}})}));
    await dialog(page).getByRole('button', {name: 'Zatwierdź operację'}).click(); await expect(dialog(page).locator('[data-structure-status]')).toHaveText('Odmowa ACL');
    expect((await api(request, 'get', `pages/${sibling.id}`)).parent_id).toBeNull(); expect(await page.locator('[data-tree-root] > [role=treeitem]').count()).toBe(2);
    await page.unroute(`**/structure/pages/${sibling.id}`); await commit(page);
    expect((await api(request, 'get', `pages/${sibling.id}`)).parent_id).toBe(root.id);
});

test('phone tree, copy dialog and cross-space access preview preserve document readback', async ({page, request}) => {
    await page.setViewportSize({width: 390, height: 844});
    const source = await createSpace(request), target = await createSpace(request, 'private'), root = await createPage(request, source, 'Phone root'), child = await createPage(request, source, 'Phone child', {parent_id: root.id});
    const upload = await request.post('/api/v1/attachments', {headers: {Authorization: 'Bearer ' + fixture().token}, multipart: {page_id: String(root.id), attachment: {name: 'diagram.png', mimeType: 'image/png', buffer: Buffer.from(fixture().imageBase64, 'base64')}}});
    expect(upload.ok(), await upload.text()).toBeTruthy(); const attachment = (await upload.json()).data;
    await api(request, 'patch', `pages/${root.id}`, {base_version: root.version, content_html: `<p>Dokumentacja</p><figure><img src="/attachments/${attachment.id}/preview" data-attachment-id="${attachment.id}" alt="Schemat" width="96" height="96"><figcaption>Podpis schematu</figcaption></figure>`});
    await page.goto(`/spaces/${source.key}`); const panel = page.locator('[data-page-panel]'); await expect(panel).not.toHaveAttribute('open', ''); await panel.locator('summary').click(); await expect(node(page, root.id)).toBeVisible();
    await node(page, root.id).locator('.tree-operation').click(); await expect(dialog(page)).toBeVisible(); await expect(dialog(page).locator('[name=action]')).toBeEnabled();
    await dialog(page).locator('[name=action]').selectOption('copy'); await dialog(page).locator('[name=destination_space_id]').selectOption(String(target.id)); await dialog(page).locator('[name=title]').fill('Phone copied'); await prepare(page);
    await expect(dialog(page).locator('[data-structure-effects]')).toContainText('Gość'); await page.screenshot({path: 'storage/temp/space-operation-phone.png'}); await commit(page);
    const copies = await api(request, 'get', `pages?space=${target.key}`), copied = copies.find(item => item.title === 'Phone copied'), copiedChild = copies.find(item => item.title === child.title); expect(copiedChild.parent_id).toBe(copied.id);
    await page.goto(pathFor(target, copied)); await expect(page.locator('[data-document-content]')).toContainText('Dokumentacja'); expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();
    const image = page.locator('[data-document-content] img'), copiedAttachment = await image.getAttribute('data-attachment-id'); expect(copiedAttachment).not.toBe(String(attachment.id));
    await expect(image).toHaveAttribute('alt', 'Schemat'); await expect(page.locator('figcaption')).toHaveText('Podpis schematu');
    await expect.poll(() => image.evaluate(img => img.complete && img.naturalWidth > 0)).toBeTruthy();
    await page.goto(pathFor(target, copied) + '/edit'); await expect(page.locator('[data-rich-editor] img')).toHaveAttribute('data-attachment-id', copiedAttachment);
    await page.locator('[data-save-button]').click(); await expect(page.locator('[data-document-content] img')).toHaveAttribute('data-attachment-id', copiedAttachment); await expect(page.locator('figcaption')).toHaveText('Podpis schematu');
});

test('visible child of hidden parent does not disclose parent metadata to guests', async ({page, request, browser}) => {
    const space = await createSpace(request), parent = await createPage(request, space, 'SECRET PARENT', {status: 'draft'}), child = await createPage(request, space, 'Public child', {parent_id: parent.id});
    const context = await browser.newContext(), guest = await context.newPage(); await guest.goto(`/spaces/${space.key}`); await expect(node(guest, child.id)).toBeVisible(); await expect(guest.locator('[data-documentation-tree]')).not.toContainText('SECRET PARENT');
    const branch = await guest.request.get(`/spaces/${space.key}/tree`); const serialized = await branch.text(); expect(serialized).not.toContain(parent.slug); expect((await branch.json()).data.items[0].parent_id).toBeNull();
    await guest.goto(pathFor(space, child)); await expect(guest.locator('.breadcrumbs')).toContainText('Niedostępny poziom'); await expect(guest.locator('.breadcrumbs')).not.toContainText('SECRET PARENT'); await context.close();
});

test('settings validation and stale versions preserve input without overwriting server changes', async ({page, request}) => {
    const space = await createSpace(request);
    await page.goto(`/spaces/${space.key}/settings`);
    await page.locator('[name=description]').fill('Opis do odzyskania');
    const owner = page.locator('[data-principal-picker][data-type=user]').first();
    await owner.locator('[data-principal-query]').fill(fixture().users[1].username);
    await expect(owner.locator(`option[value="${fixture().users[1].id}"]`)).toHaveCount(1);
    await owner.locator('[name=owner_id]').selectOption(String(fixture().users[1].id));
    await page.locator('[name=name]').fill('');
    await page.locator('[data-space-settings]').evaluate(form => form.noValidate = true);
    const invalid = page.waitForResponse(response => response.url().endsWith('/settings') && response.request().method() === 'POST');
    await page.getByRole('button', {name: 'Zapisz ustawienia'}).click(); expect((await invalid).status()).toBe(422);
    await expect(page.locator('[name=description]')).toHaveValue('Opis do odzyskania');
    await expect(page.locator('[name=owner_id]')).toHaveValue(String(fixture().users[1].id));
    const originalVersion = await page.locator('[data-space-settings] [name=base_version]').inputValue();
    await api(request, 'put', `spaces/${space.id}`, {name: 'Nowsza nazwa serwera'});
    await page.locator('[name=name]').fill('Lokalna nazwa');
    for (let retry = 0; retry < 2; retry++) {
        const conflict = page.waitForResponse(response => response.url().endsWith('/settings') && response.request().method() === 'POST');
        await page.getByRole('button', {name: 'Zapisz ustawienia'}).click(); expect((await conflict).status()).toBe(409);
        await expect(page.locator('[name=name]')).toHaveValue('Lokalna nazwa');
        await expect(page.locator('[data-space-settings] [name=base_version]')).toHaveValue(originalVersion);
    }
    expect((await api(request, 'get', `spaces/${space.id}`)).name).toBe('Nowsza nazwa serwera');
    await page.getByRole('link', {name: 'Wczytaj aktualne ustawienia z serwera'}).click();
    const acl = page.locator('#access form[data-principal-picker]'), groupName = unique('space-browser');
    const group = await api(request, 'post', 'groups', {name: groupName, slug: groupName, user_ids: []});
    await acl.locator('[name=principal_type]').selectOption('group'); await acl.locator('[data-principal-query]').fill(groupName);
    await expect(acl.locator(`option[value="${group.id}"]`)).toHaveCount(1); await acl.locator('[name=principal_id]').selectOption(String(group.id));
    await acl.locator('[name=effect]').selectOption('deny');
    await acl.locator('[name=permission]').evaluate(select => { select.add(new Option('Invalid permission', 'invalid.permission')); select.value = 'invalid.permission'; });
    const rejectedRule = page.waitForResponse(response => response.url().endsWith('/access') && response.request().method() === 'POST');
    await acl.getByRole('button', {name: 'Zapisz regułę'}).click(); expect((await rejectedRule).status()).toBe(422);
    await expect(acl.locator('[name=principal_type]')).toHaveValue('group'); await expect(acl.locator('[name=principal_id]')).toHaveValue(String(group.id)); await expect(acl.locator('[name=effect]')).toHaveValue('deny');
    await acl.locator('[name=principal_type]').selectOption('role'); await acl.locator('[data-principal-query]').fill(''); await acl.locator('[data-principal-query]').focus();
    await expect(acl.locator('[data-picker-status]')).toContainText('Wyniki:');
    await expect(acl.locator('[name=principal_id]')).toHaveValue(''); await expect(acl.locator('[name=principal_id]')).not.toContainText(groupName);
});

test('an older position search cannot replace a newer selection or its committed order', async ({page, request}) => {
    const space = await createSpace(request), root = await createPage(request, space, 'Root'), sibling = await createPage(request, space, 'Sibling');
    await page.goto(`/spaces/${space.key}`); await expect(node(page, root.id)).toBeVisible();
    await node(page, root.id).locator('.tree-operation').click(); await expect(dialog(page).locator('[name=before_id]')).toBeEnabled();
    let release; const held = new Promise(resolve => { release = resolve; });
    await page.route(`**/spaces/${space.key}/tree?*`, async route => {
        if (new URL(route.request().url()).searchParams.get('q') !== 'Root') return route.continue();
        const response = await route.fetch(); await held; await route.fulfill({response});
    });
    const older = page.waitForRequest(request => new URL(request.url()).searchParams.get('q') === 'Root');
    await dialog(page).locator('[data-position-search]').fill('Root'); await older;
    await dialog(page).locator('[data-position-search]').fill('Sibling');
    await expect(dialog(page).locator(`[name=before_id] option[value="${sibling.id}"]`)).toHaveCount(1);
    await dialog(page).locator('[name=before_id]').selectOption(String(sibling.id));
    const finished = page.waitForResponse(response => new URL(response.url()).searchParams.get('q') === 'Root'); release(); await finished;
    await expect(dialog(page).locator('[name=before_id]')).toHaveValue(String(sibling.id));
    await prepare(page); await commit(page); await page.reload(); await expect(node(page, root.id)).toBeVisible();
    expect(await page.locator('[data-tree-root] > [role=treeitem]').evaluateAll(rows => rows.map(row => Number(row.dataset.pageId)))).toEqual([root.id, sibling.id]);
});
