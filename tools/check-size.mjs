// Size budget: JS < 20 KB gzip, each layout CSS < 8 KB gzip, head inline script < 1.5 KB.
import { readFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { gzipSync } from 'node:zlib';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const budgets = [
  ['assets/dist/dscc.js', 20 * 1024],
  ['assets/dist/dscc-bar.css', 8 * 1024],
  ['assets/dist/dscc-card.css', 8 * 1024],
];

let failed = false;
for (const [file, limit] of budgets) {
  const gz = gzipSync(readFileSync(resolve(root, file))).length;
  const ok = gz < limit;
  failed = failed || !ok;
  console.log(`${ok ? 'OK  ' : 'FAIL'} ${file}: ${gz} B gzip (limit ${limit})`);
}

// Head script: measured by the PHP builder itself (typical and worst-case configs).
const probe = spawnSync('php', [resolve(root, 'tools/head-size-probe.php')], { encoding: 'utf8' });
if (probe.status !== 0) {
  failed = true;
  console.log(`FAIL head script probe could not run: ${probe.stderr || probe.error}`);
} else {
  for (const line of probe.stdout.trim().split('\n')) {
    const [label, bytes] = line.split('=');
    const ok = Number(bytes) <= 1500;
    failed = failed || !ok;
    console.log(`${ok ? 'OK  ' : 'FAIL'} head script (${label}): ${bytes} B (limit 1500)`);
  }
}
process.exit(failed ? 1 : 0);
