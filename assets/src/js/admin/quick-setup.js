/**
 * Compliance report: resolve unclassified cookies in place. Row buttons and the bulk form POST to admin-ajax; on success
 * the rows leave the report without a reload. Without JS the same buttons submit the form to admin-post. Text is set
 * with textContent only.
 */

export function initQuickSetup() {
  const cfg = window.dsccAdmin && window.dsccAdmin.quick;
  const form = document.getElementById('dscc-quick-form');
  const root = document.getElementById('dscc-compliance');
  if (!cfg || !form || !root) return;
  const t = (window.dsccAdmin && window.dsccAdmin.i18n) || {};
  const status = document.getElementById('dscc-quick-status');

  function say(message) {
    if (status) status.textContent = message || '';
  }

  /** Remove every report row of the resolved cookies (the same cookie can sit in two lists). */
  function drop(ids) {
    ids.forEach((id) => {
      document.querySelectorAll('#dscc-compliance [data-dscc-cookie-id="' + id + '"]').forEach((el) => el.remove());
    });
    const left = root.querySelectorAll('#dscc-cw-unclassified-box li[data-dscc-cookie-id]').length;
    const box = document.getElementById('dscc-cw-unclassified-box');
    const title = document.getElementById('dscc-cw-unclassified');
    if (title) title.textContent = title.textContent.replace(/\d+/, String(left));
    if (box && !left) box.remove();
  }

  function send(ids, action, row) {
    const body = new URLSearchParams();
    body.append('action', 'dscc_quick_setup');
    body.append('nonce', cfg.nonce);
    if (row) body.append('row_action', action + ':' + ids[0]);
    else {
      body.append('qs_action', action);
      ids.forEach((id) => body.append('ids[]', id));
    }
    say('');
    return fetch(cfg.ajax, { method: 'POST', body, credentials: 'same-origin' })
      .then((r) => r.json())
      .then((res) => {
        const data = (res && res.data) || {};
        if (data.done && data.done.length) drop(data.done);
        if (!res || !res.success || (data.failed && data.failed.length)) say(data.message || t.qsError);
      })
      .catch(() => say(t.qsError));
  }

  root.addEventListener('click', (e) => {
    const btn = e.target.closest ? e.target.closest('button[name="row_action"]') : null;
    if (!btn) return;
    e.preventDefault();
    const [action, id] = btn.value.split(':');
    send([id], action, true);
  });

  form.addEventListener('submit', (e) => {
    e.preventDefault();
    const select = document.getElementById('dscc-quick-action');
    const action = select ? select.value : '';
    const ids = Array.from(root.querySelectorAll('input[name="ids[]"]:checked')).map((c) => c.value);
    if (action && ids.length) send(ids, action, false);
  });
}
