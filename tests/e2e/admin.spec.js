// Admin: menu, settings tabs, live preview (no save needed), contrast warnings, save, texts, version bump, log + CSV.
const { test, expect } = require('@playwright/test');
const { resetSite, waitForLog, ui } = require('./helpers');

async function login(page) {
  await page.goto('/wp-login.php');
  await page.fill('#user_login', 'admin');
  await page.fill('#user_pass', 'password');
  await page.click('#wp-submit');
  await expect(page.locator('#wpadminbar')).toBeVisible();
}

const SETTINGS = '/wp-admin/admin.php?page=dscc-settings';

test.describe('admin', () => {
  test.beforeEach(async ({ page }) => {
    resetSite('card');
    await login(page);
  });

  test('top-level menu with Tổng quan / Cài đặt / Log; Settings > Cookie Consent forwards to settings; tab list', async ({ page }) => {
    await page.goto('/wp-admin/');
    const menu = page.locator('#adminmenu a.menu-top', { hasText: 'Cookie Consent' });
    await expect(menu).toBeVisible();
    const subs = await page.locator('#adminmenu li.toplevel_page_dscc ul.wp-submenu a').allTextContents();
    expect(subs.join('|')).toContain('Tổng quan');
    expect(subs.join('|')).toContain('Cài đặt');
    expect(subs.join('|')).toContain('Log');
    await page.goto('/wp-admin/options-general.php?page=dscc-settings-link');
    await expect(page).toHaveURL(/page=dscc-settings&tab=appearance/);
    const tabs = await page.locator('.nav-tab').allTextContents();
    expect(tabs).toEqual(['Giao diện', 'Nội dung', 'Nền tảng', 'Cookie', 'Chặn script', 'Quét cookie', 'Nhập / Xuất', 'Nâng cao']);
    await page.goto('/wp-admin/admin.php?page=dscc');
    await expect(page.locator('svg.dscc-chart')).toHaveCount(2);
  });

  test('E10 live preview updates colour without saving; desktop/390px toggle; layout switch', async ({ page }) => {
    await page.goto(SETTINGS);
    const frame = page.frameLocator('[data-dscc-preview-frame]');
    await expect(frame.locator('#dscc .dscc-card')).toBeVisible();

    // change the primary colour: iframe CSS variable changes immediately, nothing is saved
    await page.locator('#dscc-appearance-color_primary').evaluate((el) => {
      el.value = '#aa2200';
      el.dispatchEvent(new Event('input', { bubbles: true }));
    });
    await expect
      .poll(() => frame.locator('#dscc').evaluate((el) => el.style.getPropertyValue('--dscc-primary')))
      .toBe('#aa2200');
    expect(await frame.locator('#dscc').evaluate((el) => el.style.getPropertyValue('--dscc-primary-hover'))).toBe('#961e00');
    await expect
      .poll(() => frame.locator('#dscc [data-dscc-action="accept"]').first().evaluate((el) => getComputedStyle(el).backgroundColor))
      .toBe('rgb(170, 34, 0)');

    // text change in the Content tab is covered below; here switch device and view
    await page.click('[data-dscc-preview-device="mobile"]');
    expect(await page.locator('[data-dscc-preview-frame]').evaluate((el) => Math.round(el.getBoundingClientRect().width))).toBe(390);
    await page.click('[data-dscc-preview-view="prefs"]');
    await expect(frame.locator('#dscc [data-dscc-view="prefs"]')).toBeVisible();
    await page.click('[data-dscc-preview-view="widget"]');
    await expect(frame.locator('#dscc .dscc-widget')).toBeVisible();

    // switching the layout reloads the preview with the other template
    await page.selectOption('#dscc-appearance-layout', 'bar');
    await page.click('[data-dscc-preview-view="banner"]');
    await expect(frame.locator('#dscc .dscc-bar')).toBeVisible();
    // colour override survives the reload (applied again through the bridge)
    await expect
      .poll(() => frame.locator('#dscc').evaluate((el) => el.style.getPropertyValue('--dscc-primary')))
      .toBe('#aa2200');

    // nothing was saved
    resetSite('card');
    await page.goto(SETTINGS);
    expect(await page.inputValue('#dscc-appearance-color_primary')).toBe('#1e3a8a');
  });

  test('contrast warning is shown live and after save, and saving is still allowed', async ({ page }) => {
    await page.goto(SETTINGS);
    await page.locator('#dscc-appearance-color_primary').evaluate((el) => {
      el.value = '#7fbfef';
      el.dispatchEvent(new Event('input', { bubbles: true }));
    });
    await expect(page.locator('[data-dscc-contrast-live] .notice')).toHaveCount(1);
    await page.click('#submit');
    await expect(page.locator('.notice-success')).toBeVisible();
    await expect(page.locator('.notice-warning').first()).toContainText('4.5:1');
    expect(await page.inputValue('#dscc-appearance-color_primary')).toBe('#7fbfef');
  });

  test('saving appearance changes the front end; logo, layout, radius', async ({ page, browser }) => {
    await page.goto(SETTINGS);
    await page.selectOption('#dscc-appearance-layout', 'bar');
    await page.fill('#dscc-appearance-button_radius', '12');
    await page.click('#submit');
    await expect(page.locator('.notice-success')).toBeVisible();
    const visitor = await (await browser.newContext()).newPage();
    await visitor.goto('/');
    await expect(visitor.locator('#dscc .dscc-bar')).toBeVisible();
    expect(await visitor.locator('#dscc').evaluate((el) => el.style.getPropertyValue('--dscc-radius'))).toBe('12px');
  });

  test('Content tab: text override reaches the front end; restore defaults; live text preview', async ({ page, browser }) => {
    await page.goto(SETTINGS + '&tab=content');
    const frame = page.frameLocator('[data-dscc-preview-frame]');
    // wp-env runs in en_US, so the preview and the visitor see the English texts.
    await page.locator('details.dscc-texts').nth(1).locator('summary').click();
    const field = page.locator('[data-dscc-text="en.title"]');
    await field.fill('LittleD title');
    await expect(frame.locator('#dscc [data-dscc-t="title"]')).toHaveText('LittleD title');
    await page.click('#submit');
    await expect(page.locator('.notice-success')).toBeVisible();
    const visitor = await (await browser.newContext()).newPage();
    await visitor.goto('/');
    await expect(visitor.locator('#dscc [data-dscc-t="title"]')).toHaveText('LittleD title');
    await page.goto(SETTINGS + '&tab=content');
    await page.getByRole('link', { name: /cả hai ngôn ngữ/ }).click();
    await expect(page.locator('.notice-success')).toContainText('Đã khôi phục');
    await visitor.reload();
    await expect(visitor.locator('#dscc [data-dscc-t="title"]')).toHaveText('This website uses cookies');
  });

  test('Advanced tab: invalid values are sanitised; version bump asks visitors again', async ({ page, browser }) => {
    await page.goto(SETTINGS + '&tab=advanced');
    await page.fill('#dscc-advanced-cookie_name', 'bad name;');
    await page.fill('#dscc-advanced-skip_ua_patterns', 'LittleD-Admin\n(unclosed');
    await page.click('#submit');
    // An invalid cookie name never resets the stored one: it is kept and the admin is told.
    await expect(page.locator('#dscc-setting-rejected')).toContainText('giữ nguyên tên cũ');
    expect(await page.inputValue('#dscc-advanced-cookie_name')).toBe('dscc_consent');
    expect(await page.inputValue('#dscc-advanced-skip_ua_patterns')).toBe('LittleD-Admin');

    const before = await page.inputValue('#dscc-advanced-policy_version');
    await page.getByRole('button', { name: 'Yêu cầu tất cả khách chọn lại' }).click();
    await expect(page.locator('.notice-success')).toContainText('Đã tăng phiên bản chính sách');
    expect(await page.inputValue('#dscc-advanced-policy_version')).not.toBe(before);
  });

  test('Log page lists rows, filters, and exports a CSV with BOM', async ({ page, browser }) => {
    resetSite('card');
    const visitor = await (await browser.newContext()).newPage();
    await visitor.goto('/');
    await ui(visitor, 'card').accept.click();
    await waitForLog(1);

    await page.goto('/wp-admin/admin.php?page=dscc-log');
    await expect(page.locator('.dscc-log-table tbody tr')).toHaveCount(1);
    await expect(page.locator('.dscc-log-table td[data-choice="accept_all"]')).toBeVisible();
    await page.selectOption('select[name=choice]', 'reject_all');
    await page.click('.dscc-filter button[type=submit]');
    await expect(page.locator('.dscc-log-table tbody tr td[colspan]')).toBeVisible();

    await page.goto('/wp-admin/admin.php?page=dscc-log');
    const [download] = await Promise.all([page.waitForEvent('download'), page.click('a:has-text("Xuất CSV")')]);
    const stream = await download.createReadStream();
    const chunks = [];
    for await (const c of stream) chunks.push(c);
    const buf = Buffer.concat(chunks);
    expect([...buf.slice(0, 3)]).toEqual([0xef, 0xbb, 0xbf]);
    expect(buf.toString('utf8')).toContain('accept_all');
  });
});
