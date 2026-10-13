const {test, expect} = require('@playwright/test');
const fs = require('node:fs');
const {openMore} = require('./workspace-helpers.cjs');
const fixture = () => JSON.parse(fs.readFileSync('storage/temp/editor-browser.json', 'utf8'));
const theme = page => page.locator('.theme-menu [data-theme-select]');
const root = page => page.locator('html');
test.use({colorScheme: 'light', actionTimeout: 15000});
test.describe.configure({timeout: 90000});

async function login(page, index = 0) {
    await page.goto('/login');
    if (new URL(page.url()).pathname !== '/login') {
        await page.locator('.account-menu > summary').click(); await page.locator('form[action="/logout"] button').click();
        await page.goto('/login');
    }
    await page.locator('[name=identifier]').fill(fixture().users[index].username);
    await page.locator('[name=password]').fill(fixture().users[index].password);
    await page.locator('form[action="/login"] button[type=submit]').click();
    await expect(page).toHaveURL('/');
}
async function choose(page, value) {
    await page.locator('.theme-menu > summary').click();
    await theme(page).selectOption(value);
    await page.keyboard.press('Escape');
    await expect(page.locator('.theme-menu > summary')).toBeFocused();
}
async function api(request, method, path, data) {
    const response = await request[method]('/api/v1/' + path, {headers: {Authorization: 'Bearer ' + fixture().token}, data});
    expect(response.ok(), await response.text()).toBeTruthy();
    return (await response.json()).data;
}
let errors;
test.beforeEach(async ({page}) => { errors = []; page.on('pageerror', error => errors.push(error.message)); });
test.afterEach(() => expect(errors).toEqual([]));

test('one theme persists across work center, search, administration and the real editor', async ({page}) => {
    await login(page); await choose(page, 'light');
    for (const path of ['/spaces/' + fixture().spaceKey, '/search?q=wiki', '/admin', '/']) {
        await page.goto(path); await expect(root(page)).toHaveAttribute('data-theme', 'light');
        await expect(theme(page)).toHaveValue('light');
    }
    await page.goto('/spaces/' + fixture().spaceKey + '/pages/create');
    await openMore(page); await expect(page.locator('select[data-workspace-theme]')).toHaveValue('light');
    await page.locator('select[data-workspace-theme]').selectOption('dark'); await page.keyboard.press('Escape');
    await expect(root(page)).toHaveAttribute('data-theme', 'dark');
    await expect(page.locator('body')).toHaveAttribute('data-workspace-theme', 'dark');
    await page.reload(); await openMore(page); await expect(page.locator('select[data-workspace-theme]')).toHaveValue('dark');
    await page.goto('/'); await expect(root(page)).toHaveAttribute('data-theme', 'dark');
    await choose(page, 'light'); await page.reload(); await expect(root(page)).toHaveAttribute('data-theme', 'light');
});

test('system choice follows OS changes and explicit choices synchronize between tabs', async ({page}) => {
    await login(page); const other = await page.context().newPage();
    try {
        await other.goto('/'); await choose(page, 'dark');
        await expect(root(other)).toHaveAttribute('data-theme', 'dark'); await expect(theme(other)).toHaveValue('dark');
        await page.emulateMedia({colorScheme: 'light'}); await expect(root(page)).toHaveAttribute('data-theme', 'dark');
        await choose(page, 'system'); await expect(root(page)).toHaveAttribute('data-theme', 'light');
        await page.emulateMedia({colorScheme: 'dark'}); await expect(root(page)).toHaveAttribute('data-theme', 'dark');
        await expect(theme(other)).toHaveValue('system'); await expect(root(other)).toHaveAttribute('data-theme', 'light');
        await choose(other, 'light'); await expect(root(page)).toHaveAttribute('data-theme', 'light');
    } finally { await other.close(); }
});

test('guest and account preferences stay separate through actual sign-out and sign-in', async ({page}) => {
    await page.goto('/login'); await choose(page, 'dark');
    await login(page); await expect(root(page)).toHaveAttribute('data-theme', 'light'); await choose(page, 'light');
    await page.locator('.account-menu > summary').click(); await page.locator('form[action="/logout"] button').click();
    await expect(root(page)).toHaveAttribute('data-theme', 'dark');
    await login(page, 1); await expect(theme(page)).toHaveValue('system'); await choose(page, 'dark');
    await login(page, 0); await expect(theme(page)).toHaveValue('light'); await expect(root(page)).toHaveAttribute('data-theme', 'light');
});

