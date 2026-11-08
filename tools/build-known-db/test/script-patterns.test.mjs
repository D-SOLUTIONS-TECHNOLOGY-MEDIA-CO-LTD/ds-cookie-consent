import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const db = JSON.parse(await readFile(path.join(here, '..', '..', '..', 'data', 'known-cookies.json'), 'utf8'));

/** Same semantics the PHP HtmlScanner must implement: host suffix match, src/inline regex case-insensitive. */
function detect(html) {
  const found = new Set();
  const srcs = [...html.matchAll(/(?:src|href)=["']([^"']+)["']/gi)].map((m) => m[1]);
  for (const s of db.services) {
    const p = s.script_patterns;
    const hostHit = srcs.some((u) => {
      try { const h = new URL(u, 'https://site.test').hostname; return p.hosts.some((x) => h === x || h.endsWith(`.${x}`)); } catch { return false; }
    });
    const srcHit = srcs.some((u) => p.src_regex.some((r) => new RegExp(r, 'i').test(u)));
    const inlineHit = p.inline_regex.some((r) => new RegExp(r, 'i').test(html));
    if (hostHit || srcHit || inlineHit) found.add(s.slug);
  }
  return found;
}

const fixtures = [
  ['google-analytics', '<script async src="https://www.googletagmanager.com/gtag/js?id=G-LITTLED123"></script><script>gtag("config", "G-LITTLED123");</script>'],
  ['google-ads', '<script>gtag(\'config\', \'AW-123456789\');</script>'],
  ['google-floodlight', '<script async src="https://www.googletagmanager.com/gtag/js?id=DC-9999"></script>'],
  ['google-tag-manager', '<script async src="https://www.googletagmanager.com/gtm.js?id=GTM-LITTLED"></script>'],
  ['google-tag-manager', '<noscript><iframe src="https://www.googletagmanager.com/ns.html?id=GTM-ABC123"></iframe></noscript>'],
  ['youtube', '<iframe src="https://www.youtube.com/embed/LittleD"></iframe>'],
  ['youtube', '<iframe src="https://www.youtube-nocookie.com/embed/LittleD"></iframe>'],
  ['google-maps', '<iframe src="https://www.google.com/maps/embed?pb=LittleD"></iframe>'],
  ['google-recaptcha', '<script src="https://www.google.com/recaptcha/api.js?render=LittleD"></script>'],
  ['cloudflare-turnstile', '<script src="https://challenges.cloudflare.com/turnstile/v0/api.js" async></script>'],
  ['cloudflare', '<script src="/cdn-cgi/challenge-platform/scripts/jsd/main.js"></script>'],
  ['meta-pixel', '<script src="https://connect.facebook.net/en_US/fbevents.js"></script>'],
  ['meta-pixel', '<script>fbq(\'init\', \'123456789\');</script>'],
  ['tiktok-pixel', '<script src="https://analytics.tiktok.com/i18n/pixel/events.js?sdkid=LittleD"></script>'],
  ['tiktok-pixel', '<script>ttq.load(\'LITTLED\'); ttq.page();</script>'],
  ['microsoft-clarity', '<script>(function(c,l,a,r,i,t,y){t=l.createElement(r);t.src="https://www.clarity.ms/tag/"+i;})(window, document, "clarity", "script", "abc123");</script>'],
  ['microsoft-uet', '<script src="//bat.bing.com/bat.js"></script><script>var o={ti:"1"}; o.q=w[u].q; w[u]=new UET(o);</script>'],
  ['yandex-metrica', '<script>ym(12345678, "init", {clickmap:true});</script><script src="https://mc.yandex.ru/metrika/tag.js"></script>'],
  ['hotjar', '<script>h._hjSettings={hjid:1234567,hjsv:6}; r.src=t+h._hjSettings.hjid; // static.hotjar.com/c/hotjar-</script>'],
  ['hotjar', '<script src="https://static.hotjar.com/c/hotjar-1234567.js?sv=6"></script>'],
  ['linkedin-insight', '<script>_linkedin_partner_id = "123456";</script><script src="https://snap.licdn.com/li.lms-analytics/insight.min.js"></script>'],
  ['zalo', '<script src="https://sp.zalo.me/plugins/sdk.js"></script><div class="zalo-chat-widget" data-oaid="1"></div>'],
  ['intercom', '<script>window.intercomSettings = {app_id: "LittleD"}; (function(){var s=document.createElement("script"); s.src="https://widget.intercom.io/widget/littled";})();</script>'],
  ['hubspot', '<script src="//js.hs-scripts.com/12345.js" async></script>'],
  ['openai-ads-pixel', '<script>!function(w,d){var s=d.createElement("script");s.src="https://bzrcdn.openai.com/sdk/oaiq.min.js";}(window,document); oaiq("init", {pixelId: "LittleD"});</script>'],
  ['woocommerce', '<script src="/wp-content/plugins/woocommerce/assets/js/frontend/add-to-cart.min.js"></script>'],
  ['woocommerce-order-attribution', '<script src="/wp-content/plugins/woocommerce/assets/js/frontend/order-attribution.min.js"></script>'],
  ['elementor', '<script src="/wp-content/plugins/elementor/assets/js/frontend.min.js"></script>'],
  ['contact-form-7', '<script src="/wp-content/plugins/contact-form-7/includes/js/index.js"></script>'],
  ['google-site-kit', '<script id="google_gtagjs-js"></script>'],
  ['pancake-chat', '<div id="pancake-chat-plugin-root"></div>'],
];

for (const [slug, html] of fixtures) {
  test(`detects ${slug} in a standard snippet`, () => {
    assert.ok(detect(html).has(slug), `${slug} not detected in: ${html.slice(0, 80)}`);
  });
}

test('GA4 gtag snippet does not misfire as GTM, Ads or Floodlight', () => {
  const found = detect('<script async src="https://www.googletagmanager.com/gtag/js?id=G-LITTLED123"></script>');
  assert.deepEqual([...found], ['google-analytics']);
});

test('Meta Pixel snippet does not misfire as another vendor', () => {
  assert.deepEqual([...detect('<script src="https://connect.facebook.net/vi_VN/fbevents.js"></script>')], ['meta-pixel']);
});
