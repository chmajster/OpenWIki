const {test, expect} = require('@playwright/test');
const fs = require('node:fs');
const fixture = () => JSON.parse(fs.readFileSync('storage/temp/editor-browser.json', 'utf8'));
const editor = page => page.locator('[data-visual-editor]');
const api = async (request, method, path, data) => {
    const response = await request[method]('/api/v1/' + path, {headers: {Authorization: 'Bearer ' + fixture().token}, data});
    expect(response.ok(), await response.text()).toBeTruthy(); return (await response.json()).data;
};
const create = (request, html) => api(request, 'post', 'pages', {space_id: fixture().spaceId,
    title: 'Daily-' + Date.now() + '-' + Math.random().toString(16).slice(2), content_format: 'visual', content_html: html});
const path = doc => '/spaces/' + fixture().spaceKey + '/pages/' + doc.slug;
const paste = async (page, text, html = '') => {
    await editor(page).evaluate((element, {text, html}) => {
        const clipboardData = new DataTransfer(); clipboardData.setData('text/plain', text);
        if (html) clipboardData.setData('text/html', html);
        element.dispatchEvent(new ClipboardEvent('paste', {bubbles: true, cancelable: true, clipboardData}));
    }, {text, html});
};
const errors = [];
test.beforeEach(async ({page}) => {
    errors.length = 0; page.on('pageerror', error => errors.push(error.message));
    await page.goto('/login'); await page.locator('[name=identifier]').fill(fixture().users[0].username);
    await page.locator('[name=password]').fill(fixture().users[0].password);
    await page.locator('form[action="/login"] button[type=submit]').click(); await expect(page).toHaveURL('/');
});
test.afterEach(() => expect(errors).toEqual([]));

for (const width of [360, 1440]) test('link labels, editing, removal and keyboard focus round trip at ' + width + 'px', async ({page, request}) => {
    await page.setViewportSize({width, height: 900});
    const doc = await create(request, '<p>Original label</p><p>Keep this paragraph</p>');
    await page.goto(path(doc) + '/edit'); await editor(page).press('ControlOrMeta+Home');
    await editor(page).press('Shift+End');
    await expect.poll(() => page.evaluate(() => getSelection().toString())).toBe('Original label');
    await editor(page).press('ControlOrMeta+k');
    await expect(page.locator('[data-link-text]')).toHaveValue('Original label');
    await page.locator('[data-link-text]').fill('Updated label'); await page.locator('[data-link-url]').fill('https://example.test/first');
    await page.screenshot({path: 'storage/temp/daily-editor-link-' + width + '.png'});
    await page.locator('[data-link-insert]').click();
    await expect(editor(page).locator('a')).toHaveText('Updated label'); await expect(editor(page)).toBeFocused();
    await editor(page).press('ControlOrMeta+z'); await expect(editor(page).locator('a')).toHaveCount(0);
    await expect(editor(page)).toContainText('Original label'); await editor(page).press('ControlOrMeta+Shift+z');
    await editor(page).press('ControlOrMeta+Home'); await editor(page).press('ArrowRight'); await editor(page).press('ControlOrMeta+k');
    await expect(page.locator('[data-link-text]')).toHaveValue('Updated label');
    await expect(page.locator('[data-link-url]')).toHaveValue('https://example.test/first');
    await page.locator('[data-link-text]').fill('Final label'); await page.locator('[data-link-url]').fill('javascript:alert(1)');
    await page.locator('[data-link-insert]').click(); await expect(page.locator('[data-link-error]')).not.toBeEmpty();
    await expect(editor(page).locator('a')).toHaveText('Updated label');
    await page.locator('[data-link-url]').fill('https://example.test/final'); await page.locator('[data-link-insert]').click();
    await expect(editor(page).locator('a')).toHaveCount(1); await expect(editor(page).locator('a')).toHaveText('Final label');
    await page.locator('[data-save-button]').click(); await expect(page.locator('[data-document-content] a[href="https://example.test/final"]')).toHaveText('Final label');
    await page.goto(path(doc) + '/edit'); await editor(page).press('ControlOrMeta+Home'); await editor(page).press('ArrowRight');
    await editor(page).press('ControlOrMeta+k'); await page.locator('[data-link-dialog] [data-dialog-close]').click();
    await expect(editor(page)).toBeFocused(); await editor(page).press('ControlOrMeta+k'); await page.locator('[data-link-remove]').click();
    await expect(editor(page).locator('a')).toHaveCount(0); await expect(editor(page)).toContainText('Final label');
    await page.locator('[data-save-button]').click(); await expect(page.locator('[data-document-content]')).toContainText('Final label');
    await page.goto(path(doc) + '/edit');
    await expect(editor(page).locator('a')).toHaveCount(0); await expect(editor(page)).toContainText('Keep this paragraph');
    expect((await api(request, 'get', 'pages/' + doc.id)).content_html).toContain('Final label');
});

