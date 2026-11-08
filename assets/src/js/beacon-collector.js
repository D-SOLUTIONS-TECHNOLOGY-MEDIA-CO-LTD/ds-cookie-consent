/**
 * Passive cookie reporting (spec 6.2 B). Off unless the admin switched it on (cfg.beacon). A small share of page views
 * (cfg.beacon.rate, drawn here in the browser so the HTML stays identical for everyone) reports, 5 seconds after load
 * and only when the visitor already made a choice:
 *   - NAMES of cookies, localStorage and sessionStorage keys (never a value),
 *   - hosts of the resources the page loaded,
 *   - the visitor's own consent state, so the server can spot cookies that should not exist,
 *   - the page path.
 * Never for logged-in users or WordPress admin cookies; once per browser session; every failure is swallowed.
 */

const ADMIN_COOKIE = /^(wordpress_|wp-settings-|wp_lang$)/;
const MAX_BODY = 3600; // the server refuses bodies over 4096 bytes
const SESSION_FLAG = 'dscc_bc';

/** Names of the cookies the page can read, minus the WordPress admin ones. */
function cookieNames() {
  return document.cookie
    .split(';')
    .map((p) => p.split('=')[0].trim())
    .filter((n) => n && n.length <= 100 && !ADMIN_COOKIE.test(n))
    .slice(0, 60);
}

/** Keys of a web storage area. */
function storageKeys(area) {
  try {
    const out = [];
    for (let i = 0; i < area.length && out.length < 40; i++) {
      const k = area.key(i);
      if (k && k.length <= 100 && k.indexOf('dscc_') !== 0) out.push(k); // not this plugin's own keys
    }
    return out;
  } catch (e) {
    return [];
  }
}

/** Hosts other than this site's that the page loaded resources from. */
function resourceHosts() {
  const seen = {};
  try {
    performance.getEntriesByType('resource').forEach((e) => {
      try {
        const h = new URL(e.name).hostname;
        if (h && h !== location.hostname && h.indexOf('.') > 0) seen[h] = 1;
      } catch (err) {
        /* not a URL */
      }
    });
  } catch (e) {
    /* Resource Timing unavailable */
  }
  return Object.keys(seen).slice(0, 60);
}

/** Build the request body, dropping entries until it fits the size limit. */
function buildBody(cfg, rec) {
  const lists = { c: cookieNames(), l: storageKeys(window.localStorage), x: storageKeys(window.sessionStorage), h: resourceHosts() };
  const s = {};
  Object.keys(rec.s || {}).slice(0, 60).forEach((k) => (s[k] = rec.s[k] ? 1 : 0));
  const c = {};
  Object.keys(rec.c || {}).forEach((k) => (c[k] = rec.c[k] ? 1 : 0));
  for (;;) {
    const body = new URLSearchParams();
    body.append('action', 'dscc_beacon');
    body.append('d', JSON.stringify({ c: lists.c, l: lists.l, x: lists.x, h: lists.h, s: { c, s }, u: location.pathname }));
    if (body.toString().length <= MAX_BODY) return body;
    const longest = Object.keys(lists).sort((a, b) => lists[b].length - lists[a].length)[0];
    if (!lists[longest].length) return body;
    lists[longest].pop();
  }
}

/**
 * Schedule one report.
 * @param {object} cfg   Client data (cfg.beacon, cfg.ajax, cfg.preview).
 * @param {Function} read Returns the visitor's current consent record or null.
 */
export function startBeacon(cfg, read) {
  try {
    const b = cfg.beacon;
    if (!b || cfg.preview || !cfg.ajax || !(b.rate > 0) || Math.random() * 100 >= b.rate) return;
    if (document.body && document.body.classList.contains('logged-in')) return;
    if (/(^|;\s*)(wordpress_logged_in_|wp-settings-)/.test(document.cookie)) return;
    if (window.sessionStorage.getItem(SESSION_FLAG)) return;

    const send = () => {
      try {
        const rec = read();
        if (!rec) return; // no choice yet: nothing to compare with
        window.sessionStorage.setItem(SESSION_FLAG, '1');
        const body = buildBody(cfg, rec);
        if (navigator.sendBeacon && navigator.sendBeacon(cfg.ajax, body)) return;
        fetch(cfg.ajax, { method: 'POST', body, keepalive: true, credentials: 'same-origin' }).catch(() => {});
      } catch (e) {
        /* reporting must never affect the visitor */
      }
    };
    const later = () => setTimeout(() => (window.requestIdleCallback ? window.requestIdleCallback(send, { timeout: 5000 }) : send()), 5000);
    if (document.readyState === 'complete') later();
    else window.addEventListener('load', later, { once: true });
  } catch (e) {
    /* reporting must never affect the visitor */
  }
}
