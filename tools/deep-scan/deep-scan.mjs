#!/usr/bin/env node
// DS Cookie Consent deep scan CLI. Logs go to stderr; the report goes to --report or stdout.
import { readFileSync, writeFileSync } from 'node:fs';
import { parseCli, USAGE } from './lib/cli-args.mjs';
import { buildReport } from './lib/report-builder.mjs';
import { normalizeSiteUrl, resolveUrls } from './lib/url-utils.mjs';
import { runScan } from './lib/scan-runner.mjs';
import { pushReport, checkPushTarget } from './lib/push-report.mjs';

const log = (msg) => console.error(`[deep-scan] ${msg}`);

async function main() {
  let opts;
  try {
    opts = parseCli(process.argv.slice(2), process.env, { warn: log });
  } catch (err) {
    console.error(`Error: ${err.message}\n\n${USAGE}`);
    return 2;
  }
  if (opts.help) {
    console.log(USAGE);
    return 0;
  }

  let site; // where the browser goes
  let reportSite; // what the report calls the site, and where it is pushed
  let urls;
  try {
    site = normalizeSiteUrl(opts.site);
    reportSite = opts.siteUrl ? normalizeSiteUrl(opts.siteUrl) : site;
    urls = resolveUrls(opts.urls, site);
  } catch (err) {
    console.error(`Error: invalid URL: ${err.message}`);
    return 2;
  }
  if (opts.push) {
    const refusal = checkPushTarget(reportSite, opts.allowInsecure);
    if (refusal) {
      console.error(`Error: ${refusal}`);
      return 2;
    }
  }
  const pkg = JSON.parse(readFileSync(new URL('./package.json', import.meta.url), 'utf8'));

  log(`scanning ${site}`);
  const result = await runScan(
    { ...opts, homeUrl: urls[0] ?? site, urls },
    log,
  );
  const report = buildReport({
    site: reportSite,
    toolVersion: pkg.version,
    pluginDetected: result.pluginDetected,
    bannerFound: result.bannerFound,
    phases: result.phases,
    errors: result.errors,
  });
  const json = JSON.stringify(report, null, 2);
  if (opts.report) {
    writeFileSync(opts.report, json + '\n');
    log(`report written to ${opts.report}`);
  } else {
    console.log(json);
  }
  if (!report.banner_found) log('WARNING: consent banner buttons not found; phase data is still recorded');

  if (opts.push) {
    const res = await pushReport({ site: reportSite, report, user: opts.user, appPassword: opts.appPassword });
    log(`push -> HTTP ${res.status} ${res.body}`);
    if (res.hint) log(`hint: ${res.hint}`);
    if (!res.ok) return 1;
  }
  return 0;
}

main().then(
  (code) => process.exit(code),
  (err) => {
    console.error(`Fatal: ${err?.stack || err}`);
    process.exit(1);
  },
);
