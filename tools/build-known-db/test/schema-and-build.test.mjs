import test from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { buildDatabase, serializeDatabase } from '../lib/build.mjs';
import { validateDatabase, validateCookie, validateService } from '../lib/schema.mjs';
import { servicesSha256, sha256Hex, canonicalServicesJson } from '../lib/canonical.mjs';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, '..', '..', '..');
const overlay = JSON.parse(await readFile(path.join(here, '..', 'overlay-services.json'), 'utf8'));
const db = buildDatabase(overlay);

const goodCookie = () => ({
  name: 'LittleD_cookie', is_pattern: false, storage: 'cookie', duration_vi: '1 ngày', duration_en: '1 day',
  duration_seconds: 86400, purpose_vi: 'Mục đích thử', purpose_en: 'Test purpose', domain_hint: '',
});
const goodService = () => ({
  slug: 'littled-service', name: 'LittleD Service', category: 'analytics', provider_company: 'LittleD Co',
  privacy_url: 'https://example.com/privacy', country: 'VN', is_third_party: true,
  script_patterns: { hosts: ['example.com'], src_regex: ['example\\.com/x\\.js'], inline_regex: [] },
  cookies: [goodCookie()],
});

test('overlay alone builds a valid database (offline path)', () => {
  assert.deepEqual(validateDatabase(db), []);
});

test('coverage: >= 60 cookies, every one with Vietnamese purpose and duration', () => {
  const cookies = db.services.flatMap((s) => s.cookies);
  assert.ok(cookies.length >= 60, `only ${cookies.length} cookies`);
  for (const c of cookies) {
    assert.ok(c.purpose_vi.trim() && c.duration_vi.trim(), c.name);
  }
});

test('coverage: required services are present with expected categories', () => {
  const bySlug = Object.fromEntries(db.services.map((s) => [s.slug, s]));
  const required = {
    'ds-cookie-consent': 'necessary', 'google-analytics': 'analytics', 'google-ads': 'marketing', 'google-floodlight': 'marketing',
    'google-tag-manager': 'functional', youtube: 'functional', 'google-maps': 'functional', 'google-recaptcha': 'necessary',
    cloudflare: 'necessary', 'cloudflare-turnstile': 'necessary', 'meta-pixel': 'marketing', 'tiktok-pixel': 'marketing',
    'microsoft-clarity': 'analytics', 'microsoft-uet': 'marketing', 'yandex-metrica': 'analytics', hotjar: 'analytics',
    'linkedin-insight': 'marketing', zalo: 'marketing', 'pancake-chat': 'functional', intercom: 'analytics',
    ladipage: 'analytics', hubspot: 'marketing', 'openai-ads-pixel': 'marketing', woocommerce: 'necessary',
    wpml: 'necessary', polylang: 'necessary', elementor: 'functional', 'jet-popup': 'functional',
    'contact-form-7': 'functional', 'google-site-kit': 'functional',
  };
  for (const [slug, category] of Object.entries(required)) {
    assert.ok(bySlug[slug], `missing service ${slug}`);
    assert.equal(bySlug[slug].category, category, slug);
  }
  const names = new Set(db.services.flatMap((s) => s.cookies.map((c) => c.name)));
  for (const n of ['dscc_consent', '_ga_*', '__cf_bm', 'cf_clearance', '_ttp', '_tt_enable_cookie', 'ttcsid*', 'intercom-id-*',
    'intercom-device-id-*', 'intercom-session-*', '_ladipage_name', '_ladipage_email', '_ladipage_phone', '__obref',
    'wp-wpml_current_language', 'pll_language']) {
    assert.ok(names.has(n), `missing cookie ${n}`);
  }
});

test('LadiPage personal-data cookies are flagged in the purpose', () => {
  const ladi = db.services.find((s) => s.slug === 'ladipage');
  for (const n of ['_ladipage_name', '_ladipage_email', '_ladipage_phone']) {
    const c = ladi.cookies.find((x) => x.name === n);
    assert.match(c.purpose_vi, /DỮ LIỆU CÁ NHÂN/);
    assert.match(c.purpose_en, /PERSONAL DATA/);
  }
});

