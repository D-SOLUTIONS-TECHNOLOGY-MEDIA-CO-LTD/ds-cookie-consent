// Headless deep scan of the running dev site, pushed to dscc/v1/scan-result with an Application Password, then the
// compliance report in the admin (Dashboard and Cookie tab). Both layouts. Blocker on: no violations; observe mode:
// violations are listed per phase. Third-party hosts are stubbed in the driver; only localhost is contacted.
const { test, expect } = require('@playwright/test');
const { execFileSync } = require('node:child_process');
const path = require('node:path');
const { resetSite, catalog, vendors, wp, ROOT } = require('./helpers');

const DRIVER = path.join(__dirname, 'deep-scan-driver.mjs');
const PLATFORMS = ['google-analytics', 'meta-pixel', 'microsoft-clarity'];
const APP_NAME = 'dscc-e2e-deep-scan';
const TAB = (name) => `/wp-admin/admin.php?page=dscc-settings&tab=${name}`;

async function login(page) {
  await page.goto('/wp-login.php');
  await page.fill('#user_login', 'admin');
  await page.fill('#user_pass', 'password');
  await page.click('#wp-submit');
  await expect(page.locator('#wpadminbar')).toBeVisible();
}

function removeAppPasswords() {
  const list = JSON.parse(wp('user', 'application-password', 'list', 'admin', '--fields=uuid,name', '--format=json') || '[]');
  list.filter((p) => p.name === APP_NAME).forEach((p) => wp('user', 'application-password', 'delete', 'admin', p.uuid));
}

/** Creates a throw-away Application Password, runs the driver with it in the environment only, returns the push result. */
function deepScan(extraEnv = {}) {
  removeAppPasswords();
  const password = wp('user', 'application-password', 'create', 'admin', APP_NAME, '--porcelain').trim();
  try {
    const out = execFileSync('node', [DRIVER], {
      cwd: ROOT,
      encoding: 'utf8',
      env: { ...process.env, DSCC_USER: 'admin', DSCC_APP_PASSWORD: password, ...extraEnv },
      stdio: ['ignore', 'pipe', 'pipe'],
      timeout: 120_000,
    });
    const result = JSON.parse(out.trim().split('\n').pop());
    return { ...result, body: JSON.parse(result.body || '{}') };
  } finally {
    removeAppPasswords();
  }
}

test.afterAll(() => {
  vendors('off');
  removeAppPasswords();
});

for (const layout of ['card', 'bar']) {
  test.describe(`deep scan, layout ${layout}`, () => {
    test.describe.configure({ mode: 'serial' });

    test(`[${layout}] blocker on: the scan is stored and the report lists no violations`, async ({ page }) => {
      resetSite(layout);
      catalog('enable', ...PLATFORMS);
      vendors('set', 'ga', 'meta', 'clarity', 'server_cookies');
      const res = deepScan();
      expect(res.status).toBe(200);
      expect(res.banner).toBe(true);
      expect(res.plugin).toBe(true);
      expect(res.body.violations).toEqual({ before_choice: 0, after_reject: 0 });
      expect(res.body.conclusive).toBe(true);

      await login(page);
      for (const url of ['/wp-admin/admin.php?page=dscc', TAB('cookies')]) {
        await page.goto(url);
        const deep = page.locator('#dscc-deep-scan');
        await expect(deep).toBeVisible();
        await expect(deep.locator('#dscc-deep-summary')).toContainText('localhost:8888');
        await expect(deep.locator('#dscc-deep-summary')).toContainText('banner: có');
        await expect(deep.locator('#dscc-deep-age')).toHaveText('Quét hôm nay.');
        await expect(deep.locator('#dscc-deep-inconclusive')).toHaveCount(0);
        await expect(deep.locator('#dscc-deep-ok')).toBeVisible();
        await expect(deep.locator('li[data-dscc-deep-violation]')).toHaveCount(0);
      }
    });

    test(`[${layout}] observe mode: tracking runs before a choice and the report lists the violations per phase`, async ({ page }) => {
      resetSite(layout, 'blocking.mode=observe');
      catalog('enable', ...PLATFORMS);
      vendors('set', 'ga', 'meta', 'clarity', 'server_cookies');
      const res = deepScan();
      expect(res.status).toBe(200);
      expect(res.body.violations.before_choice).toBeGreaterThan(0);
      expect(res.body.violations.after_reject).toBeGreaterThan(0);

      await login(page);
      for (const url of ['/wp-admin/admin.php?page=dscc', TAB('cookies')]) {
        await page.goto(url);
        const deep = page.locator('#dscc-deep-scan');
        await expect(deep.locator('#dscc-deep-ok')).toHaveCount(0);
        for (const phase of ['before_choice', 'after_reject']) {
          await expect(deep.locator('#dscc-deep-' + phase)).toBeVisible();
        }
        const before = deep.locator('#dscc-deep-before_choice + ul');
        await expect(before.locator('[data-dscc-deep-violation="_fbp"]')).toHaveCount(1);
        await expect(before.locator('[data-dscc-deep-violation="_ga"]')).toHaveCount(1);
        await expect(before.locator('[data-dscc-deep-violation="www.clarity.ms"]')).toHaveCount(1);
        await expect(deep.locator('#dscc-deep-after_reject + ul [data-dscc-deep-violation="_fbp"]')).toHaveCount(1);
        // The same findings are leak alerts of the compliance block.
        await expect(page.locator('#dscc-cw-leaks')).toBeVisible();
        await expect(page.locator('#dscc-compliance [data-dscc-leak="_ga"]')).toContainText('quét sâu');
      }

      // A scan that cannot find the banner buttons says nothing: warning instead of OK, the earlier alerts stay.
      const blind = deepScan({ DSCC_REJECT_SELECTOR: '#littled-no-such-reject', DSCC_ACCEPT_SELECTOR: '#littled-no-such-accept' });
      expect(blind.status).toBe(200);
      expect(blind.body.conclusive).toBe(false);
      await page.goto(TAB('cookies'));
      await expect(page.locator('#dscc-deep-inconclusive')).toContainText('Lần quét chưa kết luận được: không thấy nút Từ chối / Đồng ý');
      await expect(page.locator('#dscc-deep-ok')).toHaveCount(0);
      await expect(page.locator('#dscc-compliance [data-dscc-leak="_ga"]')).toHaveCount(1);

      // A later clean run replaces the deep alerts.
      resetSite(layout);
      catalog('enable', ...PLATFORMS);
      const clean = deepScan();
      expect(clean.body.violations).toEqual({ before_choice: 0, after_reject: 0 });
      await page.goto(TAB('cookies'));
      await expect(page.locator('#dscc-compliance [data-dscc-leak]')).toHaveCount(0);
    });
  });
}
