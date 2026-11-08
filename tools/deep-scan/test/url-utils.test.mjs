import { test } from 'node:test';
import assert from 'node:assert/strict';
import { normalizeSiteUrl, resolveUrls, pickInternalLinks } from '../lib/url-utils.mjs';
import { parseCli } from '../lib/cli-args.mjs';
import { pushReport, checkPushTarget, hintFor } from '../lib/push-report.mjs';

test('normalizeSiteUrl', () => {
  assert.equal(normalizeSiteUrl('littled.test/'), 'https://littled.test');
  assert.equal(normalizeSiteUrl('http://littled.test/sub/?x=1#h'), 'http://littled.test/sub');
  assert.throws(() => normalizeSiteUrl('ftp://x.test'));
});

test('resolveUrls handles relative and absolute', () => {
  assert.deepEqual(resolveUrls(['/a', 'https://x.test/b', ' '], 'https://littled.test'), [
    'https://littled.test/a', 'https://x.test/b',
  ]);
});

test('pickInternalLinks keeps same-host pages, skips assets/admin/dupes', () => {
  const links = pickInternalLinks([
    'https://littled.test/', 'https://other.test/x', 'https://littled.test/a.pdf',
    'https://littled.test/wp-admin/', 'https://littled.test/about#team',
    'https://littled.test/about', '/contact', 'https://littled.test/third',
  ], 'https://littled.test/', 2);
  assert.deepEqual(links, ['https://littled.test/about', 'https://littled.test/contact']);
});

test('parseCli defaults, env credentials, validation', () => {
  const o = parseCli(['littled.test', '--push'], { DSCC_USER: 'LittleD', DSCC_APP_PASSWORD: 'p' });
  assert.equal(o.rejectSelector, '[data-dscc-action="reject"]');
  assert.equal(o.acceptSelector, '[data-dscc-action="accept"]');
  assert.equal(o.pages, 3);
  assert.equal(o.wait, 8000);
  assert.equal(o.user, 'LittleD');
  assert.throws(() => parseCli(['littled.test', '--push'], {}));
  assert.throws(() => parseCli([], {}));
  assert.throws(() => parseCli(['x.test', '--wait', 'abc'], {}));
  assert.deepEqual(parseCli(['x.test', '--urls', 'a, b'], {}).urls, ['a', 'b']);
});

test('pushReport sends basic auth JSON and reports status', async () => {
  let seen;
  const fetchImpl = async (url, init) => {
    seen = { url, init };
    return { ok: true, status: 200, text: async () => '{"ok":true}' };
  };
  const res = await pushReport({ site: 'https://littled.test', report: { schema: 1 }, user: 'LittleD', appPassword: 'ab cd', fetchImpl });
  assert.equal(seen.url, 'https://littled.test/wp-json/dscc/v1/scan-result');
  assert.equal(seen.init.headers.Authorization, `Basic ${Buffer.from('LittleD:ab cd').toString('base64')}`);
  assert.equal(seen.init.body, '{"schema":1}');
  assert.deepEqual([res.ok, res.status], [true, 200]);

  const bad = await pushReport({ site: 'https://x.test', report: {}, user: 'u', appPassword: 'p', fetchImpl: async () => { throw new Error('down'); } });
  assert.deepEqual([bad.ok, bad.status, bad.body], [false, 0, 'down']);
});

test('pushReport retries a 404 through ?rest_route= for plain permalinks, and only then', async () => {
  const urls = [];
  const fetchImpl = async (url) => {
    urls.push(url);
    return url.includes('rest_route')
      ? { ok: true, status: 200, text: async () => '{"ok":true}' }
      : { ok: false, status: 404, text: async () => '<html>' };
  };
  const res = await pushReport({ site: 'https://littled.test', report: {}, user: 'u', appPassword: 'p', fetchImpl });
  assert.deepEqual(urls, ['https://littled.test/wp-json/dscc/v1/scan-result', 'https://littled.test/?rest_route=/dscc/v1/scan-result']);
  assert.equal(res.status, 200);

  urls.length = 0;
  const denied = await pushReport({ site: 'https://littled.test', report: {}, user: 'u', appPassword: 'p', fetchImpl: async (u) => (urls.push(u), { ok: false, status: 401, text: async () => '{}' }) });
  assert.equal(urls.length, 1);
  assert.equal(denied.status, 401);
});

