/**
 * Settings screen behaviour: media pickers, colour pickers, live preview iframe, live contrast hints.
 * Admin-only, so jQuery (needed by wp-color-picker) is allowed here.
 */
import { darken, tintOnWhite, contrast, normalizeHex } from './color-utils.js';
import { initPlatformPicker } from './platform-picker.js';
import { initCookieQuickEdit } from './cookie-quick-edit.js';
import { initScanPanel } from './scan-panel.js';
import { initQuickSetup } from './quick-setup.js';

const cfg = window.dsccAdmin || {};
const form = document.getElementById('dscc-settings-form');

const field = (name) => (form ? form.querySelector('[data-dscc-field="appearance.' + name + '"]') : null);
const value = (name) => {
  const el = field(name);
  if (!el) return null;
  return el.type === 'checkbox' ? el.checked : el.value;
};

/* ------------------------------------------------------------ media pickers */
function initMedia() {
  document.querySelectorAll('[data-dscc-media]').forEach((box) => {
    const input = box.querySelector('input[type="hidden"]');
    const img = box.querySelector('.dscc-media__img');
    const clear = box.querySelector('[data-dscc-media-clear]');
    let frame = null;

    const set = (id, url) => {
      input.value = id || 0;
      input.setAttribute('data-dscc-media-url', url || '');
      if (url) {
        img.src = url;
        img.hidden = false;
      } else {
        img.removeAttribute('src');
        img.hidden = true;
      }
      clear.hidden = !url;
      input.dispatchEvent(new Event('change', { bubbles: true }));
    };

    box.querySelector('[data-dscc-media-pick]').addEventListener('click', () => {
      if (!window.wp || !window.wp.media) return;
      if (!frame) {
        frame = window.wp.media({ title: cfg.i18n.pickLogo, button: { text: cfg.i18n.useImage }, multiple: false });
        frame.on('select', () => {
          const a = frame.state().get('selection').first().toJSON();
          set(a.id, a.url);
        });
      }
      frame.open();
    });
    clear.addEventListener('click', () => set(0, ''));
  });
}

/* ------------------------------------------------------------ colour pickers */
function initColors() {
  const $ = window.jQuery;
  if (!$ || !$.fn.wpColorPicker) return;
  $('.dscc-color').wpColorPicker({
    change(event, ui) {
      event.target.value = ui.color.toString();
      event.target.dispatchEvent(new Event('input', { bubbles: true }));
    },
    clear() {
      const el = this;
      setTimeout(() => el.dispatchEvent(new Event('input', { bubbles: true })), 0);
    },
  });
}

/* ------------------------------------------------------------ live contrast */
function updateContrast() {
  const box = document.querySelector('[data-dscc-contrast-live]');
  if (!box) return;
  const msgs = [];
  const p = normalizeHex(value('color_primary'));
  const t = normalizeHex(value('color_text'));
  const b = normalizeHex(value('color_bar_bg'));
  if (p) {
    const r = contrast('#ffffff', p);
    if (r < 4.5) msgs.push(cfg.i18n.contrastPrimary.replace('%s', r.toFixed(2)));
  }
  if (t && b) {
    const r = contrast(t, b);
    if (r < 4.5) msgs.push(cfg.i18n.contrastText.replace('%s', r.toFixed(2)));
  }
  box.textContent = '';
  msgs.forEach((m) => {
    const d = document.createElement('div');
    d.className = 'notice notice-warning inline';
    const p2 = document.createElement('p');
    p2.textContent = m;
    d.appendChild(p2);
    box.appendChild(d);
  });
}

