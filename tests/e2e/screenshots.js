// Manual visual check helper (not part of the test run): node tests/e2e/screenshots.js <outdir>
const { chromium } = require('@playwright/test');
const path = require('node:path');
const { resetSite } = require('./helpers');

(async () => {
  const out = process.argv[2] || '/tmp';
  const browser = await chromium.launch();
  for (const layout of ['card', 'bar']) {
    resetSite(layout);
    for (const [name, viewport] of [['desktop', { width: 1440, height: 900 }], ['mobile', { width: 390, height: 844 }]]) {
      const ctx = await browser.newContext({ viewport });
      const page = await ctx.newPage();
      await page.goto('http://localhost:8888/');
      await page.waitForTimeout(700);
      await page.screenshot({ path: path.join(out, `${layout}-${name}-1-banner.png`) });
      await page.locator('#dscc [data-dscc-action="customize"]:visible').first().click();
      await page.waitForTimeout(700);
      await page.screenshot({ path: path.join(out, `${layout}-${name}-2-prefs.png`) });
      await ctx.close();
    }
  }
  await browser.close();
})();
