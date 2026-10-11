const {test, expect} = require('@playwright/test');
const fs = require('node:fs');

const fixture = () => JSON.parse(fs.readFileSync('storage/temp/editor-browser.json', 'utf8'));
const editor = (page) => page.locator('[data-visual-editor]');
const state = (page) => page.locator('[data-save-state]');
const unique = (prefix) => prefix + '-' + Date.now() + '-' + Math.random().toString(16).slice(2, 7);
const api = async (request, method, path, data) => {
    const response = await request[method]('/api/v1/' + path, {headers: {Authorization: 'Bearer ' + fixture().token}, data});
    expect(response.ok(), await response.text()).toBeTruthy();
    return (await response.json()).data;
};
const create = (request, title = unique('Page'), extra = {}) => api(request, 'post', 'pages', {
    space_id: fixture().spaceId, title, content_format: 'visual', content_html: '<p>Base document</p>', ...extra
});
const pathFor = (document) => '/spaces/' + fixture().spaceKey + '/pages/' + document.slug;
const login = async (page, index = 0) => {
    await page.goto('/login');
    await page.locator('[name=identifier]').fill(fixture().users[index].username);
    await page.locator('[name=password]').fill(fixture().users[index].password);
    await page.getByRole('button', {name: 'Sign in', exact: true}).click();
    await expect(page).toHaveURL('/');
};
test.beforeEach(async ({page}) => {
    page.on('dialog', (dialog) => dialog.accept());
    await login(page);
});

test('the real create form transfers the latest source in both directions and saves all fields', async ({page, request}) => {
    const parent = await create(request, unique('Parent'));
    await page.goto('/spaces/' + fixture().spaceKey + '/pages/create');
    const title = unique('Created through form');
    await page.locator('[name=title]').fill(title);
    await page.locator('[name=slug]').fill('form-' + Date.now());
    await page.locator('[name=parent_id]').selectOption(String(parent.id));
    await page.locator('[name=tags]').fill('browser, visual');
    await page.locator('[name=change_summary]').fill('All fields saved through the form');
    await editor(page).fill('Visual latest');
    await editor(page).press('ControlOrMeta+A');
    await editor(page).press('ControlOrMeta+B');
    await page.getByRole('tab', {name: 'Markdown', exact: true}).click();
    await expect(page.locator('[data-markdown-editor]')).toHaveValue('**Visual latest**');
    await page.locator('[data-markdown-editor]').fill('# Markdown latest\n\n**Format retained**');
    await page.getByRole('tab', {name: 'Visual', exact: true}).click();
    await expect(editor(page).locator('h1')).toHaveText('Markdown latest');
    await expect(editor(page).locator('strong')).toHaveText('Format retained');
    await page.screenshot({path: 'storage/temp/editor-desktop.png'});
    await page.getByRole('button', {name: 'Create page', exact: true}).click();
    await expect(page.locator('[data-document-content]')).toContainText('Markdown latest');
    const pages = await api(request, 'get', 'pages?space=' + fixture().spaceKey);
    const saved = await api(request, 'get', 'pages/' + pages.find((row) => row.title === title).id);
    expect(saved.parent_id).toBe(parent.id);
    expect(saved.content_format).toBe('visual');
    expect(saved.content_html).toContain('<strong>Format retained</strong>');
    expect(saved.tags.map((tag) => tag.name).sort()).toEqual(['browser', 'visual']);
    expect(await page.evaluate(() => Object.keys(localStorage).filter((key) => key.startsWith('openwiki:draft:v2:')))).toEqual([]);
});

