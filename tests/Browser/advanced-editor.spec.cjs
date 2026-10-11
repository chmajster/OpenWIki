const {test, expect} = require('@playwright/test');
const fs = require('node:fs');
const fixture = () => JSON.parse(fs.readFileSync('storage/temp/editor-browser.json', 'utf8'));
const editor = page => page.locator('[data-visual-editor]');
const api = async (request, method, path, data) => {
    const response = await request[method]('/api/v1/' + path, {headers: {Authorization: 'Bearer ' + fixture().token}, data});
    expect(response.ok(), await response.text()).toBeTruthy(); return (await response.json()).data;
};
const create = (request, html = '<p>Dokument</p>') => api(request, 'post', 'pages', {space_id: fixture().spaceId, title: 'Advanced-' + Date.now() + '-' + Math.random().toString(16).slice(2), content_format: 'visual', content_html: html});
const path = doc => '/spaces/' + fixture().spaceKey + '/pages/' + doc.slug;
const login = async (page, index = 0) => {
    await page.goto('/login'); await page.locator('[name=identifier]').fill(fixture().users[index].username);
    await page.locator('[name=password]').fill(fixture().users[index].password);
    await page.locator('form[action="/login"] button[type=submit]').click(); await expect(page).toHaveURL('/');
};
const paste = async (page, text, html = '') => {
    await editor(page).focus();
    await editor(page).evaluate((element, {text, html}) => {
        const clipboardData = new DataTransfer(); clipboardData.setData('text/plain', text); if (html) clipboardData.setData('text/html', html);
        element.dispatchEvent(new ClipboardEvent('paste', {bubbles: true, cancelable: true, clipboardData}));
    }, {text, html});
};
const errors = [];
test.beforeEach(async ({page}) => { errors.length = 0; page.on('pageerror', error => errors.push(error.message)); page.on('dialog', dialog => dialog.accept()); await login(page); });
test.afterEach(() => expect(errors).toEqual([]));

test('controlled formatting, code whitespace and advanced blocks round trip with immutable history', async ({page, request}) => {
    const source = '<h2>Instrukcja</h2><p data-align="center"><span data-text-color="blue" data-highlight="yellow">Kolor</span> x<sup>2</sup> H<sub>2</sub>O</p>'
        + '<details data-disclosure="true"><summary>Procedura</summary><div data-disclosure-content="true"><p>Kroki</p></div></details>'
        + '<div data-columns="3"><div data-column="true"><p>Pierwsza</p></div><div data-column="true"><p>Druga</p></div><div data-column="true"><p>Trzecia</p></div></div>'
        + '<aside data-callout="success"><p>Gotowe</p></aside><pre data-line-numbers="true" data-code-wrap="true"><code data-language="powershell">\t$var = &quot;&lt;tag&gt;&quot;\n  Write-Host $var</code></pre>';
    const doc = await create(request, source); await page.goto(path(doc) + '/edit');
    await expect(page.locator('[data-model-review]')).toBeHidden();
    await expect(editor(page).locator('sup')).toHaveText('2'); await expect(editor(page).locator('[data-columns]')).toHaveAttribute('data-columns', '3');
    await expect(editor(page).locator('.code-line-numbers')).toHaveText('1\n2');
    await expect(page.locator('[data-editor-outline]')).toContainText('Instrukcja');
    await editor(page).locator('code').click(); await expect(page.locator('[data-code-tools]')).toBeVisible();
    await expect(page.locator('input[data-code-wrap]')).toBeChecked(); await page.locator('input[data-code-wrap]').uncheck();
    await expect(editor(page).locator('pre')).toHaveAttribute('data-code-wrap', 'false');
    await page.locator('[data-save-button]').click(); await expect(page.locator('[data-document-content]')).toContainText('Gotowe');
    const saved = await api(request, 'get', 'pages/' + doc.id); expect(saved.content_html).toContain('data-code-wrap="false"'); expect(saved.content_html).toContain('\t$var');
    expect(saved.document.source).toBe(saved.content_html);
    await page.goto(path(doc) + '/edit'); await expect(page.locator('[data-model-review]')).toBeHidden();
    await expect(editor(page).locator('[data-text-color=blue]')).toContainText('Kolor');
    await page.goto(path(doc) + '/revisions/1'); await expect(page.locator('[data-revision-content] [data-columns]')).toBeVisible();
    await expect(page.locator('[data-revision-content] code')).toContainText('Write-Host');
    expect((await api(request, 'get', 'pages/' + doc.id)).version).toBe(2);
});

