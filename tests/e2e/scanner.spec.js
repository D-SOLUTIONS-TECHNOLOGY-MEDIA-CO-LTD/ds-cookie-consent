// Scanner: "Quét ngay" (E11) end to end with the server-side fixture (cookies _fbp, wp-wpml_current_language and
// LittleD_unknown set by PHP, GA4 / Meta / Clarity / GTM snippets, the GTM container answered locally), the compliance
// alerts, the beacon collector in the browser (both layouts), the scanner settings tab and the admin AJAX guard.
// Nothing leaves the machine: vendor requests of the browser are intercepted, the PHP side answers GTM from the fixture.
const { test, expect } = require('@playwright/test');
const { resetSite, catalog, vendors, interceptVendors, wp, sql, ui } = require('./helpers');

const TAB = (name) => `/wp-admin/admin.php?page=dscc-settings&tab=${name}`;
const FLAGS = ['ga', 'meta', 'clarity', 'gtm', 'server_cookies', 'unknown_cookie', 'gtm_stub', 'scan_loopback'];

async function login(page) {
  await page.goto('/wp-login.php');
  await page.fill('#user_login', 'admin');
  await page.fill('#user_pass', 'password');
  await page.click('#wp-submit');
  await expect(page.locator('#wpadminbar')).toBeVisible();
}

/** Run "Quét ngay" from the Cookie tab and wait until the page has reloaded with the result. */
async function scanNow(page) {
  await page.goto(TAB('cookies'));
  await page.click('#dscc-scan-start');
  await expect(page.locator('#dscc-scan-text')).toContainText('Quét xong', { timeout: 90_000 });
  await page.waitForLoadState('load');
  await expect(page.locator('#dscc-scan-last')).toContainText('Lần quét gần nhất');
}

/** The row of a cookie in the catalog table. */
const row = (page, name) => page.locator('tr.dscc-ck-row', { has: page.locator('.dscc-ck-name', { hasText: new RegExp('^' + name.replace(/[*_.-]/g, '\\$&') + '$') }) });

/** [slug, detected_via[]] of every service in the catalog. */
function detected() {
  const out = {};
  sql('SELECT slug, detected_via FROM wp_dscc_services')
    .split('\n')
    .filter(Boolean)
    .forEach((l) => {
      const [slug, via] = l.split('\t');
      out[slug] = JSON.parse(via);
    });
  return out;
}

test.afterAll(() => {
  vendors('off');
});