test('lossy conversion previews changes, supports cancellation and keeps reversible content', async ({page, request}) => {
    const document = await create(request);
    await page.goto(pathFor(document) + '/edit');
    await editor(page).fill('Underline survives recovery');
    await editor(page).press('ControlOrMeta+A');
    await editor(page).press('ControlOrMeta+U');
    await page.getByRole('tab', {name: 'Markdown', exact: true}).click();
    await expect(page.locator('[data-conversion-dialog]')).toBeVisible();
    await expect(page.locator('[data-conversion-before] u')).toHaveText('Underline survives recovery');
    await expect(page.locator('[data-conversion-diff]')).toContainText('<u>');
    await page.getByRole('button', {name: 'Keep original', exact: true}).click();
    await expect(editor(page).locator('u')).toHaveText('Underline survives recovery');
    await page.getByRole('tab', {name: 'Markdown', exact: true}).click();
    await page.getByRole('button', {name: 'Convert and keep recovery copy', exact: true}).click();
    await expect(page.locator('[data-markdown-editor]')).toHaveValue('Underline survives recovery');
    await page.reload();
    await page.getByRole('button', {name: 'Restore recovered content', exact: true}).click();
    await page.getByRole('button', {name: 'Return to content before conversion', exact: true}).click();
    await expect(editor(page).locator('u')).toHaveText('Underline survives recovery');
    await page.getByRole('button', {name: 'Save page', exact: true}).click();
    await expect(page.locator('[data-document-content] u')).toHaveText('Underline survives recovery');
});

test('conflicts retain the base through retry and recovery until an explicit resolution', async ({page, request}) => {
    const document = await create(request);
    await page.goto(pathFor(document) + '/edit');
    await editor(page).fill('Local document');
    await api(request, 'patch', 'pages/' + document.id, {base_version: 1, content_html: '<p>Server document</p>'});
    await page.getByRole('button', {name: 'Save page', exact: true}).click();
    await expect(page.locator('[data-conflict-panel]')).toBeVisible();
    await expect(page.locator('[name=base_version]')).toHaveValue('1');
    await expect(page.locator('[data-conflict-base]')).toContainText('Base document');
    await expect(page.locator('[data-conflict-local]')).toContainText('Local document');
    await expect(page.locator('[data-conflict-server]')).toContainText('Server document');
    await expect(page.getByRole('button', {name: 'Save page', exact: true})).toBeDisabled();
    await page.locator('[data-editor-form]').evaluate((form) => form.requestSubmit());
    expect((await api(request, 'get', 'pages/' + document.id)).version).toBe(2);
    await page.reload();
    await page.getByRole('button', {name: 'Restore recovered content', exact: true}).click();
    await expect(page.locator('[name=base_version]')).toHaveValue('1');
    await page.getByRole('button', {name: 'Save page', exact: true}).click();
    await expect(page.locator('[data-conflict-panel]')).toBeVisible();
    await page.locator('[data-resolve-conflict]').click();
    await expect(page.locator('[name=base_version]')).toHaveValue('2');
    await editor(page).fill('Server and local reviewed together');
    await api(request, 'patch', 'pages/' + document.id, {base_version: 2, content_html: '<p>Another concurrent update</p>'});
    await page.getByRole('button', {name: 'Save page', exact: true}).click();
    await expect(page.locator('[data-conflict-server]')).toContainText('Another concurrent update');
    await expect(page.locator('[name=base_version]')).toHaveValue('2');
    await page.locator('[data-resolve-conflict]').click();
    await page.getByRole('button', {name: 'Save page', exact: true}).click();
    await expect(page.locator('[data-document-content]')).toContainText('Server and local reviewed together');
    expect((await api(request, 'get', 'pages/' + document.id)).version).toBe(4);
});

