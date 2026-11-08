/**
 * Entry point. Reads #dscc-data, decides what to show and exposes window.DSCC.
 * Everything is wrapped in try/catch: a failure here may only cost the banner, never the site.
 */
import { createStore, uid } from './consent-store.js';
import { applyConsent, restoreConsentMode } from './consent-mode.js';
import { logChoice } from './consent-logger.js';
import { createWidget } from './ui-widget.js';
import { createBarUI } from './ui-bar.js';
import { createCardUI } from './ui-card.js';
import { computeChoice, uniformSelection } from './toggle-selection.js';
import { initPreviewBridge } from './preview-bridge.js';
import { isAllowed } from './consent-check.js';
import { createUnblocker } from './script-unblocker.js';
import { purge } from './cookie-purger.js';
import { syncBridges } from './integration-bridges.js';
import { startBeacon } from './beacon-collector.js';

/** True when the user agent matches one of the patterns. */
function uaMatches(patterns) {
  const ua = navigator.userAgent || '';
  return patterns.some((p) => {
    try {
      return !!p && new RegExp(p, 'i').test(ua);
    } catch (e) {
      return false;
    }
  });
}

/**
 * A visitor who is not asked (search bot, app webview from the skip list). Scripts and pixels stay blocked, but
 * embeds come back so the page reads normally; with skipMode "grant" (the host app owns consent) everything runs as
 * if accept_all was chosen. No cookie, no log.
 */
function skippedVisitor(cfg, grant) {
  try {
    const unblocker = createUnblocker(cfg);
    if (grant) {
      const all = { c: { analytics: 1, marketing: 1, functional: 1 }, s: {} };
      restoreConsentMode(all);
      unblocker.run(all);
    } else {
      unblocker.frames();
    }
  } catch (e) {
    /* never break the page */
  }
}

/** True when the visitor had any optional category or service switched on. */
function hadOptional(cfg, rec) {
  if (!rec) return false;
  return cfg.cats.some((c) => !c.required && rec.c[c.key]) || Object.keys(rec.s || {}).some((k) => rec.s[k]);
}

