const {test, expect} = require('@playwright/test');
const fs = require('node:fs');
const fixture = () => JSON.parse(fs.readFileSync('storage/temp/editor-browser.json', 'utf8'));
const editor = page => page.locator('[data-visual-editor]');
const unique = name => name + ' ' + Date.now() + Math.random().toString(16).slice(2, 6);
async function login(page, locale = 'pl') {
    await page.context().addCookies([{name: 'openwiki_locale', value: locale, url: 'http://127.0.0.1:8765'}]);
    await page.goto('/login'); await page.locator('[name=identifier]').fill(fixture().users[0].username); await page.locator('[name=password]').fill(fixture().users[0].password);
    await page.locator('form[action="/login"] [type=submit]').click(); await expect(page).toHaveURL('/');
}
async function createPage(request) {
    const response = await request.post('/api/v1/pages', {headers: {Authorization: 'Bearer ' + fixture().token}, data: {space_id: fixture().spaceId, title: unique('Kopia dokumentacji zespołu ' + 'Długi tytuł '.repeat(8)), content_html: '<p>Published document stays intact.</p>', status: 'published'}});
    expect(response.ok(), await response.text()).toBeTruthy(); return (await response.json()).data;
}
async function saveDraft(page, text) {
    await editor(page).fill(text); await editor(page).press('ControlOrMeta+s');
    await expect(page.locator('[data-save-state]')).toHaveAttribute('data-state', 'server');
}
const bars = page => page.evaluate(() => ['.topbar', '.workspace-header'].map(selector => {
    const {top, height} = document.querySelector(selector).getBoundingClientRect(); return {top, height};
}));
let errors;
test.beforeEach(({page}) => { errors = []; page.on('pageerror', error => errors.push(error.message)); });
test.afterEach(() => expect(errors).toEqual([]));

for (const width of [360, 768, 1440]) test('inline recovery fits ' + width + ' pixels and restores a selected checkpoint in both themes', async ({page, request}) => {
    const target = await createPage(request), text = 'Recover this private work without publishing it.';
    await page.setViewportSize({width, height: 1000}); await login(page);
    await page.goto('/spaces/' + fixture().spaceKey + '/pages/' + target.slug + '/edit');
    const baseline = await bars(page);
    await saveDraft(page, text); await page.reload();
    const panel = page.locator('[data-recovery-banner]');
    await expect(panel).toBeVisible();
    await expect(page.locator('.workspace-canvas > [data-recovery-banner]')).toHaveCount(1);
    await expect(panel.getByRole('heading', {name: 'Dostępna jest kopia odzyskiwania.'})).toBeVisible();
    await expect(panel.locator('[data-recovery-select] option[value^="checkpoint:"]').first()).toBeAttached();
    const checkpoint = await panel.locator('[data-recovery-select] option[value^="checkpoint:"]').last().getAttribute('value');
    await panel.locator('[data-recovery-select]').selectOption(checkpoint);
    await expect(panel.locator('[data-recovery-meta]')).toHaveText('Wersja bazowa: 1');
    for (const theme of ['light', 'dark']) {
        await page.locator('.theme-menu > summary').click(); await page.locator('.theme-menu [data-theme-select]').selectOption(theme); await page.keyboard.press('Escape');
        await page.evaluate(() => scrollTo(0, 0));
        expect(await bars(page)).toEqual(baseline);
        const bounds = await panel.boundingBox(), restore = await panel.locator('[data-restore-recovery]').boundingBox(), compare = await panel.locator('[data-compare-recovery]').boundingBox();
        expect(bounds.y).toBeGreaterThanOrEqual(baseline[0].height + baseline[1].height);
        for (const selector of ['[data-recovery-select]', '[data-restore-recovery]', '[data-compare-recovery]', '[data-hide-recovery]']) {
            const box = await panel.locator(selector).boundingBox();
            expect(box.x).toBeGreaterThanOrEqual(bounds.x); expect(box.x + box.width).toBeLessThanOrEqual(bounds.x + bounds.width);
        }
        if (width === 360) expect(compare.y).toBeGreaterThanOrEqual(restore.y + restore.height);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBeTruthy();
        await page.screenshot({path: 'storage/temp/recovery-panel-' + width + '-' + theme + '.png'});
    }
    await panel.locator('[data-compare-recovery]').click();
    const dialog = page.locator('[data-recovery-dialog]'); await expect(dialog).toBeVisible();
    await expect(dialog.locator('[data-recovery-current]')).toContainText('Published document stays intact.');
    await expect(dialog.locator('[data-recovery-copy]')).toContainText(text);
    await dialog.locator('[data-dialog-close]').click();
    await expect(panel.locator('[data-recovery-select]')).toHaveValue(checkpoint);
    await panel.locator('[data-restore-recovery]').click(); await expect(panel).toBeHidden(); await expect(editor(page)).toContainText(text);
    await expect(page.locator('[name=base_version]')).toHaveValue('1');
    const readback = await request.get('/api/v1/pages/' + target.id, {headers: {Authorization: 'Bearer ' + fixture().token}});
    const saved = (await readback.json()).data; expect(saved.version).toBe(1); expect(saved.content_html).toBe('<p>Published document stays intact.</p>');
});

test('dismissing recovery leaves the current document and editor navigation intact', async ({page, request}) => {
    const target = await createPage(request);
    await login(page); await page.goto('/spaces/' + fixture().spaceKey + '/pages/' + target.slug + '/edit');
    await saveDraft(page, 'A saved private recovery copy.'); await page.reload();
    const panel = page.locator('[data-recovery-banner]'); await expect(panel).toBeVisible(); const before = await bars(page);
    await panel.locator('[data-hide-recovery]').click(); await expect(panel).toBeHidden();
    await expect(editor(page)).toContainText('Published document stays intact.');
    expect(await bars(page)).toEqual(before);
    await page.reload(); await expect(panel).toBeVisible();
    await panel.locator('[data-restore-recovery]').click(); await expect(editor(page)).toContainText('A saved private recovery copy.');
});

test('English recovery labels show the base version separately from copy names', async ({page, request}) => {
    const target = await createPage(request);
    await login(page, 'en'); await page.goto('/spaces/' + fixture().spaceKey + '/pages/' + target.slug + '/edit');
    await saveDraft(page, 'English recovery content.'); await page.reload();
    const panel = page.locator('[data-recovery-banner]'); await expect(panel).toBeVisible();
    await expect(panel.getByRole('button', {name: 'Restore copy', exact: true})).toBeVisible();
    await expect(panel.getByRole('button', {name: 'Compare copy', exact: true})).toBeVisible();
    await expect(panel.locator('[data-recovery-select] option[value^="checkpoint:"]').first()).toContainText('Recovery point');
    await expect(panel.locator('[data-recovery-select]')).not.toContainText(' — base ');
    await expect(panel.locator('[data-recovery-meta]')).toHaveText('Base version: 1');
});
