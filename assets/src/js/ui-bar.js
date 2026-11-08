/**
 * Layout "bar": bottom banner + popup (3 tabs) + shield widget.
 */
import { createModal } from './ui-modal.js';
import { restoreFocus } from './focus-restore.js';

export function createBarUI(app) {
  const { root, cfg } = app;
  const bar = root.querySelector('.dscc-bar');
  const modal = createModal(app);
  let hideTimer = null;

  /** An opener inside the banner disappears with it, so it is no restore target after a decision. */
  const outside = (n) => (n && !root.contains(n) ? n : null);

  function showBar() {
    clearTimeout(hideTimer);
    bar.hidden = false;
    bar.classList.remove('is-hiding');
    document.documentElement.classList.add('dscc-open');
  }

  function hideBar(instant) {
    document.documentElement.classList.remove('dscc-open');
    if (bar.hidden) return;
    if (instant) {
      bar.hidden = true;
      return;
    }
    bar.classList.add('is-hiding');
    hideTimer = setTimeout(() => {
      bar.hidden = true;
    }, 280);
  }

  return {
    modal,
    /** First visit (or expired): banner only. */
    showInitial() {
      app.widget.hide();
      showBar();
    },
    /** "Tùy chỉnh" / policy link / widget: open the popup. */
    openPrefs(tab, trigger) {
      return modal.open(tab || 'prefs', trigger, app.record());
    },
    openTab(tab, trigger) {
      return modal.open(tab, trigger, app.record());
    },
    customize(trigger) {
      app.openPrefs('prefs', trigger);
    },
    isOpen: () => modal.isOpen(),
    /** Close the popup without saving; the banner stays when no choice exists yet. */
    close() {
      const opener = modal.close();
      if (app.record()) {
        app.widget.show(false);
        restoreFocus([opener, app.widget.el]);
      } else {
        restoreFocus([opener]);
      }
    },
    selection: () => modal.selection(),
    /** After a decision: hide banner and popup, show the widget. */
    afterDecision(hadFocusInside) {
      const opener = outside(modal.close());
      hideBar(false);
      app.widget.show(true);
      if (hadFocusInside) restoreFocus([opener, app.widget.el]);
    },
    /** Restore the initial banner (after reset). */
    reset() {
      modal.close();
      this.showInitial();
    },
    /** Admin preview helpers. */
    previewView(view) {
      modal.close();
      app.widget.hide();
      bar.hidden = true;
      if (view === 'banner') showBar();
      else if (view === 'prefs') modal.open('prefs', null, null);
      else if (view === 'widget') app.widget.show(false);
    },
    cfg,
  };
}
