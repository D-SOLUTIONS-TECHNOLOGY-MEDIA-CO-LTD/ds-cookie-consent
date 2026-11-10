// Prior blocking and unblocking (E1-E5, E12) for BOTH layouts, then blocker features that do not depend on the
// layout: server cookies, placeholder, purge, GTM modes, exclusions, observe mode, admin tab.
// Vendor traffic never leaves the machine: every external request is intercepted (see helpers.interceptVendors).
// Test IDs only: G-LITTLED000, fbq('init','000LittleD'), clarity littled000, youtube LittleD000.
const { test, expect } = require('@playwright/test');
const { resetSite, catalog, vendors, interceptVendors, waitForLog, consentCookie, consentDefault, wp, ui, PLUGIN_DIR } = require('./helpers');

const GA = /google-analytics\.com\/g\/collect|googletagmanager\.com\/gtag\/js|google-analytics\.com\/analytics\.js/;
const META = /facebook\.com\/tr|connect\.facebook\.net/;
const CLARITY = /clarity\.ms/;
const YOUTUBE = /youtube\.com\/embed/;
const GTM = /googletagmanager\.com\/gtm\.js/;
const ANY_VENDOR = [GA, META, CLARITY, YOUTUBE, GTM];

const PLATFORMS = ['google-analytics', 'meta-pixel', 'microsoft-clarity', 'youtube'];

const vendorUrls = (net) => net.urls.filter((u) => ANY_VENDOR.some((re) => re.test(u)));
const cookieNames = async (context) => (await context.cookies()).map((c) => c.name);

/** A valid consent cookie, as the browser script writes it. */
function consentCookieValue(c, s = {}) {
  return encodeURIComponent(JSON.stringify({ v: '2026-11', id: 'LittleD-e2e', t: Math.floor(Date.now() / 1000), c, s, src: 'banner' }));
}

async function recordEnabledServices(page) {
  await page.addInitScript(() => {
    window.__enabled = [];
    document.addEventListener('dscc:service-enabled', (e) => window.__enabled.push(e.detail.service));
  });
}

test.afterAll(() => {
  vendors('off');
});