for (const failure of ['network', '403', '422', '500']) {
    test(failure + ' preserves the document, metadata and original version for recovery and retry', async ({page, request}) => {
        const document = await create(request);
        await page.goto(pathFor(document) + '/edit');
        await editor(page).fill('Recoverable rich document');
        await editor(page).press('ControlOrMeta+A');
        await editor(page).press('ControlOrMeta+B');
        await page.locator('[name=tags]').fill('recoverable');
        await page.locator('[name=change_summary]').fill('Recoverable summary');
        if (failure === '422') await page.locator('[name=tags]').fill(Array.from({length: 31}, (_, index) => 'tag' + index).join(','));
        const endpoint = '**' + pathFor(document);
        await page.route(endpoint, async (route) => {
            if (failure === 'network') return route.abort('failed');
            if (failure === '500') return route.fulfill({status: 500, contentType: 'application/json', body: JSON.stringify({error: {message: 'Database unavailable'}})});
            if (failure === '403') return route.continue({postData: JSON.stringify({...route.request().postDataJSON(), editor_user_id: fixture().users[1].id})});
            return route.continue();
        });
        await page.getByRole('button', {name: 'Save page', exact: true}).click();
        await expect(state(page)).toContainText('retained');
        await expect(editor(page).locator('b,strong')).toHaveText('Recoverable rich document');
        await expect(page.locator('[name=base_version]')).toHaveValue('1');
        expect((await api(request, 'get', 'pages/' + document.id)).version).toBe(1);
        await page.unroute(endpoint);
        await page.reload();
        await page.getByRole('button', {name: 'Restore recovered content', exact: true}).click();
        await expect(editor(page).locator('b,strong')).toHaveText('Recoverable rich document');
        await expect(page.locator('[name=change_summary]')).toHaveValue('Recoverable summary');
        await page.locator('[name=tags]').fill('recoverable');
        await page.getByRole('button', {name: 'Save page', exact: true}).click();
        await expect(page.locator('[data-document-content]')).toContainText('Recoverable rich document');
    });
}

test('typing during an actual autosave keeps later content and metadata pending', async ({page, request}) => {
    const document = await create(request);
    const parent = await create(request, unique('Autosave parent'));
    await page.goto(pathFor(document) + '/edit');
    let release;
    const gate = new Promise((resolve) => { release = resolve; });
    let captured;
    const capturedRequest = new Promise((resolve) => { captured = resolve; });
    let count = 0;
    await page.route('**/editor-drafts', async (route) => {
        if (route.request().method() !== 'POST') return route.continue();
        const response = await route.fetch();
        if (++count === 1) { captured(route.request().postDataJSON()); await gate; }
        await route.fulfill({response});
    });
    await editor(page).fill('Earlier autosave');
    const earlier = await capturedRequest;
    await editor(page).fill('Later content stays');
    await page.locator('[name=title]').fill('Later title');
    await page.locator('[name=slug]').fill('later-' + Date.now());
    await page.locator('[name=parent_id]').selectOption(String(parent.id));
    await page.locator('[name=tags]').fill('later-tag');
    await page.locator('[name=change_summary]').fill('Later summary');
    release();
    await expect.poll(() => count).toBeGreaterThan(1);
    await expect(state(page)).toContainText('Draft saved');
    const response = await page.request.get('/spaces/' + fixture().spaceKey + '/editor-drafts?document_key=page:' + document.id);
    const draft = (await response.json()).data.drafts[0];
    expect(draft.sequence).toBeGreaterThan(earlier.draft_sequence);
    expect(draft.html).toContain('Later content stays');
    expect(draft.title).toBe('Later title');
    expect(draft.parentId).toBe(String(parent.id));
    expect(draft.tags).toBe('later-tag');
    expect(draft.changeSummary).toBe('Later summary');
    expect(draft.baseVersion).toBe(1);
    await page.reload();
    await page.getByRole('button', {name: 'Restore recovered content', exact: true}).click();
    await expect(editor(page)).toHaveText('Later content stays');
    await expect(page.locator('[name=tags]')).toHaveValue('later-tag');
});

