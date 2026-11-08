#!/usr/bin/env node
// Build data/known-cookies.json (+ .sha256 sidecar) from overlay-services.json.
// Usage: node build-known-db.mjs [--offline] [--check] [--out <dir>] [--overlay <file>] [--now <iso>]
import { readFile, writeFile, mkdir } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { buildDatabase, serializeDatabase } from './lib/build.mjs';
import { sha256Hex } from './lib/canonical.mjs';
import { loadOcdCsv, indexOcd, crossCheckOcd } from './lib/ocd.mjs';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, '..', '..');

function parseArgs(argv) {
  const args = { offline: false, check: false, out: path.join(root, 'data'), overlay: path.join(here, 'overlay-services.json'), now: undefined };
  for (let i = 0; i < argv.length; i++) {
    const a = argv[i];
    if (a === '--offline') args.offline = true;
    else if (a === '--check') args.check = true;
    else if (a === '--out') args.out = path.resolve(argv[++i]);
    else if (a === '--overlay') args.overlay = path.resolve(argv[++i]);
    else if (a === '--now') args.now = argv[++i];
    else throw new Error(`unknown argument: ${a}`);
  }
  return args;
}

async function main() {
  const args = parseArgs(process.argv.slice(2));
  const overlay = JSON.parse(await readFile(args.overlay, 'utf8'));
  const db = buildDatabase(overlay, { now: args.now });
  const json = serializeDatabase(db);
  const sidecar = `${sha256Hex(json)}  known-cookies.json\n`;

  // Informational cross-check against the pinned Open Cookie Database; never fatal.
  if (!args.offline) {
    try {
      const source = JSON.parse(await readFile(path.join(here, 'ocd-source.json'), 'utf8'));
      const { text } = await loadOcdCsv(source, path.join(here, '.cache'));
      const report = crossCheckOcd(db.services, indexOcd(text));
      console.log(`OCD @${source.commit.slice(0, 7)}: ${report.found.length} cookies found, ${report.missing.length} not in OCD (overlay-only)`);
      if (report.retentionDiffers.length) console.log(`  retention differs from OCD (overlay wins):\n   - ${report.retentionDiffers.join('\n   - ')}`);
    } catch (e) {
      console.warn(`OCD cross-check skipped (${e.message}); overlay alone is authoritative`);
    }
  }

  const target = path.join(args.out, 'known-cookies.json');
  if (args.check) {
    const current = await readFile(target, 'utf8').catch(() => '');
    const currentSidecar = await readFile(`${target}.sha256`, 'utf8').catch(() => '');
    if (current !== json || currentSidecar !== sidecar) {
      console.error('data/known-cookies.json is out of date; run the build');
      process.exit(1);
    }
    console.log('known-cookies.json is up to date');
    return;
  }
  await mkdir(args.out, { recursive: true });
  await writeFile(target, json);
  await writeFile(`${target}.sha256`, sidecar);
  const cookieCount = db.services.reduce((n, s) => n + s.cookies.length, 0);
  console.log(`wrote ${target}: ${db.services.length} services, ${cookieCount} cookies, sha256 ${db.sha256.slice(0, 12)}…`);
}

main().catch((e) => { console.error(e.message); process.exit(1); });
