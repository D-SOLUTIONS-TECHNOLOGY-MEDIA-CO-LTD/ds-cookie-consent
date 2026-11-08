/**
 * Admin live preview: applies unsaved CSS variables, texts and logos sent by the settings page.
 * Only active inside the preview iframe (cfg.preview). Same-origin messages only.
 */

/** Rebuild a message node: plain text with {policy_link} swapped for the existing link element. */
function setMessage(el, text, linkLabel) {
  const link = el.querySelector('a');
  const parts = String(text).split('{policy_link}');
  while (el.firstChild) el.removeChild(el.firstChild);
  parts.forEach((part, i) => {
    el.appendChild(document.createTextNode(part));
    if (i < parts.length - 1 && link) {
      if (linkLabel) link.textContent = linkLabel;
      el.appendChild(link);
    }
  });
}

function applyTexts(root, texts) {
  Object.keys(texts).forEach((key) => {
    root.querySelectorAll('[data-dscc-t="' + key + '"]').forEach((n) => {
      n.textContent = texts[key];
    });
    root.querySelectorAll('[data-dscc-t-html="' + key + '"]').forEach((n) => setMessage(n, texts[key], texts.policy_link));
  });
  const widget = root.querySelector('.dscc-widget');
  if (widget && texts.widget_label) widget.setAttribute('aria-label', texts.widget_label);
}

function applyLogos(root, logos) {
  [['.dscc-bar__logo', logos.banner], ['.dscc-head__logo', logos.popup]].forEach((pair) => {
    root.querySelectorAll(pair[0]).forEach((img) => {
      if (pair[1]) {
        img.src = pair[1];
        img.hidden = false;
      } else {
        img.hidden = true;
      }
    });
  });
}

/** Root attributes the settings page may change live; anything else in a message is ignored. */
const ALLOWED_ATTRS = ['data-card-pos', 'data-widget-side'];

export function initPreviewBridge(app) {
  window.addEventListener('message', (e) => {
    try {
      if (e.origin !== location.origin || !e.data || typeof e.data !== 'object') return;
      const d = e.data;
      if (d.type === 'dscc-preview:update') {
        if (d.vars) Object.keys(d.vars).forEach((k) => k.indexOf('--dscc-') === 0 && app.root.style.setProperty(k, d.vars[k]));
        if (d.attrs) {
          Object.keys(d.attrs).forEach((k) => {
            if (ALLOWED_ATTRS.indexOf(k) >= 0 && /^[a-z_]{1,20}$/.test(String(d.attrs[k]))) app.root.setAttribute(k, String(d.attrs[k]));
          });
        }
        if (d.texts) applyTexts(app.root, d.texts[app.cfg.lang] || d.texts);
        if (d.logos) applyLogos(app.root, d.logos);
      } else if (d.type === 'dscc-preview:view') {
        app.ui.previewView(d.view);
      }
    } catch (err) {
      /* ignore malformed preview messages */
    }
  });
  try {
    if (window.parent !== window) window.parent.postMessage({ type: 'dscc-preview:ready' }, location.origin);
  } catch (e) {
    /* ignore */
  }
}
