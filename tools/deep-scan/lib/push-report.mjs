// Sends the finished report to the WordPress site (Application Password / HTTP Basic).

export const SCAN_RESULT_PATH = '/wp-json/dscc/v1/scan-result';
/** Same route for sites whose permalinks are "plain" (no /wp-json/ rewrite). */
export const SCAN_RESULT_QUERY = '/?rest_route=/dscc/v1/scan-result';

const LOOPBACK = new Set(['localhost', '127.0.0.1', '[::1]', '::1']);

/**
 * Credentials go with every push, so plain http:// is refused unless the host is loopback or --allow-insecure is given.
 * Returns an error message, or null when the target is acceptable.
 */
export function checkPushTarget(site, allowInsecure = false) {
  let u;
  try {
    u = new URL(site);
  } catch {
    return `invalid push target: ${site}`;
  }
  if (u.protocol === 'https:') return null;
  if (u.protocol === 'http:' && (allowInsecure || LOOPBACK.has(u.hostname))) return null;
  return `refusing to send the Application Password over ${u.protocol}//${u.host}: use https://, or pass --allow-insecure if you accept clear-text credentials`;
}

/** Human hints for the responses that usually mean a proxy or web server setup problem. */
export function hintFor(status, location = '') {
  if ([301, 302, 303, 307, 308].includes(status)) {
    return `Redirected${location ? ` to ${location}` : ''}. The tool does not follow redirects (credentials must not travel to another address). Use the final address as --site-url, and make sure WordPress sees the request as HTTPS: behind a proxy or CDN forward X-Forwarded-Proto: https.`;
  }
  if (status === 401) {
    return 'Authentication failed. Check the user and the Application Password; Application Passwords need HTTPS (or a local environment); and make sure the web server passes the Authorization header to PHP (Apache .htaccess: RewriteRule .* - [E=HTTP_AUTHORIZATION:%{HTTP:Authorization}]; behind a proxy forward X-Forwarded-Proto and keep Authorization).';
  }
  return '';
}

async function post(url, report, auth, timeoutMs, fetchImpl) {
  const res = await fetchImpl(url, {
    method: 'POST',
    redirect: 'manual',
    headers: {
      'Content-Type': 'application/json',
      Accept: 'application/json',
      Authorization: `Basic ${auth}`,
    },
    body: JSON.stringify(report),
    signal: AbortSignal.timeout(timeoutMs),
  });
  const body = (await res.text()).slice(0, 500);
  const hint = hintFor(res.status, res.headers?.get?.('location') || '');
  return { ok: res.ok, status: res.status, body, ...(hint ? { hint } : {}) };
}

/**
 * Returns { ok, status, body, hint? }. Network failures resolve to ok:false, status:0.
 * A 404 on the /wp-json/ address is retried once through ?rest_route= (plain permalinks). Redirects are never followed.
 */
export async function pushReport({ site, report, user, appPassword, timeoutMs = 30000, fetchImpl = fetch }) {
  const auth = Buffer.from(`${user}:${appPassword}`).toString('base64');
  try {
    const first = await post(`${site}${SCAN_RESULT_PATH}`, report, auth, timeoutMs, fetchImpl);
    if (first.status !== 404) return first;
    return await post(`${site}${SCAN_RESULT_QUERY}`, report, auth, timeoutMs, fetchImpl);
  } catch (err) {
    return { ok: false, status: 0, body: String(err?.message || err) };
  }
}
