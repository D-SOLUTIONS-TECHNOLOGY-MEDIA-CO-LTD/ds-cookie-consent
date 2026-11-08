// Merge overlay + (optional) OCD cross-check into the final, deterministic database object.
import { servicesSha256 } from './canonical.mjs';
import { isPattern } from './patterns.mjs';
import { validateDatabase } from './schema.mjs';

const STORAGE_ORDER = ['cookie', 'localStorage', 'sessionStorage', 'indexedDB', 'pixel'];

const cmp = (a, b) => (a < b ? -1 : a > b ? 1 : 0);

export function normalizeCookie(c) {
  return {
    name: c.name,
    is_pattern: isPattern(c.name),
    storage: c.storage || 'cookie',
    duration_vi: c.duration_vi,
    duration_en: c.duration_en,
    duration_seconds: c.duration_seconds === undefined ? null : c.duration_seconds,
    purpose_vi: c.purpose_vi,
    purpose_en: c.purpose_en,
    domain_hint: c.domain_hint || '',
  };
}

export function normalizeService(s) {
  const sp = s.script_patterns || {};
  const uniqSorted = (list) => [...new Set(list || [])].sort(cmp);
  return {
    slug: s.slug,
    name: s.name,
    category: s.category,
    provider_company: s.provider_company,
    privacy_url: s.privacy_url,
    country: s.country,
    is_third_party: s.is_third_party,
    script_patterns: {
      hosts: uniqSorted(sp.hosts),
      src_regex: uniqSorted(sp.src_regex),
      inline_regex: uniqSorted(sp.inline_regex),
    },
    cookies: (s.cookies || [])
      .map(normalizeCookie)
      .sort((a, b) => cmp(a.name, b.name) || STORAGE_ORDER.indexOf(a.storage) - STORAGE_ORDER.indexOf(b.storage)),
  };
}

/** Build the database object from a parsed overlay file. Throws when invalid. */
export function buildDatabase(overlay, { now } = {}) {
  const meta = overlay.meta || {};
  const services = (overlay.services || []).map(normalizeService).sort((a, b) => cmp(a.slug, b.slug));
  const db = {
    version: meta.version,
    generated_at: now || meta.generated_at,
    sha256: servicesSha256(services),
    services,
  };
  const errors = validateDatabase(db);
  if (errors.length) throw new Error(`known-cookies database invalid:\n - ${errors.join('\n - ')}`);
  return db;
}

export function serializeDatabase(db) {
  return `${JSON.stringify(db, null, 2)}\n`;
}
