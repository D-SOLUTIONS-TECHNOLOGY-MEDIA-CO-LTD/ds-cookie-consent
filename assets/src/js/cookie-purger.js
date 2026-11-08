/**
 * Deletes the known cookies and web-storage keys of everything the visitor has not allowed.
 * The server lists them per service in #dscc-data (cfg.block.purge): [{s: slug, c: category, k: [[name, storage]]}].
 * Cookie names may be globs ("_ga_*"). A cookie is removed on the current host and its parent domains, with path "/"
 * and each folder of the current path. Cookies set with HttpOnly or by another host cannot be reached from here.
 */
import { isAllowed } from './consent-check.js';

function globToRegExp(glob) {
  return new RegExp('^' + glob.split('*').map((p) => p.replace(/[-.+?^${}()|[\]\\]/g, '\\$&')).join('.*') + '$');
}

/** Host variants a cookie could have been set for: none (host-only), "host", ".host" and the same for each parent domain. */
function domains(extra) {
  const out = [''];
  const host = location.hostname;
  if (extra) out.push(extra);
  if (/^[\d.]+$/.test(host) || host.indexOf(':') !== -1) return out; // IP address
  const parts = host.split('.');
  for (let i = 0; i <= parts.length - 2; i++) {
    const d = parts.slice(i).join('.');
    out.push(d, '.' + d);
  }
  return out;
}

/** "/", "/a", "/a/b" for /a/b/c and for /a/b/c/ the same plus "/a/b/c" (a cookie without Path defaults to the folder). */
function paths() {
  const out = ['/'];
  let acc = '';
  const segs = location.pathname.split('/').filter(Boolean);
  if (location.pathname.slice(-1) !== '/') segs.pop(); // the last segment is the page itself
  segs.forEach((seg) => {
    acc += '/' + seg;
    out.push(acc);
  });
  return out;
}

function expireCookies(re, extraDomain, kept) {
  const names = document.cookie
    .split(';')
    .map((c) => c.split('=')[0].trim())
    .filter((n) => n && re.test(n) && !kept(n));
  if (!names.length) return;
  const ds = domains(extraDomain);
  const ps = paths();
  names.forEach((name) => {
    ds.forEach((d) => {
      ps.forEach((p) => {
        document.cookie = name + '=; Max-Age=0; Expires=Thu, 01 Jan 1970 00:00:00 GMT; Path=' + p + (d ? '; Domain=' + d : '');
      });
    });
  });
}

function removeKeys(storage, re, kept) {
  const keys = [];
  for (let i = 0; i < storage.length; i++) keys.push(storage.key(i));
  keys.forEach((k) => {
    if (k && re.test(k) && !kept(k)) storage.removeItem(k);
  });
}

/**
 * @param {Object} cfg client data
 * @param {Object|null} rec current consent record
 */
export function purge(cfg, rec) {
  try {
    // Names that are never deleted (consent cookie, WordPress, WooCommerce, PHP session...): list comes from the server.
    const keep = ((cfg.block && cfg.block.keep) || []).map((g) => globToRegExp(String(g)));
    const kept = (name) => keep.some((re) => re.test(name));
    ((cfg.block && cfg.block.purge) || []).forEach((group) => {
      if (isAllowed(rec, group.c, group.s)) return;
      group.k.forEach((key) => {
        try {
          const re = globToRegExp(String(key[0]));
          if (key[1] === 'localStorage') removeKeys(window.localStorage, re, kept);
          else if (key[1] === 'sessionStorage') removeKeys(window.sessionStorage, re, kept);
          else if (key[1] === 'cookie') expireCookies(re, cfg.cookie && cfg.cookie.domain, kept);
        } catch (e) {
          /* storage may be blocked: skip this key */
        }
      });
    });
  } catch (e) {
    /* never break the page */
  }
}