test.describe('server scan (E11)', () => {
  test.describe.configure({ mode: 'serial' });
  let policyPage;

  test.beforeAll(() => {
    resetSite('card');
    policyPage = Number(catalog('page', 'LittleD cookie policy', 'Chúng tôi dùng cookie _ga để thống kê.').trim());
    resetSite('card', `content.cookie_policy_page_id=${policyPage}`, 'blocking.unclassified_cookie_mode=block');
    vendors('set', ...FLAGS);
  });

  test('Quét ngay records server cookies, classifies them and detects the platforms', async ({ page }) => {
    await login(page);
    await page.goto(TAB('cookies'));
    await expect(page.locator('#dscc-scan-last')).toContainText('Chưa có lượt quét nào');

    await scanNow(page);

    // Cookies: set by the server, classified by the known database, the unknown one is new.
    const fbp = row(page, '_fbp');
    await expect(fbp).toHaveCount(1);
    await expect(fbp.locator('td.column-set_by')).toHaveText('server');
    await expect(fbp.locator('.dscc-ck-category')).toHaveText('Tiếp thị');
    await expect(fbp.locator('.dscc-status')).toHaveText('Tự động');
    const wpml = row(page, 'wp-wpml_current_language');
    await expect(wpml.locator('.dscc-ck-category')).toHaveText('Cần thiết');
    await expect(wpml.locator('td.column-set_by')).toHaveText('server');
    const unknown = row(page, 'LittleD_unknown');
    await expect(unknown.locator('.dscc-status')).toHaveText('Mới');
    await expect(unknown.locator('.dscc-ck-category')).toHaveText('Chưa phân loại');
    await expect(unknown.locator('td.column-set_by')).toHaveText('server');
    // New cookies come first.
    expect(await page.locator('tr.dscc-ck-row').first().getAttribute('class')).toContain('dscc-ck-row--new');

    // Menu badge = new cookies.
    await expect(page.locator('#adminmenu .toplevel_page_dscc .update-plugins').first()).toHaveText('1');

    // Platforms: HTML snippets and the GTM container.
    const via = detected();
    expect(via['google-analytics']).toContain('html');
    expect(via['meta-pixel']).toContain('html');
    expect(via['microsoft-clarity']).toContain('html');
    expect(via['google-tag-manager']).toContain('html');
    expect(via['hotjar']).toContain('gtm');
    await expect(page.locator('#dscc-scan-platforms')).toContainText('Microsoft Clarity');
    await expect(page.locator('#dscc-scan-platforms')).toContainText('phát hiện qua GTM');

    // The platform picker shows the badge.
    await page.goto(TAB('platforms'));
    await expect(page.locator('[data-dscc-platform="microsoft-clarity"] .dscc-badge--detected')).toHaveText('Phát hiện trên site');
    await expect(page.locator('[data-dscc-platform="hotjar"] .dscc-badge--detected')).toHaveText('Phát hiện qua GTM');
    await expect(page.locator('[data-dscc-platform="tiktok-pixel"] .dscc-badge--detected')).toHaveCount(0);

    // Never a cookie value.
    const dump = sql('SELECT CONCAT_WS("|", name, purpose, domain, seen_on_urls) FROM wp_dscc_cookies') + sql('SELECT report FROM wp_dscc_scan_runs');
    expect(dump).not.toContain('LittleDSecretValue');
    expect(dump).not.toContain('fb.1.1700000000.LittleD');
  });

  test('compliance block lists what was set before consent and what the policy page misses', async ({ page }) => {
    await login(page);
    await page.goto(TAB('cookies'));
    const block = page.locator('#dscc-compliance');
    await expect(block).toHaveClass(/notice-error/);
    await expect(block.locator('#dscc-cw-before')).toBeVisible();
    await expect(block.locator('[data-dscc-cookie-name="_fbp"]')).toContainText('Tiếp thị');
    await expect(block.locator('[data-dscc-cookie-name="LittleD_unknown"]')).toContainText('Chưa phân loại');
    await expect(block.locator('[data-dscc-cookie-name="wp-wpml_current_language"]')).toHaveCount(0);
    await expect(block.locator('#dscc-cw-unclassified')).toContainText('1 cookie chưa phân loại');
    // The policy page only mentions _ga: the other seen cookies are missing from it.
    const policy = block.locator('#dscc-cw-policy ~ ul').first();
    await expect(policy).toContainText('_fbp');
    await expect(policy).toContainText('wp-wpml_current_language');
    await expect(policy).not.toContainText('_ga');

    // Same block on the overview screen, with category counts and the last scan time.
    await page.goto('/wp-admin/admin.php?page=dscc');
    await expect(page.locator('#dscc-compliance')).toHaveClass(/notice-error/);
    await expect(page.locator('#dscc-overview')).toContainText('Chưa phân loại: 1');
    await expect(page.locator('#dscc-overview')).toContainText('Tiếp thị: 1');
    await expect(page.locator('#dscc-overview-new')).toContainText('1 cookie mới');
    await expect(page.locator('#dscc-overview-scan')).toContainText('Lần quét cookie gần nhất');
    await expect(page.locator('.dscc-chart')).toHaveCount(2);
  });

  test('after the first scan the blocker covers the new findings: a second scan sees them held back', async ({ page, request }) => {
    await login(page);
    await scanNow(page);
    const block = page.locator('#dscc-compliance');
    // _fbp (meta-pixel is known now) and the unclassified cookie are no longer sent to a visitor without consent.
    await expect(block.locator('[data-dscc-cookie-name="_fbp"]')).toHaveCount(0);
    await expect(block.locator('[data-dscc-cookie-name="LittleD_unknown"]')).toHaveCount(0);
    await expect(block.locator('#dscc-cw-held')).toContainText('_fbp');
    await expect(block.locator('#dscc-cw-held')).toContainText('LittleD_unknown');
    // The safe ones were never touched.
    await expect(block.locator('#dscc-cw-held')).not.toContainText('wp-wpml_current_language');

    // A real first-time visitor: the same.
    const res = await request.get('/');
    const cookies = res.headersArray().filter((h) => h.name.toLowerCase() === 'set-cookie').map((h) => h.value.split('=')[0]);
    expect(cookies).toContain('wp-wpml_current_language');
    expect(cookies).not.toContain('_fbp');
    expect(cookies).not.toContain('LittleD_unknown');
  });

  test('a scan request is not cacheable and names the held cookies; without the token nothing is revealed', async ({ request }) => {
    const token = wp('eval', 'echo DSolutions\\CookieConsent\\Scanner\\ScanRequest::token();').trim();
    const ok = await request.get(`/?dscc_scan=${token}`);
    expect(ok.headers()['x-dscc-held']).toContain('_fbp');
    expect(ok.headers()['x-dscc-held']).toContain('LittleD_unknown');
    expect(ok.headers()['cache-control']).toMatch(/no-cache|no-store/);
    expect(ok.headers()['x-litespeed-cache-control']).toBe('no-cache');

    for (const bad of ['', 'abc', 'x'.repeat(24)]) {
      const res = await request.get(`/?dscc_scan=${bad}`);
      expect(res.headers()['x-dscc-held']).toBeUndefined();
    }
  });

  test('clearing the leak alerts needs the admin and the nonce', async ({ page, request }) => {
    const res = await request.post('/wp-admin/admin-post.php', { form: { action: 'dscc_alerts_clear' }, maxRedirects: 0 });
    expect([302, 400, 403]).toContain(res.status());
    await login(page);
    const status = await page.evaluate(async () => {
      const r = await fetch('/wp-admin/admin-post.php', { method: 'POST', body: new URLSearchParams({ action: 'dscc_alerts_clear' }), credentials: 'same-origin' });
      return r.status;
    });
    expect(status).toBe(403); // check_admin_referer fails without the nonce
  });

  test('the scan endpoints refuse requests without nonce or login', async ({ request, page }) => {
    const anon = await request.post('/wp-admin/admin-ajax.php', { form: { action: 'dscc_scan_start', nonce: 'x' } });
    expect(await anon.text()).toBe('0');
    await login(page);
    const before = sql('SELECT COUNT(*) FROM wp_dscc_scan_runs');
    const bad = await page.evaluate(async () => {
      const r = await fetch('/wp-admin/admin-ajax.php', { method: 'POST', body: new URLSearchParams({ action: 'dscc_scan_start', nonce: 'badnonce' }), credentials: 'same-origin' });
      return r.status;
    });
    expect(bad).toBe(403);
    expect(sql('SELECT COUNT(*) FROM wp_dscc_scan_runs')).toBe(before);
  });
});

