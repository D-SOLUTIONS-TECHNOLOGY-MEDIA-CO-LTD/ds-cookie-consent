// Admin catalog: tab order, platform picker (search, filter, tick/untick, block switch, custom platform, detected badge),
// cookie table (new first, filters, quick edit, bulk actions, add by hand, menu badge), regions cap notice.
const { test, expect } = require('@playwright/test');
const { resetSite, catalog, sql } = require('./helpers');

async function login(page) {
  await page.goto('/wp-login.php');
  await page.fill('#user_login', 'admin');
  await page.fill('#user_pass', 'password');
  await page.click('#wp-submit');
  await expect(page.locator('#wpadminbar')).toBeVisible();
}

const TAB = (name) => `/wp-admin/admin.php?page=dscc-settings&tab=${name}`;

/** Slugs of services the front end lists (from the JSON the popup script reads). */
async function frontServices(page) {
  await page.goto('/');
  return page.evaluate(() => JSON.parse(document.getElementById('dscc-data').textContent).services.map((s) => s.slug));
}

test.describe('platform picker', () => {
  test.beforeEach(async ({ page }) => {
    resetSite('card');
    await login(page);
  });

  test('tabs are Giao diện, Nội dung, Nền tảng, Cookie, Nâng cao', async ({ page }) => {
    await page.goto(TAB('appearance'));
    expect(await page.locator('.nav-tab').allTextContents()).toEqual(['Giao diện', 'Nội dung', 'Nền tảng', 'Cookie', 'Chặn script', 'Quét cookie', 'Nhập / Xuất', 'Nâng cao']);
  });

  test('grid is grouped by category; search and category filter narrow it', async ({ page }) => {
    await page.goto(TAB('platforms'));
    expect(await page.locator('.dscc-platform-group h3').allTextContents()).toEqual(expect.arrayContaining([expect.stringContaining('Cần thiết'), expect.stringContaining('Phân tích'), expect.stringContaining('Tiếp thị')]));
    const total = await page.locator('.dscc-platform').count();
    expect(total).toBeGreaterThanOrEqual(30);

    await page.fill('#dscc-platform-search', 'clarity');
    await expect(page.locator('.dscc-platform:visible')).toHaveCount(1);
    await expect(page.locator('.dscc-platform:visible .dscc-platform__name')).toHaveText('Microsoft Clarity');
    await expect(page.locator('.dscc-platform-group:visible')).toHaveCount(1);
    await page.fill('#dscc-platform-search', 'khong-co-nen-tang-nay');
    await expect(page.locator('#dscc-platform-empty')).toBeVisible();
    await page.fill('#dscc-platform-search', '');

    await page.selectOption('#dscc-platform-category', 'marketing');
    const visible = await page.locator('.dscc-platform:visible').count();
    expect(visible).toBeGreaterThan(5);
    expect(visible).toBeLessThan(total);
    expect(await page.locator('.dscc-platform:visible').evaluateAll((els) => els.every((e) => e.getAttribute('data-dscc-category') === 'marketing'))).toBe(true);
    await page.fill('#dscc-platform-search', 'tiktok');
    await expect(page.locator('.dscc-platform:visible')).toHaveCount(1);
  });

  test('block switch: on by default for analytics and marketing, off and disabled for necessary', async ({ page }) => {
    await page.goto(TAB('platforms'));
    const block = (slug) => page.locator(`[data-dscc-platform="${slug}"] input[name^="dscc_platform_block"]`);
    await expect(block('google-analytics')).toBeChecked();
    await expect(block('meta-pixel')).toBeChecked();
    await expect(block('google-analytics')).toBeEnabled();
    await expect(block('cloudflare')).not.toBeChecked();
    await expect(block('cloudflare')).toBeDisabled();
    await expect(page.locator('[data-dscc-platform="google-analytics"] input[name="dscc_platform_on[]"]')).not.toBeChecked();
  });

  test('tick saves the service and its cookies as confirmed; untick ignores them and keeps the data', async ({ page }) => {
    expect(await frontServices(page)).not.toContain('microsoft-clarity');
    await page.goto(TAB('platforms'));
    const card = page.locator('[data-dscc-platform="microsoft-clarity"]');
    await card.locator('input[name="dscc_platform_on[]"]').check();
    await expect(card).toHaveClass(/is-on/);
    await card.locator('input[name^="dscc_platform_block"]').uncheck();
    await page.click('#dscc-platforms-save');
    await expect(page.locator('.dscc-flash')).toContainText('Đã lưu danh sách nền tảng');

    expect(sql("SELECT status, block_enabled, source FROM wp_dscc_services WHERE slug='microsoft-clarity'")).toBe('confirmed\t0\tknown_db');
    expect(sql("SELECT COUNT(*) FROM wp_dscc_cookies c JOIN wp_dscc_services s ON s.id=c.service_id WHERE s.slug='microsoft-clarity' AND c.status='confirmed'")).toBe('2');
    await expect(page.locator('[data-dscc-platform="microsoft-clarity"] input[name="dscc_platform_on[]"]')).toBeChecked();
    await expect(page.locator('[data-dscc-platform="microsoft-clarity"] input[name^="dscc_platform_block"]')).not.toBeChecked();
    expect(await frontServices(page)).toContain('microsoft-clarity');

    await page.goto(TAB('platforms'));
    await page.locator('[data-dscc-platform="microsoft-clarity"] input[name="dscc_platform_on[]"]').uncheck();
    await page.click('#dscc-platforms-save');
    await expect(page.locator('.dscc-flash')).toBeVisible();
    expect(sql("SELECT status FROM wp_dscc_services WHERE slug='microsoft-clarity'")).toBe('ignored');
    expect(sql("SELECT COUNT(*) FROM wp_dscc_cookies WHERE status='ignored'")).toBe('2');
    expect(await frontServices(page)).not.toContain('microsoft-clarity');
  });

  test('"Phát hiện trên site" badge reads detected_via', async ({ page }) => {
    catalog('enable', 'hotjar');
    await page.goto(TAB('platforms'));
    await expect(page.locator('.dscc-badge--detected')).toHaveCount(0);
    sql("UPDATE wp_dscc_services SET detected_via='[\"html\"]' WHERE slug='hotjar'");
    await page.goto(TAB('platforms'));
    await expect(page.locator('[data-dscc-platform="hotjar"] .dscc-badge--detected')).toHaveText('Phát hiện trên site');
    await expect(page.locator('[data-dscc-platform="google-analytics"] .dscc-badge--detected')).toHaveCount(0);
  });

  test('custom platform form adds a ticked "Tự định nghĩa" card that visitors see', async ({ page }) => {
    await page.goto(TAB('platforms'));
    await page.fill('#dscc-custom-name', 'LittleD Chat Widget');
    await page.selectOption('#dscc-custom-category', 'marketing');
    await page.fill('#dscc-custom-privacy', 'https://littled.example/privacy');
    await page.fill('#dscc-custom-hosts', 'cdn.littled.example');
    await page.click('#dscc-custom-add');
    await expect(page.locator('.dscc-flash')).toContainText('Đã thêm nền tảng tự định nghĩa');
    const card = page.locator('[data-dscc-platform="littled-chat-widget"]');
    await expect(card.locator('.dscc-badge', { hasText: 'Tự định nghĩa' })).toBeVisible();
    await expect(card.locator('input[name="dscc_platform_on[]"]')).toBeChecked();
    expect(sql("SELECT category_key, source, block_enabled FROM wp_dscc_services WHERE slug='littled-chat-widget'")).toBe('marketing\tmanual\t1');
    expect(await frontServices(page)).toContain('littled-chat-widget');
  });

  test('update button is disabled until a source URL is configured', async ({ page }) => {
    await page.goto(TAB('platforms'));
    await expect(page.getByRole('button', { name: 'Cập nhật danh mục cookie' })).toBeDisabled();
  });
});