test('existing editor preference migrates and malformed or unrelated storage is ignored', async ({page}) => {
    await login(page);
    await page.evaluate(() => {
        const suffix = document.documentElement.dataset.instanceId + ':' + document.documentElement.dataset.userId;
        localStorage.setItem('openwiki:workspace:' + suffix, JSON.stringify({theme: 'dark', width: 'wide'}));
        localStorage.removeItem('openwiki:theme:' + suffix);
    });
    await page.reload(); await expect(theme(page)).toHaveValue('dark');
    await page.evaluate(() => {
        const suffix = document.documentElement.dataset.instanceId + ':' + document.documentElement.dataset.userId;
        localStorage.setItem('openwiki:theme:' + suffix, 'invalid');
        localStorage.setItem('openwiki:theme:other-instance:' + document.documentElement.dataset.userId, 'dark');
    });
    await page.reload(); await expect(theme(page)).toHaveValue('system'); await expect(root(page)).toHaveAttribute('data-theme', 'light');
    await page.evaluate(() => {
        const suffix = document.documentElement.dataset.instanceId + ':' + document.documentElement.dataset.userId;
        localStorage.removeItem('openwiki:theme:' + suffix);
        localStorage.setItem('openwiki:workspace:' + suffix, 'null');
    });
    await page.reload(); await expect(theme(page)).toHaveValue('system');
});

test('theme remains usable with denied browser storage and falls back safely without JavaScript', async ({page, browser}) => {
    await page.addInitScript(() => {
        Object.defineProperty(window, 'localStorage', {get() { throw new DOMException('Storage denied', 'SecurityError'); }});
    });
    await page.goto('/login'); await choose(page, 'dark'); await expect(root(page)).toHaveAttribute('data-theme', 'dark');
    await choose(page, 'light'); await expect(root(page)).toHaveAttribute('data-theme', 'light');
    const context = await browser.newContext({javaScriptEnabled: false}), plain = await context.newPage();
    try {
        await plain.goto('http://127.0.0.1:8765/login'); await expect(plain.locator('[name=identifier]')).toBeVisible();
        await expect(plain.locator('.theme-menu')).toBeHidden();
        expect(await plain.locator('body').evaluate(node => getComputedStyle(node).color)).toBe('rgb(23, 43, 77)');
    } finally { await context.close(); }
});

test('light and dark text, controls, alerts, document colors and syntax have readable contrast', async ({page}) => {
    await login(page);
    for (const choice of ['light', 'dark']) {
        await choose(page, choice);
        const ratios = await page.evaluate(() => {
            const pairs = [['text','bg'], ['text','surface'], ['muted','surface-2'], ['subtle-text','surface'],
                ['accent','surface'], ['on-accent','accent'], ['on-accent','accent-strong'],
                ['success-text','success-soft'], ['danger-text','danger-soft'], ['warning-text','warning-soft'],
                ...['red','blue','green','orange','purple','gray'].map(name => ['doc-'+name,'surface']),
                ...['keyword','string','comment','number','type'].map(name => ['syntax-'+name,'code-bg']),
                ...['yellow','green','blue','pink','gray'].map(name => ['text','highlight-'+name])];
            const probe = document.createElement('span'); document.body.append(probe);
            const rgb = token => {
                probe.style.color = 'var(--' + token + ')';
                const values = getComputedStyle(probe).color.match(/[\d.]+/g).map(Number), alpha = values[3] ?? 1;
                return alpha === 1 ? values.slice(0,3) : values.slice(0,3).map((n,i) => n * alpha + rgb('surface')[i] * (1-alpha));
            };
            const luminance = channels => channels.map(n => n / 255).map(n => n <= .04045 ? n / 12.92 : ((n + .055) / 1.055) ** 2.4).reduce((sum,n,i) => sum + n * [.2126,.7152,.0722][i],0);
            const result = pairs.map(([fg,bg]) => { const a = luminance(rgb(fg)), b = luminance(rgb(bg)); return {pair:fg+'/'+bg,ratio:(Math.max(a,b)+.05)/(Math.min(a,b)+.05)}; });
            probe.remove(); return result;
        });
        for (const result of ratios) expect(result.ratio, choice + ': ' + result.pair).toBeGreaterThanOrEqual(4.5);
    }
});