test.describe('unclassified cookie handling', () => {
  test.afterEach(() => vendors('off'));

  test('"Cần thiết" files the cookie under Cần thiết in one click', async ({ page }) => {
    resetSite('card');
    vendors('set', 'server_cookies', 'unknown_cookie', 'scan_loopback');
    await login(page);
    await scanNow(page);
    const item = page.locator('#dscc-compliance [data-dscc-cookie-name="LittleD_unknown"]');
    await expect(item).toBeVisible();
    await item.locator('.dscc-qs-necessary').click();
    await expect(page.locator('#dscc-compliance [data-dscc-cookie-name="LittleD_unknown"]')).toHaveCount(0);
    await page.reload(); // the quick setup works without a reload; the table below only changes on the next load
    const r = row(page, 'LittleD_unknown');
    await expect(r.locator('.dscc-ck-category')).toHaveText('Cần thiết');
    await expect(r.locator('.dscc-status')).toHaveText('Đã xác nhận');
  });

  test('report-only mode lists the cookie but the server keeps sending it', async ({ page, request }) => {
    resetSite('card', 'blocking.unclassified_cookie_mode=report');
    vendors('set', 'unknown_cookie', 'scan_loopback');
    await login(page);
    await scanNow(page);
    await scanNow(page);
    await expect(page.locator('#dscc-compliance [data-dscc-cookie-name="LittleD_unknown"]')).toBeVisible();
    const res = await request.get('/');
    expect(res.headersArray().filter((h) => h.name.toLowerCase() === 'set-cookie').map((h) => h.value.split('=')[0])).toContain('LittleD_unknown');
  });

  test('the admin keep list files a cookie under Cần thiết and the server keeps sending it', async ({ page, request }) => {
    resetSite('card', 'blocking.keep_cookies=LittleD_*');
    vendors('set', 'unknown_cookie', 'scan_loopback');
    await login(page);
    await scanNow(page);
    await expect(row(page, 'LittleD_unknown').locator('.dscc-ck-category')).toHaveText('Cần thiết');
    const res = await request.get('/');
    expect(res.headersArray().filter((h) => h.name.toLowerCase() === 'set-cookie').map((h) => h.value.split('=')[0])).toContain('LittleD_unknown');

    await page.goto(TAB('blocking'));
    await expect(page.locator('#dscc-blocking-keep_cookies')).toHaveValue('LittleD_*');
    await expect(page.locator('#dscc-blocking-unclassified_cookie_mode')).toHaveValue('report');
  });
});

