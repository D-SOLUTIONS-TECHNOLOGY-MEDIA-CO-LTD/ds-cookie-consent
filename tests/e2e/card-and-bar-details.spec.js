// E13 card flow, bar popup details, E10 CSS variables, per-service toggles via catalog filter.
const { test, expect } = require('@playwright/test');
const { resetSite, waitForLog, consentCookie, ui, wp, PLUGIN_DIR } = require('./helpers');

test.describe('E13 card flow', () => {
  test.beforeEach(() => resetSite('card'));

  test('prefs view starts OFF, has reject-all, Esc/X only after a choice, widget reopens saved state, log src', async ({ page, context }) => {
    await page.goto('/');
    const u = ui(page, 'card');
    const prefs = page.locator('#dscc [data-dscc-view="prefs"]');
    const x = page.locator('#dscc .dscc-card__x');

    // First screen
    await expect(page.locator('#dscc [data-dscc-view="main"]')).toBeVisible();
    await expect(x).toBeHidden();
    await u.customize.click();
    await expect(prefs).toBeVisible();

    // Mandatory fixes: toggles OFF, "reject all" also in the prefs view
    const analytics = page.locator('#dscc input[data-dscc-category="analytics"]');
    const marketing = page.locator('#dscc input[data-dscc-category="marketing"]');
    await expect(analytics).not.toBeChecked();
    await expect(marketing).not.toBeChecked();
    await expect(prefs.locator('[data-dscc-action="reject"]')).toBeVisible();
    await expect(prefs.locator('[data-dscc-action="accept"]')).toBeVisible();
    await expect(prefs.locator('[data-dscc-action="save"]')).toBeVisible();
    await expect(x).toBeHidden();

    // Esc must NOT close without an earlier choice
    await page.keyboard.press('Escape');
    await expect(prefs).toBeVisible();

    // Save with only analytics
    await analytics.evaluate((el) => el.click());
    await prefs.locator('[data-dscc-action="save"]').click();
    await expect(u.banner).toBeHidden();
    await expect(u.widget).toBeVisible();
    let rows = await waitForLog(1);
    expect(rows[0]).toMatchObject({ choice: 'custom', analytics: 1, marketing: 0, src: 'prefs' });
    expect((await consentCookie(context)).c).toEqual({ analytics: 1, marketing: 0, functional: 0 });

    // Widget reopens the card at the prefs view with the saved state
    await u.widget.click();
    await expect(prefs).toBeVisible();
    await expect(analytics).toBeChecked();
    await expect(marketing).not.toBeChecked();
    await expect(x).toBeVisible();
    expect(await page.locator('#dscc .dscc-card').getAttribute('aria-modal')).toBe('true');

    // X closes without saving (no new log row, state unchanged)
    await x.click();
    await expect(u.banner).toBeHidden();
    await expect(u.widget).toBeVisible();
    expect(await waitForLog(1)).toHaveLength(1);

    // Reopen, change, save: new row with src=widget
    await u.widget.click();
    await analytics.evaluate((el) => el.click());
    await marketing.evaluate((el) => el.click());
    await prefs.locator('[data-dscc-action="save"]').click();
    rows = await waitForLog(2);
    expect(rows[1]).toMatchObject({ choice: 'custom', analytics: 0, marketing: 1, src: 'widget' });
    expect(rows[1].consent_id).toBe(rows[0].consent_id); // same visitor id

    // "Reject all" from the reopened prefs view
    await u.widget.click();
    await prefs.locator('[data-dscc-action="reject"]').click();
    rows = await waitForLog(3);
    expect(rows[2]).toMatchObject({ choice: 'withdraw', src: 'widget' }); // marketing was allowed before
  });

  test('widget stays on in card mode even when the widget setting is off; DSCC.open and footer link give the same result', async ({ page }) => {
    resetSite('card', 'appearance.widget_enabled=0');
    await page.goto('/');
    const u = ui(page, 'card');
    await u.reject.click();
    await expect(u.widget).toBeVisible();
    await page.evaluate(() => window.DSCC.open('prefs'));
    await expect(page.locator('#dscc [data-dscc-view="prefs"]')).toBeVisible();
    await page.keyboard.press('Escape');
    await expect(u.banner).toBeHidden();
    // external opener (shortcode / menu link)
    await page.evaluate(() => {
      const a = document.createElement('a');
      a.href = '#cai-dat-cookie';
      a.id = 'dscc-test-link';
      a.textContent = 'cookie';
      document.body.appendChild(a);
    });
    await page.click('#dscc-test-link');
    await expect(page.locator('#dscc [data-dscc-view="prefs"]')).toBeVisible();
  });

  test('"Xem chi tiết cookie" opens the lazy popup on the cookie tab; Exit returns to the card', async ({ page }) => {
    await page.goto('/');
    const u = ui(page, 'card');
    await u.customize.click();
    expect(await page.locator('#dscc .dscc-modal').count()).toBe(0); // not built until needed
    await page.locator('#dscc [data-dscc-action="open-tab"]:visible').first().click();
    await expect(page.locator('#dscc .dscc-modal')).toBeVisible();
    expect(await page.locator('#dscc .dscc-modal').getAttribute('data-dscc-mode')).toBe('info');
    await expect(page.locator('#dscc .dscc-modal [data-dscc-action="save"]')).toHaveCount(0);
    await page.locator('#dscc .dscc-modal .dscc-btn--exit').click();
    await expect(page.locator('#dscc .dscc-modal')).toBeHidden();
    await expect(page.locator('#dscc [data-dscc-view="prefs"]')).toBeVisible();
  });
});

