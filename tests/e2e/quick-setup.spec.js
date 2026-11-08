// Owner decision on unclassified cookies: default mode "Chỉ báo cáo", and each unclassified row of the compliance report
// (Dashboard and Cookie tab) can be resolved in place: Cần thiết, Phân tích, Tiếp thị, Luôn giữ, Bỏ qua, one row or a
// selection, by AJAX without a reload and as a plain form POST without JavaScript.
const { test, expect } = require('@playwright/test');
const { resetSite, catalog, vendors, sql } = require('./helpers');

const DASHBOARD = '/wp-admin/admin.php?page=dscc';
const COOKIES = '/wp-admin/admin.php?page=dscc-settings&tab=cookies';

async function login(page) {
  await page.goto('/wp-login.php');
  await page.fill('#user_login', 'admin');
  await page.fill('#user_pass', 'password');
  await page.click('#wp-submit');
  await expect(page.locator('#wpadminbar')).toBeVisible();
}

const rowOf = (page, name) => page.locator(`#dscc-cw-unclassified-box li[data-dscc-unclassified-name="${name}"]`);
const category = (name) => sql(`SELECT COALESCE(s.category_key, 'unclassified') FROM wp_dscc_cookies c LEFT JOIN wp_dscc_services s ON s.id = c.service_id WHERE c.name = '${name}'`);
const status = (name) => sql(`SELECT status FROM wp_dscc_cookies WHERE name = '${name}'`);

/** Names a first-time visitor's home page asks the browser to purge, and the cookies its response sets. */
async function frontend(request) {
  const res = await request.get('/');
  const html = await res.text();
  const setCookies = res.headersArray().filter((h) => h.name.toLowerCase() === 'set-cookie').map((h) => h.value.split('=')[0]);
  return { html, setCookies };
}

test.afterAll(() => {
  vendors('off');
});