test.describe('scanner settings tab', () => {
  test.beforeEach(async ({ page }) => {
    resetSite('card');
    await login(page);
  });

  test('defaults are opt-in, save works, an invalid email is refused, weekly scan is scheduled', async ({ page }) => {
    await page.goto(TAB('scanner'));
    await expect(page.locator('#dscc-scanner-beacon_enabled')).not.toBeChecked();
    await expect(page.locator('#dscc-scanner-beacon_sample')).toHaveValue('5');
    await expect(page.locator('#dscc-scanner-auto_scan')).toHaveValue('off');
    await expect(page.locator('#dscc-scanner-max_urls')).toHaveValue('50');
    await expect(page.locator('#dscc-scanner-notify_enabled')).toBeChecked();

    await page.selectOption('#dscc-scanner-auto_scan', 'weekly');
    await page.fill('#dscc-scanner-max_urls', '12');
    await page.check('#dscc-scanner-beacon_enabled');
    await page.fill('#dscc-scanner-beacon_sample', '20');
    await page.fill('#dscc-scanner-notify_email', 'littled@example.test');
    await page.click('#submit');
    await expect(page.locator('.notice-success')).toContainText('Đã lưu');
    const s = JSON.parse(wp('option', 'get', 'dscc_settings', '--format=json')).scanner;
    expect(s).toMatchObject({ auto_scan: 'weekly', max_urls: 12, beacon_enabled: true, beacon_sample: 20, notify_email: 'littled@example.test' });
    expect(wp('cron', 'event', 'list', '--fields=hook,recurrence', '--format=csv')).toMatch(/dscc_scan_weekly,"?1 week"?/);

    // The server must refuse what the browser's own validation would also stop.
    await page.evaluate(() => (document.getElementById('dscc-settings-form').noValidate = true));
    await page.fill('#dscc-scanner-notify_email', 'not an email');
    await page.fill('#dscc-scanner-beacon_sample', '500');
    await page.selectOption('#dscc-scanner-auto_scan', 'off');
    await page.click('#submit');
    const t = JSON.parse(wp('option', 'get', 'dscc_settings', '--format=json')).scanner;
    expect(t.notify_email).toBe('littled@example.test');
    expect(t.beacon_sample).toBe(100);
    expect(wp('cron', 'event', 'list', '--fields=hook', '--format=csv')).not.toContain('dscc_scan_weekly');
  });
});