test.describe('cookie table', () => {
  test.beforeEach(async ({ page }) => {
    resetSite('card');
    catalog('preset');
    // Five cookies waiting for review: two of a vendor that is not confirmed yet, three of a confirmed one.
    catalog('service', 'littled-widget', 'LittleD Widget', 'marketing', 'auto');
    catalog('newcookie', 'littled-widget', 'littled-widget-id-*');
    catalog('newcookie', 'littled-widget', 'littled-widget-device-id-*');
    for (const name of ['LittleD_ga_a', 'LittleD_ga_b', 'LittleD_ga_c']) catalog('newcookie', 'google-analytics', name);
    await login(page);
  });

  test('new cookies first, menu badge and tab label count them, filters', async ({ page }) => {
    await page.goto(TAB('cookies'));
    await expect(page.locator('#adminmenu a.menu-top', { hasText: 'Cookie Consent' }).locator('.plugin-count')).toHaveText('5');
    await expect(page.locator('.nav-tab', { hasText: 'Cookie (5 mới)' })).toBeVisible();
    const headings = (await page.locator('#dscc-cookies-form thead th:not(.check-column)').allTextContents()).map((t) => t.replace(/\s*Sort (ascending|descending)\.?$/, '').trim());
    expect(headings).toEqual(['Tên cookie', 'Nhà cung cấp', 'Nhóm', 'Thời hạn', 'Ghi bởi', 'Lần cuối thấy', 'Số lần thấy', 'Trạng thái', 'Nguồn']);
    const status = await page.locator('tr.dscc-ck-row .dscc-status').allTextContents();
    expect(status.slice(0, 5)).toEqual(Array(5).fill('Mới'));
    expect(status.slice(5)).not.toContain('Mới');

    await page.selectOption('#dscc-f-category', 'marketing');
    await page.click('.dscc-filter button[type="submit"]');
    const cats = await page.locator('tr.dscc-ck-row .dscc-ck-category').allTextContents();
    expect(cats.length).toBeGreaterThan(0);
    expect(new Set(cats)).toEqual(new Set(['Tiếp thị']));

    await page.goto(TAB('cookies') + '&status=new');
    await expect(page.locator('tr.dscc-ck-row')).toHaveCount(5);
    await page.goto(TAB('cookies') + '&s=_fbp');
    await expect(page.locator('tr.dscc-ck-row')).toHaveCount(1);
    await page.goto(TAB('cookies') + '&category=unclassified');
    await expect(page.locator('tr.dscc-ck-row')).toHaveCount(0);
  });

  test('quick edit changes purpose and duration in place and saves them', async ({ page }) => {
    await page.goto(TAB('cookies') + '&s=dscc_consent');
    const row = page.locator('tr.dscc-ck-row', { hasText: 'dscc_consent' });
    await row.locator('.dscc-qe-open').click();
    const editor = page.locator('.dscc-qe-row');
    await expect(editor).toBeVisible();
    await editor.locator('textarea[name="purpose"]').fill('LittleD mục đích mới');
    await editor.locator('textarea[name="purpose_en"]').fill('LittleD new purpose');
    await editor.locator('input[name="duration_label"]').fill('200 ngày');
    await editor.locator('input[name="duration_label_en"]').fill('200 days');
    await editor.getByRole('button', { name: 'Lưu' }).click();
    await expect(editor).toHaveCount(0);
    await expect(row.locator('.dscc-ck-purpose')).toHaveText('LittleD mục đích mới');
    await expect(row.locator('.dscc-ck-duration')).toHaveText('200 ngày');
    expect(sql("SELECT purpose, purpose_en, duration_label, duration_label_en FROM wp_dscc_cookies WHERE name='dscc_consent'")).toBe('LittleD mục đích mới\tLittleD new purpose\t200 ngày\t200 days');

    // The visitor-facing snapshot follows the edit.
    await page.goto('/');
    expect(await page.evaluate(() => document.getElementById('dscc-tpl-modal').innerHTML)).toContain('LittleD new purpose');
  });

  test('quick edit category moves the vendor, cancel changes nothing', async ({ page }) => {
    await page.goto(TAB('cookies') + '&s=_gid');
    const row = page.locator('tr.dscc-ck-row', { hasText: '_gid' });
    await row.locator('.dscc-qe-open').click();
    await expect(page.locator('.dscc-qe__note')).toContainText('Google Analytics');
    await page.locator('.dscc-qe-row').getByRole('button', { name: 'Huỷ' }).click();
    await expect(page.locator('.dscc-qe-row')).toHaveCount(0);
    expect(sql("SELECT category_key FROM wp_dscc_services WHERE slug='google-analytics'")).toBe('analytics');

    await row.locator('.dscc-qe-open').click();
    await page.locator('.dscc-qe-row select[name="category"]').selectOption('marketing');
    await page.locator('.dscc-qe-row').getByRole('button', { name: 'Lưu' }).click();
    await expect(row.locator('.dscc-ck-category')).toHaveText('Tiếp thị');
    expect(sql("SELECT category_key, block_enabled FROM wp_dscc_services WHERE slug='google-analytics'")).toBe('marketing\t1');
  });

  test('bulk confirm confirms the vendor, skips cookies with no vendor, and lowers the badge', async ({ page }) => {
    catalog('orphan', 'LittleD_orphan');
    await page.goto(TAB('cookies') + '&status=new');
    await expect(page.locator('tr.dscc-ck-row')).toHaveCount(6);
    await page.locator('tr.dscc-ck-row', { hasText: 'littled-widget-id-*' }).locator('input[type="checkbox"]').check();
    await page.locator('tr.dscc-ck-row', { hasText: 'LittleD_orphan' }).locator('input[type="checkbox"]').check();
    await page.selectOption('#bulk-action-selector-top', 'confirm');
    await page.click('#doaction');
    await expect(page.locator('.dscc-flash').first()).toContainText('Đã xác nhận 1 cookie');
    await expect(page.locator('.dscc-flash.notice-warning')).toContainText('Bỏ qua 1 cookie');
    expect(sql("SELECT status FROM wp_dscc_cookies WHERE name='littled-widget-id-*'")).toBe('confirmed');
    expect(sql("SELECT status FROM wp_dscc_services WHERE slug='littled-widget'")).toBe('confirmed');
    await expect(page.locator('#adminmenu a.menu-top', { hasText: 'Cookie Consent' }).locator('.plugin-count')).toHaveText('5');
  });

  test('bulk move to a category, ignore and delete', async ({ page }) => {
    catalog('orphan', 'LittleD_orphan');
    await page.goto(TAB('cookies') + '&s=LittleD_orphan');
    await page.locator('tr.dscc-ck-row input[type="checkbox"]').check();
    await page.selectOption('#bulk-action-selector-top', 'move_necessary');
    await page.click('#doaction');
    await expect(page.locator('.dscc-flash')).toContainText('Đã chuyển nhóm cho 1 cookie');
    await page.goto(TAB('cookies') + '&s=LittleD_orphan');
    await expect(page.locator('tr.dscc-ck-row .dscc-ck-category')).toHaveText('Cần thiết');
    expect(sql("SELECT status FROM wp_dscc_cookies WHERE name='LittleD_orphan'")).toBe('new');

    await page.locator('tr.dscc-ck-row input[type="checkbox"]').check();
    await page.selectOption('#bulk-action-selector-top', 'ignore');
    await page.click('#doaction');
    expect(sql("SELECT status FROM wp_dscc_cookies WHERE name='LittleD_orphan'")).toBe('ignored');

    await page.goto(TAB('cookies') + '&s=LittleD_orphan');
    await page.locator('tr.dscc-ck-row input[type="checkbox"]').check();
    await page.selectOption('#bulk-action-selector-top', 'delete');
    await page.click('#doaction');
    expect(sql("SELECT COUNT(*) FROM wp_dscc_cookies WHERE name='LittleD_orphan'")).toBe('0');
  });

  test('add a cookie by hand: confirmed, listed for visitors', async ({ page }) => {
    await page.goto(TAB('cookies'));
    await page.fill('#dscc-ca-name', 'LittleD_pref');
    await page.selectOption('#dscc-ca-category', 'necessary');
    await page.fill('#dscc-ca-purpose', 'Ghi nhớ tuỳ chọn LittleD');
    await page.fill('#dscc-ca-duration', '30 ngày');
    await page.click('#dscc-cookie-add');
    await expect(page.locator('.dscc-flash')).toContainText('Đã thêm cookie');
    expect(sql("SELECT status, source FROM wp_dscc_cookies WHERE name='LittleD_pref'")).toBe('confirmed\tmanual');
    await page.goto(TAB('cookies') + '&s=LittleD_pref');
    await expect(page.locator('tr.dscc-ck-row .dscc-ck-category')).toHaveText('Cần thiết');
    await page.goto('/');
    expect(await page.evaluate(() => document.getElementById('dscc-tpl-modal').innerHTML)).toContain('LittleD_pref');
  });
});

test.describe('consent mode regions cap', () => {
  test('more than 40 regions: extras are dropped with a notice, the rest is saved', async ({ page }) => {
    resetSite('card');
    await login(page);
    await page.goto(TAB('advanced'));
    const codes = [];
    for (let i = 0; i < 45; i++) codes.push(String.fromCharCode(65 + Math.floor(i / 26)) + String.fromCharCode(65 + (i % 26)));
    await page.fill('#dscc-advanced-consent_regions', codes.join('\n'));
    await page.click('#dscc-settings-form #submit');
    await expect(page.locator('#dscc-setting-rejected')).toContainText('40 vùng đầu tiên');
    const saved = (await page.inputValue('#dscc-advanced-consent_regions')).split('\n');
    expect(saved).toHaveLength(40);
    expect(saved[39]).toBe(codes[39]);
    await page.goto('/');
    const boot = await page.evaluate(() => window.DSCC_BOOT.cfg.r);
    expect(boot.split(',')).toHaveLength(40);
  });
});
