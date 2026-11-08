// Validation for the known-cookie database (build input and output).

export const CATEGORIES = ['necessary', 'functional', 'analytics', 'marketing'];
export const STORAGES = ['cookie', 'localStorage', 'sessionStorage', 'indexedDB', 'pixel'];
const SLUG_RE = /^[a-z0-9]+(-[a-z0-9]+)*$/;

function isStr(v) { return typeof v === 'string'; }
function isStrArray(v) { return Array.isArray(v) && v.every(isStr); }

function checkRegexList(list, where, errors) {
  list.forEach((src, i) => {
    if (src.includes('~')) errors.push(`${where}[${i}]: "~" is reserved as the PHP regex delimiter`);
    try { new RegExp(src, 'i'); } catch (e) { errors.push(`${where}[${i}]: invalid regex (${e.message})`); }
  });
}

export function validateCookie(c, where) {
  const errors = [];
  if (!c || typeof c !== 'object') return [`${where}: not an object`];
  if (!isStr(c.name) || c.name === '') errors.push(`${where}: name required`);
  if (typeof c.is_pattern !== 'boolean') errors.push(`${where}: is_pattern must be boolean`);
  else if (isStr(c.name) && c.is_pattern !== c.name.includes('*')) errors.push(`${where}: is_pattern must equal (name contains "*")`);
  if (!STORAGES.includes(c.storage)) errors.push(`${where}: storage must be one of ${STORAGES.join('|')}`);
  for (const k of ['duration_vi', 'duration_en', 'purpose_vi', 'purpose_en']) {
    if (!isStr(c[k]) || c[k].trim() === '') errors.push(`${where}: ${k} required`);
  }
  if (!(c.duration_seconds === null || (Number.isInteger(c.duration_seconds) && c.duration_seconds >= 0))) {
    errors.push(`${where}: duration_seconds must be a non-negative integer or null`);
  }
  if (!isStr(c.domain_hint)) errors.push(`${where}: domain_hint must be a string (may be empty)`);
  for (const k of ['table_group', 'applies_to', 'applies_to_en']) {
    if (c[k] !== undefined && !isStr(c[k])) errors.push(`${where}: ${k} must be a string`);
  }
  const extra = Object.keys(c).filter((k) => !['name', 'is_pattern', 'storage', 'duration_vi', 'duration_en', 'duration_seconds', 'purpose_vi', 'purpose_en', 'domain_hint', 'table_group', 'applies_to', 'applies_to_en'].includes(k));
  if (extra.length) errors.push(`${where}: unknown fields ${extra.join(',')}`);
  return errors;
}

export function validateService(s, where) {
  const errors = [];
  if (!s || typeof s !== 'object') return [`${where}: not an object`];
  if (!isStr(s.slug) || !SLUG_RE.test(s.slug)) errors.push(`${where}: invalid slug`);
  if (!isStr(s.name) || s.name === '') errors.push(`${where}: name required`);
  if (!CATEGORIES.includes(s.category)) errors.push(`${where}: category must be one of ${CATEGORIES.join('|')}`);
  if (!isStr(s.provider_company) || s.provider_company === '') errors.push(`${where}: provider_company required`);
  if (!isStr(s.privacy_url) || !/^https:\/\/\S+$/.test(s.privacy_url)) errors.push(`${where}: privacy_url must be an https URL`);
  if (!isStr(s.country) || !/^[A-Z]{2}$/.test(s.country)) errors.push(`${where}: country must be ISO 3166-1 alpha-2`);
  if (typeof s.is_third_party !== 'boolean') errors.push(`${where}: is_third_party must be boolean`);
  const sp = s.script_patterns;
  if (!sp || typeof sp !== 'object') {
    errors.push(`${where}: script_patterns required`);
  } else {
    for (const k of ['hosts', 'src_regex', 'inline_regex']) {
      if (!isStrArray(sp[k])) errors.push(`${where}.script_patterns.${k} must be string[]`);
    }
    if (isStrArray(sp.hosts)) sp.hosts.forEach((h, i) => { if (!/^[a-z0-9.-]+$/.test(h)) errors.push(`${where}.script_patterns.hosts[${i}]: bare lowercase hostname expected`); });
    if (isStrArray(sp.src_regex)) checkRegexList(sp.src_regex, `${where}.script_patterns.src_regex`, errors);
    if (isStrArray(sp.inline_regex)) checkRegexList(sp.inline_regex, `${where}.script_patterns.inline_regex`, errors);
  }
  if (!Array.isArray(s.cookies)) errors.push(`${where}: cookies must be an array`);
  else s.cookies.forEach((c, i) => errors.push(...validateCookie(c, `${where}.cookies[${i}](${c && c.name})`)));
  return errors;
}

/** Validate a full database object; returns an array of error strings (empty = valid). */
export function validateDatabase(db) {
  const errors = [];
  if (!db || typeof db !== 'object') return ['database: not an object'];
  if (!isStr(db.version) || db.version === '') errors.push('version required');
  if (!isStr(db.generated_at) || Number.isNaN(Date.parse(db.generated_at))) errors.push('generated_at must be an ISO date');
  if (db.sha256 !== undefined && !(isStr(db.sha256) && /^[0-9a-f]{64}$/.test(db.sha256))) errors.push('sha256 must be 64 hex chars');
  if (!Array.isArray(db.services) || db.services.length === 0) return [...errors, 'services must be a non-empty array'];
  const slugs = new Set();
  const names = new Set();
  db.services.forEach((s, i) => {
    errors.push(...validateService(s, `services[${i}](${s && s.slug})`));
    if (s && slugs.has(s.slug)) errors.push(`duplicate slug ${s.slug}`);
    if (s) slugs.add(s.slug);
    if (s && Array.isArray(s.cookies)) {
      for (const c of s.cookies) {
        const key = `${c.storage}:${c.name}`;
        if (names.has(key)) errors.push(`duplicate cookie ${key} (names must be unique across services per storage)`);
        names.add(key);
      }
    }
  });
  return errors;
}
