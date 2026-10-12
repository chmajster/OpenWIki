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
test.beforeEach(async ({page}) => {
    await page.goto('/login'); await page.locator('[name=identifier]').fill(fixture().users[0].username);
    await page.locator('[name=password]').fill(fixture().users[0].password);
    await page.locator('form[action="/login"] button[type=submit]').click(); await expect(page).toHaveURL('/');
});

for (const width of [360, 1440]) test('link labels, editing, removal and keyboard focus round trip at ' + width + 'px', async ({page, request}) => {
    await page.setViewportSize({width, height: 900});
    const doc = await create(request, '<p>Original label</p><p>Keep this paragraph</p>');
    await page.goto(path(doc) + '/edit'); await editor(page).press('ControlOrMeta+Home');
    await editor(page).press('Shift+End');
    await expect.poll(() => page.evaluate(() => getSelection().toString())).toBe('Original label');
    await editor(page).press('ControlOrMeta+k');
    await expect(page.locator('[data-link-text]')).toHaveValue('Original label');
    await page.locator('[data-link-text]').fill('Updated label'); await page.locator('[data-link-url]').fill('https://example.test/first');
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
