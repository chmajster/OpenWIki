const {test, expect} = require('@playwright/test');
const fs = require('node:fs');
const {publish} = require('./workspace-helpers.cjs');
const fixture = () => JSON.parse(fs.readFileSync('storage/temp/editor-browser.json', 'utf8'));
const unique = name => name + ' ' + Date.now() + Math.random().toString(16).slice(2, 6);
test.describe.configure({timeout: 120000});
test.use({actionTimeout: 15000});
async function api(request, path, data) {
    const response = await request.post('/api/v1/' + path, {headers: {Authorization: 'Bearer ' + fixture().token}, data});
    expect(response.ok(), await response.text()).toBeTruthy(); return (await response.json()).data;
}
async function login(page, index = 0, locale = 'en') {
    await page.context().addCookies([{name: 'openwiki_locale', value: locale, url: 'http://127.0.0.1:8765'}]);
    await page.goto('/login'); await page.locator('[name=identifier]').fill(fixture().users[index].username); await page.locator('[name=password]').fill(fixture().users[index].password);
    await page.locator('form[action="/login"] [type=submit]').click(); await expect(page).toHaveURL('/');
}
async function templateAdmin(page) {
    await page.goto('/admin/templates/create');
    const token = await page.locator('[name=_token]').first().inputValue(), ids = [];
    return {
        async create(name, source = '# Team source\n\nA **shared** decision.') {
            const response = await page.request.post('/admin/templates', {form: {_token: token, name, description: 'A reusable team document.', content_format: 'markdown', content_markdown: source}});
            expect(response.ok()).toBeTruthy(); const id = response.url().match(/templates\/(\d+)\/edit$/)?.[1]; expect(id).toBeTruthy(); ids.push(id); return id;
        },
        async cleanup() { for (const id of ids) await page.request.post('/admin/templates/' + id + '/delete', {form: {_token: token}}); }
    };
}
let errors;
test.beforeEach(({page}) => { errors = []; page.on('pageerror', error => errors.push(error.message)); });
test.afterEach(() => expect(errors).toEqual([]));

test('gallery navigation, filters, preview, and publication keep the selected template structure', async ({page, request}) => {
    const space = await api(request, 'spaces', {name: unique('Template team'), visibility: 'public'});
    const base = '/spaces/' + space.key;
    await login(page); await page.goto(base);
    await page.locator('.workspace-tabs').getByRole('link', {name: 'Templates', exact: true}).click();
    await expect(page.locator('[data-workspace-navigation]').getByRole('link', {name: 'Templates', exact: true})).toHaveAttribute('aria-current', 'page');
    const filters = page.locator('[data-template-gallery-filters]');
    await filters.locator('[name=q]').fill('API'); await filters.locator('[name=category]').selectOption('technical'); await filters.getByRole('button', {name: 'Apply filters'}).click();
    await expect(page.locator('[data-gallery-template]')).toHaveCount(1);
    await page.getByRole('link', {name: 'Preview', exact: true}).click();
    const preview = page.locator('#template-preview');
    await expect(preview.locator('table')).toHaveCount(1);
    const headings = await preview.locator('[data-gallery-preview] h1,[data-gallery-preview] h2,[data-gallery-preview] h3').allTextContents();
    await preview.getByRole('link', {name: 'Use template'}).click();
    await expect(page.locator('[data-initial-template-name]')).toContainText('API Documentation');
    await expect(page.locator('[data-workspace-templates]')).toBeHidden();
    await expect(page.locator('[data-visual-editor] table')).toHaveCount(1);
    await page.locator('[data-document-title]').fill(unique('API handbook'));
    await publish(page);
    await expect(page.locator('[data-document-content] h1,[data-document-content] h2,[data-document-content] h3')).toHaveText(headings);
    await expect(page.locator('[data-document-content] table')).toHaveCount(1);
});

test('custom templates use literal search and pagination and preserve Markdown source', async ({page}) => {
    await login(page); const admin = await templateAdmin(page), prefix = unique('Łódź 100%_!');
    try {
        for (let i = 0; i < 14; i++) await admin.create(prefix + ' ' + String(i).padStart(2, '0'));
        await page.goto('/spaces/' + fixture().spaceKey + '/templates?q=' + encodeURIComponent(prefix) + '&category=custom');
        await expect(page.locator('[data-template-total]')).toHaveAttribute('data-template-total', '14');
        await expect(page.locator('[data-gallery-template]')).toHaveCount(12);
        await page.getByRole('link', {name: 'Next', exact: true}).click(); await expect(page.locator('[data-gallery-template]')).toHaveCount(2);
        await page.reload(); await expect(page.locator('[name=q]').last()).toHaveValue(prefix);
        await page.locator('[data-gallery-template]').last().getByRole('link', {name: 'Use template'}).click();
        await expect(page.locator('[data-content-format]')).toHaveValue('markdown');
        await expect(page.locator('[data-markdown-editor]')).toHaveValue('# Team source\n\nA **shared** decision.');
    } finally { await admin.cleanup(); }
});

