'use strict';

const {chromium, expect} = require('@playwright/test');
const {publish} = require('../Browser/workspace-helpers.cjs');
const path = require('node:path');
const url = process.argv[2];
const settings = {
    app_name: 'Browser installation', app_url: url, timezone: 'Europe/Warsaw',
    db_host: process.env.OPENWIKI_TEST_DB_HOST, db_port: process.env.OPENWIKI_TEST_DB_PORT,
    db_database: process.env.OPENWIKI_INSTALL_DB, db_username: process.env.OPENWIKI_TEST_DB_USERNAME,
    db_password: process.env.OPENWIKI_TEST_DB_PASSWORD || '',
    admin_username: 'wiki-owner', admin_email: 'owner@example.test',
    admin_password: 'Installation-Password!42', admin_password_confirmation: 'Installation-Password!42',
};
const screenshot = name => path.resolve('storage/temp/install-' + name + '.png');

(async () => {
    const browser = await chromium.launch();
    const errors = [];
    try {
        const plain = await browser.newContext({javaScriptEnabled: false});
        const plainPage = await plain.newPage();
        await plainPage.goto(url + '/install');
        await expect(plainPage.locator('[name=admin_password]')).toBeVisible();
        await plain.close();
        for (const width of [390, 1280]) {
            const context = await browser.newContext({viewport: {width, height: 950}});
            await context.addCookies([{name: 'openwiki_locale', value: 'en', url}]);
            const page = await context.newPage();
            page.on('pageerror', error => errors.push(error.message));
            await page.goto(url);
            await expect(page).toHaveURL(url + '/install');
            await expect(page.locator('[data-wizard-panel="1"]')).toBeVisible();
            await expect(page.locator('[data-wizard-panel="1"] [data-wizard-next]')).toBeEnabled();
            await page.screenshot({path: screenshot('requirements-' + width)});
            expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();
            await page.locator('[data-wizard-panel="1"] [data-wizard-next]').click();
            await page.locator('[name=app_name]').fill(settings.app_name);
            await page.locator('[data-wizard-panel="2"] [data-wizard-next]').click();
            for (const key of ['db_host', 'db_port', 'db_database', 'db_username', 'db_password']) await page.locator('[name=' + key + ']').fill(settings[key]);
            if (width === 390) {
                await page.locator('[name=db_host]').fill('localhost;dbname=other');
                await page.locator('[data-database-test-button]').click();
                await expect(page.locator('[data-database-test-status]')).toHaveClass(/is-error/);
                await expect(page.locator('[data-database-next]')).toBeDisabled();
                await context.close();
                continue;
            }
            await page.locator('[data-database-test-button]').click();
            await expect(page.locator('[data-database-next]')).toBeEnabled({timeout: 20000});
            // A tested connection cannot silently remain valid after editing its settings.
            await page.locator('[name=db_database]').fill(settings.db_database + '_changed');
            await expect(page.locator('[data-database-next]')).toBeDisabled();
            await page.locator('[name=db_database]').fill(settings.db_database);
            await page.locator('[data-database-test-button]').click();
            await expect(page.locator('[data-database-next]')).toBeEnabled({timeout: 20000});
            await page.locator('[data-database-next]').click();
            const token = await page.locator('[name=_token]').inputValue();
            const csrf = await page.request.post(url + '/install', {form: {...settings, _token: 'invalid'}});
            expect(csrf.status()).toBe(419);
            const weak = await page.request.post(url + '/install', {form: {...settings, _token: token, admin_password: 'admin', admin_password_confirmation: 'admin'}});
            expect(weak.status()).toBe(422);
            expect(await weak.text()).not.toContain('SQLSTATE');
            for (const key of ['admin_username', 'admin_email', 'admin_password', 'admin_password_confirmation']) await page.locator('[name=' + key + ']').fill(settings[key]);
            await page.screenshot({path: screenshot('administrator')});
            await page.locator('[data-wizard-form] [type=submit]').click();
            await expect(page).toHaveURL(url + '/login', {timeout: 60000});
            await page.locator('[name=identifier]').fill(settings.admin_username);
            await page.locator('[name=password]').fill(settings.admin_password);
            await page.getByRole('button', {name: 'Sign in', exact: true}).click();
            await expect(page).toHaveURL(url + '/');
            await page.goto(url + '/spaces/create');
            await page.locator('[name=name]').fill('Installed documentation');
            await page.locator('[name=space_key]').fill('INSTALL');
            await page.getByRole('button', {name: 'Create space', exact: true}).click();
            await expect(page).toHaveURL(url + '/spaces/INSTALL');
            await page.goto(url + '/spaces/INSTALL/pages/create');
            await page.locator('[name=title]').fill('Installed page');
            await page.locator('[data-visual-editor]').fill('Content saved after browser installation.');
            await publish(page);
            await expect(page.locator('[data-document-content]')).toContainText('Content saved after browser installation.');
            await page.reload();
            await expect(page.locator('[data-document-content]')).toContainText('Content saved after browser installation.');
            await page.goto(url + '/account/api-tokens');
            await page.locator('[name=name]').fill('Installation verification');
            await page.locator('[name="scopes[]"][value="pages:read"]').check();
            await page.getByRole('button', {name: 'Create token', exact: true}).click();
            const apiToken = await page.locator('.token-once code').textContent();
            const api = await page.request.get(url + '/api/v1/pages', {headers: {Authorization: 'Bearer ' + apiToken}});
            expect(api.status()).toBe(200);
            expect((await api.json()).data.some(item => item.title === 'Installed page')).toBeTruthy();
            const closed = await page.request.post(url + '/install/test-database', {form: {...settings, _token: token}});
            expect(closed.status()).toBe(409);
            await page.screenshot({path: screenshot('complete')});
            await context.close();
        }
        const context = await browser.newContext({javaScriptEnabled: false});
        const page = await context.newPage();
        await page.goto(url + '/login');
        await page.locator('[name=identifier]').fill(settings.admin_username);
        await page.locator('[name=password]').fill(settings.admin_password);
        await page.locator('button[type=submit]').click();
        await expect(page).toHaveURL(url + '/');
        await page.goto(url + '/spaces/INSTALL/pages/installed-page');
        await expect(page.locator('[data-document-content]')).toContainText('Content saved after browser installation.');
        await context.close();
        expect(errors).toEqual([]);
        process.stdout.write('PASS: mobile/desktop wizard, CSRF, validation, database test invalidation, migrations, custom admin, login, space, rich editor, persistence, API and installer lock.\n');
    } finally { await browser.close(); }
})().catch(error => { process.stderr.write(error.stack + '\n'); process.exitCode = 1; });