test('the --app-password flag is gone; the password comes from env or a file', () => {
  assert.throws(() => parseCli(['x.test', '--push', '--user', 'u', '--app-password', 'secret'], {}), /removed.*DSCC_APP_PASSWORD.*--app-password-file/s);
  const reads = [];
  const readFile = (path) => (reads.push(path), 'ab cd ef\n');
  const o = parseCli(['x.test', '--push', '--user', 'u', '--app-password-file', '/run/LittleD-secret'], {}, { readFile });
  assert.equal(o.appPassword, 'ab cd ef');
  assert.deepEqual(reads, ['/run/LittleD-secret']);
  assert.throws(() => parseCli(['x.test', '--push', '--user', 'u', '--app-password-file', '/x'], {}, { readFile: () => '\n' }), /empty/);
  assert.throws(() => parseCli(['x.test', '--push', '--user', 'u', '--app-password-file', '/x'], {}, { readFile: () => { throw new Error('ENOENT'); } }), /cannot read/);
  assert.equal(parseCli(['x.test', '--push', '--user', 'u'], { DSCC_APP_PASSWORD: 'p' }).appPassword, 'p');
});

test('--site-url, --resolve and --allow-insecure are parsed and validated', () => {
  const o = parseCli(['http://web.internal', '--site-url', 'https://littled.test', '--resolve', 'littled.test=172.17.0.1', '--resolve', 'cdn.littled.test=10.0.0.2', '--allow-insecure'], {});
  assert.equal(o.siteUrl, 'https://littled.test');
  assert.deepEqual(o.resolve, ['littled.test=172.17.0.1', 'cdn.littled.test=10.0.0.2']);
  assert.equal(o.allowInsecure, true);
  assert.throws(() => parseCli(['x.test', '--resolve', 'nonsense'], {}));
  assert.throws(() => parseCli(['x.test', '--resolve', 'a.test=1.2.3.4;rm'], {}));
});

test('checkPushTarget refuses clear-text credentials except to loopback or with --allow-insecure', () => {
  assert.equal(checkPushTarget('https://littled.test'), null);
  for (const ok of ['http://localhost:8888', 'http://127.0.0.1', 'http://[::1]:8080']) assert.equal(checkPushTarget(ok), null, ok);
  assert.match(checkPushTarget('http://littled.test'), /refusing/);
  assert.match(checkPushTarget('http://192.168.1.5'), /refusing/);
  assert.equal(checkPushTarget('http://littled.test', true), null);
  assert.match(checkPushTarget('ftp://littled.test', true), /refusing/);
});

test('redirects are not followed and 301/302/401 come with a hint', async () => {
  let init;
  const redirect = await pushReport({
    site: 'http://littled.test', report: {}, user: 'u', appPassword: 'p',
    fetchImpl: async (url, i) => (init = i, { ok: false, status: 301, headers: new Headers({ location: 'https://littled.test/' }), text: async () => '' }),
  });
  assert.equal(init.redirect, 'manual');
  assert.equal(redirect.ok, false);
  assert.match(redirect.hint, /X-Forwarded-Proto/);
  assert.match(redirect.hint, /https:\/\/littled\.test\//);
  const denied = await pushReport({ site: 'https://littled.test', report: {}, user: 'u', appPassword: 'p', fetchImpl: async () => ({ ok: false, status: 401, text: async () => '{}' }) });
  assert.match(denied.hint, /Authorization/);
  assert.match(denied.hint, /HTTP_AUTHORIZATION/);
  assert.equal(hintFor(200), '');
  assert.equal(hintFor(302).startsWith('Redirected'), true);
});
