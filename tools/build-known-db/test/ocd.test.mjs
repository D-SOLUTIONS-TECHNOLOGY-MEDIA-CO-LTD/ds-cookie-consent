import test from 'node:test';
import assert from 'node:assert/strict';
import { mkdtemp } from 'node:fs/promises';
import os from 'node:os';
import path from 'node:path';
import { parseCsv } from '../lib/csv.mjs';
import { indexOcd, loadOcdCsv, crossCheckOcd, rawUrl } from '../lib/ocd.mjs';
import { sha256Hex } from '../lib/canonical.mjs';

const HEADER = 'ID,Platform,Category,Cookie / Data Key name,Domain,Description,Retention period,Data Controller,User Privacy & GDPR Rights Portals,Wildcard match';
const CSV = [
  HEADER,
  'id1,LittleD Analytics,Analytics,_ga_,,"ID used, with ""quotes"" and\nnewline",2 years,LittleD,https://example.com,1',
  'id2,LittleD Analytics,Analytics,_ga,,Fixed name,2 years,LittleD,https://example.com,0',
].join('\n');

test('parseCsv: quoted commas, doubled quotes and embedded newlines', () => {
  const rows = parseCsv(CSV);
  assert.equal(rows.length, 3);
  assert.equal(rows[1][5], 'ID used, with "quotes" and\nnewline');
});

test('indexOcd: wildcard rows become patterns, retention parsed', () => {
  const idx = indexOcd(CSV);
  assert.equal(idx.get('_ga_*')[0].seconds, 63072000);
  assert.ok(idx.has('_ga'));
});

test('indexOcd: rejects an unexpected header', () => {
  assert.throws(() => indexOcd('a,b,c\n1,2,3'), /header/);
});

test('crossCheckOcd: reports found, missing and differing retention without changing data', () => {
  const idx = indexOcd(CSV);
  const services = [{ slug: 'littled', cookies: [
    { name: '_ga_*', storage: 'cookie', duration_seconds: 63072000, duration_en: '2 years' },
    { name: '_ga', storage: 'cookie', duration_seconds: 86400, duration_en: '1 day' },
    { name: 'LittleD_only', storage: 'cookie', duration_seconds: null, duration_en: 'Session' },
    { name: 'LittleD_ls', storage: 'localStorage', duration_seconds: null, duration_en: 'x' },
  ] }];
  const r = crossCheckOcd(services, idx);
  assert.deepEqual(r.found, ['littled:_ga_*', 'littled:_ga']);
  assert.deepEqual(r.missing, ['littled:LittleD_only']);
  assert.equal(r.retentionDiffers.length, 1);
});

test('loadOcdCsv: downloads from the pinned commit URL, caches, and enforces the pinned hash', async () => {
  const dir = await mkdtemp(path.join(os.tmpdir(), 'littled-ocd-'));
  const source = { repo: 'LittleD/repo', commit: 'abc123', csv_path: 'x.csv', csv_sha256: sha256Hex(CSV) };
  const urls = [];
  const fakeFetch = async (url) => { urls.push(url); return { ok: true, text: async () => CSV }; };
  const first = await loadOcdCsv(source, dir, fakeFetch);
  assert.equal(first.text, CSV);
  assert.equal(urls[0], rawUrl(source));
  assert.match(urls[0], /\/abc123\//);
  await loadOcdCsv(source, dir, fakeFetch); // served from cache
  assert.equal(urls.length, 1);

  const dir2 = await mkdtemp(path.join(os.tmpdir(), 'littled-ocd-'));
  await assert.rejects(loadOcdCsv({ ...source, csv_sha256: 'f'.repeat(64) }, dir2, fakeFetch), /mismatch/);
  await assert.rejects(loadOcdCsv(source, await mkdtemp(path.join(os.tmpdir(), 'littled-ocd-')), async () => ({ ok: false, status: 503 })), /HTTP 503/);
});
