// E1-E9 run for BOTH layouts (card and bar), plus E6 and E7.
const { test, expect } = require('@playwright/test');
const { resetSite, waitForLog, logRows, consentDefault, consentUpdates, consentEvents, consentCookie, ui } = require('./helpers');

for (const layout of ['card', 'bar']) {
  test.describe(`layout ${layout}`, () => {
    test.beforeEach(() => resetSite(layout));

    test(`E1 [${layout}] new visitor: banner, Consent Mode denied, no tracking cookies, no external requests`, async ({ page, context }) => {
      const external = [];
      page.on('request', (r) => {
        const u = new URL(r.url());
        if (!['localhost', '127.0.0.1'].includes(u.hostname) && u.protocol.startsWith('http')) external.push(r.url());
      });
      await page.goto('/');
      const u = ui(page, layout);
      await expect(u.banner).toBeVisible();
      await expect(u.widget).toBeHidden();

      const def = await consentDefault(page);
      expect(def).toMatchObject({ analytics_storage: 'denied', ad_storage: 'denied', ad_user_data: 'denied', ad_personalization: 'denied', functionality_storage: 'granted', security_storage: 'granted', wait_for_update: 500 });

      const names = (await context.cookies()).map((c) => c.name);
      for (const n of ['_ga', '_gid', '_fbp', '_clck', '_gcl_au']) expect(names).not.toContain(n);
      expect(names).not.toContain('dscc_consent');
      expect(external).toEqual([]);

      // Reject sits next to Accept on the first screen and nothing is pre-ticked.
      await expect(u.reject).toBeVisible();
      await expect(u.accept).toBeVisible();
      expect(await page.locator('#dscc input[type=checkbox]:checked').count()).toBe(0);
      if (layout === 'bar') await expect(page.locator('#dscc .dscc-bar [data-dscc-action="close"]')).toHaveCount(0);
    });

    test(`E2 [${layout}] reject: widget shows, stays rejected after reload, log reject_all`, async ({ page, context }) => {
      await page.goto('/');
      const u = ui(page, layout);
      await u.reject.click();
      await expect(u.banner).toBeHidden();
      await expect(u.widget).toBeVisible();

      const updates = await consentUpdates(page);
      expect(updates[updates.length - 1]).toMatchObject({ analytics_storage: 'denied', ad_storage: 'denied' });
      expect((await consentEvents(page)).pop()).toEqual({ analytics: false, marketing: false });

      const cookie = await consentCookie(context);
      expect(cookie.c).toEqual({ analytics: 0, marketing: 0 });

      for (const p of ['/', '/?p=1', '/?page_id=2']) {
        await page.goto(p);
        await expect(u.banner).toBeHidden();
        await expect(u.widget).toBeVisible();
        const def = await consentDefault(page);
        expect(def.analytics_storage).toBe('denied');
      }
      const rows = await waitForLog(1);
      expect(rows).toHaveLength(1);
      expect(rows[0]).toMatchObject({ choice: 'reject_all', analytics: 0, marketing: 0, src: 'banner' });
      expect(rows[0].ip_masked).toMatch(/\.0$|::$/);
    });

    test(`E3 [${layout}] accept: Consent Mode granted, event pushed, cookie written, log accept_all`, async ({ page, context }) => {
      await page.goto('/');
      const u = ui(page, layout);
      await u.accept.click();
      await expect(u.widget).toBeVisible();
      const updates = await consentUpdates(page);
      expect(updates.pop()).toMatchObject({ analytics_storage: 'granted', ad_storage: 'granted', ad_user_data: 'granted', ad_personalization: 'granted' });
      expect((await consentEvents(page)).pop()).toEqual({ analytics: true, marketing: true });

      const cookie = await consentCookie(context);
      expect(cookie.c).toEqual({ analytics: 1, marketing: 1 });
      expect(cookie.v).toBe('2026-11');
      expect(cookie.id).toMatch(/^[a-f0-9-]{20,}$/);

      // After a reload the head script restores the granted default before any tag runs.
      await page.reload();
      const def = await consentDefault(page);
      expect(def).toMatchObject({ analytics_storage: 'granted', ad_storage: 'granted' });
      expect((await consentEvents(page)).length).toBeGreaterThan(0);

      const rows = await waitForLog(1);
      expect(rows[0]).toMatchObject({ choice: 'accept_all', analytics: 1, marketing: 1, src: 'banner' });
    });

    test(`E6 [${layout}] bumping policy_version asks again`, async ({ page }) => {
      await page.goto('/');
      const u = ui(page, layout);
      await u.accept.click();
      await page.reload();
      await expect(u.banner).toBeHidden();
      resetSite(layout, 'advanced.policy_version=2099-01');
      await page.reload();
      await expect(u.banner).toBeVisible();
      await expect(u.widget).toBeHidden();
      const def = await consentDefault(page);
      expect(def.analytics_storage).toBe('denied');
    });

    test(`E7 [${layout}] skip user-agent: no banner, no listeners, no log, consent stays denied`, async ({ browser }) => {
      resetSite(layout, 'advanced.skip_ua_patterns=LittleD-App');
      const context = await browser.newContext({ userAgent: 'Mozilla/5.0 LittleD-App/1.0' });
      const page = await context.newPage();
      await page.goto('/');
      await page.waitForTimeout(800);
      expect(await page.locator('#dscc').count()).toBe(0);
      expect(await page.evaluate(() => document.documentElement.classList.contains('dscc-skip'))).toBe(true);
      expect(await page.evaluate(() => typeof window.DSCC)).toBe('undefined');
      expect((await consentDefault(page)).analytics_storage).toBe('denied');
      expect(logRows()).toHaveLength(0);
      await context.close();
    });

    test(`E8 [${layout}] mobile 390x844: no horizontal scroll, stacked / sheet / fullscreen popup`, async ({ browser }) => {
      const context = await browser.newContext({ viewport: { width: 390, height: 844 }, isMobile: true, hasTouch: true });
      const page = await context.newPage();
      await page.goto('/');
      const u = ui(page, layout);
      await expect(u.banner).toBeVisible();
      expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBe(390);

      let box = await u.banner.boundingBox();
      if (layout === 'bar') {
        expect(box.width).toBeGreaterThanOrEqual(389);
        const a = await u.accept.boundingBox();
        const r = await u.reject.boundingBox();
        expect(a.width).toBeGreaterThan(300); // full-width stacked buttons
        expect(a.height).toBeGreaterThanOrEqual(43);
        expect(r.y).toBeGreaterThan(a.y - 1);
        await u.customize.click();
        const m = await u.dialog.boundingBox();
        expect(Math.round(m.width)).toBe(390);
        expect(m.height).toBeGreaterThanOrEqual(840); // full-screen popup
        expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBe(390);
      } else {
        // Bottom sheet: full width, glued to the bottom edge (wait for the fade/slide-in to finish).
        await expect(u.banner).toHaveClass(/is-in/);
        await page.waitForTimeout(350);
        box = await u.banner.boundingBox();
        expect(Math.round(box.x)).toBe(0);
        expect(Math.round(box.width)).toBe(390);
        expect(Math.round(box.y + box.height)).toBe(844);
        const a = await u.accept.boundingBox();
        const r = await u.reject.boundingBox();
        expect(Math.abs(a.y - r.y)).toBeLessThan(4); // two main buttons share a row
        expect(a.y).toBeLessThan((await u.customize.boundingBox()).y); // customise on the row below
        await u.customize.click();
        await expect(page.locator('#dscc [data-dscc-view="prefs"]')).toBeVisible();
        const m = await u.dialog.boundingBox();
        expect(m.height).toBeLessThanOrEqual(844 * 0.85 + 1);
        expect(await page.evaluate(() => document.documentElement.scrollWidth)).toBe(390);
      }
      await context.close();
    });

    test(`E9 [${layout}] keyboard: tab order, focus trap, arrows, Esc, focus returns to the widget`, async ({ page }) => {
      await page.goto('/');
      const u = ui(page, layout);
      await expect(u.banner).toBeVisible();

      // First screen: every action is reachable with Tab.
      const seen = new Set();
      for (let i = 0; i < 80; i++) {
        await page.keyboard.press('Tab');
        const a = await page.evaluate(() => (document.activeElement && document.activeElement.getAttribute('data-dscc-action')) || '');
        if (a) seen.add(a);
      }
      for (const a of ['accept', 'reject', 'customize']) expect(seen.has(a), `Tab reaches ${a}`).toBe(true);

      // Choose, then reopen from the widget with the keyboard.
      await u.reject.click();
      await expect(u.widget).toBeVisible();
      await u.widget.focus();
      await page.keyboard.press('Enter');
      await expect(u.dialog).toBeVisible();

      // Focus never leaves the dialog while tabbing in both directions.
      for (let i = 0; i < 25; i++) {
        await page.keyboard.press('Tab');
        expect(await page.evaluate(() => !!document.activeElement.closest('.dscc-modal, .dscc-card'))).toBe(true);
      }
      for (let i = 0; i < 25; i++) {
        await page.keyboard.press('Shift+Tab');
        expect(await page.evaluate(() => !!document.activeElement.closest('.dscc-modal, .dscc-card'))).toBe(true);
      }

      if (layout === 'bar') {
        // Arrow keys move between tabs.
        const first = page.locator('#dscc .dscc-tab').first();
        await first.focus();
        await page.keyboard.press('ArrowRight');
        await expect(page.locator('#dscc .dscc-tab[aria-selected="true"]')).toHaveCount(1);
        expect(await page.locator('#dscc .dscc-tab').nth(1).getAttribute('aria-selected')).toBe('true');
        await page.keyboard.press('ArrowLeft');
        expect(await page.locator('#dscc .dscc-tab').first().getAttribute('aria-selected')).toBe('true');
        // Roles required by WCAG.
        await expect(page.locator('#dscc [role="tablist"]')).toHaveCount(1);
        expect(await page.locator('#dscc .dscc-modal').getAttribute('aria-modal')).toBe('true');
      } else {
        expect(await page.locator('#dscc .dscc-card').getAttribute('role')).toBe('dialog');
      }
      expect(await page.locator('#dscc input[type=checkbox][role=switch]').count()).toBeGreaterThan(0);

      await page.keyboard.press('Escape');
      await expect(u.dialog).toBeHidden();
      await expect(u.widget).toBeVisible();
      expect(await page.evaluate(() => document.activeElement.classList.contains('dscc-widget'))).toBe(true);
      // Esc closed without saving: still exactly one log row.
      expect(await waitForLog(1)).toHaveLength(1);
    });
  });
}
