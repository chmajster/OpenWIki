const {test, expect} = require('@playwright/test');
const fs = require('node:fs');

test.describe.configure({timeout: 90000});
const fixture = () => JSON.parse(fs.readFileSync('storage/temp/editor-browser.json', 'utf8'));
const unique = prefix => prefix + ' ' + Date.now() + Math.random().toString(16).slice(2, 6);
const api = async (request, path, data) => {
    const response = await request.post('/api/v1/' + path, {headers: {Authorization: 'Bearer ' + fixture().token}, data});
    expect(response.ok(), await response.text()).toBeTruthy();
    return (await response.json()).data;
};
const create = async request => {
    const space = await api(request, 'spaces', {name: unique('Reader team'), visibility: 'public'});
    const paragraph = '<p>' + 'A shared decision needs useful context. '.repeat(45) + '</p>';
    const document = await api(request, 'pages', {
        space_id: space.id, title: unique('Team handbook'), status: 'published',
        content_html: '<h2>Introduction</h2>' + paragraph + '<h2>Implementation</h2>' + paragraph + '<h2>Next steps</h2>' + paragraph
    });
    return {space, document, path: '/spaces/' + space.key + '/pages/' + document.slug};
};
async function login(page) {
    await page.context().addCookies([{name: 'openwiki_locale', value: 'en', url: 'http://127.0.0.1:8765'}]);
    await page.goto('/login');
    await page.locator('[name=identifier]').fill(fixture().users[0].username);
    await page.locator('[name=password]').fill(fixture().users[0].password);
    await page.locator('form[action="/login"] [type=submit]').click();
    await expect(page).toHaveURL('/');
}

test('reader share panel copies the canonical page URL and explains access', async ({page, request}) => {
    const item = await create(request);
    await page.addInitScript(() => {
        Object.defineProperty(navigator, 'clipboard', {configurable: true, value: {writeText: async value => { window.testCopiedLink = value; }}});
    });
    await login(page);
    await page.goto(item.path + '?from=search');
    await page.locator('[data-reader-share] > summary').click();
    const share = page.locator('[data-reader-share]');
    await expect(share).toContainText('People need access to this page');
    await expect(share.locator('[data-reader-share-url]')).toHaveValue('http://127.0.0.1:8765' + item.path);
    await share.getByRole('button', {name: 'Copy link'}).click();
    await expect(share.getByRole('status')).toHaveText('Link copied');
    expect(await page.evaluate(() => window.testCopiedLink)).toBe('http://127.0.0.1:8765' + item.path);
    await page.evaluate(() => {
        Object.defineProperty(navigator, 'clipboard', {configurable: true, value: undefined});
        document.execCommand = () => false;
    });
    await share.getByRole('button', {name: 'Copy link'}).click();
    await expect(share.getByRole('status')).toHaveText('Select the link to copy it.');
    await expect(share.locator('[data-reader-share-url]')).toBeFocused();
    await page.keyboard.press('Escape');
    await expect(share).not.toHaveAttribute('open', '');
    await page.locator('.page-menu > summary').click();
    await expect(page.locator('.page-menu').getByRole('link', {name: 'Permissions'})).toHaveAttribute('href', item.path + '/permissions');
    await page.keyboard.press('Escape');
});

test('outline follows sections and remains usable on a narrow screen', async ({page, request}) => {
    const item = await create(request);
    await login(page);
    await page.goto(item.path);
    const outline = page.locator('[data-reader-outline]');
    await expect(outline).toHaveAttribute('open', '');
    await expect(outline.locator('a[href="#introduction"]')).toHaveAttribute('aria-current', 'location');
    await page.evaluate(() => {
        const heading = document.getElementById('implementation');
        window.scrollTo(0, window.scrollY + heading.getBoundingClientRect().top - 90);
    });
    await expect(outline.locator('a[href="#implementation"]')).toHaveAttribute('aria-current', 'location');
    await expect(outline.getByRole('link', {name: 'Attachments'})).toHaveAttribute('href', '#attachments');
    await expect(outline.getByRole('link', {name: 'Comments'})).toHaveAttribute('href', '#comments');

    await page.screenshot({path: 'storage/temp/reader-outline-desktop.png', fullPage: true});

    await page.setViewportSize({width: 390, height: 844});
    await page.reload();
    await expect(outline).not.toHaveAttribute('open', '');
    await outline.locator('summary').click();
    await outline.getByRole('link', {name: 'Next steps'}).click();
    await expect(page).toHaveURL(/#next-steps$/);
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();
    await page.locator('.theme-menu > summary').click();
    await page.locator('[data-theme-select]').selectOption('dark');
    await page.keyboard.press('Escape');
    await expect(page.locator('html')).toHaveAttribute('data-theme', 'dark');
    await page.screenshot({path: 'storage/temp/reader-outline-mobile-dark.png', fullPage: true});
    expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();
});

test('guest can use share and outline without JavaScript in Polish', async ({request, browser}) => {
    const item = await create(request);
    const context = await browser.newContext({javaScriptEnabled: false, viewport: {width: 390, height: 844}});
    const page = await context.newPage();
    try {
        await page.goto('http://127.0.0.1:8765' + item.path);
        await page.locator('[data-reader-share] > summary').click();
        await expect(page.getByRole('link', {name: 'Otwórz odnośnik'})).toHaveAttribute('href', item.path);
        await expect(page.locator('[data-reader-copy]')).toBeHidden();
        await expect(page.locator('[data-reader-outline]')).toHaveAttribute('open', '');
        await page.locator('[data-reader-outline]').getByRole('link', {name: 'Implementation'}).click();
        await expect(page).toHaveURL(/#implementation$/);
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth)).toBeTruthy();
    } finally { await context.close(); }
});