test('table operations retain merged content, widths and pasted spreadsheet cells', async ({page, request}) => {
    const doc = await create(request, '<table><tbody><tr><th colspan="2">Wspólny nagłówek</th></tr><tr><td>A</td><td>B</td></tr></tbody></table><p>Koniec</p>');
    await page.goto(path(doc) + '/edit'); await editor(page).locator('th').click();
    await page.locator('[data-table-command=splitCell]').click(); await expect(editor(page).locator('th')).toHaveCount(2);
    await editor(page).locator('td').first().click(); await page.locator('[data-cell-color]').selectOption('blue');
    await page.locator('[data-column-width]').fill('240'); await page.locator('[data-column-width-apply]').click();
    await page.locator('[data-table-move="column:1"]').click();
    await expect(editor(page).locator('td').first()).toHaveText('B'); await expect(editor(page).locator('td').nth(1)).toHaveText('A');
    await editor(page).locator('td').first().click(); await page.keyboard.press('Tab'); await page.keyboard.type(' navigation');
    await editor(page).locator('p').last().click(); await page.keyboard.press('End'); await page.keyboard.press('Enter'); await paste(page, 'Nazwa\tWynik\nTest\tOK');
    await expect(editor(page).locator('table')).toHaveCount(2); await expect(editor(page).locator('table').nth(1)).toContainText('OK');
    await page.locator('[data-save-button]').click(); await expect(page.locator('[data-document-content] table')).toHaveCount(2);
    await page.goto(path(doc) + '/edit'); await expect(page.locator('[data-model-review]')).toBeHidden();
    await expect(editor(page).locator('[data-cell-color=blue]')).toHaveAttribute('colwidth', '240');
    await expect(editor(page).locator('table').nth(1).locator('tr')).toHaveCount(2);
});

test('Mermaid preview rejects active input, keeps invalid source and displays safe diagrams after saving', async ({page, request}) => {
    const doc = await create(request); await page.goto(path(doc) + '/edit'); await page.locator('.format-menu summary').click(); await page.locator('[data-open-diagram]').click();
    await page.locator('[data-diagram-source]').fill('flowchart LR\n A --> B'); await page.locator('[data-diagram-render]').click();
    await expect(page.locator('[data-diagram-status]')).toHaveAttribute('data-valid', 'true', {timeout: 15000});
    await page.locator('[data-diagram-source]').fill('flowchart LR\n click A "https://example.test"'); await page.locator('[data-diagram-render]').click();
    await expect(page.locator('[data-diagram-status]')).toHaveAttribute('data-valid', 'false');
    await page.locator('[data-diagram-source]').fill('broken diagram source'); await page.locator('[data-diagram-render]').click();
    await expect(page.locator('[data-diagram-status]')).toHaveAttribute('data-valid', 'false'); await page.locator('[data-diagram-insert]').click();
    await expect(editor(page).locator('[data-diagram]')).toHaveText('broken diagram source');
    await page.locator('[data-save-button]').click(); await expect(page.locator('[data-document-content] [data-diagram] code')).toHaveText('broken diagram source');
    await expect(page.locator('[data-document-content] .diagram-status')).toContainText('Błąd');
    await page.goto(path(doc) + '/edit'); await editor(page).locator('[data-diagram]').click(); await page.locator('.format-menu summary').click(); await page.locator('[data-open-diagram]').click();
    await expect(page.locator('[data-diagram-source]')).toHaveValue('broken diagram source');
    await page.locator('[data-diagram-source]').fill('flowchart LR\n A --> B'); await page.locator('[data-diagram-insert]').click(); await page.locator('[data-save-button]').click();
    await expect(page.locator('[data-document-content] .diagram-status')).toHaveAttribute('data-valid', 'true', {timeout: 15000});
    await expect(page.locator('[data-document-content] iframe')).toHaveAttribute('sandbox', 'allow-scripts');
});

test('slash, mentions, block operations, find and focus work through the keyboard', async ({page, request}) => {
    const doc = await create(request); await page.goto(path(doc) + '/edit'); await editor(page).fill('/nag');
    await expect(page.locator('[data-command-menu]')).toContainText('Nagłówek H1'); await editor(page).press('Enter'); await page.keyboard.type('Plan');
    await expect(editor(page).locator('h1')).toHaveText('Plan'); await editor(page).press('End'); await editor(page).press('Enter'); await page.keyboard.type('alpha alpha');
    await editor(page).press('ControlOrMeta+f'); await page.locator('[data-find-text]').fill('alpha'); await page.locator('[data-replace-text]').fill('beta'); await page.locator('[data-replace-all]').click();
    await expect(editor(page)).toContainText('beta beta'); await page.locator('[data-find-dialog] [data-dialog-close]').click();
    await editor(page).press('ControlOrMeta+z'); await expect(editor(page)).toContainText('alpha alpha'); await editor(page).press('ControlOrMeta+Shift+z');
    await page.locator('[data-block-action=duplicate]').click(); await expect(editor(page)).toContainText('beta betabeta beta');
    await editor(page).press('Alt+Shift+ArrowUp'); await page.locator('[data-block-action=delete]').click();
    await editor(page).press('ControlOrMeta+End'); await editor(page).press('Enter'); await page.keyboard.type('@' + fixture().users[0].username.slice(0, 18));
    await expect(page.locator('[data-command-menu]')).toContainText('@' + fixture().users[0].username); await editor(page).press('Enter');
    await expect(editor(page)).toContainText('@' + fixture().users[0].username);
    await page.locator('[data-focus-mode]').click(); await expect(page.locator('[data-wiki-editor]')).toHaveClass(/is-focused/);
    await editor(page).press('ControlOrMeta+s'); await expect(page.locator('[data-save-state]')).toHaveAttribute('data-state', 'server');
    await expect(page.locator('[data-focus-state]')).toContainText('Zapisano'); await page.locator('[data-focus-mode]').click();
    await page.locator('[data-save-button]').click(); await expect(page.locator('[data-document-content]')).toContainText('@' + fixture().users[0].username);
});

