const {test, expect} = require('@playwright/test');
const {execFileSync} = require('node:child_process');
const fs = require('node:fs');
const {phpArguments} = require('./php-command.cjs');
test.describe.configure({timeout: 240000});
const fixture = () => JSON.parse(fs.readFileSync('storage/temp/editor-browser.json', 'utf8'));
const login = async (page, index = 8) => {
    await page.context().addCookies([{name: 'openwiki_locale', value: 'pl', url: 'http://127.0.0.1:8765'}]);
    await page.goto('/login');
    await page.locator('[name=identifier]').fill(fixture().users[index].username);
    await page.locator('[name=password]').fill(fixture().users[index].password);
    await page.getByRole('button', {name: 'Zaloguj', exact: true}).click();
    await expect(page).toHaveURL('/');
};

for (const viewport of [{width: 1280, height: 900}, {width: 390, height: 844}]) {
    test(`real search form, ACL-safe suggestions, stable pages and API agree at ${viewport.width}px`, async ({page, request}) => {
        await page.setViewportSize(viewport);
        const errors = []; page.on('pageerror', error => errors.push(error.message));
        const sample = JSON.parse(execFileSync('php', [...phpArguments, 'tests/Browser/fixture.php', 'search-seed'], {encoding: 'utf8'}));
        await login(page);
        await page.goto('/search');
        await page.locator('[name=type]').selectOption('page');
        await page.locator('[name=per_page]').selectOption('10');
        await page.locator('main [data-search-form] [name=q]').fill(sample.query);
        await expect(page.locator('#search-suggestions')).toBeVisible();
        expect(await page.locator('#search-suggestions').innerText()).not.toContain('SECRET');
        await page.locator('main [data-search-form] [name=q]').press('ArrowDown');
        await page.locator('main [data-search-form] [name=q]').press('Enter');
        await expect(page.locator('h1')).toContainText(sample.query);
        await page.goto('/search');
        await page.locator('[name=type]').selectOption('page'); await page.locator('[name=per_page]').selectOption('10');
        await page.locator('main [data-search-form] [name=q]').fill(sample.query); await page.locator('main [data-search-form] [name=q]').press('Escape');
        await page.locator('main [data-search-form] [name=q]').press('Enter');
        await expect(page.locator('[data-search-total]')).toHaveText('36');
        const keys = [];
        for (let number = 1; number <= 4; number++) {
            expect(await page.content()).not.toContain('SECRET ancestor');
            keys.push(...await page.locator('[data-search-key]').evaluateAll(nodes => nodes.map(node => node.dataset.searchKey)));
            if (number < 4) await page.getByRole('link', {name: 'Następna', exact: true}).click();
        }
        expect(keys.length).toBe(36); expect(new Set(keys).size).toBe(36);
        const response = await request.get('/api/v1/search', {headers: {Authorization: 'Bearer ' + fixture().workflowTokens[8]}, params: {q: sample.query, type: 'page', per_page: 100}});
        expect(response.status()).toBe(200); const api = await response.json();
        expect(api.meta.total).toBe(36); expect(api.data.map(row => row.result_key)).toEqual(keys);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= document.documentElement.clientWidth + 1)).toBe(true);
        await page.goto('/search');
        await page.route('**/search/suggest?**', route => route.fulfill({status: 503, contentType: 'application/json', body: '{}'}));
        await page.locator('[name=type]').selectOption('page'); await page.locator('main [data-search-form] [name=q]').fill(sample.query);
        await expect(page.locator('[data-search-suggestion-status]')).toContainText('chwilowo niedostępne');
        await page.locator('main [data-search-form] [name=q]').press('Enter'); await expect(page.locator('[data-search-total]')).toHaveText('36');
        expect(errors).toEqual([]);
    });
}

test('real index administration queues work and displays durable worker progress', async ({page}) => {
    execFileSync('php', [...phpArguments, 'bin/console', 'search:index', '--wait'], {env: {...process.env, APP_KEY: 'browser-test-key-'.repeat(4), DB_HOST: process.env.OPENWIKI_TEST_DB_HOST, DB_PORT: process.env.OPENWIKI_TEST_DB_PORT, DB_DATABASE: process.env.OPENWIKI_TEST_DB_DATABASE, DB_USERNAME: process.env.OPENWIKI_TEST_DB_USERNAME, DB_PASSWORD: process.env.OPENWIKI_TEST_DB_PASSWORD}, encoding: 'utf8'});
    await login(page, 0); await page.goto('/admin/system');
    const panel = page.locator('[data-search-index]');
    await panel.getByRole('button', {name: 'Przebuduj indeks'}).click();
    await expect(panel.getByRole('button', {name: 'Przebuduj indeks'})).toBeDisabled();
    execFileSync('php', [...phpArguments, 'bin/console', 'search:work'], {env: {...process.env, APP_KEY: 'browser-test-key-'.repeat(4), DB_HOST: process.env.OPENWIKI_TEST_DB_HOST, DB_PORT: process.env.OPENWIKI_TEST_DB_PORT, DB_DATABASE: process.env.OPENWIKI_TEST_DB_DATABASE, DB_USERNAME: process.env.OPENWIKI_TEST_DB_USERNAME, DB_PASSWORD: process.env.OPENWIKI_TEST_DB_PASSWORD}, encoding: 'utf8'});
    await panel.getByRole('link', {name: 'Odśwież postęp'}).click();
    expect(Number(await panel.locator('progress').getAttribute('value'))).toBeGreaterThan(0);
    execFileSync('php', [...phpArguments, 'bin/console', 'search:index', '--wait'], {env: {...process.env, APP_KEY: 'browser-test-key-'.repeat(4), DB_HOST: process.env.OPENWIKI_TEST_DB_HOST, DB_PORT: process.env.OPENWIKI_TEST_DB_PORT, DB_DATABASE: process.env.OPENWIKI_TEST_DB_DATABASE, DB_USERNAME: process.env.OPENWIKI_TEST_DB_USERNAME, DB_PASSWORD: process.env.OPENWIKI_TEST_DB_PASSWORD}, encoding: 'utf8'});
});