test('tabs keep independent drafts, another account gets none, and new documents have distinct identities', async ({page, context, request}) => {
    const document = await create(request);
    await page.goto(pathFor(document) + '/edit');
    await page.locator('[name=title]').fill('First tab draft');
    await editor(page).fill('First tab content');
    const second = await context.newPage();
    second.on('dialog', (dialog) => dialog.accept());
    await second.goto(pathFor(document) + '/edit');
    await second.locator('[name=title]').fill('Second tab draft');
    await editor(second).fill('Second tab content');
    await expect(state(page)).toContainText('Draft saved');
    await expect(state(second)).toContainText('Draft saved');
    const draftUrl = '/spaces/' + fixture().spaceKey + '/editor-drafts?document_key=page:' + document.id;
    expect((await (await second.request.get(draftUrl)).json()).data.drafts).toHaveLength(2);
    await page.getByRole('button', {name: 'Save page', exact: true}).click();
    await expect(page.locator('[data-document-content]')).toContainText('First tab content');
    expect((await (await second.request.get(draftUrl)).json()).data.drafts).toHaveLength(1);
    await second.reload();
    await second.locator('[data-recovery-select]').selectOption({label: (await second.locator('[data-recovery-select] option').allTextContents()).find((label) => label.startsWith('Second tab draft'))});
    await second.getByRole('button', {name: 'Restore recovered content', exact: true}).click();
    await expect(editor(second)).toHaveText('Second tab content');
    await expect(second.locator('[name=base_version]')).toHaveValue('1');

    const account = await context.newPage();
    await account.goto('/');
    const csrf = await account.locator('form[action="/logout"] input[name="_token"]').inputValue();
    await account.request.post('/logout', {form: {_token: csrf}});
    await login(account, 1);
    await account.goto(pathFor(document) + '/edit');
    await expect(account.locator('[data-recovery-banner]')).toBeHidden();
    expect((await (await account.request.get(draftUrl)).json()).data.drafts).toHaveLength(0);
    await expect(second.getByRole('button', {name: 'Save page', exact: true})).toBeDisabled();
    await account.goto('/spaces/' + fixture().spaceKey + '/pages/create');
    const firstNew = account.url();
    await editor(account).fill('New document one');
    await account.goto('/spaces/' + fixture().spaceKey + '/pages/create');
    expect(account.url()).not.toBe(firstNew);
    await expect(account.locator('[data-recovery-banner]')).toBeHidden();
});

test('a second Visual form save retains backlinks and works at mobile width', async ({page, request}) => {
    const target = await create(request, unique('Backlink target'));
    const source = await create(request, unique('Backlink source'), {content_html: '<p>[[' + target.title + ']]</p>'});
    await page.setViewportSize({width: 390, height: 844});
    await page.goto(pathFor(source) + '/edit');
    await page.screenshot({path: 'storage/temp/editor-mobile.png'});
    await page.getByRole('button', {name: 'Save page', exact: true}).click();
    await expect(page.locator('[data-document-content] a')).toHaveText(target.title);
    await page.goto(pathFor(target));
    await expect(page.locator('#backlinks a').filter({hasText: source.title})).toBeVisible();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBeTruthy();
});

test('a session expiring during editing retains the document through sign-in and recovery', async ({page, request}) => {
    const document = await create(request);
    await page.goto(pathFor(document) + '/edit');
    await editor(page).fill('Content before session expiry');
    await page.locator('[name=change_summary]').fill('Expired session recovery');
    const csrf = await page.locator('form[action="/logout"] input[name="_token"]').inputValue();
    await page.request.post('/logout', {form: {_token: csrf}});
    await page.getByRole('button', {name: 'Save page', exact: true}).click();
    await expect(state(page)).toContainText('Authentication required');
    await expect(editor(page)).toHaveText('Content before session expiry');
    await expect(page.locator('[name=base_version]')).toHaveValue('1');
    await login(page);
    await page.goto(pathFor(document) + '/edit');
    await page.getByRole('button', {name: 'Restore recovered content', exact: true}).click();
    await expect(editor(page)).toHaveText('Content before session expiry');
    await expect(page.locator('[name=change_summary]')).toHaveValue('Expired session recovery');
    await page.getByRole('button', {name: 'Save page', exact: true}).click();
    await expect(page.locator('[data-document-content]')).toContainText('Content before session expiry');
});