test('determinism: repeated and reordered builds are byte-identical', () => {
  const a = serializeDatabase(buildDatabase(overlay));
  const b = serializeDatabase(buildDatabase(overlay));
  const shuffled = structuredClone(overlay);
  shuffled.services.reverse();
  shuffled.services.forEach((s) => s.cookies.reverse());
  const c = serializeDatabase(buildDatabase(shuffled));
  assert.equal(a, b);
  assert.equal(a, c);
});

test('sha256 field covers the canonical services payload', () => {
  assert.equal(db.sha256, sha256Hex(canonicalServicesJson(db.services)));
  const tampered = structuredClone(db.services);
  tampered[0].cookies[0].purpose_vi += ' x';
  assert.notEqual(servicesSha256(tampered), db.sha256);
});

test('committed data files are up to date with the overlay and the sidecar matches', async () => {
  const file = await readFile(path.join(root, 'data', 'known-cookies.json'), 'utf8');
  const sidecar = await readFile(path.join(root, 'data', 'known-cookies.json.sha256'), 'utf8');
  assert.equal(file, serializeDatabase(db), 'run `npm run build` in tools/build-known-db');
  assert.equal(sidecar, `${sha256Hex(file)}  known-cookies.json\n`);
  assert.equal(JSON.parse(file).sha256, db.sha256);
});

test('forbidden word never appears in the output', () => {
  assert.doesNotMatch(serializeDatabase(db), new RegExp(['cla', 'ude'].join('')), 'i');
});

test('schema rejects invalid data', () => {
  assert.deepEqual(validateService(goodService(), 's'), []);
  assert.deepEqual(validateCookie(goodCookie(), 'c'), []);
  assert.match(validateService({ ...goodService(), category: 'unclassified' }, 's').join(), /category/);
  assert.match(validateService({ ...goodService(), slug: 'Bad Slug' }, 's').join(), /slug/);
  assert.match(validateService({ ...goodService(), privacy_url: 'http://x.test' }, 's').join(), /https/);
  assert.match(validateCookie({ ...goodCookie(), name: '_ga_*' }, 'c').join(), /is_pattern/);
  assert.match(validateCookie({ ...goodCookie(), storage: 'flash' }, 'c').join(), /storage/);
  assert.match(validateCookie({ ...goodCookie(), duration_seconds: -1 }, 'c').join(), /duration_seconds/);
  assert.match(validateCookie({ ...goodCookie(), purpose_vi: '' }, 'c').join(), /purpose_vi/);
  const badRegex = goodService();
  badRegex.script_patterns.src_regex = ['(unclosed'];
  assert.match(validateService(badRegex, 's').join(), /invalid regex/);
  const tilde = goodService();
  tilde.script_patterns.inline_regex = ['a~b'];
  assert.match(validateService(tilde, 's').join(), /delimiter/);
});

test('schema rejects duplicate slugs and duplicate cookie names across services', () => {
  const a = goodService();
  const b = { ...goodService(), slug: 'littled-other' };
  const base = { version: '1', generated_at: '2026-11-07T00:00:00Z', services: [a, b] };
  assert.match(validateDatabase(base).join(), /duplicate cookie/);
  assert.match(validateDatabase({ ...base, services: [a, a] }).join(), /duplicate slug/);
  assert.match(validateDatabase({ ...base, services: [] }).join(), /non-empty/);
});

test('buildDatabase throws on an invalid overlay', () => {
  const bad = structuredClone(overlay);
  bad.services[0].category = 'nope';
  assert.throws(() => buildDatabase(bad), /invalid/);
});

test('NOTICE records the pinned Open Cookie Database commit and the modification statement', async () => {
  const source = JSON.parse(await readFile(path.join(here, '..', 'ocd-source.json'), 'utf8'));
  const notice = await readFile(path.join(root, 'data', 'NOTICE'), 'utf8');
  assert.match(source.commit, /^[0-9a-f]{40}$/);
  assert.ok(notice.includes(source.commit));
  assert.match(notice, /modified work/);
  const license = await readFile(path.join(root, 'data', 'LICENSE-open-cookie-database.txt'), 'utf8');
  assert.match(license, /Apache License\s+Version 2\.0/);
});
