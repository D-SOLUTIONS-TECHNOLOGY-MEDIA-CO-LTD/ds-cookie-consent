import { test } from 'node:test';
import assert from 'node:assert/strict';
import {
  hostFromUrl, parseSetCookieNames, sanitizeCookie, dedupeCookies,
  createPhaseCollector, buildReport, REPORT_SCHEMA, PHASE_NAMES,
} from '../lib/report-builder.mjs';

test('hostFromUrl accepts http(s) only and lowercases', () => {
  assert.equal(hostFromUrl('https://WWW.Example.com/a?b=1'), 'www.example.com');
  assert.equal(hostFromUrl('data:text/plain,hi'), null);
  assert.equal(hostFromUrl('not a url'), null);
  assert.equal(hostFromUrl('wss://x.test/'), null);
});

test('parseSetCookieNames keeps names only, handles multi-line headers', () => {
  const raw = 'LittleD_a=secret1; Path=/; HttpOnly\nLittleD_b=secret2; Secure\n=bad\nnoequals';
  assert.deepEqual(parseSetCookieNames(raw), ['LittleD_a', 'LittleD_b']);
  assert.deepEqual(parseSetCookieNames(undefined), []);
});

test('sanitizeCookie strips value and unknown fields', () => {
  const c = sanitizeCookie({ name: 'LittleD_x', value: 'TOPSECRET', domain: '.a.test', path: '/', httpOnly: 1, secure: 0, sameSite: 'Lax', expires: 123, extra: 'x' });
  assert.deepEqual(Object.keys(c).sort(), ['domain', 'expires', 'httpOnly', 'name', 'path', 'sameSite', 'secure']);
  assert.equal(c.httpOnly, true);
  assert.equal(c.secure, false);
  assert.ok(!JSON.stringify(c).includes('TOPSECRET'));
});

test('dedupeCookies merges by name+domain+path and drops nameless', () => {
  const out = dedupeCookies([
    { name: 'LittleD_a', value: '1', domain: 'a.test', path: '/', expires: 10 },
    { name: 'LittleD_a', value: '2', domain: 'a.test', path: '/', expires: 20 },
    { name: 'LittleD_a', value: '3', domain: 'b.test', path: '/' },
    { name: '', value: 'x', domain: 'a.test', path: '/' },
  ]);
  assert.equal(out.length, 2);
  assert.equal(out.find((c) => c.domain === 'a.test').expires, 20);
  assert.equal(out.find((c) => c.domain === 'b.test').expires, -1);
});

test('collector gathers pages, hosts, storage keys, set-cookie names', () => {
  const col = createPhaseCollector();
  col.addPage('https://LittleD.test/');
  col.addPage('about:blank');
  col.addRequest('https://LittleD.test/x.js');
  col.addRequest('https://cdn.tracker.test/p.gif');
  col.addRequest('data:image/gif;base64,AAA');
  col.addSetCookie('https://cdn.tracker.test/p.gif', 'LittleD_t=v; Path=/\nLittleD_u=v');
  col.addSetCookie('https://cdn.tracker.test/q', 'LittleD_t=other');
  col.addStorage('https://littled.test', ['k1', 'k2'], ['s1']);
  col.addStorage('https://littled.test', ['k1'], []);
  const snap = col.snapshot([{ name: 'LittleD_c', value: 'zzz', domain: 'littled.test', path: '/' }]);

  assert.deepEqual(snap.pages, ['https://LittleD.test/']);
  assert.deepEqual(snap.hosts, ['cdn.tracker.test', 'littled.test']);
  assert.deepEqual(snap.set_cookies, [{ host: 'cdn.tracker.test', names: ['LittleD_t', 'LittleD_u'] }]);
  assert.deepEqual(snap.storage.local, [
    { origin: 'https://littled.test', key: 'k1' },
    { origin: 'https://littled.test', key: 'k2' },
  ]);
  assert.deepEqual(snap.storage.session, [{ origin: 'https://littled.test', key: 's1' }]);
  assert.equal(snap.cookies.length, 1);
  assert.ok(!JSON.stringify(snap).includes('zzz'));
});

test('buildReport has schema 1 shape and fills missing phases', () => {
  const col = createPhaseCollector();
  col.addPage('https://littled.test/');
  const r = buildReport({
    site: 'https://littled.test', toolVersion: '1.0.0', pluginDetected: 1, bannerFound: 0,
    phases: { before_choice: col.snapshot([]) }, errors: [new Error('boom')],
    now: new Date('2026-11-07T00:00:00Z'),
  });
  assert.equal(r.schema, REPORT_SCHEMA);
  assert.equal(r.schema, 1);
  assert.equal(r.scanned_at, '2026-11-07T00:00:00.000Z');
  assert.equal(r.plugin_detected, true);
  assert.equal(r.banner_found, false);
  assert.deepEqual(Object.keys(r.phases), PHASE_NAMES);
  for (const n of PHASE_NAMES) {
    assert.deepEqual(Object.keys(r.phases[n]).sort(), ['cookies', 'hosts', 'pages', 'set_cookies', 'storage']);
  }
  assert.deepEqual(r.phases.after_accept.pages, []);
  assert.deepEqual(r.errors, ['Error: boom']);
});
