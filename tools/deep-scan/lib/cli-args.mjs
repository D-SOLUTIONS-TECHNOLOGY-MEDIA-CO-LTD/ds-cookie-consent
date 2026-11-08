// CLI argument parsing for deep-scan.mjs.
import { parseArgs } from 'node:util';
import { readFileSync, statSync } from 'node:fs';

export const DEFAULTS = {
  rejectSelector: '[data-dscc-action="reject"]',
  acceptSelector: '[data-dscc-action="accept"]',
  pages: 3,
  wait: 8000,
};

export const USAGE = `Usage: node deep-scan.mjs <site-url> [options]
  --urls a,b,c            Pages to scan (default: site-url + 2 internal links)
  --pages 3               Pages per phase when auto-discovering
  --wait 8000             Wait (ms) before recording the before_choice phase
  --report out.json       Write report to file (default: stdout)
  --push                  POST the report to <site>/wp-json/dscc/v1/scan-result (https, or loopback)
  --user U                WordPress user (or env DSCC_USER)
  --app-password-file F   File holding the Application Password (or env DSCC_APP_PASSWORD). There is no
                          --app-password flag: a password on the command line shows up in ps and in logs.
  --site-url URL          Public address of the site: used as report "site" and as push target, while
                          <site-url> (first argument) is only where the browser goes (e.g. an internal name)
  --resolve host=ip       Make the browser resolve host to ip (Chromium --host-resolver-rules); repeatable
  --allow-insecure        Allow --push over plain http:// to a non-loopback host (credentials in clear text)
  --reject-selector SEL   Default ${DEFAULTS.rejectSelector}
  --accept-selector SEL   Default ${DEFAULTS.acceptSelector}`;

/** Reads the Application Password file; warns through `warn` when other users can read it. */
function readPasswordFile(path, readFile, warn) {
  let text;
  try {
    text = readFile(path, 'utf8');
  } catch (err) {
    throw new Error(`cannot read --app-password-file ${path}: ${err.message}`);
  }
  const password = String(text).replace(/\r?\n+$/, '');
  if (!password) throw new Error(`--app-password-file ${path} is empty`);
  try {
    if (statSync(path).mode & 0o077) warn(`WARNING: ${path} is readable by other users; run chmod 600 on it`);
  } catch {
    /* injected readers in tests have no real file */
  }
  return password;
}

export function parseCli(argv, env = process.env, { readFile = readFileSync, warn = () => {} } = {}) {
  const { values, positionals } = parseArgs({
    args: argv,
    allowPositionals: true,
    options: {
      urls: { type: 'string' },
      pages: { type: 'string' },
      wait: { type: 'string' },
      report: { type: 'string' },
      push: { type: 'boolean', default: false },
      user: { type: 'string' },
      'app-password': { type: 'string' },
      'app-password-file': { type: 'string' },
      'site-url': { type: 'string' },
      resolve: { type: 'string', multiple: true },
      'allow-insecure': { type: 'boolean', default: false },
      'reject-selector': { type: 'string' },
      'accept-selector': { type: 'string' },
      help: { type: 'boolean', short: 'h', default: false },
    },
  });
  if (values['app-password'] !== undefined) {
    throw new Error('--app-password was removed because a password on the command line leaks through ps and logs. Use the env var DSCC_APP_PASSWORD or --app-password-file <path>.');
  }
  const toInt = (v, d, min, max, label) => {
    if (v === undefined) return d;
    const n = Number(v);
    if (!Number.isInteger(n) || n < min || n > max) throw new Error(`--${label} must be an integer ${min}-${max}`);
    return n;
  };
  const resolve = (values.resolve || []).map((rule) => {
    if (!/^[A-Za-z0-9.-]+=[0-9A-Fa-f:.]+$/.test(rule)) throw new Error(`--resolve expects host=ip, got "${rule}"`);
    return rule;
  });
  const opts = {
    help: values.help,
    site: positionals[0],
    urls: values.urls ? values.urls.split(',').map((s) => s.trim()).filter(Boolean) : [],
    pages: toInt(values.pages, DEFAULTS.pages, 1, 10, 'pages'),
    wait: toInt(values.wait, DEFAULTS.wait, 0, 60000, 'wait'),
    report: values.report,
    push: values.push,
    user: values.user || env.DSCC_USER,
    appPassword: undefined,
    siteUrl: values['site-url'],
    resolve,
    allowInsecure: values['allow-insecure'],
    rejectSelector: values['reject-selector'] || DEFAULTS.rejectSelector,
    acceptSelector: values['accept-selector'] || DEFAULTS.acceptSelector,
  };
  if (!opts.help) {
    if (!opts.site) throw new Error('Missing <site-url>');
    if (opts.push) {
      opts.appPassword = values['app-password-file']
        ? readPasswordFile(values['app-password-file'], readFile, warn)
        : env.DSCC_APP_PASSWORD;
      if (!opts.user || !opts.appPassword) {
        throw new Error('--push needs --user (or env DSCC_USER) and the password in env DSCC_APP_PASSWORD or --app-password-file');
      }
    }
  }
  return opts;
}
