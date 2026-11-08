// Browser-driven scan: before_choice -> after_reject -> after_accept.
// Uses Playwright chromium; all observations flow into report-builder collectors.
import { chromium } from 'playwright';
import { createPhaseCollector, originFromUrl } from './report-builder.mjs';
import { pickInternalLinks } from './url-utils.mjs';

const NAV_TIMEOUT = 30000;
const POST_LOAD_WAIT = 2500; // lets late trackers fire on pages after a choice
const UA = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36';

/** Context wrapper that routes network events to whichever collector is currently active. */
async function newTrackedContext(browser, prepareContext) {
  const context = await browser.newContext({ userAgent: UA, ignoreHTTPSErrors: true });
  if (prepareContext) await prepareContext(context); // programmatic hook (tests stub third-party hosts)
  const state = { active: null, pending: new Set() };
  context.on('request', (req) => state.active?.addRequest(req.url()));
  context.on('response', (res) => {
    const collector = state.active;
    if (!collector) return;
    const p = res
      .headersArray()
      .then((headers) => {
        for (const h of headers) if (h.name.toLowerCase() === 'set-cookie') collector.addSetCookie(res.url(), h.value);
      })
      .catch(() => {}) // body-less / aborted responses can fail; names are best effort
      .finally(() => state.pending.delete(p));
    state.pending.add(p);
  });
  return { context, state };
}

async function readStorageKeys(page) {
  try {
    return await page.evaluate(() => ({
      local: Object.keys(window.localStorage),
      session: Object.keys(window.sessionStorage),
    }));
  } catch {
    return { local: [], session: [] }; // storage blocked or page navigated away
  }
}

/** Loads one URL, waits, records storage keys. Returns the open page (caller closes). */
async function loadPage(tracked, collector, url, waitMs, errors) {
  tracked.state.active = collector;
  const page = await tracked.context.newPage();
  try {
    await page.goto(url, { waitUntil: 'load', timeout: NAV_TIMEOUT });
    collector.addPage(page.url());
    await page.waitForTimeout(waitMs);
    const keys = await readStorageKeys(page);
    collector.addStorage(originFromUrl(page.url()), keys.local, keys.session);
  } catch (err) {
    errors.push(`load ${url}: ${err.message.split('\n')[0]}`);
  }
  return page;
}

async function snapshotPhase(tracked, collector) {
  await Promise.allSettled([...tracked.state.pending]);
  tracked.state.active = null;
  return collector.snapshot(await tracked.context.cookies());
}

async function visitAll(tracked, collector, urls, waitMs, errors) {
  for (const url of urls) {
    const page = await loadPage(tracked, collector, url, waitMs, errors);
    await page.close().catch(() => {});
  }
}

async function clickIfVisible(page, selector, errors, label) {
  const btn = page.locator(`${selector}:visible`).first();
  try {
    await btn.waitFor({ state: 'visible', timeout: 5000 });
    await btn.click({ timeout: 5000 });
    await page.waitForTimeout(1000); // consent write + purge settle
    return true;
  } catch {
    errors.push(`${label} button not found: ${selector}`);
    return false;
  }
}

async function discoverLinks(page, home, count) {
  if (count <= 0) return [];
  const hrefs = await page
    .evaluate(() => [...document.querySelectorAll('a[href]')].map((a) => a.href))
    .catch(() => []);
  return pickInternalLinks(hrefs, home, count);
}

/**
 * Runs the three phases. Returns { phases, bannerFound, pluginDetected, errors }.
 * Never throws for page-level problems; they land in errors[].
 */
export async function runScan(opts, log = () => {}) {
  const errors = [];
  const phases = {};
  const args = opts.resolve?.length ? [`--host-resolver-rules=${opts.resolve.map((r) => `MAP ${r.replace('=', ' ')}`).join(', ')}`] : [];
  const browser = await chromium.launch({ headless: true, args });
  let bannerFound = false;
  let pluginDetected = false;
  try {
    // Phase 1: clean context, home page only, long wait.
    log('phase before_choice');
    const a = await newTrackedContext(browser, opts.prepareContext);
    const c1 = createPhaseCollector();
    const home = await loadPage(a, c1, opts.homeUrl, opts.wait, errors);
    pluginDetected = await home.evaluate(() => typeof window.DSCC !== 'undefined').catch(() => false);
    const links = opts.urls.length
      ? []
      : await discoverLinks(home, opts.homeUrl, opts.pages - 1);
    const scanUrls = opts.urls.length ? opts.urls : [opts.homeUrl, ...links];
    phases.before_choice = await snapshotPhase(a, c1);

    // Phase 2: reject on the still-open home page, then revisit pages in the same context.
    log('phase after_reject');
    const rejectErrors = [];
    const hasReject = await clickIfVisible(home, opts.rejectSelector, rejectErrors, 'reject');
    const hasAccept = (await home.locator(`${opts.acceptSelector}:visible`).count().catch(() => 0)) > 0;
    bannerFound = hasReject || hasAccept;
    errors.push(...rejectErrors);
    await home.close().catch(() => {});
    const c2 = createPhaseCollector();
    await visitAll(a, c2, scanUrls, POST_LOAD_WAIT, errors);
    phases.after_reject = await snapshotPhase(a, c2);
    await a.context.close();

    // Phase 3: brand-new context, accept, revisit pages. Setup load is not recorded.
    log('phase after_accept');
    const b = await newTrackedContext(browser, opts.prepareContext);
    const setup = await loadPage(b, createPhaseCollector(), scanUrls[0], 1500, errors);
    const accepted = await clickIfVisible(setup, opts.acceptSelector, [], 'accept');
    if (!accepted) errors.push(`accept button not found: ${opts.acceptSelector}`);
    bannerFound = bannerFound || accepted;
    await setup.close().catch(() => {});
    const c3 = createPhaseCollector();
    await visitAll(b, c3, scanUrls, POST_LOAD_WAIT, errors);
    phases.after_accept = await snapshotPhase(b, c3);
    await b.context.close();
  } finally {
    await browser.close().catch(() => {});
  }
  return { phases, bannerFound, pluginDetected, errors };
}