/* ------------------------------------------------------------ preview */
function initPreview() {
  const wrap = document.querySelector('[data-dscc-preview]');
  if (!wrap || !form) return;
  const frame = wrap.querySelector('[data-dscc-preview-frame]');
  const stage = wrap.querySelector('[data-dscc-preview-stage]');
  let view = 'banner';
  let ready = false;
  let reloadTimer = null;

  const savedLayout = () => (frame.dataset.layout || 'card');

  function collectVars() {
    const layout = value('layout') || savedLayout();
    const vars = {};
    const primary = normalizeHex(value('color_primary'));
    if (primary) {
      vars['--dscc-primary'] = primary;
      const hover = normalizeHex(value('color_primary_hover'));
      vars['--dscc-primary-hover'] = hover || darken(primary, 0.12);
      const reject = normalizeHex(value('color_reject_bg'));
      vars['--dscc-reject-bg'] = reject || tintOnWhite(primary, 0.12);
    }
    const map = { '--dscc-text': 'color_text', '--dscc-bar-bg': 'color_bar_bg', '--dscc-modal-bg': 'color_modal_bg' };
    Object.keys(map).forEach((k) => {
      const hex = normalizeHex(value(map[k]));
      if (hex) vars[k] = hex;
    });
    const radius = value('button_radius');
    if (radius !== null) {
      const r = String(radius).trim();
      vars['--dscc-radius'] = r === 'pill' || (r === '' && layout === 'bar') ? '999px' : r === '' ? '8px' : Math.max(0, Math.min(40, parseInt(r, 10) || 0)) + 'px';
    }
    const num = (name, lo, hi, def) => Math.max(lo, Math.min(hi, parseInt(value(name), 10) || def));
    if (field('overlay_opacity')) vars['--dscc-overlay'] = 'rgba(15,23,42,' + num('overlay_opacity', 0, 60, 35) / 100 + ')';
    if (field('widget_offset_x')) vars['--dscc-widget-x'] = num('widget_offset_x', 0, 400, 20) + 'px';
    if (field('widget_bottom')) vars['--dscc-widget-bottom'] = num('widget_bottom', 0, 400, 20) + 'px';
    if (field('logo_height')) vars['--dscc-logo-h'] = num('logo_height', 12, 120, 30) + 'px';
    if (field('logo_popup_height')) vars['--dscc-logo-popup-h'] = num('logo_popup_height', 12, 120, 22) + 'px';
    if (field('font')) vars['--dscc-font'] = String(value('font') || 'inherit').replace(/[^A-Za-z0-9 ,\-_'"]/g, '') || 'inherit';
    return vars;
  }

  function collectTexts() {
    const out = { vi: {}, en: {} };
    form.querySelectorAll('[data-dscc-text]').forEach((ta) => {
      const parts = ta.getAttribute('data-dscc-text').split('.');
      const text = (ta.value.trim() || ta.getAttribute('placeholder') || '').split('{site}').join(cfg.siteName || '');
      if (out[parts[0]]) out[parts[0]][parts[1]] = text;
    });
    const label = value('widget_label');
    if (label) {
      out.vi.widget_label = label;
      out.en.widget_label = label;
    }
    return out;
  }

  function collectLogos() {
    const url = (name) => {
      const el = field(name);
      return el ? el.getAttribute('data-dscc-media-url') || '' : null;
    };
    const banner = url('logo_id');
    const popup = url('logo_popup_id');
    if (banner === null) return null;
    return { banner, popup: popup || banner };
  }

  function post(msg) {
    try {
      if (ready && frame.contentWindow) frame.contentWindow.postMessage(msg, location.origin);
    } catch (e) {
      /* ignore */
    }
  }

  function sendUpdate() {
    const msg = { type: 'dscc-preview:update', vars: collectVars(), attrs: {}, texts: collectTexts() };
    if (field('card_position')) msg.attrs['data-card-pos'] = value('card_position');
    if (field('widget_side')) msg.attrs['data-widget-side'] = value('widget_side');
    const logos = collectLogos();
    if (logos) msg.logos = logos;
    post(msg);
  }

  /** Fields that change the server rendered structure reload the iframe; the rest update instantly. */
  const STRUCTURAL = ['layout', 'button_order', 'customize_style', 'widget_enabled', 'per_service_toggle', 'widget_show'];

  function load() {
    ready = false;
    const url = new URL(cfg.previewBase, location.href);
    url.searchParams.set('view', view);
    const layout = value('layout');
    if (layout) url.searchParams.set('layout', layout);
    STRUCTURAL.forEach((k) => {
      const v = value(k);
      if (v !== null && k !== 'layout') url.searchParams.set('ov[' + k + ']', v === true ? '1' : v === false ? '0' : v);
    });
    frame.dataset.layout = layout || '';
    frame.src = url.toString();
  }

  window.addEventListener('message', (e) => {
    if (e.origin !== location.origin || !e.data || e.data.type !== 'dscc-preview:ready') return;
    if (e.source !== frame.contentWindow) return;
    ready = true;
    sendUpdate();
  });

  form.addEventListener('input', (e) => {
    const name = e.target.getAttribute('data-dscc-field') || '';
    updateContrast();
    sendUpdate();
    if (STRUCTURAL.some((k) => name === 'appearance.' + k)) {
      clearTimeout(reloadTimer);
      reloadTimer = setTimeout(load, 250);
    }
  });
  form.addEventListener('change', (e) => {
    const name = e.target.getAttribute('data-dscc-field') || '';
    sendUpdate();
    if (STRUCTURAL.some((k) => name === 'appearance.' + k)) {
      clearTimeout(reloadTimer);
      reloadTimer = setTimeout(load, 250);
    }
  });

  wrap.querySelectorAll('[data-dscc-preview-view]').forEach((btn) => {
    btn.addEventListener('click', () => {
      view = btn.getAttribute('data-dscc-preview-view');
      wrap.querySelectorAll('[data-dscc-preview-view]').forEach((b) => b.classList.toggle('is-active', b === btn));
      post({ type: 'dscc-preview:view', view });
    });
  });
  wrap.querySelectorAll('[data-dscc-preview-device]').forEach((btn) => {
    btn.addEventListener('click', () => {
      const mobile = btn.getAttribute('data-dscc-preview-device') === 'mobile';
      stage.classList.toggle('is-mobile', mobile);
      wrap.querySelectorAll('[data-dscc-preview-device]').forEach((b) => b.classList.toggle('is-active', b === btn));
    });
  });

  load();
}

document.addEventListener('DOMContentLoaded', () => {
  try {
    initMedia();
    initColors();
    updateContrast();
    initPreview();
  } catch (e) {
    /* the form still works without the enhancements */
  }
  try {
    initPlatformPicker();
    initCookieQuickEdit();
    initScanPanel();
    initQuickSetup();
  } catch (e) {
    /* the tabs still work as plain forms */
  }
});
