/**
 * Hands the visitor's choice to other plugins that follow their own consent API.
 *  - WP Consent API (cfg.int.wpc): wp_set_consent(category, 'allow'|'deny'); Site Kit and others listen to it.
 *  - Meta Pixel for WordPress (cfg.int.fbs): window.fbwcsignal.release() / hold().
 * Every call is wrapped; a missing function is simply skipped.
 */
import { isAllowed } from './consent-check.js';

/** WP Consent API category => how to read it from the record. */
function wpCategories(cfg, rec) {
  const hasFunctional = (cfg.cats || []).some((c) => c.key === 'functional');
  return {
    functional: true,
    preferences: hasFunctional ? isAllowed(rec, 'functional') : true,
    statistics: isAllowed(rec, 'analytics'),
    'statistics-anonymous': isAllowed(rec, 'analytics'),
    marketing: isAllowed(rec, 'marketing'),
  };
}

function syncWpConsent(cfg, rec) {
  const set = window.wp_set_consent;
  if (typeof set !== 'function') return;
  const want = wpCategories(cfg, rec);
  Object.keys(want).forEach((cat) => {
    try {
      const value = want[cat] ? 'allow' : 'deny';
      const has = typeof window.wp_has_consent === 'function' ? !!window.wp_has_consent(cat) : null;
      if (has === null || has !== want[cat]) set(cat, value);
    } catch (e) {
      /* ignore */
    }
  });
}

function syncMeta(cfg, rec) {
  const sig = window.fbwcsignal;
  if (!sig) return;
  const call = isAllowed(rec, 'marketing', 'meta-pixel') ? sig.release : sig.hold;
  if (typeof call !== 'function') return;
  Promise.resolve(call.call(sig)).catch(() => {});
}

/**
 * @param {Object} cfg client data
 * @param {Object|null} rec consent record (null = no choice yet)
 */
export function syncBridges(cfg, rec) {
  try {
    const flags = cfg.int || {};
    if (flags.wpc) {
      syncWpConsent(cfg, rec);
      // The consent API script may load after ours; try again once everything is loaded.
      if (typeof window.wp_set_consent !== 'function') window.addEventListener('load', () => syncWpConsent(cfg, rec), { once: true });
    }
    if (flags.fbs && rec) syncMeta(cfg, rec);
  } catch (e) {
    /* never break the page */
  }
}
