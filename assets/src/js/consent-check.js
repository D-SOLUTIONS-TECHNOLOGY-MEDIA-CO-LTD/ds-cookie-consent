/**
 * Does a consent record allow something of this category (and service)?
 * Same rule as ConsentReader::allows() on the server:
 *  - "necessary" is always allowed; no record means nothing optional is allowed;
 *  - a category value with spaces ("analytics marketing") is satisfied by any part;
 *  - a service without its own entry follows its category; a service with an entry needs both.
 *
 * @param {Object|null} rec  consent record {c, s} or null
 * @param {string} category  category key(s)
 * @param {string} [slug]    service slug
 * @returns {boolean}
 */
export function isAllowed(rec, category, slug) {
  const parts = String(category || '')
    .split(/\s+/)
    .filter(Boolean);
  if (parts.indexOf('necessary') !== -1) return true;
  if (!rec || !rec.c) return false;
  const s = rec.s || {};
  return parts.some((p) => !!rec.c[p] && (!slug || !(slug in s) || !!s[slug]));
}
