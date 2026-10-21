const {test, expect} = require('@playwright/test');
const fs = require('node:fs');
test.describe.configure({timeout: 120000});
test.use({actionTimeout: 15000});
const fixture = () => JSON.parse(fs.readFileSync('storage/temp/editor-browser.json', 'utf8'));
const unique = prefix => prefix + ' ' + Date.now() + Math.random().toString(16).slice(2, 6);
const form = page => page.locator('[data-browse-filters]');
async function api(request, method, path, data) {
    const response = await request[method]('/api/v1/' + path, {headers: {Authorization: 'Bearer ' + fixture().token}, data});
    expect(response.ok(), await response.text()).toBeTruthy(); return (await response.json()).data;
}
async function login(page, index = 0) {
    await page.context().addCookies([{name: 'openwiki_locale', value: 'en', url: 'http://127.0.0.1:8765'}]);
    await page.goto('/login'); await page.locator('[name=identifier]').fill(fixture().users[index].username); await page.locator('[name=password]').fill(fixture().users[index].password);
    await page.locator('form[action="/login"] [type=submit]').click(); await expect(page).toHaveURL('/');
}
const space = (request, name, visibility = 'public') => api(request, 'post', 'spaces', {name, visibility, description: 'A shared library of team guides and decisions.'});
const document = (request, target, title, extra = {}) => api(request, 'post', 'pages', {space_id: target.id, title, content_html: '<p>Verified team documentation.</p>', status: 'published', ...extra});
let errors;
test.beforeEach(({page}) => { errors = []; page.on('pageerror', error => errors.push(error.message)); });
test.afterEach(() => expect(errors).toEqual([]));

test('directory filters, display modes, sorting, and pages survive reload with real links', async ({page, request}) => {
    const prefix = unique('Directory team'), created = [];
    for (let i = 0; i < 14; i++) created.push(await space(request, prefix + ' ' + String(i).padStart(2, '0'), i === 12 ? 'private' : i === 13 ? 'restricted' : 'public'));
    await api(request, 'put', 'spaces/' + created[13].id, {status: 'archived'});
    await login(page); await page.goto('/?view=spaces');
    await form(page).locator('[name=q]').fill(prefix); await form(page).getByRole('button', {name: 'Apply filters', exact: true}).click();
    await expect(page.locator('[data-listing-total]')).toHaveAttribute('data-listing-total', '13');
    await expect(page.locator('.home-space-card')).toHaveCount(12);
    await page.getByRole('link', {name: 'Next', exact: true}).click(); await expect(page.locator('.home-space-card')).toHaveCount(1);
    await expect(page.locator('.home-space-card')).toContainText(created[12].name);
    await page.getByRole('link', {name: 'List view', exact: true}).click(); await expect(page.locator('[data-directory-layout]')).toHaveAttribute('data-directory-layout', 'list');
    await page.reload(); await expect(form(page).locator('[name=q]')).toHaveValue(prefix); await expect(page.locator('[data-directory-layout]')).toHaveAttribute('data-directory-layout', 'list');
    await form(page).locator('[name=visibility]').selectOption('public'); await form(page).locator('[name=sort]').selectOption('updated'); await form(page).getByRole('button', {name: 'Apply filters', exact: true}).click();
    await expect(page.locator('[data-listing-total]')).toHaveAttribute('data-listing-total', '12'); await expect(page.locator('.home-space-card')).toHaveCount(12);
    await form(page).locator('[name=visibility]').selectOption('all'); await form(page).locator('[name=state]').selectOption('archived'); await form(page).getByRole('button', {name: 'Apply filters', exact: true}).click();
    await expect(page.locator('.home-space-card')).toHaveCount(1); await expect(page.locator('.home-space-card')).toContainText(created[13].name);
    await page.screenshot({path: 'storage/temp/directory-archived-list.png', fullPage: true});
    await page.locator('.home-space-card').click(); await expect(page.locator('h1')).toHaveText(created[13].name); await expect(page.locator('.shell-create[href$="/pages/create"]')).toHaveCount(0);
    await page.goto('/?view=spaces&visibility[]=private'); await expect(page.getByRole('alert')).toContainText('Choose a valid filter');
    await page.getByRole('link', {name: 'Reset filters', exact: true}).click(); await expect(page).toHaveURL('/?view=spaces');
});

