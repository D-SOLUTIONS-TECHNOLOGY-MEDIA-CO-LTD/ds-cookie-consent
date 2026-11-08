// Export / import with preview: the bundled preset, uploaded files, the export download.
const { test, expect } = require('@playwright/test');
const helpers = require('./helpers');
const { sql, wp } = helpers;

/** These specs check the English source strings, so the site language is English. */
const resetSite = (layout, ...pairs) => helpers.resetSite(layout, 'site.locale=en_US', ...pairs);

const SETTINGS = '/wp-admin/admin.php?page=dscc-settings';

async function login(page) {
  await page.goto('/wp-login.php');
  await page.fill('#user_login', 'admin');
  await page.fill('#user_pass', 'password');
  await page.click('#wp-submit');
  await expect(page.locator('#wpadminbar')).toBeVisible();
}

test.describe('export and import', () => {
  test.beforeEach(async ({ page }) => {
    resetSite('card');
    await login(page);
  });

  test.afterEach(() => resetSite('card'));

  test('the bundled preset is previewed first and only loaded by the Apply click', async ({ page }) => {
    expect(sql('SELECT COUNT(*) FROM wp_dscc_services')).toBe('0');
    await page.goto(SETTINGS + '&tab=transfer');
    await page.selectOption('#dscc-import-preset', 'd-solutions-standard');
    await page.locator('#dscc-import-preview-button').click();

    const preview = page.locator('#dscc-import-preview');
    await expect(preview).toBeVisible();
    await expect(preview).toContainText('D-Solutions — Standard');
    const services = await preview.locator('tr[data-dscc-row="services"] td').allTextContents();
    expect(Number(services[0])).toBe(13);
    expect(sql('SELECT COUNT(*) FROM wp_dscc_services'), 'the preview wrote nothing').toBe('0');

    await page.locator('#dscc-import-apply').click();
    await expect(page.locator('.dscc-flash')).toContainText('Import finished');
    expect(sql('SELECT COUNT(*) FROM wp_dscc_services')).toBe('13');
    expect(sql('SELECT COUNT(*) FROM wp_dscc_cookies')).toBe('34');
    expect(wp('eval', 'echo \\DSolutions\\CookieConsent\\Settings\\Settings::get("advanced","cookie_name");')).toBe('dscc_consent');

    // Importing it again shows nothing to do.
    await page.selectOption('#dscc-import-preset', 'd-solutions-standard');
    await page.locator('#dscc-import-preview-button').click();
    await expect(page.locator('#dscc-import-nothing')).toBeVisible();
    await expect(page.locator('#dscc-import-apply')).toHaveCount(0);
  });

  test('a file you upload is previewed; a malformed one is refused and changes nothing', async ({ page }) => {
    await page.goto(SETTINGS + '&tab=transfer');
    await page.setInputFiles('#dscc-import-file', { name: 'bad.json', mimeType: 'application/json', buffer: Buffer.from('{"format":"other"}') });
    await page.locator('#dscc-import-preview-button').click();
    await expect(page.locator('.dscc-flash.notice-error')).toContainText('not a DS Cookie Consent export file');
    await expect(page.locator('#dscc-import-preview')).toHaveCount(0);

    await page.setInputFiles('#dscc-import-file', { name: 'broken.json', mimeType: 'application/json', buffer: Buffer.from('{not json') });
    await page.locator('#dscc-import-preview-button').click();
    await expect(page.locator('.dscc-flash.notice-error')).toContainText('not a valid JSON object');

    const good = { format: 'dscc-export', version: 1, settings: { appearance: { color_primary: '#445566' } }, services: [{ slug: 'littled-e2e', name: 'LittleD E2E', category_key: 'analytics' }] };
    await page.setInputFiles('#dscc-import-file', { name: 'good.json', mimeType: 'application/json', buffer: Buffer.from(JSON.stringify(good)) });
    await page.locator('#dscc-import-preview-button').click();
    await expect(page.locator('#dscc-import-settings')).toContainText('#445566');
    expect(sql("SELECT COUNT(*) FROM wp_dscc_services WHERE slug='littled-e2e'")).toBe('0');
    await page.locator('#dscc-import-apply').click();
    await expect(page.locator('.dscc-flash')).toContainText('Import finished');
    expect(sql("SELECT name FROM wp_dscc_services WHERE slug='littled-e2e'")).toBe('LittleD E2E');
  });

  test('the export downloads a dscc-export file that has no log and no site-bound values', async ({ page }) => {
    await page.goto(SETTINGS + '&tab=transfer');
    const [download] = await Promise.all([page.waitForEvent('download'), page.locator('#dscc-export-download').click()]);
    expect(download.suggestedFilename()).toMatch(/^ds-cookie-consent-.+-\d{8}\.json$/);
    const path = await download.path();
    const doc = JSON.parse(require('node:fs').readFileSync(path, 'utf8'));
    expect(doc.format).toBe('dscc-export');
    expect(doc.version).toBe(1);
    expect(doc.settings.advanced).not.toHaveProperty('policy_version');
    expect(doc.settings.appearance).not.toHaveProperty('logo_id');
    expect(doc).not.toHaveProperty('log');
  });
});
