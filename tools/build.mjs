// Bundles front-end and admin assets with esbuild into assets/dist (committed).
import { build, context } from 'esbuild';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const root = resolve(dirname(fileURLToPath(import.meta.url)), '..');
const watch = process.argv.includes('--watch');

const common = {
  absWorkingDir: root,
  bundle: true,
  minify: true,
  legalComments: 'none',
  logLevel: 'info',
  outdir: 'assets/dist',
};

const jobs = [
  // Front end: one JS bundle, one CSS file per layout (each composed of base + modal + layout parts).
  { ...common, entryPoints: { dscc: 'assets/src/js/main.js' }, format: 'iife', target: 'es2017' },
  {
    ...common,
    entryPoints: { 'dscc-bar': 'assets/src/css/entry-bar.css', 'dscc-card': 'assets/src/css/entry-card.css' },
    target: ['chrome80', 'firefox78', 'safari13'],
  },
  // Admin
  { ...common, entryPoints: { 'dscc-admin': 'assets/src/js/admin/settings.js' }, format: 'iife', target: 'es2017' },
  { ...common, entryPoints: { 'dscc-admin': 'assets/src/css/admin.css' } },
];

if (watch) {
  for (const job of jobs) {
    const ctx = await context(job);
    await ctx.watch();
  }
} else {
  await Promise.all(jobs.map((job) => build(job)));
}
