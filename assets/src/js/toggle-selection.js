/**
 * Reads / fills the category and per-service switches inside a container and keeps them in sync.
 * Selection shape: { analytics:boolean, marketing:boolean, [functional], services:{slug:boolean} }.
 */

function catInput(scope, key) {
  return scope.querySelector('input[data-dscc-category="' + key + '"]');
}

function serviceInputs(scope, cat) {
  const row = scope.querySelector('[data-dscc-cat="' + cat.key + '"]');
  return row ? Array.prototype.slice.call(row.querySelectorAll('input[data-dscc-service]')) : [];
}

/** Category toggle follows its services (checked when any service is on). */
function syncCategory(scope, cat) {
  const input = catInput(scope, cat.key);
  const svc = serviceInputs(scope, cat);
  if (input && svc.length) input.checked = svc.some((i) => i.checked);
}

/** Set every switch from a consent record (all OFF when there is none: nothing is pre-ticked). */
export function fillSelection(scope, cfg, rec) {
  cfg.cats.forEach((cat) => {
    if (cat.required) return;
    const input = catInput(scope, cat.key);
    const on = !!(rec && rec.c && rec.c[cat.key]);
    if (input) input.checked = on;
    serviceInputs(scope, cat).forEach((i) => {
      const slug = i.getAttribute('data-dscc-service');
      i.checked = rec && rec.s && slug in rec.s ? !!rec.s[slug] : on;
    });
    syncCategory(scope, cat);
  });
}

/** Collect the current selection. */
export function readSelection(scope, cfg) {
  const sel = { services: {} };
  cfg.cats.forEach((cat) => {
    if (cat.required) return;
    syncCategory(scope, cat);
    const input = catInput(scope, cat.key);
    const on = !!(input && input.checked);
    sel[cat.key] = on;
    if (cfg.perService) {
      const inputs = serviceInputs(scope, cat);
      cat.services.forEach((slug) => {
        const i = inputs.filter((n) => n.getAttribute('data-dscc-service') === slug)[0];
        sel.services[slug] = i ? i.checked : on;
      });
    }
  });
  return sel;
}

/** Selection with every optional category (and service) on or off. */
export function uniformSelection(cfg, on) {
  const sel = { services: {} };
  cfg.cats.forEach((cat) => {
    if (cat.required) return;
    sel[cat.key] = on;
    if (cfg.perService) cat.services.forEach((slug) => (sel.services[slug] = on));
  });
  return sel;
}

/** accept_all / reject_all / custom, derived from a selection. */
export function computeChoice(cfg, sel) {
  const values = [];
  cfg.cats.forEach((cat) => {
    if (!cat.required) values.push(!!sel[cat.key]);
  });
  Object.keys(sel.services || {}).forEach((k) => values.push(!!sel.services[k]));
  if (values.length && values.every(Boolean)) return 'accept_all';
  if (!values.some(Boolean)) return 'reject_all';
  return 'custom';
}

/** Keep category and service switches in step while the visitor toggles them. */
export function bindToggleSync(scope, cfg) {
  scope.addEventListener('change', (e) => {
    try {
      const t = e.target;
      if (!t || t.type !== 'checkbox') return;
      const key = t.getAttribute('data-dscc-category');
      if (key) {
        const cat = cfg.cats.filter((c) => c.key === key)[0];
        if (cat) serviceInputs(scope, cat).forEach((i) => (i.checked = t.checked));
      } else if (t.hasAttribute('data-dscc-service')) {
        const row = t.closest('[data-dscc-cat]');
        const cat = row && cfg.cats.filter((c) => c.key === row.getAttribute('data-dscc-cat'))[0];
        if (cat) syncCategory(scope, cat);
      }
    } catch (err) {
      /* ignore */
    }
  });
}
