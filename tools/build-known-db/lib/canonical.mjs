import { createHash } from 'node:crypto';

/** Recursively sort object keys (arrays keep their order). */
export function sortKeysDeep(value) {
  if (Array.isArray(value)) return value.map(sortKeysDeep);
  if (value && typeof value === 'object') {
    return Object.fromEntries(Object.keys(value).sort().map((k) => [k, sortKeysDeep(value[k])]));
  }
  return value;
}

/**
 * Canonical payload that the "sha256" field covers: the services array, keys
 * sorted recursively, compact JSON, unescaped unicode and slashes. PHP can
 * reproduce it with a recursive ksort + json_encode(JSON_UNESCAPED_UNICODE |
 * JSON_UNESCAPED_SLASHES).
 */
export function canonicalServicesJson(services) {
  return JSON.stringify(sortKeysDeep(services));
}

export function sha256Hex(input) {
  return createHash('sha256').update(input).digest('hex');
}

export function servicesSha256(services) {
  return sha256Hex(canonicalServicesJson(services));
}
