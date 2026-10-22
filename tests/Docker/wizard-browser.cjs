'use strict';
const {chromium, expect} = require('@playwright/test');

(async () => {
    const browser = await chromium.launch();
    try {
        for (const width of [1280, 390]) {
            const page = await browser.newPage({viewport: {width, height: 900}});
            await page.goto(process.argv[2] + '/install');
            await expect(page.locator('[data-wizard-panel="1"]')).toBeVisible();
            await page.locator('[data-wizard-panel="1"] [data-wizard-next]').click();
            await expect(page.locator('[name="app_url"]')).toHaveValue(process.argv[2]);
            await page.locator('[data-wizard-panel="2"] [data-wizard-next]').click();
            for (const name of ['db_host', 'db_port', 'db_database', 'db_username', 'db_password']) {
                if (!await page.locator('[name="' + name + '"]').evaluate(input => input.readOnly)) throw new Error('Managed field is editable: ' + name);
            }
            await expect(page.locator('[name="db_host"]')).toHaveValue('db');
            await expect(page.locator('[name="db_password"]')).toHaveValue('');
            await expect(page.locator('[name="create_database"]')).toBeDisabled();
            await expect(page.locator('[name="full_database_reinstall"]')).toBeChecked();
            await expect(page.locator('[name="full_database_reinstall"]')).toBeEnabled();
            await page.locator('[data-database-test-button]').click();
            await expect(page.locator('[data-database-next]')).toBeEnabled({timeout: 15000});
            await page.locator('[data-database-next]').click();
            await expect(page.locator('[data-wizard-panel="4"]')).toBeVisible();
            const overflow = await page.evaluate(() => document.documentElement.scrollWidth > innerWidth);
            if (overflow) throw new Error('Wizard overflows at width ' + width);
            await page.close();
            process.stdout.write('[ OK ] Real Docker wizard controls and database test at ' + width + 'px\n');
        }
    } finally { await browser.close(); }
})().catch(error => { process.stderr.write(error.stack + '\n'); process.exitCode = 1; });
