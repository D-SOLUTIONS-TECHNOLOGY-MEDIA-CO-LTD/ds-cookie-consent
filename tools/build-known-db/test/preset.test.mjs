import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { buildPreset } from '../lib/preset.mjs';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, '..', '..', '..');
const read = async (p) => JSON.parse(await readFile(path.join(root, p), 'utf8'));
const PRESET_FILE = 'presets/d-solutions-standard.json';
const known = await read('data/known-cookies.json');
const definition = await read('tools/build-known-db/preset-definition.json');
const preset = await read(PRESET_FILE);

const EXPECTED_SLUGS = [
  'ds-cookie-consent', 'wordpress-core', 'woocommerce', 'google-recaptcha', 'google-tag-manager', 'google-analytics', 'microsoft-clarity',
  'woocommerce-order-attribution', 'google-ads', 'meta-pixel', 'tiktok-pixel', 'youtube', 'zalo',
];

test('committed preset equals a fresh build', async () => {
  assert.deepEqual(preset, buildPreset(definition, known));
  const file = await readFile(path.join(root, PRESET_FILE), 'utf8');
  assert.equal(file, `${JSON.stringify(buildPreset(definition, known), null, 2)}\n`);
});

test('export envelope: named in both languages, no setting is changed', () => {
  assert.equal(preset.format, 'dscc-export');
  assert.equal(preset.version, 1);
  assert.equal(preset.preset.label, 'D-Solutions — Mẫu tiêu chuẩn');
  assert.equal(preset.preset.label_en, 'D-Solutions — Standard');
  assert.ok(preset.preset.description && preset.preset.description_en);
  assert.deepEqual(preset.texts, { vi: {}, en: {} });
  assert.deepEqual(preset.settings, {});
});

test('services are the generic D-Solutions platform set, every slug is in known-cookies', () => {
  assert.deepEqual(preset.services.map((s) => s.slug), EXPECTED_SLUGS);
  const knownSlugs = new Set(known.services.map((s) => s.slug));
  for (const s of preset.services) {
    assert.ok(knownSlugs.has(s.slug), s.slug);
    assert.equal(s.status, 'confirmed', s.slug);
    assert.equal(s.source, 'known_db', s.slug);
  }
  const slugs = new Set(preset.services.map((s) => s.slug));
  for (const c of preset.cookies) assert.ok(slugs.has(c.service_slug), c.name);
});

test('every preset cookie is the known-db record of its service, confirmed', () => {
  const bySlug = new Map(known.services.map((s) => [s.slug, s]));
  for (const c of preset.cookies) {
    const record = bySlug.get(c.service_slug).cookies.find((k) => k.name === c.name);
    assert.ok(record, `${c.service_slug}/${c.name}`);
    assert.equal(c.status, 'confirmed', c.name);
    assert.equal(c.duration_label, record.duration_vi, c.name);
    assert.equal(c.purpose, record.purpose_vi, c.name);
    assert.equal(c.domain, record.domain_hint || '', c.name);
  }
});

test('categories: necessary, functional, analytics and marketing hold the expected cookies', () => {
  const cat = (c) => preset.services.find((s) => s.slug === c.service_slug).category_key;
  const row = (name) => preset.cookies.find((c) => c.name === name);
  const expect = [
    ['dscc_consent', 'necessary', '180 ngày'], ['wordpress_test_cookie', 'necessary', 'Phiên'], ['woocommerce_cart_hash', 'necessary', 'Phiên'], ['_GRECAPTCHA', 'necessary', '179 ngày'],
    ['_ga', 'analytics', '2 năm'], ['_ga_*', 'analytics', '2 năm'], ['_gid', 'analytics', '24 giờ'], ['_gat_UA-*', 'analytics', '1 phút'],
    ['_clck', 'analytics', '1 năm'], ['_clsk', 'analytics', '1 ngày'], ['sbjs_*', 'analytics', 'Phiên'],
    ['_gcl_au', 'marketing', '90 ngày'], ['_fbp', 'marketing', '90 ngày'], ['_ttp', 'marketing', 'Khoảng 13 tháng'], ['YSC', 'functional', 'Phiên'],
  ];
  for (const [name, category, duration] of expect) {
    const c = row(name);
    assert.ok(c, name);
    assert.equal(cat(c), category, name);
    assert.equal(c.duration_label, duration, name);
  }
  const tagManager = preset.services.find((s) => s.slug === 'google-tag-manager');
  assert.equal(tagManager.category_key, 'functional');
  assert.ok(!preset.cookies.some((c) => c.service_slug === 'google-tag-manager' || c.service_slug === 'zalo'), 'GTM and Zalo set no cookie of their own');
});

test('nothing company-specific: no setting, no skip user agent, no chat selector', () => {
  const text = JSON.stringify(preset);
  assert.ok(!/FABiApp|pancake/i.test(text));
  assert.ok(!new RegExp(`(^|[^a-z])${['ip', 'os'].join('')}`, 'i').test(text));
  assert.ok(!new RegExp(['cla', 'ude'].join(''), 'i').test(text));
});

test('cookie rows use only data-model field names', () => {
  const fields = ['service_slug', 'name', 'name_is_pattern', 'storage', 'domain', 'path', 'duration_label', 'duration_label_en', 'duration_seconds', 'purpose', 'purpose_en', 'http_only', 'set_by', 'status', 'source', 'table_group', 'applies_to', 'applies_to_en'];
  for (const c of preset.cookies) assert.deepEqual(Object.keys(c), fields);
});

test('buildPreset rejects unknown slugs and cookies', () => {
  assert.throws(() => buildPreset({ ...definition, services: [{ slug: 'littled-missing', status: 'auto', cookies: [] }] }, known), /not in known-cookies/);
  assert.throws(() => buildPreset({ ...definition, services: [{ slug: 'wpml', status: 'auto', cookies: [{ name: 'LittleD_nope', status: 'new' }] }] }, known), /not found/);
});

test('every table_group member sits next to its lead in the cookie list', () => {
  const seen = new Set();
  let prev = null;
  for (const c of preset.cookies) {
    if (c.table_group) {
      const k = `${c.service_slug}|${c.table_group}`;
      if (k !== prev) assert.ok(!seen.has(k), `group ${k} is split`);
      seen.add(k);
      prev = k;
    } else prev = null;
  }
});