test('gallery and native creation work without JavaScript and retain the parent', async ({page, browser, request}) => {
    await login(page); const admin = await templateAdmin(page), name = unique('Native source');
    const parent = await api(request, 'pages', {space_id: fixture().spaceId, title: unique('Parent guide'), content_html: '<p>Parent context</p>', status: 'published'});
    const context = await browser.newContext({javaScriptEnabled: false, viewport: {width: 390, height: 844}}), plain = await context.newPage();
    try {
        await admin.create(name); await login(plain, 0, 'pl');
        await plain.goto('/spaces/' + fixture().spaceKey + '/templates?parent=' + parent.id + '&q=' + encodeURIComponent(name));
        await plain.getByRole('link', {name: 'Podgląd', exact: true}).click();
        await expect(plain.locator('[data-gallery-preview]')).toContainText('Team source');
        await plain.locator('#template-preview').getByRole('link', {name: 'Użyj szablonu'}).click();
        await expect(plain.locator('[data-content-format]')).toHaveValue('markdown');
        await expect(plain.locator('[name=parent_id]')).toHaveValue(String(parent.id));
        await plain.locator('[data-document-title]').fill(unique('Native child'));
        await plain.locator('[data-save-button]').click();
        await expect(plain.locator('[data-document-content]')).toContainText('A shared decision.');
        await expect(plain.locator('.breadcrumbs')).toContainText(parent.title);
        expect(await plain.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();
    } finally { await context.close(); await admin.cleanup(); }
});

test('gallery handles invalid selections and restricted access in both themes and mobile layouts', async ({page, request, browser}) => {
    await login(page);
    const base = '/spaces/' + fixture().spaceKey;
    const invalid = await page.goto(base + '/templates?category[]=custom'); expect(invalid.status()).toBe(422);
    await page.getByRole('link', {name: 'Reset filters'}).click();
    const missing = await page.goto(base + '/pages/create?template=builtin:missing'); expect(missing.status()).toBe(404);
    for (const width of [1440, 768, 390]) for (const theme of ['light', 'dark']) {
        await page.setViewportSize({width, height: 900}); await page.goto(base + '/templates');
        await page.locator('.theme-menu > summary').click(); await page.locator('[data-theme-select]').selectOption(theme); await page.keyboard.press('Escape');
        if (width === 1440 && theme === 'light') await page.screenshot({path: 'storage/temp/space-template-gallery-overview.png'});
        await page.locator('[data-gallery-template]').first().getByRole('link', {name: 'Preview'}).click();
        await expect(page.locator('[data-gallery-preview]')).toBeVisible();
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();
        if (width === 1440 || width === 390) await page.screenshot({path: `storage/temp/space-template-gallery-${width}-${theme}.png`, fullPage: false});
    }
    const context = await browser.newContext(), reader = await context.newPage();
    try {
        await reader.goto('http://127.0.0.1:8765' + base + '/templates'); await expect(reader).toHaveURL('/login');
        await login(reader, 3); const denied = await reader.goto(base + '/templates'); expect(denied.status()).toBe(403);
        await expect(reader.locator('[data-gallery-template]')).toHaveCount(0);
    } finally { await context.close(); }
    const other = await api(request, 'spaces', {name: unique('Other template space'), visibility: 'public'});
    const parent = await api(request, 'pages', {space_id: other.id, title: unique('Foreign parent'), content_html: '<p>Foreign</p>', status: 'published'});
    const deniedParent = await page.goto(base + '/templates?parent=' + parent.id); expect(deniedParent.status()).toBe(404);
    await expect(page.locator('body')).not.toContainText(parent.title);
    const hidden = await api(request, 'pages', {space_id: fixture().spaceId, title: unique('Restricted parent'), content_html: '<p>Restricted</p>', status: 'published'});
    await page.goto(base + '/pages/' + hidden.slug + '/permissions');
    await page.locator('[name=principal]').selectOption('user:' + fixture().users[4].id); await page.locator('[name=permission]').selectOption('page.view'); await page.locator('[name=effect]').selectOption('deny');
    await page.getByRole('button', {name: 'Save ACL rule', exact: true}).click();
    const authorContext = await browser.newContext(), author = await authorContext.newPage();
    try {
        await login(author, 4); expect((await author.goto(base + '/templates')).status()).toBe(200);
        expect((await author.goto(base + '/templates?parent=' + hidden.id)).status()).toBe(404);
        await expect(author.locator('body')).not.toContainText(hidden.title);
    } finally { await authorContext.close(); }
});