test('directory totals exclude private spaces and treat Unicode and wildcards literally', async ({page, request, browser}) => {
    const prefix = unique('Łódź 100%_!'), allowed = await space(request, prefix + ' public'), hidden = await space(request, prefix + ' confidential', 'private');
    await login(page, 3); await page.goto('/?view=spaces&q=' + encodeURIComponent(prefix));
    await expect(page.locator('[data-listing-total]')).toHaveAttribute('data-listing-total', '1');
    await expect(page.locator('.home-space-card')).toContainText(allowed.name); await expect(page.locator('main')).not.toContainText(hidden.name);
    await form(page).locator('[name=q]').fill('unmatched-' + prefix); await form(page).getByRole('button', {name: 'Apply filters', exact: true}).click();
    await expect(page.locator('[data-listing-total]')).toHaveAttribute('data-listing-total', '0'); await expect(page.locator('.workspace-empty')).toContainText('No spaces match');
    const context = await browser.newContext({javaScriptEnabled: false, viewport: {width: 390, height: 844}}), guest = await context.newPage();
    try {
        await guest.goto('http://127.0.0.1:8765/?view=spaces'); await form(guest).locator('[name=q]').fill(prefix); await form(guest).getByRole('button', {name: 'Zastosuj filtry', exact: true}).click();
        await expect(guest.locator('[data-listing-total]')).toHaveAttribute('data-listing-total', '1'); await guest.getByRole('link', {name: 'Widok listy', exact: true}).click();
        await expect(guest.locator('[data-directory-layout]')).toHaveAttribute('data-directory-layout', 'list');
        expect(await guest.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();
    } finally { await context.close(); }
});

test('space content links, filters, counts, and navigation use exactly the selected space', async ({page, request}) => {
    const target = await space(request, unique('Content team')), other = await space(request, unique('Content team extra'));
    for (let i = 0; i < 13; i++) await document(request, target, 'Guide ' + String(i).padStart(2, '0'));
    await document(request, other, 'Guide outsider');
    await login(page); await page.goto('/spaces/' + target.key);
    await page.locator('.workspace-tabs').getByRole('link', {name: 'Content', exact: true}).click();
    await expect(page.locator('[data-listing-total]')).toHaveAttribute('data-listing-total', '13'); await expect(page.locator('[data-content-page]')).toHaveCount(12);
    await expect(page.locator('[data-space-content]')).not.toContainText('Guide outsider');
    await page.getByRole('link', {name: 'Next', exact: true}).click(); await expect(page.locator('[data-content-page]')).toHaveCount(1); await expect(page.locator('[data-space-content]')).toContainText('Guide 12');
    await page.locator('[data-content-page] a').click(); await expect(page.locator('[data-document-content]')).toContainText('Verified team documentation.');
    await page.locator('[data-workspace-navigation]').getByRole('link', {name: 'All pages', exact: true}).click();
    await form(page).locator('[name=q]').fill('Guide 03'); await form(page).getByRole('button', {name: 'Apply filters', exact: true}).click();
    await expect(page.locator('[data-listing-total]')).toHaveAttribute('data-listing-total', '1'); await page.reload(); await expect(page.locator('[data-space-content]')).toContainText('Guide 03');
    await form(page).locator('[name=state]').selectOption('draft'); await form(page).getByRole('button', {name: 'Apply filters', exact: true}).click(); await expect(page.locator('.workspace-empty')).toContainText('No pages match');
    await page.goto('/spaces/' + target.key + '?tab=content&page=0'); await expect(page.getByRole('alert')).toContainText('Choose a valid page');
    await page.getByRole('link', {name: 'Reset filters', exact: true}).click(); await expect(page).toHaveURL('/spaces/' + target.key + '?tab=content');
});

test('content hides denied ancestors and working pages and remains usable without JavaScript', async ({page, request, browser}) => {
    const target = await space(request, unique('Content ACL')), hidden = await document(request, target, unique('Hidden parent'));
    await document(request, target, unique('Hidden descendant'), {parent_id: hidden.id}); const allowed = await document(request, target, 'Public child', {parent_id: hidden.id});
    await api(request, 'put', 'spaces/' + target.id, {review_workflow: true});
    await document(request, target, 'Unpublished working page', {status: 'draft'});
    await login(page); const url = '/spaces/' + target.key + '/pages/' + hidden.slug;
    await page.goto(url + '/permissions'); await page.locator('[name=principal]').selectOption('user:' + fixture().users[3].id); await page.locator('[name=permission]').selectOption('page.view'); await page.locator('[name=effect]').selectOption('deny'); await page.getByRole('button', {name: 'Save ACL rule', exact: true}).click();
    await page.goto('/spaces/' + target.key + '/pages/' + allowed.slug + '/permissions'); const token = await page.locator('[name=_token]').first().inputValue();
    const change = await page.request.post('/spaces/' + target.key + '/pages/' + allowed.slug + '/permissions/inheritance', {form: {_token: token, inherit_acl: '0'}}); expect(change.ok()).toBeTruthy();
    const context = await browser.newContext({javaScriptEnabled: false}), reader = await context.newPage();
    try {
        await login(reader, 3); await reader.goto('/spaces/' + target.key + '?tab=content');
        await expect(reader.locator('[data-listing-total]')).toHaveAttribute('data-listing-total', '1'); await expect(reader.locator('[data-space-content]')).not.toContainText('Hidden');
        await expect(reader.locator('[data-space-content]')).not.toContainText('Unpublished');
        await reader.locator('[data-content-page] a').click(); await expect(reader.locator('h1')).toHaveText('Public child');
    } finally { await context.close(); }
});

for (const width of [390, 768, 1440]) test('directory and content fit ' + width + ' pixels in both themes', async ({page, request}) => {
    const target = await space(request, unique('Responsive knowledge')); await document(request, target, 'A long document title with useful context for the team');
    await page.setViewportSize({width, height: 900}); await login(page);
    for (const theme of ['light', 'dark']) {
        await page.locator('.theme-menu > summary').click(); await page.locator('[data-theme-select]').selectOption(theme); await page.keyboard.press('Escape');
        for (const path of ['/?view=spaces&q=' + encodeURIComponent(target.name), '/spaces/' + target.key + '?tab=content']) {
            await page.goto(path); await expect(page.locator('html')).toHaveAttribute('data-theme', theme); expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();
            await page.screenshot({path: `storage/temp/browse-${path.startsWith('/?') ? 'directory' : 'content'}-${width}-${theme}.png`, fullPage: true});
        }
    }
});
