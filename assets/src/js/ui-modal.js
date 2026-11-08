/**
 * Popup (dialog with tabs). Markup is cloned lazily from <template id="dscc-tpl-modal">.
 * Mode "full" (bar layout): preferences + policy tabs. Mode "info" (card layout): read-only policy tabs.
 */
import { trapFocus } from './focus-trap.js';
import { bindToggleSync, fillSelection, readSelection } from './toggle-selection.js';

export function createModal(app) {
  const { root, cfg } = app;
  const overlay = root.querySelector('.dscc-overlay');
  let modal = null;
  let release = null;
  let trigger = null;
  let prevOverflow = '';
  let open = false;

  function tabs() {
    return modal ? Array.prototype.slice.call(modal.querySelectorAll('.dscc-tab')) : [];
  }

  /** Build the dialog from the template on first use. */
  function ensure() {
    if (modal) return true;
    const tpl = document.getElementById('dscc-tpl-modal');
    if (!tpl || !tpl.content || !overlay) return false;
    overlay.appendChild(tpl.content.cloneNode(true));
    modal = overlay.querySelector('.dscc-modal');
    if (!modal) return false;
    bindToggleSync(modal, cfg);
    if (app.localize) app.localize(modal);
    const list = modal.querySelector('.dscc-tabs');
    if (list) {
      list.addEventListener('keydown', (e) => {
        try {
          if (e.key !== 'ArrowRight' && e.key !== 'ArrowLeft') return;
          const all = tabs();
          const i = all.indexOf(document.activeElement);
          if (i < 0) return;
          const next = (i + (e.key === 'ArrowRight' ? 1 : all.length - 1)) % all.length;
          selectTab(all[next].getAttribute('data-dscc-tab'));
          all[next].focus();
          e.preventDefault();
        } catch (err) {
          /* ignore */
        }
      });
    }
    modal.addEventListener('click', (e) => {
      const tab = e.target.closest && e.target.closest('.dscc-tab');
      if (tab) selectTab(tab.getAttribute('data-dscc-tab'));
    });
    overlay.addEventListener('mousedown', (e) => {
      if (e.target === overlay) app.closeUI();
    });
    return true;
  }

  function selectTab(key) {
    const all = tabs();
    if (!all.some((t) => t.getAttribute('data-dscc-tab') === key)) key = all.length ? all[0].getAttribute('data-dscc-tab') : key;
    all.forEach((t) => {
      const on = t.getAttribute('data-dscc-tab') === key;
      t.setAttribute('aria-selected', on ? 'true' : 'false');
      t.tabIndex = on ? 0 : -1;
      const panel = modal.querySelector('#dscc-panel-' + t.getAttribute('data-dscc-tab'));
      if (panel) {
        panel.hidden = !on;
        const frame = panel.querySelector('iframe[data-dscc-src]');
        if (on && frame && !frame.getAttribute('src')) frame.setAttribute('src', frame.getAttribute('data-dscc-src'));
      }
    });
    const body = modal.querySelector('.dscc-body');
    if (body) body.scrollTop = 0;
  }

  return {
    isOpen: () => open,
    ensure,
    /** Open on a tab. `rec` is the saved record used to fill the switches. */
    open(tab, opener, rec) {
      if (!ensure()) return false;
      if (open) {
        // Already open (a second DSCC.open, a menu link clicked twice): only switch tab. Re-running the setup
        // would capture the locked overflow as "previous" and stack a second focus trap.
        selectTab(tab);
        return true;
      }
      trigger = opener || document.activeElement;
      if (modal.querySelector('[data-dscc-category]')) fillSelection(modal, cfg, rec);
      selectTab(tab);
      overlay.hidden = false;
      prevOverflow = document.documentElement.style.overflow;
      document.documentElement.style.overflow = 'hidden';
      document.documentElement.classList.add('dscc-modal-open');
      open = true;
      release = trapFocus(modal);
      setTimeout(() => {
        try {
          const sel = modal.querySelector('.dscc-tab[aria-selected="true"]') || modal;
          sel.focus();
        } catch (e) {
          /* ignore */
        }
      }, 30);
      return true;
    },
    /** Close without saving. Focus handling is done by the caller. */
    close() {
      if (!open) return null;
      open = false;
      overlay.hidden = true;
      document.documentElement.style.overflow = prevOverflow;
      document.documentElement.classList.remove('dscc-modal-open');
      if (release) release();
      release = null;
      const t = trigger;
      trigger = null;
      return t;
    },
    selection: () => (modal ? readSelection(modal, cfg) : null),
    root: () => modal,
  };
}
