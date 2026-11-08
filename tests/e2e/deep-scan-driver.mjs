// Runs the headless deep scan (tools/deep-scan) against the local wp-env site and pushes the report with an
// Application Password. Third-party hosts are answered by the same stubs as the other E2E specs, so nothing leaves the
// machine. Credentials come from the environment only; the last stdout line is JSON {status, body}.
import { createRequire } from 'node:module';
import { runScan } from '../../tools/deep-scan/lib/scan-runner.mjs';
import { buildReport } from '../../tools/deep-scan/lib/report-builder.mjs';
import { pushReport } from '../../tools/deep-scan/lib/push-report.mjs';
import { DEFAULTS } from '../../tools/deep-scan/lib/cli-args.mjs';

const { interceptVendors } = createRequire(import.meta.url)('./helpers.js');

const site = process.env.DSCC_BASE_URL || 'http://localhost:8888';
const user = process.env.DSCC_USER;
const appPassword = process.env.DSCC_APP_PASSWORD;
if (new URL(site).hostname !== 'localhost') throw new Error('The E2E driver only scans localhost');
if (!user || !appPassword) throw new Error('DSCC_USER and DSCC_APP_PASSWORD are required');

const result = await runScan({
  homeUrl: `${site}/`,
  urls: [`${site}/`],
  pages: 1,
  wait: Number(process.env.DSCC_WAIT || 1500),
  rejectSelector: process.env.DSCC_REJECT_SELECTOR || DEFAULTS.rejectSelector,
  acceptSelector: process.env.DSCC_ACCEPT_SELECTOR || DEFAULTS.acceptSelector,
  prepareContext: (context) => interceptVendors(context),
});
const report = buildReport({ site, toolVersion: 'e2e', pluginDetected: result.pluginDetected, bannerFound: result.bannerFound, phases: result.phases, errors: result.errors });
if (process.env.DSCC_REPORT_OUT) (await import('node:fs')).writeFileSync(process.env.DSCC_REPORT_OUT, JSON.stringify(report));
const pushed = await pushReport({ site, report, user, appPassword });
console.log(JSON.stringify({ status: pushed.status, body: pushed.body, banner: report.banner_found, plugin: report.plugin_detected }));
