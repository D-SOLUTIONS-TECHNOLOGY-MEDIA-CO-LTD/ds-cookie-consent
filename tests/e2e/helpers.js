// Shared helpers: wp-cli through wp-env and small page utilities.
const { execFileSync } = require('node:child_process');
const path = require('node:path');

const ROOT = path.resolve(__dirname, '..', '..');
const PLUGIN_DIR = 'wp-content/plugins/' + path.basename(ROOT);

/** Run wp-cli inside the wp-env dev container and return stdout. */
function wp(...args) {
  return execFileSync('npx', ['wp-env', 'run', 'cli', 'wp', ...args], { cwd: ROOT, encoding: 'utf8', stdio: ['ignore', 'pipe', 'pipe'] }).toString();
}

/** Reset settings/log for a layout. Extra pairs: 'group.key=value'. */
function resetSite(layout, ...pairs) {
  wp('eval-file', `${PLUGIN_DIR}/tests/e2e/fixtures/reset.php`, layout, ...pairs);
}

/** Run tests/e2e/fixtures/catalog.php with a command (enable|disable|block|preset|service|newcookie|orphan|addcookie|page) and arguments. */
function catalog(...args) {
  return wp('eval-file', `${PLUGIN_DIR}/tests/e2e/fixtures/catalog.php`, ...args);
}

/** Run tests/e2e/fixtures/vendors.php: 'set' + flags (ga ordered meta clarity youtube pixel gtm server_cookies) or 'off'. */
function vendors(...args) {
  return wp('eval-file', `${PLUGIN_DIR}/tests/e2e/fixtures/vendors.php`, ...args);
}

/**
 * Intercept every request that is not for the local site: vendor hosts answer with tiny stubs that behave like the real
 * scripts do (set their cookie, fire their collect request), everything else is answered empty. Nothing leaves the
 * machine. Returns {urls, count(regex)}; every request is recorded, even the stubbed ones.
 */
