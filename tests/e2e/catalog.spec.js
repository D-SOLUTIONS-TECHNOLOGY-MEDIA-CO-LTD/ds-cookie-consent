// Front end catalog: confirmed platforms and cookies in the popup (vendor list, cookie tables), per-service switches
// feeding the "s" map, analytics-only state (E4 at consent-state level), withdraw with services, shortcode page.
const { test, expect } = require('@playwright/test');
const { resetSite, catalog, waitForLog, logServices, consentUpdates, consentCookie, consentDefault, ui } = require('./helpers');

const POLICY_REL = 'nofollow noopener noreferrer';

test.describe('catalog in the popup (bar layout)', () => {
  test.beforeEach(() => {
    resetSite('bar');
    catalog('enable', 'google-analytics', 'microsoft-clarity', 'meta-pixel', 'wpml');
  });

  test('vendor list per category with policy links, "Xem cookie (n)" opens the cookie table', async ({ page }) => {
    await page.goto('/');
    await ui(page, 'bar').customize.click();
    const analytics = page.locator('#dscc [data-dscc-cat="analytics"]');
    await analytics.locator('[data-dscc-action="toggle-details"]').click();
    await expect(analytics.locator('.dscc-vendor')).toHaveCount(2);
    await expect(analytics.locator('.dscc-vendor__name')).toHaveText(['Google Analytics (GA4)', 'Microsoft Clarity']);

    // Vendor policy links open in a new tab and carry nofollow noopener noreferrer.
    const link = analytics.locator('.dscc-vendor a').first();
    expect(await link.getAttribute('target')).toBe('_blank');
    expect(await link.getAttribute('rel')).toBe(POLICY_REL);
    expect(await link.getAttribute('href')).toBe('https://policies.google.com/privacy');

    const ga = analytics.locator('[data-dscc-service-row="google-analytics"]');
    await ga.locator('[data-dscc-action="toggle-cookies"]').click();
    const rows = ga.locator('.dscc-ck tbody tr');
    await expect(rows).toHaveCount(4);
    const names = await ga.locator('.dscc-ck tbody tr td:first-child').allTextContents();
    expect(names).toEqual(expect.arrayContaining(['_ga', '_gid', '_ga_<id>', '_gat_UA-<id>']));
    expect(await ga.locator('.dscc-ck thead th').allTextContents()).toEqual(['Cookie name', 'Set by', 'Duration', 'Purpose']);

    // Necessary vendors show "Always on", never a switch.
    const necessary = page.locator('#dscc [data-dscc-cat="necessary"]');
    await necessary.locator('[data-dscc-action="toggle-details"]').click();
    await expect(necessary.locator('[data-dscc-service-row="wpml"] .dscc-vendor__on')).toBeVisible();
    await expect(necessary.locator('input[data-dscc-service]')).toHaveCount(0);
  });

  test('cookies that are new or ignored never reach visitors', async ({ page }) => {
    catalog('preset');
    catalog('service', 'littled-widget', 'LittleD Widget', 'marketing', 'auto');
    catalog('newcookie', 'littled-widget', 'littled-widget-id-*');
    catalog('newcookie', 'google-analytics', 'LittleD_pending');
    catalog('disable', 'meta-pixel');
    await page.goto('/');
    await ui(page, 'bar').customize.click();
    const html = await page.locator('#dscc').evaluate((el) => el.outerHTML + document.getElementById('dscc-tpl-modal').innerHTML);
    for (const hidden of ['littled-widget', 'LittleD_pending', '_fbp', 'meta-pixel']) expect(html).not.toContain(hidden);
    expect(html).toContain('google-analytics');
    expect(await page.locator('#dscc input[data-dscc-service="meta-pixel"]').count()).toBe(0);
  });

  test('policy tab lists every confirmed vendor table when no policy page is configured', async ({ page }) => {
    await page.goto('/');
    await page.evaluate(() => window.DSCC.open('cookies'));
    const panel = page.locator('#dscc-panel-cookies');
    await expect(panel.locator('h3')).toHaveCount(4);
    await expect(panel.locator('a[rel="' + POLICY_REL + '"]').first()).toBeVisible();
  });
});