test('typing during conversion keeps the current source until the latest conversion is requested', async ({page, request}) => {
    const document = await create(request);
    await page.goto(pathFor(document) + '/edit');
    await editor(page).fill('Source at conversion start');
    let release;
    const gate = new Promise((resolve) => { release = resolve; });
    let signal;
    const converted = new Promise((resolve) => { signal = resolve; });
    const endpoint = '**/editor-convert';
    await page.route(endpoint, async (route) => {
        const response = await route.fetch();
        signal();
        await gate;
        await route.fulfill({response});
    });
    await page.getByRole('tab', {name: 'Markdown', exact: true}).click();
    await converted;
    await editor(page).fill('Latest source while conversion was pending');
    release();
    await expect(state(page)).toContainText('document changed during conversion');
    await expect(page.getByRole('tab', {name: 'Visual', exact: true})).toHaveAttribute('aria-selected', 'true');
    await expect(editor(page)).toHaveText('Latest source while conversion was pending');
    await page.unroute(endpoint);
    await page.getByRole('tab', {name: 'Markdown', exact: true}).click();
    await expect(page.locator('[data-markdown-editor]')).toHaveValue('Latest source while conversion was pending');
    await page.getByRole('button', {name: 'Save page', exact: true}).click();
    await expect(page.locator('[data-document-content]')).toContainText('Latest source while conversion was pending');
});

test('a new-page server draft restores all metadata after local storage is cleared', async ({page, context, request}) => {
    const parent = await create(request, unique('New page parent'));
    await page.goto('/spaces/' + fixture().spaceKey + '/pages/create');
    const originalUrl = page.url();
    const documentKey = 'new:' + new URL(originalUrl).searchParams.get('document');
    const title = unique('Server recovered new page');
    await page.locator('[name=title]').fill(title);
    await page.locator('[name=slug]').fill('new-recovered-' + Date.now());
    await page.locator('[name=parent_id]').selectOption(String(parent.id));
    await page.locator('[name=tags]').fill('new-draft-tag');
    await page.locator('[name=change_summary]').fill('New draft summary');
    await editor(page).fill('New page rich content');
    await editor(page).press('ControlOrMeta+A');
    await editor(page).press('ControlOrMeta+B');
    await expect(state(page)).toContainText('Draft saved');
    await page.evaluate(() => localStorage.clear());
    // A new tab has no unload handler that could recreate the cleared local copy.
    page = await context.newPage();
    await page.goto(originalUrl);
    await expect(page).toHaveURL(originalUrl);
    expect(await page.evaluate(() => Object.keys(localStorage).filter((key) => key.startsWith('openwiki:draft:v2:')))).toEqual([]);
    await page.getByRole('button', {name: 'Restore recovered content', exact: true}).click();
    await expect(page.locator('[name=title]')).toHaveValue(title);
    await expect(page.locator('[name=parent_id]')).toHaveValue(String(parent.id));
    await expect(page.locator('[name=tags]')).toHaveValue('new-draft-tag');
    await expect(page.locator('[name=change_summary]')).toHaveValue('New draft summary');
    await expect(editor(page).locator('b,strong')).toHaveText('New page rich content');
    await expect(page.locator('[name=base_version]')).toHaveValue('0');
    await page.getByRole('button', {name: 'Create page', exact: true}).click();
    await expect(page.locator('[data-document-content]')).toContainText('New page rich content');
    const drafts = await page.request.get('/spaces/' + fixture().spaceKey + '/editor-drafts?document_key=' + encodeURIComponent(documentKey));
    expect((await drafts.json()).data.drafts).toEqual([]);
});

