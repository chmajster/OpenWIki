const {expect} = require('@playwright/test');
async function openPublication(page) {
    if (!await page.locator('[data-publication-dialog]').isVisible()) {
        if (await page.locator('[data-save-button]').isDisabled()) await clickTool(page, '[data-open-publication]');
        else await page.locator('[data-save-button]').click();
    }
    await expect(page.locator('[data-publication-error]')).not.toHaveText('Sprawdzanie lokalizacji i uprawnień…');
}
async function publish(page) {
    await openPublication(page);
    await expect(page.locator('[data-confirm-publication]')).toBeEnabled();
    await page.locator('[data-confirm-publication]').click();
}
async function editMetadata(page, selector, action, value) {
    await openPublication(page);
    await page.locator(selector === '[name=parent_id]' ? '[data-publication-parent]' : selector)[action](value);
    await page.locator('[data-publication-dialog] [data-dialog-close]').click();
}
async function openMore(page) { if (await page.locator('.workspace-more').getAttribute('open') === null) await page.locator('.workspace-more>summary').click(); }
async function clickTool(page, selector) {
    const tool = page.locator(selector), details = tool.locator('xpath=ancestor::details[1]');
    if (await details.count() && await details.getAttribute('open') === null) await details.locator(':scope > summary').click();
    await tool.click();
}
module.exports = {openPublication, publish, editMetadata, openMore, clickTool};