test.describe('catalog in the card layout', () => {
  test('"Xem chi tiết cookie" opens the cookie list from the catalog', async ({ page }) => {
    resetSite('card');
    catalog('enable', 'google-analytics', 'meta-pixel');
    await page.goto('/');
    await ui(page, 'card').customize.click();
    await page.locator('#dscc .dscc-card__more [data-dscc-action="open-tab"]').click();
    const panel = page.locator('#dscc-panel-cookies');
    await expect(panel).toBeVisible();
    await expect(panel.locator('table')).toHaveCount(2);
    await expect(panel.locator('td', { hasText: '_fbp' })).toBeVisible();
  });
});

test.describe('per-service toggles bound to the consent s map', () => {
  test.beforeEach(() => {
    resetSite('bar');
    catalog('enable', 'google-analytics', 'microsoft-clarity', 'meta-pixel');
  });

  test('one service on: s map, category follows, log, Consent Mode, DSCC.has(slug)', async ({ page, context }) => {
    await page.goto('/');
    await ui(page, 'bar').customize.click();
    const analyticsCat = page.locator('#dscc input[data-dscc-category="analytics"]');
    const marketingCat = page.locator('#dscc input[data-dscc-category="marketing"]');
    await page.locator('#dscc [data-dscc-cat="analytics"] [data-dscc-action="toggle-details"]').click();
    await page.locator('#dscc input[data-dscc-service="google-analytics"]').evaluate((el) => el.click());
    await expect(analyticsCat).toBeChecked();
    await expect(page.locator('#dscc input[data-dscc-service="microsoft-clarity"]')).not.toBeChecked();
    await expect(marketingCat).not.toBeChecked();
    await page.locator('#dscc .dscc-modal [data-dscc-action="save"]').click();

    const cookie = await consentCookie(context);
    expect(cookie.c).toEqual({ analytics: 1, marketing: 0 });
    expect(cookie.s).toEqual({ 'google-analytics': 1, 'microsoft-clarity': 0, 'meta-pixel': 0 });
    expect(await page.evaluate(() => [window.DSCC.has('google-analytics'), window.DSCC.has('microsoft-clarity'), window.DSCC.has('meta-pixel'), window.DSCC.has('analytics'), window.DSCC.has('marketing')])).toEqual([true, false, false, true, false]);
    expect(await page.evaluate(() => window.DSCC.get().services)).toEqual({ 'google-analytics': true, 'microsoft-clarity': false, 'meta-pixel': false });

    const rows = await waitForLog(1);
    expect(rows[0]).toMatchObject({ choice: 'custom', analytics: 1, marketing: 0, src: 'prefs' });
    expect(logServices()[0]).toEqual({ 'google-analytics': 1, 'microsoft-clarity': 0, 'meta-pixel': 0 });
    const updates = await consentUpdates(page);
    expect(updates[updates.length - 1]).toMatchObject({ analytics_storage: 'granted', ad_storage: 'denied' });
  });

  test('group toggle switches every vendor of the group, and turning the last vendor off clears the group', async ({ page }) => {
    await page.goto('/');
    await ui(page, 'bar').customize.click();
    await page.locator('#dscc [data-dscc-cat="analytics"] [data-dscc-action="toggle-details"]').click();
    const group = page.locator('#dscc input[data-dscc-category="analytics"]');
    const ga = page.locator('#dscc input[data-dscc-service="google-analytics"]');
    const clarity = page.locator('#dscc input[data-dscc-service="microsoft-clarity"]');
    await group.evaluate((el) => el.click());
    await expect(ga).toBeChecked();
    await expect(clarity).toBeChecked();
    await ga.evaluate((el) => el.click());
    await expect(group).toBeChecked();
    await clarity.evaluate((el) => el.click());
    await expect(group).not.toBeChecked();
  });

  test('E4 analytics only: consent state and Consent Mode, kept on the next page view', async ({ page, context }) => {
    await page.goto('/');
    await ui(page, 'bar').customize.click();
    await page.locator('#dscc input[data-dscc-category="analytics"]').evaluate((el) => el.click());
    await page.locator('#dscc .dscc-modal [data-dscc-action="save"]').click();

    const cookie = await consentCookie(context);
    expect(cookie.c).toEqual({ analytics: 1, marketing: 0 });
    expect(cookie.s).toEqual({ 'google-analytics': 1, 'microsoft-clarity': 1, 'meta-pixel': 0 });
    const updates = await consentUpdates(page);
    expect(updates[updates.length - 1]).toMatchObject({ analytics_storage: 'granted', ad_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied' });
    expect(await page.evaluate(() => [window.DSCC.has('analytics'), window.DSCC.has('marketing')])).toEqual([true, false]);

    await page.goto('/?page_view=2');
    expect(await consentDefault(page)).toMatchObject({ analytics_storage: 'granted', ad_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied' });
    await expect(ui(page, 'bar').banner).toBeHidden();
    expect(await page.evaluate(() => window.DSCC.has('meta-pixel'))).toBe(false);
    expect(await page.evaluate(() => window.DSCC.has('google-analytics'))).toBe(true);
  });

  test('withdraw: a visitor who had one service on and switches every service off', async ({ page }) => {
    await page.goto('/');
    await ui(page, 'bar').customize.click();
    await page.locator('#dscc [data-dscc-cat="analytics"] [data-dscc-action="toggle-details"]').click();
    await page.locator('#dscc input[data-dscc-service="google-analytics"]').evaluate((el) => el.click());
    await page.locator('#dscc .dscc-modal [data-dscc-action="save"]').click();
    await waitForLog(1);

    await ui(page, 'bar').widget.click();
    await page.locator('#dscc [data-dscc-cat="analytics"] [data-dscc-action="toggle-details"]').click();
    const ga = page.locator('#dscc input[data-dscc-service="google-analytics"]');
    await expect(ga).toBeChecked();
    await ga.evaluate((el) => el.click());
    await page.locator('#dscc .dscc-modal [data-dscc-action="save"]').click();

    const rows = await waitForLog(2);
    expect(rows[1]).toMatchObject({ choice: 'withdraw', analytics: 0, marketing: 0, src: 'widget' });
    expect(logServices()[1]).toEqual({ 'google-analytics': 0, 'microsoft-clarity': 0, 'meta-pixel': 0 });
  });

  test('accept all sets every service, reject afterwards is a withdrawal', async ({ page, context }) => {
    await page.goto('/');
    await ui(page, 'bar').accept.click();
    expect((await consentCookie(context)).s).toEqual({ 'google-analytics': 1, 'microsoft-clarity': 1, 'meta-pixel': 1 });
    await waitForLog(1);
    await page.evaluate(() => window.DSCC.reset());
    await waitForLog(2);
    await page.reload();
    await ui(page, 'bar').reject.click();
    const rows = await waitForLog(3);
    expect(rows.map((r) => r.choice)).toEqual(['accept_all', 'withdraw', 'reject_all']);
  });
});

test.describe('shortcode page', () => {
  test('[dscc_cookie_table] renders the three policy tables from the D-Solutions preset, [dscc_last_scan] a date', async ({ page }) => {
    resetSite('card');
    catalog('preset');
    catalog('newcookie', 'google-analytics', 'LittleD_pending');
    const id = catalog('page', 'LittleD cookie policy', '<p>Danh sach cap nhat: [dscc_last_scan]</p>[dscc_cookie_table lang="vi"]').trim();
    await page.goto('/?page_id=' + id);

    const tables = page.locator('.dscc-cookie-table');
    await expect(tables).toHaveCount(3);
    expect(await page.locator('.dscc-cookie-table__title').allTextContents()).toEqual(['Cookie cần thiết', 'Cookie phân tích', 'Cookie tiếp thị']);
    for (const t of await tables.all()) {
      expect(await t.locator('thead th').allTextContents()).toEqual(['Cookie', 'Bên đặt', 'Mục đích', 'Thời hạn', 'Áp dụng tại']);
    }
    expect(await page.locator('.dscc-cookie-table--necessary tbody tr').count()).toBe(12);
    expect(await page.locator('.dscc-cookie-table--analytics tbody tr').count()).toBe(8);
    expect(await page.locator('.dscc-cookie-table--marketing tbody tr').count()).toBe(13);
    const ga = page.locator('.dscc-cookie-table--analytics tbody tr', { hasText: '_gid' });
    await expect(ga.locator('td').nth(0)).toHaveText('_gid');
    await expect(ga.locator('td').nth(1)).toHaveText('Google Analytics (GA4)');
    await expect(ga.locator('td').nth(2)).toHaveText('Phân biệt người dùng trong ngày');
    await expect(ga.locator('td').nth(3)).toHaveText('24 giờ');
    await expect(ga.locator('td').nth(4)).toHaveText(new URL(page.url()).hostname);
    const link = ga.locator('td').nth(1).locator('a');
    expect(await link.getAttribute('target')).toBe('_blank');
    expect(await link.getAttribute('rel')).toBe(POLICY_REL);
    const yt = page.locator('.dscc-cookie-table--marketing tbody tr', { hasText: 'YouTube' }).last();
    await expect(yt.locator('td').nth(0)).toHaveText('YSC, __Secure-ROLLOUT_TOKEN');
    await expect(yt.locator('td').nth(3)).toHaveText('YSC: Phiên; __Secure-ROLLOUT_TOKEN: 180 ngày');
    await expect(yt.locator('td').nth(4)).toHaveText('.youtube.com');
    await expect(page.locator('.dscc-cookie-table--analytics td', { hasText: '_ga_<mã>' })).toBeVisible();
    await expect(page.locator('.dscc-cookie-table--necessary td', { hasText: 'wordpress_test_cookie' })).toBeVisible();
    await expect(page.locator('body')).not.toContainText('LittleD_pending');
    await expect(page.locator('body')).toContainText(/Danh sach cap nhat: \d{2}\/\d{2}\/\d{4}/);
  });
});

test.describe('card layout: s map and partial consent', () => {
  test.beforeEach(() => {
    resetSite('card');
    catalog('enable', 'google-analytics', 'microsoft-clarity', 'meta-pixel');
  });

  test('analytics only: services follow their category, DSCC.has, log and next page view', async ({ page, context }) => {
    await page.goto('/');
    await ui(page, 'card').customize.click();
    await page.locator('#dscc input[data-dscc-category="analytics"]').evaluate((el) => el.click());
    await expect(page.locator('#dscc input[data-dscc-category="marketing"]')).not.toBeChecked();
    await page.locator('#dscc [data-dscc-view="prefs"] [data-dscc-action="save"]').click();

    const cookie = await consentCookie(context);
    expect(cookie.c).toEqual({ analytics: 1, marketing: 0 });
    expect(cookie.s).toEqual({ 'google-analytics': 1, 'microsoft-clarity': 1, 'meta-pixel': 0 });
    expect(await page.evaluate(() => [window.DSCC.has('google-analytics'), window.DSCC.has('meta-pixel'), window.DSCC.has('analytics'), window.DSCC.has('marketing')])).toEqual([true, false, true, false]);
    const rows = await waitForLog(1);
    expect(rows[0]).toMatchObject({ choice: 'custom', analytics: 1, marketing: 0, src: 'prefs' });
    expect(logServices()[0]).toEqual({ 'google-analytics': 1, 'microsoft-clarity': 1, 'meta-pixel': 0 });
    const updates = await consentUpdates(page);
    expect(updates[updates.length - 1]).toMatchObject({ analytics_storage: 'granted', ad_storage: 'denied' });

    await page.goto('/?page_view=2');
    expect(await consentDefault(page)).toMatchObject({ analytics_storage: 'granted', ad_storage: 'denied' });
    await expect(ui(page, 'card').banner).toBeHidden();
    await ui(page, 'card').widget.click();
    await expect(page.locator('#dscc input[data-dscc-category="analytics"]')).toBeChecked();
    await expect(page.locator('#dscc input[data-dscc-category="marketing"]')).not.toBeChecked();
  });

  test('accept all sets every service; turning everything off afterwards is a withdrawal', async ({ page, context }) => {
    await page.goto('/');
    await ui(page, 'card').accept.click();
    expect((await consentCookie(context)).s).toEqual({ 'google-analytics': 1, 'microsoft-clarity': 1, 'meta-pixel': 1 });
    await waitForLog(1);
    await ui(page, 'card').widget.click();
    await page.locator('#dscc input[data-dscc-category="analytics"]').evaluate((el) => el.click());
    await page.locator('#dscc input[data-dscc-category="marketing"]').evaluate((el) => el.click());
    await page.locator('#dscc [data-dscc-view="prefs"] [data-dscc-action="save"]').click();
    const rows = await waitForLog(2);
    expect(rows[1]).toMatchObject({ choice: 'withdraw', analytics: 0, marketing: 0, src: 'widget' });
    expect((await consentCookie(context)).s).toEqual({ 'google-analytics': 0, 'microsoft-clarity': 0, 'meta-pixel': 0 });
  });
});