test.describe('bar popup', () => {
  test.beforeEach(() => resetSite('bar'));

  test('customise opens a 3-part dialog; X and Exit close without saving; Save logs src=prefs then widget', async ({ page }) => {
    await page.goto('/');
    const u = ui(page, 'bar');
    await u.customize.click();
    const modal = page.locator('#dscc .dscc-modal');
    await expect(modal).toBeVisible();
    expect(await page.locator('#dscc .dscc-tab').count()).toBeGreaterThanOrEqual(2);
    const analytics = page.locator('#dscc input[data-dscc-category="analytics"]');
    await expect(analytics).not.toBeChecked();
    await modal.locator('.dscc-x').click();
    await expect(modal).toBeHidden();
    await expect(u.banner).toBeVisible(); // banner stays: no choice was made
    expect(await waitForLog(0, 300)).toHaveLength(0);

    await u.customize.click();
    await analytics.evaluate((el) => el.click());
    await modal.locator('[data-dscc-action="save"]').click();
    await expect(u.widget).toBeVisible();
    let rows = await waitForLog(1);
    expect(rows[0]).toMatchObject({ choice: 'custom', analytics: 1, marketing: 0, src: 'prefs' });

    await u.widget.click();
    await expect(analytics).toBeChecked();
    await modal.locator('[data-dscc-action="save"]').click();
    rows = await waitForLog(2);
    expect(rows[1].src).toBe('widget');
  });

  test('banner policy link opens the cookie tab without leaving the page', async ({ page }) => {
    await page.goto('/');
    await page.locator('#dscc .dscc-bar__msg a').click();
    await expect(page.locator('#dscc .dscc-modal')).toBeVisible();
    expect(await page.locator('#dscc .dscc-tab[aria-selected="true"]').getAttribute('data-dscc-tab')).toBe('cookies');
    expect(new URL(page.url()).pathname).toBe('/');
  });

  test('accept_last puts Accept on the far right; accept_first puts it first', async ({ page }) => {
    resetSite('bar', 'appearance.button_order=accept_last');
    await page.goto('/');
    let order = await page.locator('#dscc .dscc-bar__btns [data-dscc-action]').evaluateAll((els) => els.map((e) => e.getAttribute('data-dscc-action')));
    expect(order).toEqual(['customize', 'reject', 'accept']);
    resetSite('bar', 'appearance.button_order=accept_first');
    await page.reload();
    order = await page.locator('#dscc .dscc-bar__btns [data-dscc-action]').evaluateAll((els) => els.map((e) => e.getAttribute('data-dscc-action')));
    expect(order).toEqual(['accept', 'reject', 'customize']);
  });
});

