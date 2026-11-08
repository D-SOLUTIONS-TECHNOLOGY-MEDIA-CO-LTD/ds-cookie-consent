/**
 * Inline quick edit for the cookie table: opens an editor row under the cookie, saves through admin-ajax
 * (action dscc_cookie_quick_edit) and updates the visible cells without reloading. Text is set with
 * textContent/value only, never as HTML.
 */

const FIELDS = [
  ['purpose', 'qePurposeVi', 'textarea'],
  ['purpose_en', 'qePurposeEn', 'textarea'],
  ['duration_label', 'qeDurationVi', 'input'],
  ['duration_label_en', 'qeDurationEn', 'input'],
  ['table_group', 'qeGroup', 'input'],
  ['applies_to', 'qeAppliesVi', 'input'],
  ['applies_to_en', 'qeAppliesEn', 'input'],
];

function el(tag, attrs, text) {
  const node = document.createElement(tag);
  Object.keys(attrs || {}).forEach((k) => node.setAttribute(k, attrs[k]));
  if (text) node.textContent = text;
  return node;
}

export function initCookieQuickEdit() {
  const wrap = document.querySelector('.dscc-cookies');
  if (!wrap) return;
  const t = (window.dsccAdmin && window.dsccAdmin.i18n) || {};
  const ajax = wrap.getAttribute('data-dscc-ajax');
  const nonce = wrap.getAttribute('data-dscc-qe-nonce');
  const tpl = document.getElementById('dscc-qe-categories');

  function close(row) {
    const editor = row.nextElementSibling;
    if (editor && editor.classList.contains('dscc-qe-row')) editor.remove();
    row.classList.remove('is-editing');
  }

  function open(btn) {
    const row = btn.closest('tr');
    if (!row || row.classList.contains('is-editing')) return;
    const data = JSON.parse(btn.getAttribute('data-dscc-edit'));
    row.classList.add('is-editing');

    const cols = row.children.length;
    const tr = el('tr', { class: 'dscc-qe-row' });
    const td = el('td', { colspan: String(cols) });
    const box = el('div', { class: 'dscc-qe' });

    const field = (label, control) => {
      const lab = el('label', { class: 'dscc-qe__field' });
      lab.appendChild(el('span', {}, label));
      lab.appendChild(control);
      return lab;
    };

    const select = el('select', { name: 'category' });
    if (tpl) select.appendChild(tpl.content.cloneNode(true));
    select.value = data.category || '';
    box.appendChild(field(t.qeCategory || 'Category', select));
    if (data.shared) {
      box.appendChild(el('p', { class: 'description dscc-qe__note' }, (t.qeVendorNote || '').replace('%s', data.vendor)));
    }
    const inputs = {};
    FIELDS.forEach((f) => {
      const control = f[2] === 'textarea' ? el('textarea', { rows: '2', name: f[0] }) : el('input', { type: 'text', name: f[0], maxlength: '255' });
      control.value = data[f[0]] || '';
      inputs[f[0]] = control;
      box.appendChild(field(t[f[1]] || f[0], control));
    });

    const actions = el('p', { class: 'dscc-qe__actions' });
    const save = el('button', { type: 'button', class: 'button button-primary' }, t.qeSave || 'Save');
    const cancel = el('button', { type: 'button', class: 'button' }, t.qeCancel || 'Cancel');
    const status = el('span', { class: 'dscc-qe__status', role: 'status' });
    actions.appendChild(save);
    actions.appendChild(cancel);
    actions.appendChild(status);
    box.appendChild(actions);
    td.appendChild(box);
    tr.appendChild(td);
    row.parentNode.insertBefore(tr, row.nextSibling);

    cancel.addEventListener('click', () => close(row));
    save.addEventListener('click', () => {
      const body = new FormData();
      body.append('action', 'dscc_cookie_quick_edit');
      body.append('nonce', nonce);
      body.append('id', data.id);
      body.append('category', select.value);
      FIELDS.forEach((f) => body.append(f[0], inputs[f[0]].value));
      save.disabled = true;
      status.textContent = '';
      fetch(ajax, { method: 'POST', credentials: 'same-origin', body })
        .then((r) => r.json())
        .then((res) => {
          if (!res || !res.success) throw new Error((res && res.data && res.data.message) || '');
          const d = res.data;
          const set = (sel, text) => {
            const node = row.querySelector(sel);
            if (node) node.textContent = text;
          };
          set('.dscc-ck-purpose', d.purpose);
          set('.dscc-ck-duration', d.duration_label);
          set('.dscc-ck-category', d.category_label);
          btn.setAttribute('data-dscc-edit', JSON.stringify(Object.assign({}, data, d)));
          close(row);
        })
        .catch((err) => {
          save.disabled = false;
          status.textContent = err && err.message ? err.message : t.qeError || 'Error';
        });
    });
    select.focus();
  }

  wrap.addEventListener('click', (e) => {
    const btn = e.target.closest && e.target.closest('.dscc-qe-open');
    if (btn) {
      e.preventDefault();
      try {
        open(btn);
      } catch (err) {
        /* leave the table usable */
      }
    }
  });
}