test.describe('unclassified cookies: mode and quick setup', () => {
  test.describe.configure({ mode: 'serial' });

  test('the report shows the mode badge with a link to the Blocking tab', async ({ page }) => {
    resetSite('card');
    catalog('orphan', 'LittleD_orphan');
    await login(page);
    for (const url of [DASHBOARD, COOKIES]) {
      await page.goto(url);
      await expect(page.locator('#dscc-mode-badge')).toHaveText('Chỉ báo cáo');
      await expect(page.locator('#dscc-mode-link')).toHaveAttribute('href', /tab=blocking#dscc-blocking-unclassified_cookie_mode/);
    }
    await page.click('#dscc-mode-link');
    await expect(page.locator('#dscc-blocking-unclassified_cookie_mode')).toHaveValue('report');
    await expect(page.locator('#dscc-blocking-unclassified_cookie_mode option[value="block"]')).toHaveCount(1);
    await expect(page.locator('#dscc-blocking-unclassified_cookie_mode')).toBeVisible();

    resetSite('card', 'blocking.unclassified_cookie_mode=block');
    await page.goto(COOKIES);
    await expect(page.locator('#dscc-mode-badge')).toHaveText('Đang chặn');
  });

  for (const layout of ['card', 'bar']) {
    test(`[${layout}] Tiếp thị on one row (Dashboard): no reload, and the front end starts enforcing the cookie`, async ({ page, request }) => {
      resetSite(layout);
      vendors('set', 'unknown_cookie', 'scan_loopback');
      await login(page);
      // Two scans: the first catalogues the cookie; in report mode it is neither stripped nor listed for purging.
      await page.goto(COOKIES);
      await page.click('#dscc-scan-start');
      await expect(page.locator('#dscc-scan-text')).toContainText('Quét xong', { timeout: 90_000 });
      await page.waitForLoadState('load');

      let front = await frontend(request);
      expect(front.setCookies).toContain('LittleD_unknown');
      expect(front.html).not.toContain('"LittleD_unknown"');

      await page.goto(DASHBOARD);
      await expect(page.locator('#dscc-mode-badge')).toHaveText('Chỉ báo cáo');
      await expect(rowOf(page, 'LittleD_unknown')).toBeVisible();
      await page.evaluate(() => {
        window.__noReload = true;
      });
      await rowOf(page, 'LittleD_unknown').locator('.dscc-qs-marketing').click();
      await expect(page.locator('#dscc-compliance [data-dscc-cookie-name="LittleD_unknown"]')).toHaveCount(0);
      expect(await page.evaluate(() => window.__noReload)).toBe(true);

      expect(category('LittleD_unknown')).toBe('marketing');
      expect(status('LittleD_unknown')).toBe('confirmed');
      front = await frontend(request);
      expect(front.setCookies).not.toContain('LittleD_unknown');
      expect(front.html).toContain('LittleD_unknown');
    });
  }

  test('bulk Cần thiết on a selection (Cookie tab) removes the rows without a reload', async ({ page }) => {
    resetSite('card');
    catalog('orphan', 'LittleD_b1');
    catalog('orphan', 'LittleD_b2');
    catalog('orphan', 'LittleD_b3');
    await login(page);
    await page.goto(COOKIES);
    await page.evaluate(() => {
      window.__noReload = true;
    });
    await page.locator('#dscc-cw-unclassified-box li[data-dscc-unclassified-name="LittleD_b1"] .dscc-qs-check').check();
    await page.locator('#dscc-cw-unclassified-box li[data-dscc-unclassified-name="LittleD_b2"] .dscc-qs-check').check();
    await page.selectOption('#dscc-quick-action', 'necessary');
    await page.click('#dscc-quick-apply');
    await expect(rowOf(page, 'LittleD_b1')).toHaveCount(0);
    await expect(rowOf(page, 'LittleD_b2')).toHaveCount(0);
    await expect(rowOf(page, 'LittleD_b3')).toHaveCount(1);
    await expect(page.locator('#dscc-cw-unclassified')).toContainText('1 cookie');
    expect(await page.evaluate(() => window.__noReload)).toBe(true);
    expect([category('LittleD_b1'), category('LittleD_b2'), category('LittleD_b3')]).toEqual(['necessary', 'necessary', 'unclassified']);

    // The last row goes: the whole section disappears.
    await rowOf(page, 'LittleD_b3').locator('.dscc-qs-ignore').click();
    await expect(page.locator('#dscc-cw-unclassified-box')).toHaveCount(0);
    expect(status('LittleD_b3')).toBe('ignored');
  });

  test('Luôn giữ adds the name to the keep list', async ({ page }) => {
    resetSite('card');
    catalog('orphan', 'LittleD_keepme');
    await login(page);
    await page.goto(COOKIES);
    await rowOf(page, 'LittleD_keepme').locator('.dscc-qs-keep').click();
    await expect(rowOf(page, 'LittleD_keepme')).toHaveCount(0);
    await page.goto('/wp-admin/admin.php?page=dscc-settings&tab=blocking');
    await expect(page.locator('#dscc-blocking-keep_cookies')).toHaveValue(/LittleD_keepme/);
    expect(category('LittleD_keepme')).toBe('necessary');
  });

  test('without JavaScript the buttons post to admin-post and come back to the report', async ({ browser }) => {
    resetSite('card');
    catalog('orphan', 'LittleD_nojs1');
    catalog('orphan', 'LittleD_nojs2');
    const context = await browser.newContext({ javaScriptEnabled: false, baseURL: 'http://localhost:8888' });
    const page = await context.newPage();
    await page.goto('/wp-login.php');
    await page.fill('#user_login', 'admin');
    await page.fill('#user_pass', 'password');
    await page.click('#wp-submit');
    await page.goto(COOKIES);
    await rowOf(page, 'LittleD_nojs1').locator('.dscc-qs-analytics').click();
    await expect(page).toHaveURL(/tab=cookies/);
    await expect(rowOf(page, 'LittleD_nojs1')).toHaveCount(0);
    expect(category('LittleD_nojs1')).toBe('analytics');

    await rowOf(page, 'LittleD_nojs2').locator('.dscc-qs-check').check();
    await page.selectOption('#dscc-quick-action', 'ignore');
    await page.click('#dscc-quick-apply');
    await expect(rowOf(page, 'LittleD_nojs2')).toHaveCount(0);
    expect(status('LittleD_nojs2')).toBe('ignored');
    await context.close();
  });

  test('the action needs the admin and the nonce', async ({ request, page }) => {
    resetSite('card');
    catalog('orphan', 'LittleD_guard');
    const id = sql("SELECT id FROM wp_dscc_cookies WHERE name = 'LittleD_guard'");
    const anon = await request.post('/wp-admin/admin-ajax.php', { form: { action: 'dscc_quick_setup', nonce: 'x', row_action: `necessary:${id}` } });
    expect(await anon.text()).toBe('0');
    await login(page);
    const bad = await page.evaluate(async (cookie) => {
      const r = await fetch('/wp-admin/admin-ajax.php', { method: 'POST', body: new URLSearchParams({ action: 'dscc_quick_setup', nonce: 'badnonce', row_action: `necessary:${cookie}` }), credentials: 'same-origin' });
      return r.status;
    }, id);
    expect(bad).toBe(403);
    const post = await page.evaluate(async (cookie) => {
      const r = await fetch('/wp-admin/admin-post.php', { method: 'POST', body: new URLSearchParams({ action: 'dscc_quick_setup', row_action: `necessary:${cookie}` }), credentials: 'same-origin' });
      return r.status;
    }, id);
    expect(post).toBe(403);
    expect(category('LittleD_guard')).toBe('unclassified');
  });
});
