// Cookie-name pattern helpers shared by the build and its tests.
// Convention: a name containing "*" is a glob pattern ("*" = any run of characters).

export function isPattern(name) {
  return name.includes('*');
}

/**
 * Convert an Open Cookie Database name into our pattern notation.
 * OCD marks wildcard rows with "Wildcard match = 1" and stores only the fixed
 * prefix (e.g. "_ga_" for "_ga_<id>"), so the pattern is prefix + "*".
 */
export function fromOcdName(name, wildcardFlag) {
  const n = name.trim();
  if (String(wildcardFlag).trim() === '1' && !n.endsWith('*')) return `${n}*`;
  return n;
}

/** Glob match for a concrete cookie name against a stored name/pattern. */
export function matchesName(pattern, candidate) {
  if (!isPattern(pattern)) return pattern === candidate;
  const source = pattern
    .split('*')
    .map((part) => part.replace(/[.*+?^${}()|[\]\\]/g, '\\$&'))
    .join('.*');
  return new RegExp(`^${source}$`).test(candidate);
}

/**
 * Name shown to visitors. A trailing "*" that follows "_" or "-" is shown as
 * "<mã>" (e.g. "_ga_*" -> "_ga_<mã>", "_gat_UA-*" -> "_gat_UA-<mã>"); other
 * patterns keep the literal "*" (e.g. "ttcsid*", "_sp_id.*").
 */
export function displayName(name) {
  return name.replace(/([_-])\*$/, '$1<mã>');
}

const UNIT_SECONDS = {
  second: 1, minute: 60, hour: 3600, day: 86400, week: 604800, month: 2592000, year: 31536000,
};

/** Parse an English retention label ("2 years", "30 minutes") to seconds; null for session/unknown. */
export function parseRetentionSeconds(label) {
  const m = /^\s*(\d+)\s*(second|minute|hour|day|week|month|year)s?\b/i.exec(label || '');
  if (!m) return null;
  return Number(m[1]) * UNIT_SECONDS[m[2].toLowerCase()];
}