test('table size picker, cell merging, disclosure and column insertion have functioning model commands', async ({page, request}) => {
    const doc = await create(request); await page.goto(path(doc) + '/edit'); await editor(page).press('ControlOrMeta+End'); await editor(page).press('Enter');
    await page.locator('[data-insert-table]').click(); await page.locator('[data-table-rows]').fill('2'); await page.locator('[data-table-cols]').fill('2'); await page.locator('[data-table-header]').uncheck(); await page.locator('[data-table-insert]').click();
    const cells = editor(page).locator('td'); await cells.nth(0).click(); await page.keyboard.type('Left'); await cells.nth(1).click(); await page.keyboard.type('Right');
    await cells.nth(0).click(); await cells.nth(1).click({modifiers: ['Shift']}); await expect(page.locator('[data-table-command=mergeCells]')).toBeEnabled(); await page.locator('[data-table-command=mergeCells]').click();
    await expect(editor(page).locator('td[colspan="2"]')).toContainText('Left'); await expect(editor(page).locator('td[colspan="2"]')).toContainText('Right');
    await editor(page).press('ControlOrMeta+End'); await editor(page).press('ArrowDown'); await page.locator('.format-menu summary').click(); await page.locator('[data-open-block]').click(); await page.locator('[data-block-title]').fill('Nowa sekcja'); await page.locator('[data-block-insert]').click();
    await expect(editor(page).locator('summary')).toHaveText('Nowa sekcja'); await page.locator('[data-open-block]').click(); await page.locator('[data-block-kind]').selectOption('columns2'); await page.locator('[data-block-insert]').click(); await expect(editor(page).locator('[data-columns]')).toHaveAttribute('data-columns', '2');
    await page.locator('[data-save-button]').click(); await expect(page.locator('[data-document-content] td[colspan="2"]')).toContainText('Right');
    await page.goto(path(doc) + '/edit'); await expect(page.locator('[data-model-review]')).toBeHidden(); await expect(editor(page).locator('summary')).toHaveText('Nowa sekcja'); await expect(editor(page).locator('td[colspan="2"]')).toContainText('Left');
});

test('an overnested external paste is rejected before replacing the current document', async ({page, request}) => {
    const doc = await create(request); await page.goto(path(doc) + '/edit'); await editor(page).fill('Keep the document'); await editor(page).press('ControlOrMeta+a');
    await paste(page, 'too deep', '<div>'.repeat(110) + '<p>too deep</p>' + '</div>'.repeat(110));
    await expect(editor(page)).toHaveText('Keep the document'); await expect(page.locator('[data-block-feedback]')).toContainText('przekracza limit');
});

for (const width of [360, 768, 1440]) test('workspace, wide tables and keyboard save at ' + width + 'px', async ({page, request}) => {
    await page.setViewportSize({width, height: 900});
    const doc = await create(request, '<h2>Telefon i komputer</h2><div data-columns="2"><div data-column="true"><p>Pierwsza</p></div><div data-column="true"><p>Druga</p></div></div><table><tr><td colwidth="1000">Szeroka tabela</td><td colwidth="1000">Druga komórka</td></tr></table>');
    await page.goto(path(doc) + '/edit'); await expect(page.locator('[data-model-review]')).toBeHidden();
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBeTruthy();
    await editor(page).press('ControlOrMeta+End'); await editor(page).press('Enter'); await page.keyboard.type('Najnowsza treść ' + width); await editor(page).press('ControlOrMeta+s');
    await expect(page.locator('[data-save-state]')).toHaveAttribute('data-state', 'server');
    await page.screenshot({path: 'storage/temp/editor-' + width + '-saved.png', fullPage: true});
    await page.locator('[data-save-button]').click(); await expect(page.locator('[data-document-content]')).toContainText('Najnowsza treść ' + width);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBeTruthy();
    await page.screenshot({path: 'storage/temp/document-' + width + '.png', fullPage: true});
});