for (const layout of ['card', 'bar']) {
  test.describe(`blocker, layout ${layout}`, () => {
    let net;

    test.beforeEach(async ({ context }) => {
      resetSite(layout);
      catalog('enable', ...PLATFORMS);
      vendors('set', 'ga', 'meta', 'clarity', 'youtube', 'pixel');
      net = await interceptVendors(context);
    });

    test(`E1 [${layout}] new visitor: tracking tags are blocked, no vendor request, no tracking cookie`, async ({ page, context }) => {
      await page.goto('/');
      await expect(ui(page, layout).banner).toBeVisible();
      await page.waitForTimeout(700);

      expect(vendorUrls(net)).toEqual([]);
      const names = await cookieNames(context);
      for (const n of ['_ga', '_fbp', '_clck', 'dscc_consent']) expect(names).not.toContain(n);
      expect(await consentDefault(page)).toMatchObject({ analytics_storage: 'denied', ad_storage: 'denied' });

      // Markup contract: scripts are text/plain with category + service, the loader keeps its address in data-dscc-src.
      const blocked = await page.locator('script[type="text/plain"][data-dscc-category]').evaluateAll((els) =>
        els.map((e) => ({ cat: e.getAttribute('data-dscc-category'), svc: e.getAttribute('data-dscc-service'), src: e.getAttribute('data-dscc-src'), hasSrc: e.hasAttribute('src') }))
      );
      expect(blocked.filter((b) => b.svc === 'google-analytics').length).toBe(2);
      expect(blocked.filter((b) => b.svc === 'meta-pixel').length).toBe(1);
      expect(blocked.filter((b) => b.svc === 'microsoft-clarity').length).toBe(1);
      expect(blocked.every((b) => !b.hasSrc)).toBe(true);
      expect(blocked.find((b) => b.svc === 'google-analytics' && b.src).src).toContain('googletagmanager.com/gtag/js?id=G-LITTLED000');

      // Iframe and pixel: address parked in data-dscc-src, placeholder in front of the iframe.
      const frame = page.locator('#littled-video');
      expect(await frame.getAttribute('src')).toBeNull();
      expect(await frame.getAttribute('data-dscc-src')).toContain('youtube.com/embed/LittleD000');
      expect(await frame.getAttribute('data-dscc-category')).toBe('functional');
      await expect(frame).toBeHidden();
      await expect(page.locator('.dscc-placeholder')).toBeVisible();
      await expect(page.locator('.dscc-placeholder__text')).toHaveText(/^(Bật cookie Chức năng để xem video|Enable Functional cookies to watch this video)\.$/);
      expect(await page.locator('#littled-pixel').getAttribute('src')).toBeNull();
      // The pixel in <noscript> is removed too: a visitor without JavaScript cannot consent.
      expect(await page.locator('noscript').evaluateAll((els) => els.map((e) => e.textContent).join(''))).not.toContain('facebook.com/tr');
    });

    test(`E3 [${layout}] accept: tags run, cookies appear, log accept_all, service events fire`, async ({ page, context }) => {
      await recordEnabledServices(page);
      await page.goto('/');
      await ui(page, layout).accept.click();

      await expect.poll(() => [net.count(GA), net.count(META), net.count(CLARITY), net.count(YOUTUBE)].every((n) => n > 0)).toBe(true);
      await expect.poll(async () => (await cookieNames(context)).filter((n) => ['_ga', '_fbp', '_clck'].includes(n)).sort()).toEqual(['_clck', '_fbp', '_ga']);
      expect(await page.locator('#littled-video').getAttribute('src')).toContain('youtube.com/embed/LittleD000');
      await expect(page.locator('.dscc-placeholder')).toHaveCount(0);
      await expect(page.locator('#littled-video')).toBeVisible();
      expect(await page.locator('script[type="text/plain"][data-dscc-category]').count()).toBe(0);
      expect(await page.evaluate(() => window.__enabled.sort())).toEqual(expect.arrayContaining(['google-analytics', 'meta-pixel', 'microsoft-clarity', 'youtube']));

      const rows = await waitForLog(1);
      expect(rows[0]).toMatchObject({ choice: 'accept_all', analytics: 1, marketing: 1 });

      // Next page view: consent restored from the cookie, tags run straight away.
      net.clear();
      await page.goto('/?p=1');
      await expect.poll(() => net.count(GA) > 0 && net.count(META) > 0).toBe(true);
    });

    test(`E2 [${layout}] reject: nothing runs on this page or the next ones`, async ({ page, context }) => {
      await page.goto('/');
      await ui(page, layout).reject.click();
      for (const p of ['/', '/?p=1', '/?page_id=2']) {
        await page.goto(p);
        await page.waitForTimeout(400);
      }
      expect(vendorUrls(net)).toEqual([]);
      const names = await cookieNames(context);
      for (const n of ['_ga', '_fbp', '_clck']) expect(names).not.toContain(n);
      const rows = await waitForLog(1);
      expect(rows[0].choice).toBe('reject_all');
    });

    test(`E4 [${layout}] only analytics: Google and Clarity run, Meta and YouTube (Functional) stay blocked`, async ({ page, context }) => {
      await page.goto('/');
      await ui(page, layout).customize.click();
      await page.locator('#dscc input[data-dscc-category="analytics"]').evaluate((el) => el.click());
      await page.locator('#dscc [data-dscc-action="save"]:visible').first().click();

      await expect.poll(() => net.count(GA) > 0 && net.count(CLARITY) > 0).toBe(true);
      await page.waitForTimeout(600);
      expect(net.count(META)).toBe(0);
      expect(net.count(YOUTUBE)).toBe(0);
      const names = await cookieNames(context);
      expect(names).toEqual(expect.arrayContaining(['_ga', '_clck']));
      expect(names).not.toContain('_fbp');
      await expect(page.locator('.dscc-placeholder')).toBeVisible();
      expect((await consentCookie(context)).c).toEqual({ analytics: 1, marketing: 0, functional: 0 });
    });

    test(`E4b [${layout}] only Functional: YouTube loads, Google, Clarity and Meta stay blocked`, async ({ page, context }) => {
      await page.goto('/');
      await ui(page, layout).customize.click();
      await page.locator('#dscc input[data-dscc-category="functional"]').evaluate((el) => el.click());
      await page.locator('#dscc [data-dscc-action="save"]:visible').first().click();

      await expect.poll(() => net.count(YOUTUBE)).toBeGreaterThan(0);
      await page.waitForTimeout(600);
      expect(net.count(GA) + net.count(META) + net.count(CLARITY)).toBe(0);
      await expect(page.locator('.dscc-placeholder')).toHaveCount(0);
      expect((await consentCookie(context)).c).toEqual({ analytics: 0, marketing: 0, functional: 1 });
      expect(await page.evaluate(() => [window.DSCC.has('youtube'), window.DSCC.has('google-analytics'), window.DSCC.has('meta-pixel')])).toEqual([true, false, false]);
    });

    test(`E5 [${layout}] withdraw marketing: _fbp is deleted, the page reloads, Meta stays off`, async ({ page, context }) => {
      await page.goto('/');
      await ui(page, layout).accept.click();
      await expect.poll(async () => (await cookieNames(context)).includes('_fbp')).toBe(true);
      await waitForLog(1);

      await page.evaluate(() => (window.__marker = 'before-reload'));
      net.clear();
      await ui(page, layout).widget.click();
      await page.locator('#dscc input[data-dscc-category="marketing"]').evaluate((el) => el.click());
      const reloaded = page.waitForEvent('load');
      await page.locator('#dscc [data-dscc-action="save"]:visible').first().click();
      await reloaded;
      await page.waitForTimeout(600);

      expect(await page.evaluate(() => window.__marker)).toBeUndefined();
      expect(await cookieNames(context)).not.toContain('_fbp');
      expect(net.count(META)).toBe(0);
      expect(net.count(GA)).toBeGreaterThan(0);
      const rows = await waitForLog(2);
      expect(rows[1]).toMatchObject({ choice: 'custom', analytics: 1, marketing: 0 });
    });

    test(`E12 [${layout}] cached page: same HTML for everyone, behaviour still follows the visitor`, async ({ page, request, browser }) => {
      const anonymous = await (await request.get('/')).text();
      const consented = await (
        await request.get('/', { headers: { cookie: `dscc_consent=${consentCookieValue({ analytics: 1, marketing: 1 })}` } })
      ).text();
      expect(consented).toBe(anonymous);
      expect(anonymous).toContain('data-dscc-category="marketing"');

      // Serve that single stored page to two visitors.
      const serveCached = (p) => p.route('http://localhost:8888/', (route) => route.fulfill({ status: 200, contentType: 'text/html', body: anonymous }));

      await serveCached(page);
      await page.goto('/');
      await page.waitForTimeout(500);
      expect(vendorUrls(net)).toEqual([]);

      const ctx2 = await browser.newContext({ locale: 'vi-VN' });
      const net2 = await interceptVendors(ctx2);
      await ctx2.addCookies([{ name: 'dscc_consent', value: consentCookieValue({ analytics: 1, marketing: 1 }), url: 'http://localhost:8888' }]);
      const page2 = await ctx2.newPage();
      await serveCached(page2);
      await page2.goto('/');
      await expect.poll(() => net2.count(GA) > 0 && net2.count(META) > 0 && net2.count(CLARITY) > 0).toBe(true);
      expect((await ctx2.cookies()).map((c) => c.name)).toEqual(expect.arrayContaining(['_ga', '_fbp', '_clck']));
      await ctx2.close();
    });
  });
}

