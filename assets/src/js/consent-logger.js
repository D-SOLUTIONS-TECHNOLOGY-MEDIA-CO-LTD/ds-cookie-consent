/**
 * Sends one anonymous log row to admin-ajax (works when REST is blocked). Never throws.
 * No cookie values are ever sent: only the consent id, flags, version, language and the page path.
 */

export function logChoice(cfg, rec, choice, src) {
  try {
    if (cfg.preview || !cfg.ajax) return;
    const body = new URLSearchParams();
    body.append('action', 'dscc_log');
    body.append('id', rec.id);
    body.append('choice', choice);
    body.append('c', JSON.stringify(rec.c));
    if (rec.s && Object.keys(rec.s).length) body.append('s', JSON.stringify(rec.s));
    body.append('v', rec.v);
    body.append('lang', (document.documentElement.lang || cfg.lang || '').slice(0, 2).toLowerCase() || cfg.lang);
    body.append('url', location.pathname);
    body.append('src', src);
    if (navigator.sendBeacon && navigator.sendBeacon(cfg.ajax, body)) return;
    fetch(cfg.ajax, { method: 'POST', body, keepalive: true, credentials: 'same-origin' }).catch(() => {});
  } catch (e) {
    /* logging must never affect the visitor */
  }
}