test('changing a link destination keeps existing inline formatting and draft metadata', async ({page, request}) => {
    const doc = await create(request, '<p><a href="https://example.test/old"><strong>Bold label</strong></a> after</p>');
    await page.goto(path(doc) + '/edit'); await page.locator('[name=change_summary]').fill('Updated destination');
    await editor(page).press('ControlOrMeta+Home'); await editor(page).press('ArrowRight'); await editor(page).press('ControlOrMeta+k');
    await expect(page.locator('[data-link-text]')).toHaveValue('Bold label');
    await page.locator('[data-link-url]').fill('https://example.test/new'); await page.locator('[data-link-insert]').click();
    await expect(editor(page).locator('a')).toHaveCount(1); await expect(editor(page).locator('a strong')).toHaveText('Bold label');
    await editor(page).press('ControlOrMeta+s'); await expect(page.locator('[data-save-state]')).toHaveAttribute('data-state', 'server');
    await page.locator('[data-save-button]').click(); const saved = await api(request, 'get', 'pages/' + doc.id);
    expect(saved.content_html).toContain('href="https://example.test/new"'); expect(saved.content_html).toContain('<strong>Bold label</strong>');
    await page.goto(path(doc) + '/edit'); await expect(editor(page).locator('a strong')).toHaveText('Bold label');
});

test('renaming an uploaded file link preserves protected attachment metadata through publication', async ({page, request}) => {
    const doc = await create(request, '<p>File documentation</p>'); await page.goto(path(doc) + '/edit');
    await editor(page).press('ControlOrMeta+End'); await editor(page).press('Enter');
    await page.locator('[data-attachment-file]').setInputFiles({name: 'runbook.txt', mimeType: 'text/plain', buffer: Buffer.from('Documented procedure\n')});
    const link = editor(page).locator('a[data-draft-file-id]'); await expect(link).toHaveCount(1);
    const size = await link.getAttribute('data-file-size'); const type = await link.getAttribute('data-file-type');
    expect(size).not.toBeNull(); expect(type).not.toBeNull();
    await link.click(); await editor(page).press('Home'); await editor(page).press('ArrowRight'); await editor(page).press('ControlOrMeta+k');
    await expect(page.locator('[data-link-url]')).toHaveValue(await link.getAttribute('href'));
    await page.locator('[data-link-text]').fill('Protected runbook');
    await page.locator('[data-link-insert]').click(); await expect(link).toHaveText('Protected runbook');
    await expect(link).toHaveAttribute('data-file-size', size); await expect(link).toHaveAttribute('data-file-type', type);
    await page.locator('[data-save-button]').click(); const savedLink = page.locator('[data-document-content] a[data-attachment-id]');
    await expect(savedLink).toHaveText('Protected runbook'); const href = await savedLink.getAttribute('href');
    expect((await page.request.get(href)).status()).toBe(200); expect((await request.get(href, {maxRedirects: 0})).status()).toBe(404);
    await page.goto(path(doc) + '/edit'); const reopened = editor(page).locator('a[data-attachment-id]');
    await expect(reopened).toHaveText('Protected runbook'); await expect(reopened).toHaveAttribute('data-file-size', size);
    await expect(reopened).toHaveAttribute('data-file-type', type);
});

