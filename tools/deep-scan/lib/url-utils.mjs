// URL helpers for site normalization and internal link selection.
import { hostFromUrl } from './report-builder.mjs';

const SKIP_EXT = /\.(pdf|zip|rar|gz|jpe?g|png|gif|webp|svg|ico|mp[34]|avi|mov|css|js|xml|json|docx?|xlsx?|pptx?)$/i;
const SKIP_PATH = /\/(wp-admin|wp-login\.php|wp-json|feed|xmlrpc\.php|cart|checkout|my-account)(\/|$)/i;

/** Normalizes the user supplied site URL: adds https:// when missing, strips trailing slash. */
export function normalizeSiteUrl(input) {
  if (/^[a-z][a-z0-9+.-]*:\/\//i.test(input) && !/^https?:\/\//i.test(input)) {
    throw new Error('Only http(s) URLs are supported');
  }
  const raw = /^https?:\/\//i.test(input) ? input : `https://${input}`;
  const u = new URL(raw); // throws on garbage, caller reports
  if (u.protocol !== 'http:' && u.protocol !== 'https:') throw new Error('Only http(s) URLs are supported');
  u.hash = '';
  u.search = '';
  return u.toString().replace(/\/+$/, '');
}

/** Resolves --urls entries (absolute or relative) against the site URL. */
export function resolveUrls(list, siteUrl) {
  const base = siteUrl.endsWith('/') ? siteUrl : `${siteUrl}/`;
  return list.map((s) => s.trim()).filter(Boolean).map((s) => new URL(s, base).toString());
}

/** Picks up to `max` distinct same-host internal links, excluding the start page, assets and admin paths. */
export function pickInternalLinks(hrefs, startUrl, max) {
  const host = hostFromUrl(startUrl);
  const seen = new Set([stripHash(startUrl)]);
  const picked = [];
  for (const href of hrefs || []) {
    if (picked.length >= max) break;
    let u;
    try {
      u = new URL(href, startUrl);
    } catch {
      continue;
    }
    if (hostFromUrl(u.toString()) !== host) continue;
    if (SKIP_EXT.test(u.pathname) || SKIP_PATH.test(u.pathname)) continue;
    const key = stripHash(u.toString());
    if (seen.has(key) || seen.has(key.replace(/\/$/, ''))) continue;
    seen.add(key);
    picked.push(key);
  }
  return picked;
}

function stripHash(url) {
  const u = new URL(url);
  u.hash = '';
  return u.toString();
}