test('Polish light document, discussions, tasks and work center fit 390, 768 and 1440 pixels', async ({page, request}) => {
    await login(page); await page.context().addCookies([{name:'openwiki_locale',value:'pl',url:'http://127.0.0.1:8765'}]);
    const document = await api(request, 'post', 'pages', {space_id:fixture().spaceId,title:'Jasna dokumentacja',status:'published',content_format:'visual',content_html:'<h2>Procedura odtworzenia</h2><p>Sprawdź kopię zapasową i potwierdź gotowość środowiska.</p><blockquote>Przed rozpoczęciem zachowaj dane.</blockquote><pre><code data-language="javascript">const status = "gotowe";</code></pre><p><span data-text-color="blue">Wskazówka</span> <span data-highlight="yellow">Ważne informacje</span></p>'});
    const path = '/spaces/' + fixture().spaceKey + '/pages/' + document.slug;
    const stored = (await api(request,'get','pages/'+document.id)).content_html;
    await api(request,'post','pages/'+document.id+'/tasks',{title:'Potwierdź poprawność kopii',assignee_id:fixture().users[0].id});
    await page.goto(path); await choose(page,'light');
    await expect(page.locator('[data-document-content] [data-text-color=blue]')).toHaveCSS('color','rgb(0, 85, 204)');
    await expect(page.locator('[data-document-content] [data-highlight=yellow]')).toHaveCSS('background-color','rgb(255, 240, 179)');
    await page.evaluate(() => {
        const text = document.querySelector('[data-document-content] p').firstChild, range = document.createRange();
        range.setStart(text,0); range.setEnd(text,23); const selection = getSelection(); selection.removeAllRanges(); selection.addRange(range);
        document.dispatchEvent(new Event('selectionchange'));
    });
    await page.locator('[data-comment-selection]').click();
    await page.locator('[data-comment-form] [name=body]').fill('Potwierdź datę ostatniej poprawnej kopii.');
    await page.locator('[data-comment-form] [type=submit]').click(); await expect(page.locator('.collaboration-thread')).toContainText('Potwierdź datę');
    await page.locator('[data-panel-close]').click();
    for (const width of [390,768,1440]) {
        await page.setViewportSize({width,height:900});
        await page.locator('.theme-menu > summary').click();
        const menu = await page.locator('.theme-menu .menu-items').boundingBox();
        expect(menu.x).toBeGreaterThanOrEqual(0); expect(menu.x + menu.width).toBeLessThanOrEqual(width);
        await page.keyboard.press('Escape'); await page.evaluate(() => scrollTo({top:0,behavior:'instant'}));
        await page.screenshot({path:'storage/temp/theme-light-document-'+width+'.png'});
        expect(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth + 1)).toBeTruthy();
        await page.locator('[data-discussions-open]').click();
        await expect(page.locator('[data-discussions-feedback]')).not.toContainText(/Loading|Ładowanie/);
        await page.screenshot({path:'storage/temp/theme-light-discussion-'+width+'.png'});
        await page.locator('[data-panel-close]').click();
    }
    await page.locator('[data-tasks-list]').getByRole('button',{name:'Potwierdź poprawność kopii'}).click();
    await expect(page.locator('[data-task-dialog]')).toBeVisible(); await page.screenshot({path:'storage/temp/theme-light-task.png'});
    await page.locator('[data-task-close]').click(); await page.goto('/?type=task'); await expect(page.locator('[data-work-center]')).toContainText('Potwierdź poprawność kopii');
    await page.evaluate(() => scrollTo({top:0,behavior:'instant'}));
    await page.screenshot({path:'storage/temp/theme-light-center.png'}); await choose(page,'dark');
    await page.evaluate(() => scrollTo({top:0,behavior:'instant'}));
    await page.screenshot({path:'storage/temp/theme-dark-center.png'});
    expect((await api(request,'get','pages/'+document.id)).content_html).toBe(stored);
});