test('oversized spreadsheet and plain text paste preserve the selection and recoverable document', async ({page, request}) => {
    const doc = await create(request, '<p>Retain this document</p>'); await page.goto(path(doc) + '/edit');
    await editor(page).press('ControlOrMeta+a'); await paste(page, Array(51).fill('Column A\tColumn B').join('\n'));
    await expect(editor(page)).toHaveText('Retain this document'); await expect(page.locator('[data-block-feedback]')).toContainText('limit');
    await paste(page, 'x'.repeat(2000001)); await expect(editor(page)).toHaveText('Retain this document');
    await expect(page.locator('[data-block-feedback]')).toContainText('limit');
    await paste(page, 'Safe replacement'); await expect(editor(page)).toHaveText('Safe replacement');
    await editor(page).press('ControlOrMeta+z'); await expect(editor(page)).toHaveText('Retain this document');
});

test('editor shortcuts respect metadata fields and the active Markdown source', async ({page, request}) => {
    const doc = await create(request, '<p>Visual source</p>'); await page.goto(path(doc) + '/edit');
    await page.locator('[name=title]').focus(); await page.keyboard.press('ControlOrMeta+k');
    await expect(page.locator('[data-link-dialog]')).not.toBeVisible(); await expect(page.locator('#global-search')).toBeFocused();
    await page.getByRole('tab', {name: 'Markdown', exact: true}).click(); await page.locator('[data-markdown-editor]').fill('## Current Markdown');
    await page.locator('[data-markdown-editor]').press('ControlOrMeta+k');
    await expect(page.locator('[data-link-dialog]')).not.toBeVisible(); await expect(page.locator('#global-search')).toBeFocused();
    await expect(page.locator('[data-markdown-editor]')).toHaveValue('## Current Markdown');
    await page.locator('[data-save-button]').click(); await expect(page.locator('[data-document-content] h2')).toHaveText('Current Markdown');
});

test('a late mention response cannot reopen a dismissed menu or use an old selection', async ({page, request}) => {
    const doc = await create(request, '<p>Keep the document</p>'); await page.goto(path(doc) + '/edit');
    let release, arrived, delayed, requested;
    const holdNext = () => {
        delayed = new Promise(resolve => { release = resolve; });
        requested = new Promise(resolve => { arrived = resolve; });
    };
    holdNext();
    await page.route('**/editor-users?*', async route => {
        const response = await route.fetch(); arrived(); await delayed; await route.fulfill({response});
    });
    await editor(page).press('ControlOrMeta+End'); await page.keyboard.type(' @' + fixture().users[0].username.slice(0, 18));
    await requested; await editor(page).press('Escape');
    const returned = page.waitForResponse(response => response.url().includes('/editor-users?'));
    release(); await returned;
    await expect(page.locator('[data-command-menu]')).toBeHidden();
    holdNext(); await editor(page).press('ControlOrMeta+End'); await requested;
    await editor(page).press('ControlOrMeta+Home'); await page.keyboard.type('New start ');
    const secondReturned = page.waitForResponse(response => response.url().includes('/editor-users?'));
    release(); await secondReturned; await page.unroute('**/editor-users?*');
    await expect(page.locator('[data-command-menu]')).toBeHidden();
    await page.locator('[data-save-button]').click(); await expect(page.locator('[data-document-content]')).toContainText('New start Keep the document');
});

test('list indentation tools show when the selected list item can actually move', async ({page, request}) => {
    const doc = await create(request, '<p>Plain text</p><ul><li>First item</li><li>Second item</li></ul>');
    await page.goto(path(doc) + '/edit'); await editor(page).press('ControlOrMeta+Home');
    await expect(page.locator('[data-rich-command=sink]')).toBeDisabled(); await expect(page.locator('[data-rich-command=lift]')).toBeDisabled();
    await editor(page).locator('li').first().click(); await expect(page.locator('[data-rich-command=sink]')).toBeDisabled();
    await editor(page).locator('li').nth(1).click(); await expect(page.locator('[data-rich-command=sink]')).toBeEnabled();
    await page.locator('[data-rich-command=sink]').click(); await expect(editor(page).locator('ul ul li')).toHaveText('Second item');
    await expect(page.locator('[data-rich-command=lift]')).toBeEnabled(); await page.locator('[data-rich-command=lift]').click();
    await expect(editor(page).locator('ul ul')).toHaveCount(0); await page.locator('[data-save-button]').click();
    await expect(page.locator('[data-document-content] li')).toHaveCount(2);
});
