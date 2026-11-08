import test from 'node:test';
import assert from 'node:assert/strict';
import { isPattern, fromOcdName, matchesName, displayName, parseRetentionSeconds } from '../lib/patterns.mjs';

test('isPattern: only names containing "*"', () => {
  assert.equal(isPattern('_ga_*'), true);
  assert.equal(isPattern('_ga'), false);
});

test('fromOcdName: wildcard rows get a trailing "*", fixed rows stay unchanged', () => {
  assert.equal(fromOcdName('_ga_', '1'), '_ga_*');
  assert.equal(fromOcdName('_ga_', 1), '_ga_*');
  assert.equal(fromOcdName('_ga', '0'), '_ga');
  assert.equal(fromOcdName('_hjSession_*', '1'), '_hjSession_*');
  assert.equal(fromOcdName(' _sp_id. ', '1'), '_sp_id.*');
});

test('displayName: "_ga_*" shows as "_ga_<mã>", dot/plain suffixes keep "*"', () => {
  assert.equal(displayName('_ga_*'), '_ga_<mã>');
  assert.equal(displayName('_gat_UA-*'), '_gat_UA-<mã>');
  assert.equal(displayName('intercom-device-id-*'), 'intercom-device-id-<mã>');
  assert.equal(displayName('ttcsid*'), 'ttcsid*');
  assert.equal(displayName('_sp_id.*'), '_sp_id.*');
  assert.equal(displayName('_ga'), '_ga');
});

test('matchesName: glob matching is anchored and escapes regex characters', () => {
  assert.equal(matchesName('_ga_*', '_ga_LittleD123'), true);
  assert.equal(matchesName('_ga_*', '_ga'), false);
  assert.equal(matchesName('_ga_*', 'x_ga_ABC'), false);
  assert.equal(matchesName('_sp_id.*', '_sp_id.1a2b'), true);
  assert.equal(matchesName('_sp_id.*', '_sp_idX1a2b'), false);
  assert.equal(matchesName('_gat_UA-*', '_gat_UA-12345-1'), true);
  assert.equal(matchesName('ttcsid*', 'ttcsid_LittleD'), true);
  assert.equal(matchesName('_ga', '_ga'), true);
  assert.equal(matchesName('_ga', '_gaX'), false);
});

test('parseRetentionSeconds: English labels to seconds, session/unknown to null', () => {
  assert.equal(parseRetentionSeconds('2 years'), 63072000);
  assert.equal(parseRetentionSeconds('24 hours'), 86400);
  assert.equal(parseRetentionSeconds('1 minute'), 60);
  assert.equal(parseRetentionSeconds('30 days'), 2592000);
  assert.equal(parseRetentionSeconds('Session'), null);
  assert.equal(parseRetentionSeconds(''), null);
});
