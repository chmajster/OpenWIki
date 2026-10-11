const {test, expect} = require('@playwright/test');
const fs = require('node:fs'), os = require('node:os');
test('100k-character document with table, syntax and protected image remains responsive and saves exactly', async ({page, request, browser}) => {
    test.setTimeout(90000);
    const fixture = JSON.parse(fs.readFileSync('storage/temp/editor-browser.json', 'utf8'));
    const errors = []; page.on('pageerror', error => errors.push(error.message)); page.on('dialog', dialog => dialog.accept());
    const text = 'Dokumentacja techniczna i zespołowa. '.repeat(15);
    const html = '<h2>Wydajność dokumentu</h2>' + Array.from({length: 190}, () => '<p>' + text + '</p>').join('')
        + '<table><tr><th>Nazwa</th><th>Wynik</th></tr><tr><td>A</td><td>OK</td></tr></table><pre><code data-language="php">\t$example = &quot;&lt;tag&gt;&quot;;\n  echo $example;</code></pre><p>Koniec</p>';
    const response = await request.post('/api/v1/pages', {headers: {Authorization: 'Bearer ' + fixture.token}, data: {space_id: fixture.spaceId, title: 'Performance-' + Date.now(), content_html: html}});
    expect(response.ok(), await response.text()).toBeTruthy(); const doc = (await response.json()).data;
    await page.goto('/login'); await page.locator('[name=identifier]').fill(fixture.users[0].username); await page.locator('[name=password]').fill(fixture.users[0].password); await page.locator('form[action="/login"] button[type=submit]').click();
    const loads = [];
    for (let run = 0; run < 3; run++) {
        const started = performance.now(); await page.goto('/spaces/' + fixture.spaceKey + '/pages/' + doc.slug + '/edit');
        await expect(page.locator('[data-model-review]')).toBeHidden(); await expect(page.locator('[data-visual-editor]')).toContainText('Koniec'); loads.push(performance.now() - started);
    }
    await page.locator('[data-image-file]').setInputFiles({name: 'performance.png', mimeType: 'image/png', buffer: Buffer.from(fixture.imageBase64, 'base64')});
    await expect(page.locator('[data-upload-list]')).toContainText('Zapisano jako prywatny'); await expect(page.locator('[data-visual-editor] img')).toBeVisible();
    await page.locator('[data-visual-editor]').press('ControlOrMeta+End');
    await page.evaluate(() => {
        window.inputPaints = [];
        document.querySelector('[data-visual-editor]').addEventListener('input', () => { const start = performance.now(); requestAnimationFrame(() => inputPaints.push(performance.now() - start)); });
    });
    await page.keyboard.type(' benchmark');
    const before = await page.evaluate(() => ({anchor: getSelection().anchorOffset, focus: getSelection().focusOffset, scroll: scrollY}));
    const draftStart = performance.now(); await page.keyboard.press('ControlOrMeta+s'); await expect(page.locator('[data-save-state]')).toHaveAttribute('data-state', 'server'); const draftMs = performance.now() - draftStart;
    expect(await page.evaluate(() => ({anchor: getSelection().anchorOffset, focus: getSelection().focusOffset, scroll: scrollY}))).toEqual(before);
    const paint = await page.evaluate(() => inputPaints); expect(paint.length).toBeGreaterThan(0);
    await page.screenshot({path: 'storage/temp/editor-100k-saved.png'});
    const saveStart = performance.now(); await page.locator('[data-save-button]').click(); await expect(page.locator('[data-document-content]')).toContainText('benchmark'); const publishMs = performance.now() - saveStart;
    const savedResponse = await request.get('/api/v1/pages/' + doc.id, {headers: {Authorization: 'Bearer ' + fixture.token}}); const saved = (await savedResponse.json()).data;
    expect(saved.content_html).toContain('/attachments/'); expect(saved.content_html).toContain('data-language="php"'); expect(saved.content_html).toContain('<table>'); expect(saved.content_html.length).toBeGreaterThan(100000);
    expect(saved.document.source).toBe(saved.content_html); expect(saved.version).toBe(2); expect(errors).toEqual([]);
    const median = values => [...values].sort((a, b) => a - b)[Math.floor(values.length / 2)];
    const measurements = {environment: {os: os.type() + ' ' + os.release(), cpu: os.cpus()[0].model, node: process.version, chromium: browser.version()}, sourceCharacters: saved.content_html.length, loadMs: loads.map(Math.round), loadMedianMs: Math.round(median(loads)), inputToAnimationFrameMs: paint.map(Math.round), inputMedianMs: Math.round(median(paint)), inputMaxMs: Math.round(Math.max(...paint)), draftMs: Math.round(draftMs), publishMs: Math.round(publishMs)};
    fs.writeFileSync('storage/temp/editor-performance.json', JSON.stringify(measurements, null, 2));
    expect(Math.max(...loads)).toBeLessThan(10000); expect(Math.max(...paint)).toBeLessThan(1000); expect(draftMs).toBeLessThan(10000);
});