function init() {
  const dataEl = document.getElementById('dscc-data');
  const root = document.getElementById('dscc');
  if (!dataEl || !root) return;

  const cfg = JSON.parse(dataEl.textContent);
  const boot = window.DSCC_BOOT || {};

  // Skip rules (app webviews, bots): remove the UI entirely, bind nothing, log nothing.
  const botSkip = uaMatches([cfg.bots]);
  const userSkip = !!boot.skip || uaMatches(cfg.ua || []);
  if ((botSkip || userSkip) && !cfg.preview) {
    document.documentElement.classList.add('dscc-skip');
    root.remove();
    skippedVisitor(cfg, !botSkip && cfg.skipMode === 'grant');
    return;
  }

  const store = createStore(cfg);
  const app = { root, cfg, reopened: false };

  // Language picked in the browser (both languages are in the markup): follow <html lang>, e.g. when GTranslate rewrites it.
  const serverLang = cfg.lang;
  app.localize = (scope) => {
    if (!cfg.dual) return;
    Array.prototype.forEach.call(scope.querySelectorAll('[data-dscc-attr]'), (el) => {
      const text = el.getAttribute('data-dscc-' + cfg.lang);
      if (text !== null) el.setAttribute(el.getAttribute('data-dscc-attr'), text);
    });
  };
  function applyLang() {
    const html = (document.documentElement.lang || '').toLowerCase();
    cfg.lang = html.indexOf('vi') === 0 ? 'vi' : html.indexOf('en') === 0 ? 'en' : serverLang;
    root.setAttribute('data-dscc-active-lang', cfg.lang);
    app.localize(root);
    // Shortcode links print both languages (the non-server one hidden): show the active one.
    Array.prototype.forEach.call(document.querySelectorAll('[data-dscc-open] > [data-dscc-lang], [data-dscc-ph] [data-dscc-lang]'), (el) => {
      el.hidden = el.getAttribute('data-dscc-lang') !== cfg.lang;
    });
    const modal = root.querySelector('.dscc-modal');
    if (modal) app.localize(modal);
  }
  if (cfg.dual && !cfg.preview) {
    applyLang();
    try {
      new MutationObserver(applyLang).observe(document.documentElement, { attributes: true, attributeFilter: ['lang'] });
    } catch (e) {
      /* the language is then picked once, at load */
    }
  }

  /** The widget rule "after_reject": only visible when nothing optional is granted. */
  const widgetAllowed = () => {
    if (cfg.widget.show !== 'after_reject') return true;
    const rec = store.read();
    return !!rec && cfg.cats.every((c) => c.required || !rec.c[c.key]);
  };

  app.record = () => (cfg.preview ? null : store.read());
  app.widget = createWidget(root, cfg, widgetAllowed);
  app.ui = cfg.layout === 'bar' ? createBarUI(app) : createCardUI(app);
  app.closeUI = () => app.ui.close();

  // ---- Blocking: put allowed tags back, delete what is not allowed, tell other plugins.
  const block = cfg.block || { mode: 'observe', reload: false, purge: [] };
  const unblocker = createUnblocker(cfg);

  /** Apply a record (or null = nothing allowed) to the page. */
  function applyBlocking(rec) {
    try {
      if (block.mode === 'block') purge(cfg, rec);
      unblocker.run(rec).then(() => syncBridges(cfg, rec));
    } catch (e) {
      /* a blocking failure must never break the page */
    }
  }

  /** True when the new record allows less than the old one (some category or service was switched off). */
  function lostAccess(prev, next) {
    if (!prev) return false;
    return (
      cfg.cats.some((c) => !c.required && isAllowed(prev, c.key) && !isAllowed(next, c.key)) ||
      cfg.services.some((s) => isAllowed(prev, s.cat, s.slug) && !isAllowed(next, s.cat, s.slug))
    );
  }

  /** Scripts that already ran cannot be stopped: after a withdrawal reload the page so they do not keep writing. */
  function reloadIfNeeded(prev, next) {
    try {
      if (block.mode === 'block' && block.reload && lostAccess(prev, next) && unblocker.activated()) setTimeout(() => location.reload(), 300);
    } catch (e) {
      /* ignore */
    }
  }

  /** Source of a decision: anything made after reopening is "widget". */
  const srcFor = (fallback) => (app.reopened ? 'widget' : fallback);

  /** Persist, apply and log a selection, then update the UI. */
  function commit(sel, choice, fallbackSrc) {
    if (cfg.preview) return null;
    const prev = store.read();
    // Switching everything off after having allowed something is a withdrawal, not a plain rejection.
    if (choice === 'reject_all' && hadOptional(cfg, prev)) choice = 'withdraw';
    const src = srcFor(fallbackSrc);
    const c = {};
    cfg.cats.forEach((cat) => {
      if (!cat.required) c[cat.key] = sel[cat.key] ? 1 : 0;
    });
    const s = {};
    Object.keys(sel.services || {}).forEach((k) => (s[k] = sel.services[k] ? 1 : 0));
    const rec = { v: cfg.v, id: (prev && prev.id) || uid(), t: Math.floor(Date.now() / 1000), c, s, src };
    // analytics/marketing keys are always present for Consent Mode and the log
    if (!('analytics' in rec.c)) rec.c.analytics = 0;
    if (!('marketing' in rec.c)) rec.c.marketing = 0;

    const hadFocusInside = root.contains(document.activeElement);
    store.write(rec);
    applyConsent(cfg, rec);
    logChoice(cfg, rec, choice, src);
    app.reopened = false;
    app.ui.afterDecision(hadFocusInside);
    applyBlocking(rec);
    reloadIfNeeded(prev, rec);
    return rec;
  }

  app.accept = (fallbackSrc) => commit(uniformSelection(cfg, true), 'accept_all', fallbackSrc || 'banner');
  app.reject = (fallbackSrc) => commit(uniformSelection(cfg, false), 'reject_all', fallbackSrc || 'banner');
  app.save = () => {
    const sel = app.ui.selection();
    if (sel) commit(sel, computeChoice(cfg, sel), 'prefs');
  };

  /** Placeholder button on a blocked embed: allow that category (and that service) and keep every other choice. */
  app.enable = (slug, category) => {
    const prev = store.read();
    const cat = cfg.cats.filter((c) => c.key === category && !c.required)[0];
    if (!cat) return;
    const sel = { services: {} };
    cfg.cats.forEach((c) => {
      if (c.required) return;
      sel[c.key] = !!(prev && prev.c[c.key]);
      if (cfg.perService) c.services.forEach((sl) => (sel.services[sl] = isAllowed(prev, c.key, sl)));
    });
    sel[category] = true;
    if (cfg.perService && slug in sel.services) sel.services[slug] = true;
    commit(sel, computeChoice(cfg, sel), 'widget');
  };

  /** Open preferences or a policy tab; reopening with an existing choice marks later decisions as "widget". */
  app.openPrefs = (tab, trigger) => {
    app.reopened = !!app.record();
    return app.ui.openPrefs(tab, trigger);
  };
  app.openTab = (tab, trigger) => {
    app.reopened = !!app.record();
    return app.ui.openTab(tab, trigger);
  };

  // ---- Delegated clicks inside the UI
  root.addEventListener('click', (e) => {
    try {
      const el = e.target.closest('[data-dscc-action]');
      if (!el) return;
      const action = el.getAttribute('data-dscc-action');
      const inPrefs = !!el.closest('[data-dscc-view="prefs"]') || !!el.closest('.dscc-modal');
      if (action === 'accept') app.accept(inPrefs ? 'prefs' : 'banner');
      else if (action === 'reject') app.reject(inPrefs ? 'prefs' : 'banner');
      else if (action === 'customize') app.ui.customize(el);
      else if (action === 'save') app.save();
      else if (action === 'close') app.ui.close();
      else if (action === 'open-prefs') app.openPrefs('prefs', el);
      else if (action === 'open-tab') {
        e.preventDefault();
        app.openTab(el.getAttribute('data-dscc-tab') || 'cookies', el);
      } else if (action === 'toggle-details' || action === 'toggle-cookies') {
        const box = document.getElementById(el.getAttribute('aria-controls'));
        const expand = el.getAttribute('aria-expanded') !== 'true';
        el.setAttribute('aria-expanded', expand ? 'true' : 'false');
        if (box) box.hidden = !expand;
      }
    } catch (err) {
      /* never break the page */
    }
  });

  // ---- Placeholder buttons of blocked embeds (they sit in the page, outside the banner root)
  document.addEventListener('click', (e) => {
    try {
      const b = e.target.closest && e.target.closest('[data-dscc-enable]');
      if (!b) return;
      e.preventDefault();
      app.enable(b.getAttribute('data-dscc-enable'), b.getAttribute('data-dscc-enable-cat'));
    } catch (err) {
      /* ignore */
    }
  });

  // ---- External openers (menu link, shortcode, data attribute)
  document.addEventListener('click', (e) => {
    try {
      const t = e.target.closest && e.target.closest('[data-dscc-open], a[href$="#cai-dat-cookie"], a[href$="#cookie-settings"]');
      if (!t) return;
      e.preventDefault();
      app.openPrefs('prefs', t);
    } catch (err) {
      /* ignore */
    }
  });

  // ---- Esc: close the popup, or the card when it was reopened with a saved choice
  document.addEventListener('keydown', (e) => {
    try {
      if (e.key !== 'Escape') return;
      if (cfg.layout === 'bar') {
        if (app.ui.isOpen()) app.ui.close();
      } else if (app.ui.modal.isOpen() || app.ui.canEscape()) {
        app.ui.close();
      }
    } catch (err) {
      /* ignore */
    }
  });

  // ---- Public API
  const api = {
    open(tab) {
      app.openPrefs(tab || 'prefs', document.activeElement);
    },
    get() {
      const r = store.read();
      if (!r) return null;
      const services = {};
      Object.keys(r.s).forEach((k) => (services[k] = !!r.s[k]));
      return { analytics: !!r.c.analytics, marketing: !!r.c.marketing, services };
    },
    has(name) {
      if (name === 'necessary') return true;
      const r = store.read();
      if (!r) return false;
      if (name in r.c) return !!r.c[name];
      if (name in r.s) return !!r.s[name];
      // A service without its own switch follows its category.
      const svc = cfg.services.filter((x) => x.slug === name)[0];
      return !!svc && (svc.cat === 'necessary' || !!r.c[svc.cat]);
    },
    reset() {
      const prev = store.read();
      store.clear();
      if (prev) {
        const off = { v: prev.v, id: prev.id, t: Math.floor(Date.now() / 1000), c: { analytics: 0, marketing: 0 }, s: {}, src: 'api' };
        applyConsent(cfg, off);
        logChoice(cfg, off, 'withdraw', 'api');
      }
      applyBlocking(null);
      reloadIfNeeded(prev, null);
      app.reopened = false;
      app.widget.hide();
      app.ui.reset();
    },
  };
  window.DSCC = api;

  // ---- Initial state
  root.hidden = false;
  if (cfg.preview) {
    initPreviewBridge(app);
    app.ui.previewView(boot.view || 'banner');
    return;
  }
  const stored = store.read();
  if (stored) {
    restoreConsentMode(stored);
    app.widget.show(false);
  } else {
    app.ui.showInitial();
  }
  applyBlocking(stored);
  startBeacon(cfg, store.read);
}

function start() {
  try {
    init();
  } catch (e) {
    /* a plugin failure must leave the site working */
  }
}

if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', start);
else start();
