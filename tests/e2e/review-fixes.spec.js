// Regression tests for the review fixes: double-open focus trap, focus restore, language pick,
// withdraw choice, missing timestamp, bot skip, identical HTML, custom CSS rejection notice, 429 counter.
const { test, expect } = require('@playwright/test');
const { resetSite, waitForLog, consentCookie, ui } = require('./helpers');

const LAYOUTS = ['card', 'bar'];

/** Dispatch a Tab keydown on the body and report whether a focus trap swallowed it. */
async function tabIsPrevented(page) {
  return page.evaluate(() => {
    const ev = new KeyboardEvent('keydown', { key: 'Tab', bubbles: true, cancelable: true });
    document.body.dispatchEvent(ev);
    return ev.defaultPrevented;
  });
}

async function pageIsUnlocked(page) {
  return page.evaluate(() => document.documentElement.style.overflow !== 'hidden' && !document.documentElement.classList.contains('dscc-modal-open'));
}

for (const layout of LAYOUTS) {
  test.describe(`review fixes [${layout}]`, () => {
    test.beforeEach(() => resetSite(layout));

    test(`[${layout}] DSCC.open twice then Esc: Tab is not trapped and scrolling is restored (popup)`, async ({ page }) => {
      await page.goto('/');
      await expect(ui(page, layout).banner).toBeVisible();
      await page.evaluate(() => {
        window.DSCC.open('cookies');
        window.DSCC.open('cookies');
      });
      await expect(page.locator('#dscc .dscc-modal')).toBeVisible();
      await page.keyboard.press('Escape');
      await expect(page.locator('#dscc .dscc-modal')).toBeHidden();
      expect(await tabIsPrevented(page)).toBe(false);
      expect(await pageIsUnlocked(page)).toBe(true);
    });

    test(`[${layout}] DSCC.open twice then Esc after a saved choice (widget path)`, async ({ page }) => {
      await page.goto('/');
      await ui(page, layout).accept.click();
      await expect(ui(page, layout).widget).toBeVisible();
      await page.evaluate(() => {
        window.DSCC.open();
        window.DSCC.open();
      });
      await expect(ui(page, layout).dialog).toBeVisible();
      await page.keyboard.press('Escape');
      await expect(ui(page, layout).dialog).toBeHidden();
      expect(await tabIsPrevented(page)).toBe(false);
      expect(await pageIsUnlocked(page)).toBe(true);
      await expect(ui(page, layout).widget).toBeVisible();
    });

    test(`[${layout}] focus returns to the opener, or to the widget when the opener is gone`, async ({ page }) => {
      await page.goto('/');
      await ui(page, layout).accept.click();
      await page.evaluate(() => {
        const a = document.createElement('a');
        a.id = 'littled-opener';
        a.href = '#cai-dat-cookie';
        a.textContent = 'LittleD cookies';
        a.style.cssText = 'position:fixed;top:10px;left:10px;z-index:1;background:#fff';
        document.body.appendChild(a);
      });
      await page.click('#littled-opener');
      await expect(ui(page, layout).dialog).toBeVisible();
      await page.keyboard.press('Escape');
      await expect(ui(page, layout).dialog).toBeHidden();
      expect(await page.evaluate(() => document.activeElement && document.activeElement.id)).toBe('littled-opener');

      // Opener removed while the dialog is open: the widget takes focus.
      await page.click('#littled-opener');
      await expect(ui(page, layout).dialog).toBeVisible();
      await page.evaluate(() => document.getElementById('littled-opener').remove());
      await page.keyboard.press('Escape');
      await expect(ui(page, layout).dialog).toBeHidden();
      expect(await page.evaluate(() => document.activeElement && document.activeElement.classList.contains('dscc-widget'))).toBe(true);
    });

    test(`withdraw [${layout}] rejecting after allowing something logs withdraw; plain reject stays reject_all`, async ({ page }) => {
      await page.goto('/');
      await ui(page, layout).reject.click();
      let rows = await waitForLog(1);
      expect(rows[0].choice).toBe('reject_all');

      // Allow everything from the reopened dialog, then switch everything off again.
      const analytics = page.locator('#dscc input[data-dscc-category="analytics"]');
      const marketing = page.locator('#dscc input[data-dscc-category="marketing"]');
      const save = page.locator('#dscc [data-dscc-action="save"]:visible');
      await ui(page, layout).widget.click();
      await analytics.evaluate((el) => el.click());
      await marketing.evaluate((el) => el.click());
      await save.click();
      rows = await waitForLog(2);
      expect(rows[1]).toMatchObject({ choice: 'accept_all', src: 'widget' });

      await ui(page, layout).widget.click();
      await analytics.evaluate((el) => el.click());
      await marketing.evaluate((el) => el.click());
      await save.click();
      rows = await waitForLog(3);
      expect(rows[2]).toMatchObject({ choice: 'withdraw', analytics: 0, marketing: 0, src: 'widget' });
    });

    test(`[${layout}] no globals or events of a former plugin; a cookie in the old two-flag format asks again`, async ({ page, context }) => {
      resetSite(layout);
      await page.goto('/');
      const brand = new RegExp('ip' + 'os', 'i').source;
      const leaked = () => page.evaluate((source) => {
        const re = new RegExp(source, 'i');
        return {
          globals: Object.keys(window).filter((k) => re.test(k)),
          events: Array.from(window.dataLayer || []).filter((e) => e && re.test(String(e.event))).length,
        };
      }, brand);
      expect(await leaked()).toEqual({ globals: [], events: 0 });
      await ui(page, layout).accept.click();
      expect(await page.evaluate(() => window.DSCC.get())).toMatchObject({ analytics: true, marketing: true });
      expect(await leaked()).toEqual({ globals: [], events: 0 });

      // A cookie in the old {v,a,m,t,id} shape is not a valid record: the banner shows again.
      const fresh = await context.browser().newContext({ locale: 'vi-VN' });
      const version = await page.evaluate(() => window.DSCC_BOOT.cfg.v);
      await fresh.addCookies([{ name: 'dscc_consent', value: encodeURIComponent(JSON.stringify({ v: version, a: 1, m: 1, t: new Date().toISOString(), id: 'LittleD-old-shape' })), domain: 'localhost', path: '/' }]);
      const fp = await fresh.newPage();
      await fp.goto('/');
      await expect(ui(fp, layout).banner).toBeVisible();
      await fresh.close();
    });

    test(`[${layout}] client language: both languages in the HTML, <html lang> picks one and follows changes`, async ({ page }) => {
      resetSite(layout, 'advanced.lang_source=client');
      await page.goto('/');
      const root = page.locator('#dscc');
      await expect(root).toHaveAttribute('data-dscc-active-lang', 'en');
      expect(await page.locator('#dscc [data-dscc-lang="vi"]').count()).toBeGreaterThanOrEqual(3);
      expect(await page.locator('#dscc [data-dscc-lang="en"]').count()).toBeGreaterThanOrEqual(3);
      const english = (await ui(page, layout).accept.innerText()).trim();

      await page.evaluate(() => {
        document.documentElement.lang = 'vi';
      });
      await expect(root).toHaveAttribute('data-dscc-active-lang', 'vi');
      const vietnamese = (await ui(page, layout).accept.innerText()).trim();
      expect(vietnamese).not.toBe(english);
      expect(vietnamese).toMatch(/Đồng ý/);

      // The widget label (an attribute, not text) follows too.
      const label = await page.locator('#dscc .dscc-widget').getAttribute('aria-label');
      await page.evaluate(() => {
        document.documentElement.lang = 'en-GB';
      });
      await expect(root).toHaveAttribute('data-dscc-active-lang', 'en');
      expect(await page.locator('#dscc .dscc-widget').getAttribute('aria-label')).not.toBe(label);
    });

    test(`[${layout}] a cookie without a timestamp counts as expired`, async ({ page, context, baseURL }) => {
      await context.addCookies([
        {
          name: 'dscc_consent',
          value: encodeURIComponent(JSON.stringify({ v: '2026-11', id: 'LittleD-no-t-0001', c: { analytics: 1, marketing: 1 }, s: {}, src: 'banner' })),
          url: baseURL,
        },
      ]);
      await page.goto('/');
      await expect(ui(page, layout).banner).toBeVisible();
      expect(await page.evaluate(() => window.DSCC.get())).toBeNull();
      const def = await page.evaluate(() => Array.from(window.dataLayer).find((a) => a && a[0] === 'consent' && a[1] === 'default')[2]);
      expect(def.analytics_storage).toBe('denied');
      expect(await consentCookie(context)).not.toBeNull(); // untouched until the visitor decides
    });

    test(`[${layout}] HTML is identical for anonymous, consented and bot requests`, async ({ page, baseURL }) => {
      const anon = await (await page.request.get(baseURL + '/')).text();
      const cookie = encodeURIComponent(JSON.stringify({ v: '2026-11', id: 'LittleD-r5-0001', t: Math.floor(Date.now() / 1000), c: { analytics: 1, marketing: 0 }, s: {}, src: 'banner' }));
      const consented = await (await page.request.get(baseURL + '/', { headers: { cookie: 'dscc_consent=' + cookie } })).text();
      const bot = await (await page.request.get(baseURL + '/', { headers: { 'user-agent': 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' } })).text();
      expect(anon).toContain('id="dscc"');
      expect(consented).toBe(anon);
      expect(bot).toBe(anon);
    });
  });
}

test.describe('review fixes [layout-independent]', () => {
  test('card: calling DSCC.open again keeps unsaved switches', async ({ page }) => {
    resetSite('card');
    await page.goto('/');
    await ui(page, 'card').customize.click();
    const analytics = page.locator('#dscc [data-dscc-view="prefs"] input[data-dscc-category="analytics"]');
    await analytics.evaluate((el) => el.click());
    await expect(analytics).toBeChecked();
    await page.evaluate(() => window.DSCC.open());
    await expect(analytics).toBeChecked();
  });

  test('bots are skipped by the main script even though the head only carries user patterns', async ({ browser }) => {
    resetSite('bar');
    const context = await browser.newContext({ userAgent: 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' });
    const page = await context.newPage();
    await page.goto('/');
    await page.waitForTimeout(600);
    expect(await page.locator('#dscc').count()).toBe(0);
    expect(await page.evaluate(() => document.documentElement.classList.contains('dscc-skip'))).toBe(true);
    expect(await page.evaluate(() => typeof window.DSCC)).toBe('undefined');
    await context.close();
  });

  test('user patterns beyond the head budget are still enforced by the main script', async ({ browser }) => {
    // 12 long patterns do not all fit in the 1.5 KB head script; the last one is the one that matches.
    const long = Array.from({ length: 11 }, (_, i) => 'LittleD-NoMatch-' + String(i).padStart(2, '0') + '-' + 'x'.repeat(80)).join('|');
    resetSite('card', 'advanced.skip_ua_patterns=' + long + '|LittleD-LateApp');
    const context = await browser.newContext({ userAgent: 'Mozilla/5.0 LittleD-LateApp/1.0' });
    const page = await context.newPage();
    await page.goto('/');
    await page.waitForTimeout(600);
    expect(await page.locator('#dscc').count()).toBe(0);
    expect(await page.evaluate(() => document.documentElement.classList.contains('dscc-skip'))).toBe(true);
    await context.close();
  });

  test.describe('admin', () => {
    async function login(page) {
      await page.goto('/wp-login.php');
      await page.fill('#user_login', 'admin');
      await page.fill('#user_pass', 'password');
      await page.click('#wp-submit');
      await expect(page.locator('#wpadminbar')).toBeVisible();
    }

    test('refused custom CSS keeps the previous stylesheet and explains why', async ({ page }) => {
      resetSite('card');
      await login(page);
      await page.goto('/wp-admin/admin.php?page=dscc-settings&tab=appearance');
      await page.fill('#dscc-appearance-custom_css', '.littled-keep{color:red}');
      await page.click('#submit');
      await expect(page.locator('.notice-success')).toBeVisible();
      expect(await page.inputValue('#dscc-appearance-custom_css')).toBe('.littled-keep{color:red}');

      await page.fill('#dscc-appearance-custom_css', '.littled-bad{color:red');
      await page.click('#submit');
      await expect(page.locator('#dscc-css-rejected')).toBeVisible();
      await expect(page.locator('.notice-success')).toHaveCount(0);
      expect(await page.inputValue('#dscc-appearance-custom_css')).toBe('.littled-keep{color:red}');
    });

    test('overview shows how many log requests the rate limit refused', async ({ page, request, baseURL }) => {
      resetSite('card');
      const body = new URLSearchParams({ action: 'dscc_log', id: 'LittleD-rate-0001', choice: 'custom', c: '{"analytics":1,"marketing":0}', v: '2026-11', lang: 'vi', url: '/', src: 'banner' }).toString();
      const statuses = [];
      for (let i = 0; i < 12; i++) {
        const res = await request.post(baseURL + '/wp-admin/admin-ajax.php', { data: body, headers: { 'content-type': 'application/x-www-form-urlencoded', origin: baseURL } });
        statuses.push(res.status());
      }
      expect(statuses.filter((s) => s === 429)).toHaveLength(2);
      await login(page);
      await page.goto('/wp-admin/admin.php?page=dscc');
      await expect(page.locator('#dscc-blocked')).toContainText('2 hôm nay, 2 trong 7 ngày');
    });
  });
});
