#!/usr/bin/env node
// Generate presets/d-solutions-standard.json. Usage: node build-preset.mjs [--check]
import { readFile, writeFile, mkdir } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { buildPreset } from './lib/preset.mjs';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, '..', '..');
const check = process.argv.includes('--check');

const definition = JSON.parse(await readFile(path.join(here, 'preset-definition.json'), 'utf8'));
const knownDb = JSON.parse(await readFile(path.join(root, 'data', 'known-cookies.json'), 'utf8'));
const json = `${JSON.stringify(buildPreset(definition, knownDb), null, 2)}\n`;
const target = path.join(root, 'presets', 'd-solutions-standard.json');

if (check) {
  const current = await readFile(target, 'utf8').catch(() => '');
  if (current !== json) { console.error('presets/d-solutions-standard.json is out of date; run build-preset.mjs'); process.exit(1); }
  console.log('presets/d-solutions-standard.json is up to date');
} else {
  await mkdir(path.dirname(target), { recursive: true });
  await writeFile(target, json);
  console.log(`wrote ${target}`);
}
