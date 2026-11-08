/**
 * Layout "card": compact box with an in-place preferences view, shield widget, info popup for cookie details.
 * - Preferences switches start OFF when the visitor never chose (nothing is pre-ticked).
 * - Reopened from the widget: shows the saved choice; X / Esc close without saving; focus is trapped.
 */
import { createModal } from './ui-modal.js';
import { trapFocus } from './focus-trap.js';
import { restoreFocus } from './focus-restore.js';
import { bindToggleSync, fillSelection, readSelection } from './toggle-selection.js';

export function createCardUI(app) {
  const { root, cfg } = app;
  const card = root.querySelector('.dscc-card');
  const closeBtn = root.querySelector('.dscc-card__x');
  const views = {
    main: root.querySelector('[data-dscc-view="main"]'),
    prefs: root.querySelector('[data-dscc-view="prefs"]'),
  };
  const info = createModal(app);
  let release = null;
  let reopened = false;
  let trigger = null;
  let hideTimer = null;

  bindToggleSync(card, cfg);

  /** An opener inside the card disappears with it, so it is no restore target after a decision. */
  const outside = (n) => (n && !root.contains(n) ? n : null);

  function setView(name) {
    views.main.hidden = name !== 'main';
    views.prefs.hidden = name !== 'prefs';
    card.setAttribute('aria-labelledby', name === 'main' ? 'dscc-card-title' : 'dscc-card-title-prefs');
  }

  function showCard() {
    clearTimeout(hideTimer);
    root.classList.remove('dscc-card-out');
    card.hidden = false;
    document.documentElement.classList.add('dscc-open');
    requestAnimationFrame(() => requestAnimationFrame(() => card.classList.add('is-in')));
  }

  function hideCard() {
    card.classList.remove('is-in');
    card.hidden = true;
    document.documentElement.classList.remove('dscc-open');
    if (release) release();
    release = null;
    reopened = false;
    closeBtn.hidden = true;
    card.setAttribute('aria-modal', 'false');
  }

  function focusFirstToggle() {
    try {
      const t = views.prefs.querySelector('input[data-dscc-category]') || views.prefs.querySelector('button');
      if (t) t.focus();
    } catch (e) {
      /* ignore */
    }
  }

  return {
    modal: info,
    showInitial() {
      app.widget.hide();
      setView('main');
      showCard();
    },
    /** Prefs view: from "Tùy chỉnh" (first visit) or reopened from the widget / API / shortcode. */
    openPrefs(tab, opener) {
      const rec = app.record();
      if (tab === 'privacy' || tab === 'cookies') return this.openTab(tab, opener);
      // Already showing the preferences (a second DSCC.open, a repeated menu click): keep the unsaved switches.
      if (!card.hidden && !views.prefs.hidden) return true;
      trigger = opener || document.activeElement;
      reopened = !!rec;
      fillSelection(views.prefs, cfg, rec);
      setView('prefs');
      app.widget.hide();
      showCard();
      if (reopened) {
        closeBtn.hidden = false;
        card.setAttribute('aria-modal', 'true');
        if (!release) release = trapFocus(card);
      }
      focusFirstToggle();
      return true;
    },
    /** Cookie details popup. */
    openTab(tab, opener) {
      return info.open(tab || 'cookies', opener, app.record());
    },
    customize(opener) {
      app.openPrefs('prefs', opener);
    },
    isOpen: () => info.isOpen() || (!card.hidden && reopened),
    /** Esc / X: the info popup first, then the reopened card. Never closes the first-visit card. */
    close() {
      if (info.isOpen()) {
        restoreFocus([info.close(), app.widget.el]);
        return;
      }
      if (!reopened) return;
      const opener = trigger;
      trigger = null;
      hideCard();
      app.widget.show(false);
      restoreFocus([opener, app.widget.el]);
    },
    selection: () => readSelection(views.prefs, cfg),
    afterDecision(hadFocusInside) {
      const opener = outside(info.close()) || outside(trigger);
      trigger = null;
      hideCard();
      app.widget.show(true);
      if (hadFocusInside) restoreFocus([opener, app.widget.el]);
    },
    reset() {
      info.close();
      hideCard();
      this.showInitial();
    },
    previewView(view) {
      info.close();
      hideCard();
      app.widget.hide();
      if (view === 'banner') {
        setView('main');
        showCard();
      } else if (view === 'prefs') {
        fillSelection(views.prefs, cfg, null);
        setView('prefs');
        showCard();
      } else if (view === 'widget') {
        app.widget.show(false);
      }
    },
    /** True while the card is open in reopened mode (Esc allowed). */
    canEscape: () => reopened,
  };
}