test('later edits during first-page submission remain in the editor with the confirmed version', async ({page, request}) => {
    await page.goto('/spaces/' + fixture().spaceKey + '/pages/create');
    const title = unique('First submitted title');
    await page.locator('[name=title]').fill(title);
    await editor(page).fill('First submitted content');
    let release;
    const gate = new Promise((resolve) => { release = resolve; });
    let captured;
    const saved = new Promise((resolve) => { captured = resolve; });
    const endpoint = '**/spaces/' + fixture().spaceKey + '/pages';
    await page.route(endpoint, async (route) => {
        const response = await route.fetch();
        captured((await response.json()).data.page);
        await gate;
        await route.fulfill({response});
    });
    await page.getByRole('button', {name: 'Create page', exact: true}).click();
    const document = await saved;
    await editor(page).fill('Later content during save');
    await page.locator('[name=tags]').fill('later-during-submit');
    release();
    await expect(page.locator('[name=base_version]')).toHaveValue('1');
    await expect(editor(page)).toHaveText('Later content during save');
    await expect(page.getByRole('button', {name: 'Save page', exact: true})).toBeEnabled();
    expect((await api(request, 'get', 'pages/' + document.id)).content_html).toContain('First submitted content');
    await page.unroute(endpoint);
    expect(await page.locator('[data-editor-form]').getAttribute('action')).toBe(pathFor(document));
    expect(await page.locator('[data-editor-form]').evaluate((form) => [...form.elements].filter((element) => element.validity && !element.validity.valid).map((element) => ({name: element.name, message: element.validationMessage})))).toEqual([]);
    await page.getByRole('button', {name: 'Save page', exact: true}).click();
    await expect(page.locator('[data-document-content]')).toContainText('Later content during save');
    expect((await api(request, 'get', 'pages/' + document.id)).version).toBe(2);
});

test('conversion recovery is available in revision history after the converted page is saved', async ({page, request}) => {
    const document = await create(request);
    await page.goto(pathFor(document) + '/edit');
    await editor(page).fill('Original rich formatting');
    await editor(page).press('ControlOrMeta+A');
    await editor(page).press('ControlOrMeta+U');
    await page.getByRole('tab', {name: 'Markdown', exact: true}).click();
    await page.getByRole('button', {name: 'Convert and keep recovery copy', exact: true}).click();
    await page.getByRole('button', {name: 'Save page', exact: true}).click();
    await expect(page.locator('[data-document-content]')).toContainText('Original rich formatting');
    await page.getByRole('link', {name: 'History', exact: true}).click();
    await page.getByRole('button', {name: 'Restore content before conversion', exact: true}).click();
    await expect(page.locator('[data-document-content] u')).toHaveText('Original rich formatting');
    expect((await api(request, 'get', 'pages/' + document.id)).version).toBe(3);
});

test('recovering another tab cannot delete that tab’s later recovery copy', async ({page, context, request}) => {
    const document = await create(request);
    await page.goto(pathFor(document) + '/edit');
    await editor(page).fill('Recovery snapshot from tab one');
    await expect(state(page)).toContainText('Draft saved');
    const second = await context.newPage();
    second.on('dialog', (dialog) => dialog.accept());
    await second.goto(pathFor(document) + '/edit');
    await second.getByRole('button', {name: 'Restore recovered content', exact: true}).click();
    await expect(editor(second)).toHaveText('Recovery snapshot from tab one');
    await editor(page).fill('Newer work in tab one');
    await expect(state(page)).toContainText('Draft saved');
    await second.getByRole('button', {name: 'Save page', exact: true}).click();
    await expect(second.locator('[data-document-content]')).toContainText('Recovery snapshot from tab one');
    const response = await second.request.get('/spaces/' + fixture().spaceKey + '/editor-drafts?document_key=page:' + document.id);
    expect((await response.json()).data.drafts.some((draft) => draft.html.includes('Newer work in tab one'))).toBeTruthy();
    expect(await second.evaluate(() => Object.keys(localStorage).some((key) => key.startsWith('openwiki:draft:v2:') && localStorage.getItem(key).includes('Newer work in tab one')))).toBeTruthy();
});