for (const layout of ['card', 'bar']) {
  test.describe(`beacon collector, layout ${layout}`, () => {
    let net;
    const consent = (c, s = {}) => encodeURIComponent(JSON.stringify({ v: '2026-11', id: 'LittleD-e2e', t: Math.floor(Date.now() / 1000), c, s, src: 'banner' }));

    test.beforeEach(async ({ context }) => {
      vendors('off');
      net = await interceptVendors(context);
    });

    /** Resolves with the first dscc_beacon request, or null after ms. */
    async function beaconRequest(page, ms) {
      try {
        return await page.waitForRequest((r) => r.method() === 'POST' && /admin-ajax\.php/.test(r.url()) && /action=dscc_beacon/.test(r.postData() || ''), { timeout: ms });
      } catch (e) {
        return null;
      }
    }

    test('opt-in: nothing is sent while it is off, even with a choice', async ({ page, context }) => {
      resetSite(layout);
      await context.addCookies([{ name: 'dscc_consent', value: consent({ analytics: 0, marketing: 0 }), url: 'http://localhost:8888/' }]);
      const pending = beaconRequest(page, 8000);
      await page.goto('/');
      expect(await pending).toBeNull();
      expect(JSON.parse(await page.locator('#dscc-data').textContent()).beacon).toBeNull();
    });

    test('reports names only, 5 s after load, with the visitor consent; a denied marketing cookie becomes a leak alert', async ({ page, context }) => {
      resetSite(layout, 'scanner.beacon_enabled=1', 'scanner.beacon_sample=100');
      // The site has Meta Pixel (so the platform exists); its block switch is off so the purger leaves the cookie for the beacon to see.
      catalog('enable', 'meta-pixel');
      catalog('block', 'meta-pixel', '0');
      await context.addCookies([
        { name: 'dscc_consent', value: consent({ analytics: 1, marketing: 0 }), url: 'http://localhost:8888/' },
        { name: '_fbp', value: 'fb.1.LittleDVALUE', url: 'http://localhost:8888/' },
        { name: '_ga', value: 'GA1.1.LittleDVALUE', url: 'http://localhost:8888/' },
        { name: 'LittleD_unknown', value: 'LittleDVALUE', url: 'http://localhost:8888/' },
      ]);
      const data = JSON.parse((await page.request.get('/').then((r) => r.text())).match(/id="dscc-data">(.*?)<\/script>/s)[1]);
      expect(data.beacon).toEqual({ rate: 100 });

      const started = Date.now();
      const pending = beaconRequest(page, 20000);
      await page.goto('/');
      await page.waitForLoadState('load');
      const req = await pending;
      expect(req).not.toBeNull();
      expect(Date.now() - started).toBeGreaterThanOrEqual(4500);

      const body = req.postData();
      expect(body.length).toBeLessThan(4096);
      expect(body).not.toContain('LittleDVALUE');
      const payload = JSON.parse(new URLSearchParams(body).get('d'));
      expect(payload.c).toEqual(expect.arrayContaining(['_fbp', '_ga', 'LittleD_unknown', 'dscc_consent']));
      expect(payload.s.c).toEqual({ analytics: 1, marketing: 0 });
      expect(payload.u).toBe('/');
      expect(Array.isArray(payload.h)).toBe(true);

      // Server side: merged as client-set, the leak alert exists, nothing about the visitor was stored.
      await expect.poll(() => sql("SELECT set_by FROM wp_dscc_cookies WHERE name = '_fbp'"), { timeout: 8000 }).toBe('client');
      expect(sql("SELECT status FROM wp_dscc_cookies WHERE name = 'LittleD_unknown'")).toBe('new');
      expect(sql("SELECT status FROM wp_dscc_cookies WHERE name = 'dscc_consent'")).toBe('auto');
      const alerts = JSON.parse(wp('option', 'get', 'dscc_alerts', '--format=json'));
      expect(Object.values(alerts.leaks).map((l) => l.name)).toEqual(['_fbp']);
      expect(JSON.stringify(alerts)).not.toContain('LittleDVALUE');

      // Only once per browser session.
      const again = beaconRequest(page, 7500);
      await page.reload();
      expect(await again).toBeNull();

      // The leak shows on the overview.
      const admin = await context.newPage();
      await login(admin);
      await admin.goto('/wp-admin/admin.php?page=dscc');
      await expect(admin.locator('#dscc-cw-leaks')).toBeVisible();
      await expect(admin.locator('[data-dscc-leak="_fbp"]')).toContainText('Tiếp thị');
      await admin.click('#dscc-alerts-clear');
      await expect(admin.locator('#dscc-cw-leaks')).toHaveCount(0);
    });

    test('nothing is sent before the visitor has made a choice, nor by a logged-in user', async ({ page, context }) => {
      resetSite(layout, 'scanner.beacon_enabled=1', 'scanner.beacon_sample=100');
      const pending = beaconRequest(page, 7500);
      await page.goto('/');
      await expect(ui(page, layout).banner).toBeVisible();
      expect(await pending).toBeNull();

      await context.addCookies([{ name: 'dscc_consent', value: consent({ analytics: 0, marketing: 0 }), url: 'http://localhost:8888/' }]);
      await login(page);
      const adminPending = beaconRequest(page, 7500);
      await page.goto('/');
      expect(await adminPending).toBeNull();
    });
  });
}
