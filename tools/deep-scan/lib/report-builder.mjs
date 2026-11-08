// Pure helpers that turn raw browser observations into the schema-1 report.
// No browser or network access here so everything is unit-testable.
// Cookie VALUES are never read, stored or emitted by this module.

export const REPORT_SCHEMA = 1;
export const PHASE_NAMES = ['before_choice', 'after_reject', 'after_accept'];

/** Returns the lowercase hostname of an http(s) URL, or null for anything else. */
export function hostFromUrl(url) {
  try {
    const u = new URL(url);
    return u.protocol === 'http:' || u.protocol === 'https:' ? u.hostname.toLowerCase() : null;
  } catch {
    return null;
  }
}

/** Returns the origin of an http(s) URL, or null. */
export function originFromUrl(url) {
  try {
    const u = new URL(url);
    return u.protocol === 'http:' || u.protocol === 'https:' ? u.origin : null;
  } catch {
    return null;
  }
}

/**
 * Extracts cookie names from one or more raw Set-Cookie header values.
 * Playwright joins repeated headers with "\n". Only the name is kept.
 */
export function parseSetCookieNames(headerValue) {
  if (typeof headerValue !== 'string') return [];
  const names = [];
  for (const line of headerValue.split('\n')) {
    const eq = line.indexOf('=');
    const name = (eq === -1 ? '' : line.slice(0, eq)).trim();
    if (name) names.push(name);
  }
  return names;
}

/** Whitelists cookie metadata fields. The value field is dropped on purpose. */
export function sanitizeCookie(c) {
  return {
    name: String(c.name ?? ''),
    domain: String(c.domain ?? ''),
    path: String(c.path ?? '/'),
    httpOnly: Boolean(c.httpOnly),
    secure: Boolean(c.secure),
    sameSite: String(c.sameSite ?? 'None'),
    // -1 means a session cookie (Playwright convention).
    expires: typeof c.expires === 'number' ? c.expires : -1,
  };
}

/** Sanitizes then dedupes by name+domain+path (last one wins), sorted for stable output. */
export function dedupeCookies(cookies) {
  const map = new Map();
  for (const raw of cookies || []) {
    const c = sanitizeCookie(raw);
    if (!c.name) continue;
    map.set(`${c.name}|${c.domain}|${c.path}`, c);
  }
  return [...map.values()].sort((a, b) =>
    `${a.domain}|${a.name}|${a.path}`.localeCompare(`${b.domain}|${b.name}|${b.path}`),
  );
}

/** Accumulates observations for one phase, then snapshots them into the report shape. */
export function createPhaseCollector() {
  const pages = new Set();
  const hosts = new Set();
  const setCookies = new Map(); // host -> Set(names)
  const local = new Map(); // origin -> Set(keys)
  const session = new Map();

  const addKeys = (target, origin, keys) => {
    if (!origin || !Array.isArray(keys)) return;
    if (!target.has(origin)) target.set(origin, new Set());
    for (const k of keys) if (typeof k === 'string' && k) target.get(origin).add(k);
  };
  const flatten = (target) =>
    [...target.entries()]
      .flatMap(([origin, keys]) => [...keys].map((key) => ({ origin, key })))
      .sort((a, b) => `${a.origin}|${a.key}`.localeCompare(`${b.origin}|${b.key}`));

  return {
    addPage(url) {
      if (hostFromUrl(url)) pages.add(url);
    },
    addRequest(url) {
      const host = hostFromUrl(url);
      if (host) hosts.add(host);
    },
    addSetCookie(url, headerValue) {
      const host = hostFromUrl(url);
      const names = parseSetCookieNames(headerValue);
      if (!host || !names.length) return;
      if (!setCookies.has(host)) setCookies.set(host, new Set());
      for (const n of names) setCookies.get(host).add(n);
    },
    addStorage(origin, localKeys, sessionKeys) {
      addKeys(local, origin, localKeys);
      addKeys(session, origin, sessionKeys);
    },
    snapshot(cookies) {
      return {
        pages: [...pages],
        cookies: dedupeCookies(cookies),
        storage: { local: flatten(local), session: flatten(session) },
        hosts: [...hosts].sort(),
        set_cookies: [...setCookies.entries()]
          .map(([host, names]) => ({ host, names: [...names].sort() }))
          .sort((a, b) => a.host.localeCompare(b.host)),
      };
    },
  };
}

/** Assembles the final report object (schema 1). Missing phases become empty phases. */
export function buildReport({ site, toolVersion, pluginDetected, bannerFound, phases, errors, now = new Date() }) {
  const empty = () => createPhaseCollector().snapshot([]);
  const out = {};
  for (const name of PHASE_NAMES) out[name] = phases?.[name] ?? empty();
  return {
    schema: REPORT_SCHEMA,
    site,
    scanned_at: now.toISOString(),
    tool_version: toolVersion,
    plugin_detected: Boolean(pluginDetected),
    banner_found: Boolean(bannerFound),
    phases: out,
    errors: (errors || []).map(String),
  };
}