async function interceptVendors(context) {
  const urls = [];
  const js = (body) => ({ status: 200, contentType: 'application/javascript', body });
  await context.route('**/*', (route) => {
    const url = route.request().url();
    let host = '';
    try {
      host = new URL(url).hostname;
    } catch (e) {
      return route.continue();
    }
    if (host === 'localhost' || host === '127.0.0.1' || !/^https?:/.test(url)) return route.continue();
    urls.push(url);
    if (/googletagmanager\.com\/gtag\/js/.test(url)) {
      return route.fulfill(js("(window.__order=window.__order||[]).push('ga-loader');document.cookie='_ga=GA1.1.LittleD; path=/; max-age=63072000';new Image().src='https://www.google-analytics.com/g/collect?v=2&tid=G-LITTLED000';"));
    }
    if (/google-analytics\.com\/analytics\.js/.test(url)) {
      return route.fulfill(js("(window.__order=window.__order||[]).push('ga-analytics-js');window.ga=function(){};"));
    }
    if (/googletagmanager\.com\/gtm\.js/.test(url)) return route.fulfill(js("window.__gtm=1;"));
    if (/connect\.facebook\.net\/.*fbevents\.js/.test(url)) {
      return route.fulfill(js("document.cookie='_fbp=fb.1.LittleD; path=/; max-age=7776000';new Image().src='https://www.facebook.com/tr/?id=000LittleD&ev=PageView';"));
    }
    if (/clarity\.ms\/tag\//.test(url)) {
      return route.fulfill(js("document.cookie='_clck=LittleD; path=/; max-age=31536000';new Image().src='https://www.clarity.ms/collect';"));
    }
    if (/youtube\.com\/embed\//.test(url)) return route.fulfill({ status: 200, contentType: 'text/html', body: '<html><body>LittleD video</body></html>' });
    if (/\.(png|gif)|collect|\/tr\/?\?/.test(url)) return route.fulfill({ status: 200, contentType: 'image/gif', body: Buffer.from('R0lGODlhAQABAAAAACw=', 'base64') });
    return route.fulfill({ status: 200, contentType: 'text/plain', body: '' });
  });
  return { urls, count: (re) => urls.filter((u) => re.test(u)).length, clear: () => (urls.length = 0) };
}

/** Run a SQL statement through wp-cli and return the raw tab-separated output without the header. */
function sql(query) {
  return wp('db', 'query', query, '--skip-column-names').trim();
}

/** services_json of every log row, parsed, oldest first. */
function logServices() {
  return sql('SELECT services_json FROM wp_dscc_consent_log ORDER BY id ASC')
    .split('\n')
    .filter(Boolean)
    .map((l) => (l === 'NULL' ? null : JSON.parse(l)));
}

/** Rows of the consent log as objects, oldest first. */
function logRows() {
  const out = wp('db', 'query', 'SELECT consent_id,choice,analytics,marketing,src,policy_version,ip_masked,page_url FROM wp_dscc_consent_log ORDER BY id ASC', '--skip-column-names');
  return out
    .split('\n')
    .map((l) => l.trim())
    .filter(Boolean)
    .map((l) => {
      const [consent_id, choice, analytics, marketing, src, policy_version, ip_masked, page_url] = l.split('\t');
      return { consent_id, choice, analytics: Number(analytics), marketing: Number(marketing), src, policy_version, ip_masked, page_url };
    });
}

/** Wait until the log has at least n rows (the log request is a background beacon). */
async function waitForLog(n, timeoutMs = 8000) {
  const end = Date.now() + timeoutMs;
  let rows = [];
  while (Date.now() < end) {
    rows = logRows();
    if (rows.length >= n) return rows;
    await new Promise((r) => setTimeout(r, 400));
  }
  return rows;
}

/** The Consent Mode "default" call recorded in dataLayer. */
async function consentDefault(page) {
  return page.evaluate(() => {
    const e = Array.from(window.dataLayer || []).find((a) => a && a[0] === 'consent' && a[1] === 'default');
    return e ? Object.assign({}, e[2]) : null;
  });
}

/** Every Consent Mode "update" call recorded in dataLayer. */
async function consentUpdates(page) {
  return page.evaluate(() =>
    Array.from(window.dataLayer || [])
      .filter((a) => a && a[0] === 'consent' && a[1] === 'update')
      .map((a) => Object.assign({}, a[2]))
  );
}

/** dscc_consent_update events pushed to dataLayer. */
async function consentEvents(page) {
  return page.evaluate(() => Array.from(window.dataLayer || []).filter((a) => a && a.event === 'dscc_consent_update').map((a) => ({ analytics: a.dscc_analytics, marketing: a.dscc_marketing })));
}

/** Parsed consent cookie or null. */
async function consentCookie(context, name = 'dscc_consent') {
  const c = (await context.cookies()).find((x) => x.name === name);
  return c ? JSON.parse(decodeURIComponent(c.value)) : null;
}

/** Locators that differ between the two layouts. */
function ui(page, layout) {
  const root = page.locator('#dscc');
  return {
    root,
    banner: layout === 'bar' ? page.locator('#dscc .dscc-bar') : page.locator('#dscc .dscc-card'),
    widget: page.locator('#dscc .dscc-widget'),
    accept: page.locator('#dscc [data-dscc-action="accept"]:visible').first(),
    reject: page.locator('#dscc [data-dscc-action="reject"]:visible').first(),
    customize: page.locator('#dscc [data-dscc-action="customize"]:visible').first(),
    dialog: layout === 'bar' ? page.locator('#dscc .dscc-modal') : page.locator('#dscc .dscc-card'),
  };
}

module.exports = { vendors, interceptVendors, wp, catalog, sql, logServices, resetSite, logRows, waitForLog, consentDefault, consentUpdates, consentEvents, consentCookie, ui, ROOT, PLUGIN_DIR };
