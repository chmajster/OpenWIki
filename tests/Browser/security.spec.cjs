const {test, expect} = require('@playwright/test');
const {execFileSync} = require('node:child_process');
const {phpArguments} = require('./php-command.cjs');

test('sanitized fragments remain inert after browser parsing without CSP', async ({page}) => {
    const corpus = JSON.parse(execFileSync('php', [...phpArguments, 'tests/Browser/html-security.php'], {encoding: 'utf8'}));
    // about:blank has no response headers, including CSP.
    await page.goto('about:blank');
    for (const html of corpus.html) {
        await page.evaluate((html) => { window.__xss = 0; document.body.innerHTML = html; }, html);
        await page.waitForTimeout(50);
        const result = await page.evaluate((policy) => ({
            executed: window.__xss,
            text: document.body.textContent,
            unsafe: [...document.body.querySelectorAll('*')].flatMap((node) => {
                const tag = node.localName;
                const allowed = policy.global.concat(policy.attributes[tag] || []);
                return !policy.tags.includes(tag) ? [tag] : [...node.attributes]
                    .filter((attr) => !allowed.includes(attr.name) || /^on/i.test(attr.name)
                        || (['href', 'src'].includes(attr.name) && /javascript:|[\x00-\x20\\]/i.test(attr.value)))
                    .map((attr) => attr.name);
            })
        }), corpus.policy);
        expect(result.executed).toBe(0);
        if (html !== '') expect(result.text).toContain('retained');
        expect(result.unsafe).toEqual([]);
    }
});
