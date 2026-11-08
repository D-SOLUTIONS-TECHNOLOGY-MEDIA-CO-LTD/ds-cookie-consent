// Open Cookie Database access: pinned download (with local cache) and lookup index.
import { mkdir, readFile, writeFile } from 'node:fs/promises';
import path from 'node:path';
import { parseCsv } from './csv.mjs';
import { fromOcdName, parseRetentionSeconds } from './patterns.mjs';
import { sha256Hex } from './canonical.mjs';

export function rawUrl(source) {
  return `https://raw.githubusercontent.com/${source.repo}/${source.commit}/${source.csv_path}`;
}

/** Load the pinned CSV text from cache or network. Throws on failure or hash mismatch. */
export async function loadOcdCsv(source, cacheDir, fetchImpl = fetch) {
  const cacheFile = path.join(cacheDir, `ocd-${source.commit}.csv`);
  let text;
  try {
    text = await readFile(cacheFile, 'utf8');
  } catch {
    const res = await fetchImpl(rawUrl(source), { signal: AbortSignal.timeout(60_000) });
    if (!res.ok) throw new Error(`download failed: HTTP ${res.status}`);
    text = await res.text();
  }
  const digest = sha256Hex(text);
  if (source.csv_sha256 && source.csv_sha256 !== digest) {
    throw new Error(`OCD CSV sha256 mismatch for pinned commit (expected ${source.csv_sha256}, got ${digest})`);
  }
  await mkdir(cacheDir, { recursive: true });
  await writeFile(cacheFile, text);
  return { text, digest };
}

/** Index OCD rows by cookie name (pattern notation). Columns per OCD README. */
export function indexOcd(csvText) {
  const rows = parseCsv(csvText);
  const header = rows[0];
  const col = (name) => header.indexOf(name);
  const iPlatform = col('Platform');
  const iName = col('Cookie / Data Key name');
  const iRetention = col('Retention period');
  const iWildcard = col('Wildcard match');
  if ([iPlatform, iName, iRetention, iWildcard].includes(-1)) throw new Error('unexpected OCD CSV header');
  const byName = new Map();
  for (const r of rows.slice(1)) {
    const name = fromOcdName(r[iName] || '', r[iWildcard]);
    if (!name) continue;
    const entry = { platform: r[iPlatform], name, retention: r[iRetention], seconds: parseRetentionSeconds(r[iRetention]) };
    if (!byName.has(name)) byName.set(name, []);
    byName.get(name).push(entry);
  }
  return byName;
}

/**
 * Informational cross-check of overlay cookies against OCD. The overlay stays
 * authoritative; nothing from OCD is copied into the output here.
 */
export function crossCheckOcd(services, index) {
  const found = [];
  const missing = [];
  const retentionDiffers = [];
  for (const s of services) {
    for (const c of s.cookies) {
      if (c.storage !== 'cookie') continue;
      const hits = index.get(c.name);
      if (!hits) { missing.push(`${s.slug}:${c.name}`); continue; }
      found.push(`${s.slug}:${c.name}`);
      if (c.duration_seconds !== null && hits.every((h) => h.seconds !== null && h.seconds !== c.duration_seconds)) {
        retentionDiffers.push(`${s.slug}:${c.name} overlay=${c.duration_en} ocd=${hits[0].retention}`);
      }
    }
  }
  return { found, missing, retentionDiffers };
}
