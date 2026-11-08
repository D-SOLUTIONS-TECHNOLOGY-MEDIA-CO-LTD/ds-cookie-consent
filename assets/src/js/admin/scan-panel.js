/**
 * "Quét ngay" panel: starts a server scan and polls its progress. Each status request works one batch of five pages on
 * the server, so the bar keeps moving even when WP-Cron is slow. Text is set with textContent only.
 */

function format(template, args) {
  return String(template || '').replace(/%(\d)\$s/g, (m, i) => (args[Number(i) - 1] === undefined ? '' : args[Number(i) - 1]));
}

export function initScanPanel() {
  const panel = document.getElementById('dscc-scan-panel');
  const cfg = window.dsccAdmin && window.dsccAdmin.scan;
  if (!panel || !cfg) return;
  const t = (window.dsccAdmin && window.dsccAdmin.i18n) || {};
  const button = document.getElementById('dscc-scan-start');
  const box = document.getElementById('dscc-scan-progress');
  const bar = document.getElementById('dscc-scan-bar');
  const text = document.getElementById('dscc-scan-text');

  function post(action, extra) {
    const body = new URLSearchParams();
    body.append('action', action);
    body.append('nonce', cfg.nonce);
    Object.keys(extra || {}).forEach((k) => body.append(k, extra[k]));
    return fetch(cfg.ajax, { method: 'POST', body, credentials: 'same-origin' }).then((r) => r.json());
  }

  function show(message, value) {
    box.hidden = false;
    text.textContent = message;
    if (typeof value === 'number') bar.value = value;
  }

  function fail(message) {
    show(message || t.scanError, 0);
    button.disabled = false;
  }

  function poll(id) {
    post('dscc_scan_status', { id })
      .then((res) => {
        if (!res || !res.success) return fail(t.scanError);
        const st = res.data;
        if (!st.finished) {
          show(format(t.scanProgress, [st.done, st.total]), st.total ? Math.round((100 * st.done) / st.total) : 0);
          return setTimeout(() => poll(id), 800);
        }
        if (st.status !== 'done') return fail(t.scanFailed);
        show(format(t.scanDone, [st.cookies, st.new_cookies]), 100);
        setTimeout(() => window.location.reload(), 700);
      })
      .catch(() => fail(t.scanError));
  }

  function start() {
    button.disabled = true;
    show(format(t.scanProgress, [0, '?']), 0);
    post('dscc_scan_start')
      .then((res) => {
        if (!res || !res.success) return fail(res && res.data && res.data.message);
        poll(res.data.id);
      })
      .catch(() => fail(t.scanError));
  }

  button.addEventListener('click', start);
  // A scan started earlier (or by cron) is still running: pick up its progress.
  const running = panel.getAttribute('data-dscc-running');
  if (running) {
    button.disabled = true;
    show(format(t.scanProgress, [0, '?']), 0);
    poll(running);
  }
}