test.describe('E10 colours and widget placement', () => {
  for (const layout of ['card', 'bar']) {
    test(`[${layout}] primary colour change reaches the front end through CSS variables`, async ({ page }) => {
      resetSite(layout, 'appearance.color_primary=#aa2200');
      await page.goto('/');
      const u = ui(page, layout);
      const v = await page.evaluate(() => document.getElementById('dscc').style.getPropertyValue('--dscc-primary'));
      expect(v).toBe('#aa2200');
      expect(await u.accept.evaluate((el) => getComputedStyle(el).backgroundColor)).toBe('rgb(170, 34, 0)');
      // only the active layout CSS file is loaded
      const hrefs = await page.locator('link[rel=stylesheet]').evaluateAll((l) => l.map((x) => x.href));
      expect(hrefs.some((h) => h.includes(`dscc-${layout}.css`))).toBe(true);
      expect(hrefs.some((h) => h.includes(`dscc-${layout === 'bar' ? 'card' : 'bar'}.css`))).toBe(false);
    });
  }

  test('widget goes to the right corner with offsets', async ({ page }) => {
    resetSite('bar', 'appearance.widget_side=right', 'appearance.widget_offset_x=30', 'appearance.widget_bottom=40');
    await page.goto('/');
    await ui(page, 'bar').reject.click();
    await page.waitForTimeout(600); // let the pop-in animation finish
    const box = await page.locator('#dscc .dscc-widget').boundingBox();
    const vp = page.viewportSize();
    expect(Math.round(vp.width - (box.x + box.width))).toBe(30);
    expect(Math.round(vp.height - (box.y + box.height))).toBe(40);
  });

  test('configured third-party element is hidden while the banner is open and the widget rises above it afterwards', async ({ page }) => {
    resetSite('card', 'advanced.hide_selectors=#littled-chat');
    await page.goto('/');
    await page.evaluate(() => {
      const d = document.createElement('div');
      d.id = 'littled-chat';
      d.style.cssText = 'position:fixed;left:20px;bottom:20px;width:60px;height:60px;background:#f00;z-index:2147483647';
      document.body.appendChild(d);
    });
    expect(await page.locator('#littled-chat').evaluate((e) => getComputedStyle(e).visibility)).toBe('hidden');
    await ui(page, 'card').reject.click();
    await page.waitForTimeout(1200);
    expect(await page.locator('#littled-chat').evaluate((e) => getComputedStyle(e).visibility)).toBe('visible');
    const w = await page.locator('#dscc .dscc-widget').boundingBox();
    const c = await page.locator('#littled-chat').boundingBox();
    expect(w.y + w.height).toBeLessThanOrEqual(c.y); // widget sits above the chat bubble
  });
});

test.describe('per-service toggles (catalog filter, default ON)', () => {
  test.beforeAll(() => {
    // A test-only mu-plugin supplies one service through the dscc_catalog_services filter.
    wp('eval', "wp_mkdir_p( WPMU_PLUGIN_DIR ); copy( WP_PLUGIN_DIR . '/" + PLUGIN_DIR.split('/').pop() + "/tests/e2e/fixtures/catalog-fixture.php', WPMU_PLUGIN_DIR . '/littled-dscc-catalog.php' );");
  });
  test.afterAll(() => {
    wp('eval', "unlink( WPMU_PLUGIN_DIR . '/littled-dscc-catalog.php' );");
  });

  test('service toggle syncs with the category toggle; external policy link attributes; s map in cookie and log', async ({ page, context }) => {
    resetSite('bar');
    await page.goto('/');
    await ui(page, 'bar').customize.click();
    const cat = page.locator('#dscc input[data-dscc-category="analytics"]');
    const svc = page.locator('#dscc input[data-dscc-service="littled-ga"]');
    await page.locator('#dscc [data-dscc-action="toggle-details"]').first().click();
    await expect(svc).toBeVisible();
    await expect(cat).not.toBeChecked();
    await svc.evaluate((el) => el.click());
    await expect(cat).toBeChecked();
    const link = page.locator('#dscc .dscc-vendor a[href^="https://policies.example"]');
    expect(await link.getAttribute('target')).toBe('_blank');
    expect(await link.getAttribute('rel')).toBe('nofollow noopener noreferrer');
    await page.locator('#dscc [data-dscc-action="toggle-cookies"]').first().click();
    await expect(page.locator('#dscc .dscc-ck table td').first()).toHaveText('_littled');
    await page.locator('#dscc .dscc-modal [data-dscc-action="save"]').click();
    expect((await consentCookie(context)).s).toEqual({ 'littled-ga': 1 });
    const rows = await waitForLog(1);
    expect(rows[0]).toMatchObject({ choice: 'custom', analytics: 1, marketing: 0 });
    expect(await page.evaluate(() => window.DSCC.has('littled-ga'))).toBe(true);
    await page.evaluate(() => window.DSCC.reset());
    expect(await page.evaluate(() => window.DSCC.get())).toBeNull();
    const after = await waitForLog(2);
    expect(after[1].choice).toBe('withdraw');
  });
});
