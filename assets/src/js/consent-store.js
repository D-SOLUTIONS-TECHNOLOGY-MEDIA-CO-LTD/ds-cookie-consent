/**
 * Consent cookie storage (contract: {v,id,t,c,s,src}, value = encodeURIComponent(JSON)).
 * The validation rules mirror ConsentReader::parse() in PHP and the inline head script.
 */

const MAX_ENCODED = 1900; // keep the cookie well under the 4 KB browser limit; "s" is dropped beyond this

function escapeRegExp(s) {
  return s.replace(/[-.*+?^${}()|[\]\\]/g, '\\$&');
}

function cleanId(id) {
  return typeof id === 'string' ? id.replace(/[^a-zA-Z0-9-]/g, '').slice(0, 64) : '';
}

function flags(obj) {
  const out = {};
  if (obj && typeof obj === 'object') {
    Object.keys(obj).forEach((k) => {
      out[k] = obj[k] ? 1 : 0;
    });
  }
  return out;
}

/** Generate a consent id (uuid v4 when crypto is available). */
export function uid() {
  try {
    if (window.crypto && window.crypto.randomUUID) return window.crypto.randomUUID();
  } catch (e) {
    /* fall through */
  }
  return Date.now().toString(36) + '-' + Math.random().toString(36).slice(2, 10);
}

/** Read and JSON-decode the raw cookie value, or null. */
export function readRaw(name) {
  try {
    const m = document.cookie.match(new RegExp('(?:^|;\\s*)' + escapeRegExp(name) + '=([^;]+)'));
    return m ? JSON.parse(decodeURIComponent(m[1])) : null;
  } catch (e) {
    return null;
  }
}

/**
 * Normalise a decoded cookie. Returns null when invalid, expired or for another version.
 * @returns {{v:string,id:string,t:number,c:Object,s:Object,src:string}|null}
 */
export function normalize(raw, version, days, now) {
  if (!raw || typeof raw !== 'object' || String(raw.v) !== String(version)) return null;
  const id = cleanId(raw.id);
  if (!id) return null;
  let t = Number(raw.t);
  t = Number.isFinite(t) ? Math.floor(t) : 0;
  // A record without a usable timestamp can never be shown to expire, so it counts as expired.
  if (!t || t + days * 86400 < now) return null;

  if (!raw.c || typeof raw.c !== 'object') return null;
  return { v: String(raw.v), id, t, c: flags(raw.c), s: flags(raw.s), src: typeof raw.src === 'string' ? raw.src : 'banner' };
}

/** Create a store bound to the cookie settings. */
export function createStore(cfg) {
  const { name, days, domain } = cfg.cookie;
  let mem = null; // fallback when cookies are blocked

  function serialize(rec) {
    const obj = { v: rec.v, id: rec.id, t: rec.t, c: rec.c, s: rec.s, src: rec.src };
    let enc = encodeURIComponent(JSON.stringify(obj));
    if (enc.length > MAX_ENCODED) {
      obj.s = {};
      enc = encodeURIComponent(JSON.stringify(obj));
    }
    return enc;
  }

  function write(rec) {
    mem = rec;
    let s = name + '=' + serialize(rec) + '; Max-Age=' + days * 86400 + '; Path=/; SameSite=Lax';
    if (location.protocol === 'https:') s += '; Secure';
    if (domain) s += '; Domain=' + domain;
    try {
      document.cookie = s;
    } catch (e) {
      /* cookies disabled: keep the in-memory copy for this page view */
    }
  }

  function read() {
    const now = Math.floor(Date.now() / 1000);
    let rec = normalize(readRaw(name), cfg.v, days, now);
    if (!rec && mem) rec = normalize(mem, cfg.v, days, now);
    return rec;
  }

  function clear() {
    mem = null;
    try {
      document.cookie = name + '=; Max-Age=0; Path=/' + (domain ? '; Domain=' + domain : '');
    } catch (e) {
      /* ignore */
    }
  }

  return { read, write, clear };
}