test.describe('blocker details', () => {
  let net;

  test.beforeEach(async ({ context }) => {
    resetSite('card');
    catalog('enable', ...PLATFORMS);
    net = await interceptVendors(context);
  });

  test('scripts run in document order: an external script finishes before the inline code that needs it', async ({ page }) => {
    vendors('set', 'ordered', 'ga');
    await recordEnabledServices(page);
    await page.goto('/');
    await ui(page, 'card').accept.click();
    await expect.poll(() => page.evaluate(() => (window.__order || []).length)).toBeGreaterThanOrEqual(3);
    const order = await page.evaluate(() => window.__order);
    expect(order.indexOf('ga-analytics-js')).toBeGreaterThanOrEqual(0);
    expect(order.indexOf('ga-analytics-js')).toBeLessThan(order.indexOf('ga-create'));
  });

  test('ServerCookieGuard: _fbp from PHP is dropped without consent, wp-wpml_current_language stays, consent lets it through', async ({ request }) => {
    vendors('set', 'server_cookies');
    const setCookies = async (headers) => {
      const res = await request.get('/', { headers });
      return res.headersArray().filter((h) => h.name.toLowerCase() === 'set-cookie').map((h) => h.value);
    };
    const without = await setCookies({});
    expect(without.some((c) => c.startsWith('_fbp='))).toBe(false);
    expect(without.some((c) => c.startsWith('wp-wpml_current_language='))).toBe(true);

    const analyticsOnly = await setCookies({ cookie: `dscc_consent=${consentCookieValue({ analytics: 1, marketing: 0 })}` });
    expect(analyticsOnly.some((c) => c.startsWith('_fbp='))).toBe(false);

    const marketing = await setCookies({ cookie: `dscc_consent=${consentCookieValue({ analytics: 0, marketing: 1 })}` });
    expect(marketing.some((c) => c.startsWith('_fbp='))).toBe(true);
    expect(marketing.some((c) => c.startsWith('wp-wpml_current_language='))).toBe(true);

    const switchedOff = await setCookies({ cookie: `dscc_consent=${consentCookieValue({ analytics: 0, marketing: 1 }, { 'meta-pixel': 0 })}` });
    expect(switchedOff.some((c) => c.startsWith('_fbp='))).toBe(false);
  });

  test('placeholder button grants only what it names and keeps the other choices', async ({ page, context }) => {
    vendors('set', 'ga', 'youtube');
    await page.goto('/');
    await page.locator('.dscc-placeholder__btn').click();
    await expect.poll(() => net.count(YOUTUBE)).toBeGreaterThan(0);
    await expect(page.locator('.dscc-placeholder')).toHaveCount(0);
    expect(await page.evaluate(() => [window.DSCC.has('youtube'), window.DSCC.has('google-analytics'), window.DSCC.has('microsoft-clarity')])).toEqual([true, false, false]);
    const cookie = await consentCookie(context);
    expect(cookie.c).toEqual({ analytics: 0, marketing: 0, functional: 1 });
    expect(cookie.s.youtube).toBe(1);
    expect(cookie.s['meta-pixel']).toBe(0);
    expect(net.count(GA)).toBe(0);
    const rows = await waitForLog(1);
    expect(rows[0]).toMatchObject({ choice: 'custom', src: 'widget' });
  });

  test('known cookies and storage keys are deleted before consent and after a withdrawal without reload', async ({ page, context }) => {
    resetSite('card', 'blocking.reload_on_withdraw=0');
    catalog('enable', ...PLATFORMS);
    catalog('addcookie', 'microsoft-clarity', 'LittleD_tracker_*', 'localStorage');
    catalog('addcookie', 'microsoft-clarity', 'wp_littled_keep', 'cookie'); // safelisted by name (wp_*), never deleted
    vendors('set', 'ga');
    await context.addCookies([
      { name: '_ga', value: 'GA1.1.leftover', url: 'http://localhost:8888' },
      { name: '_ga_LITTLED000', value: 'x', url: 'http://localhost:8888' },
      { name: 'LittleD_necessary', value: 'keep', url: 'http://localhost:8888' },
      { name: 'wp_littled_keep', value: 'keep', url: 'http://localhost:8888' },
    ]);
    await page.addInitScript(() => window.localStorage.setItem('LittleD_tracker_1', 'x'));
    await page.goto('/');
    await page.waitForTimeout(400);
    let names = await cookieNames(context);
    expect(names).not.toContain('_ga');
    expect(names).not.toContain('_ga_LITTLED000');
    expect(names).toContain('LittleD_necessary');
    expect(names).toContain('wp_littled_keep');
    expect(await page.evaluate(() => window.localStorage.getItem('LittleD_tracker_1'))).toBeNull();

    // Accept, then switch analytics off: cookies go, the page is NOT reloaded (setting off), the choice is a custom/withdraw.
    await ui(page, 'card').accept.click();
    await expect.poll(async () => (await cookieNames(context)).includes('_ga')).toBe(true);
    await page.evaluate(() => (window.__marker = 'same-page'));
    await ui(page, 'card').widget.click();
    await page.locator('#dscc input[data-dscc-category="analytics"]').evaluate((el) => el.click());
    await page.locator('#dscc [data-dscc-action="save"]:visible').first().click();
    await expect.poll(async () => (await cookieNames(context)).includes('_ga')).toBe(false);
    expect(await page.evaluate(() => window.__marker)).toBe('same-page');
  });

  test('GTM: loaded with Consent Mode by default, blocked until analytics or marketing in block mode', async ({ page }) => {
    catalog('enable', 'google-tag-manager');
    vendors('set', 'gtm');
    await page.goto('/');
    await expect.poll(() => net.count(GTM)).toBeGreaterThan(0);

    net.clear();
    resetSite('card', 'blocking.gtm_mode=block');
    catalog('enable', ...PLATFORMS, 'google-tag-manager');
    await page.goto('/');
    await page.waitForTimeout(600);
    expect(net.count(GTM)).toBe(0);
    expect(await page.locator('script[type="text/plain"][data-dscc-service="google-tag-manager"]').getAttribute('data-dscc-category')).toBe('analytics marketing');
    // The noscript iframe fallback is removed as well (no-JS visitors cannot consent).
    expect(await page.locator('noscript').evaluateAll((els) => els.map((e) => e.textContent).join(''))).not.toContain('ns.html?id=GTM-LITTLED');
    await ui(page, 'card').customize.click();
    await page.locator('#dscc input[data-dscc-category="analytics"]').evaluate((el) => el.click());
    await page.locator('#dscc [data-dscc-action="save"]:visible').first().click();
    await expect.poll(() => net.count(GTM)).toBeGreaterThan(0);
  });

  test('exclusion list keeps matching tags out of the blocker', async ({ page }) => {
    resetSite('card', 'blocking.exclusions=G-LITTLED000');
    catalog('enable', ...PLATFORMS);
    vendors('set', 'ga', 'meta');
    await page.goto('/');
    await page.waitForTimeout(600);
    expect(net.count(GA)).toBeGreaterThan(0);
    expect(net.count(META)).toBe(0);
  });

  test('cookies set without Path on a deep page are deleted (the folder of the page itself too)', async ({ page, context, request }) => {
    const home = await (await request.get('/')).text();
    await page.route('http://localhost:8888/a/b/c/', (route) => route.fulfill({ status: 200, contentType: 'text/html', body: home }));
    await context.addCookies([{ name: '_ga', value: 'GA1.1.deep', domain: 'localhost', path: '/a/b/c' }]);
    await page.goto('/a/b/c/');
    await page.waitForTimeout(400);
    expect(await cookieNames(context)).not.toContain('_ga');
  });

  test('search bot: no banner, no log, embeds come back, scripts and pixels stay blocked', async ({ browser }) => {
    vendors('set', 'ga', 'meta', 'youtube', 'pixel');
    const ctx = await browser.newContext({ userAgent: 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)', locale: 'vi-VN' });
    const botNet = await interceptVendors(ctx);
    const page = await ctx.newPage();
    await page.goto('/');
    await page.waitForTimeout(600);
    await expect(page.locator('#dscc')).toHaveCount(0);
    await expect(page.locator('.dscc-placeholder')).toHaveCount(0);
    expect(await page.locator('#littled-video').getAttribute('src')).toContain('youtube.com/embed/LittleD000');
    await expect(page.locator('#littled-video')).toBeVisible();
    expect(botNet.count(YOUTUBE)).toBeGreaterThan(0);
    expect(botNet.count(GA)).toBe(0);
    expect(botNet.count(META)).toBe(0);
    expect(await page.locator('script[type="text/plain"][data-dscc-category]').count()).toBeGreaterThan(0);
    expect(await page.locator('#littled-pixel').getAttribute('src')).toBeNull();
    expect((await ctx.cookies()).map((c) => c.name)).not.toContain('dscc_consent');
    await ctx.close();
    expect((await waitForLog(0)).length).toBe(0);
  });

  test('skip user agent, mode deny (default): same as a bot', async ({ browser }) => {
    resetSite('card', 'advanced.skip_ua_patterns=LittleD-App');
    catalog('enable', ...PLATFORMS);
    vendors('set', 'ga', 'youtube');
    const ctx = await browser.newContext({ userAgent: 'LittleD-App/1.0 (webview)', locale: 'vi-VN' });
    const appNet = await interceptVendors(ctx);
    const page = await ctx.newPage();
    await page.goto('/');
    await page.waitForTimeout(600);
    await expect(page.locator('#dscc')).toHaveCount(0);
    expect(appNet.count(YOUTUBE)).toBeGreaterThan(0);
    expect(appNet.count(GA)).toBe(0);
    await ctx.close();
  });

  test('skip user agent, mode grant: the host app owns consent, everything runs, no cookie, no log', async ({ browser }) => {
    resetSite('card', 'advanced.skip_ua_patterns=LittleD-App', 'advanced.skip_ua_mode=grant');
    catalog('enable', ...PLATFORMS);
    vendors('set', 'ga', 'meta', 'youtube', 'server_cookies');
    const ctx = await browser.newContext({ userAgent: 'LittleD-App/1.0 (webview)', locale: 'vi-VN' });
    const appNet = await interceptVendors(ctx);
    const page = await ctx.newPage();
    const res = await page.goto('/');
    await expect.poll(() => appNet.count(GA) > 0 && appNet.count(META) > 0 && appNet.count(YOUTUBE) > 0).toBe(true);
    await expect(page.locator('#dscc')).toHaveCount(0);
    expect((await ctx.cookies()).map((c) => c.name)).not.toContain('dscc_consent');
    // The server guard lets the plugin's own cookie through for this agent.
    expect((await res.headersArray()).some((h) => h.name.toLowerCase() === 'set-cookie' && h.value.startsWith('_fbp='))).toBe(true);
    await ctx.close();
    expect((await waitForLog(0)).length).toBe(0);
  });

  test('observe mode: nothing is changed, what would be blocked is recorded', async ({ page }) => {
    resetSite('card', 'blocking.mode=observe');
    catalog('enable', ...PLATFORMS);
    vendors('set', 'ga', 'meta', 'youtube', 'server_cookies');
    await page.goto('/');
    await expect.poll(() => net.count(GA) > 0 && net.count(META) > 0 && net.count(YOUTUBE) > 0).toBe(true);
    expect(await page.locator('script[type="text/plain"][data-dscc-category]').count()).toBe(0);
    expect(await page.locator('.dscc-placeholder').count()).toBe(0);
    const res = await page.request.get('/');
    expect(res.headersArray().some((h) => h.name.toLowerCase() === 'set-cookie' && h.value.startsWith('_fbp='))).toBe(true);

    const report = JSON.parse(wp('option', 'get', 'dscc_observed', '--format=json'));
    expect(Object.keys(report.items).sort()).toEqual(['google-analytics', 'meta-pixel', 'youtube']);
    for (const item of Object.values(report.items)) expect(item.samples.length).toBeLessThanOrEqual(3);
  });
});

async function login(page) {
  await page.goto('/wp-login.php');
  await page.fill('#user_login', 'admin');
  await page.fill('#user_pass', 'password');
  await page.click('#wp-submit');
  await expect(page.locator('#wpadminbar')).toBeVisible();
}

test.describe('admin tab Chặn script', () => {
  const TAB = '/wp-admin/admin.php?page=dscc-settings&tab=blocking';

  test.beforeEach(async ({ page }) => {
    resetSite('card');
    catalog('enable', ...PLATFORMS);
    await login(page);
  });

  test('settings save and exclusions drop invalid lines', async ({ page }) => {
    await page.goto(TAB);
    await expect(page.locator('#dscc-blocking-mode')).toHaveValue('block');
    await expect(page.locator('#dscc-blocking-gtm_mode')).toHaveValue('consent_mode');
    await expect(page.locator('#dscc-blocking-block_unclassified')).toBeChecked();
    await expect(page.locator('#dscc-blocking-reload_on_withdraw')).toBeChecked();
    await expect(page.locator('#dscc-cache-note')).toContainText('trang cache');

    await page.selectOption('#dscc-blocking-mode', 'observe');
    await page.selectOption('#dscc-blocking-gtm_mode', 'block');
    await page.fill('#dscc-blocking-exclusions', 'my-tracker.js\nab\n/(a+)+$/\n/gtag\\/js/');
    await page.locator('#dscc-blocking-reload_on_withdraw').uncheck();
    await page.click('#submit');
    await expect(page.locator('.notice-success')).toBeVisible();

    await expect(page.locator('#dscc-blocking-mode')).toHaveValue('observe');
    await expect(page.locator('#dscc-blocking-gtm_mode')).toHaveValue('block');
    await expect(page.locator('#dscc-blocking-reload_on_withdraw')).not.toBeChecked();
    await expect(page.locator('#dscc-blocking-exclusions')).toHaveValue('my-tracker.js\n/gtag\\/js/');
  });

  test('lists the blocked platforms with a link to the Nền tảng tab, and the integrations table', async ({ page }) => {
    await page.goto(TAB);
    await expect(page.locator('#dscc-blocking-platforms tbody tr')).toHaveCount(4);
    await expect(page.locator('a[href*="tab=platforms"]').first()).toBeVisible();
    await expect(page.locator('#dscc-integrations tbody tr')).toHaveCount(6);
  });

  test('slow page warning and the observe report are shown, and reset clears the report', async ({ page }) => {
    wp('option', 'update', 'dscc_blocker_perf', JSON.stringify({ ms: 48.2, bytes: 120000, path: '/LittleD-slow/', time: Math.floor(Date.now() / 1000) }), '--format=json');
    wp('option', 'update', 'dscc_observed', JSON.stringify({ flushed: 1, items: { 'meta-pixel': { category: 'marketing', pages: 3, last: 1, samples: ['script https://connect.facebook.net/en_US/fbevents.js'] } } }), '--format=json');
    await page.goto(TAB);
    await expect(page.locator('#dscc-perf-warning')).toContainText(/48[.,]2 ms/);
    await expect(page.locator('#dscc-perf-warning')).toContainText('/LittleD-slow/');
    await expect(page.locator('#dscc-observed tbody tr')).toHaveCount(1);
    await page.getByRole('button', { name: 'Xoá báo cáo' }).click();
    await expect(page.locator('#dscc-observed')).toHaveCount(0);
  });

  test('skip user agent mode and Meta hold fields exist; fail-open warning shows and a save clears it', async ({ page }) => {
    await page.goto('/wp-admin/admin.php?page=dscc-settings&tab=advanced');
    await expect(page.locator('#dscc-advanced-skip_ua_mode')).toHaveValue('deny');
    await page.goto(TAB);
    await expect(page.locator('#dscc-blocking-meta_hold')).toBeChecked();
    await expect(page.locator('#dscc-blocking-gtm_mode ~ .description')).toContainText('dscc_consent_update');
    wp('option', 'update', 'dscc_blocker_failopen', JSON.stringify({ reason: 'pcre', path: '/LittleD-big/', time: Math.floor(Date.now() / 1000) }), '--format=json');
    await page.goto(TAB);
    await expect(page.locator('#dscc-failopen-warning')).toContainText('/LittleD-big/');
    await page.click('#submit');
    await expect(page.locator('.notice-success')).toBeVisible();
    await expect(page.locator('#dscc-failopen-warning')).toHaveCount(0);
  });

  test('head script over budget shows a warning and keeps every region', async ({ page }) => {
    wp('eval-file', `${PLUGIN_DIR}/tests/e2e/fixtures/head-budget.php`);
    await page.goto(TAB);
    await expect(page.locator('#dscc-head-over-budget')).toBeVisible();
    await page.goto('/');
    expect(await page.evaluate(() => window.DSCC_BOOT.cfg.r.split(',').length)).toBe(120);
  });
});
