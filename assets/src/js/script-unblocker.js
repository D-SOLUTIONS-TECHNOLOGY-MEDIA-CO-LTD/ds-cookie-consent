/**
 * Puts blocked tags back when the visitor allows them.
 *
 * Blocked markup (written by the server, identical for every visitor):
 *   <script type="text/plain" data-dscc-category data-dscc-service [data-dscc-src] [data-dscc-type]>
 *   <iframe data-dscc-src hidden data-dscc-category data-dscc-service> preceded by .dscc-placeholder
 *   <img data-dscc-src data-dscc-category data-dscc-service>   (tracking pixel)
 *
 * Scripts are recreated in document order. A classic external script holds the queue until it loaded (or failed, or
 * 8 s passed) so the inline code after it can use it; async scripts do not hold it. Everything is try/catch: a
 * single broken tag must not stop the others.
 */
import { isAllowed } from './consent-check.js';

const BLOCKED = 'script[type="text/plain"][data-dscc-category], iframe[data-dscc-src][data-dscc-category], img[data-dscc-src][data-dscc-category]';
const OWN_ATTRS = ['type', 'data-dscc-src', 'data-dscc-category', 'data-dscc-service', 'data-dscc-type', 'data-no-optimize'];
const MARKER = /^\/\*data-dscc\*\//;
const LOAD_TIMEOUT = 8000;

function fire(name, detail) {
  try {
    document.dispatchEvent(new CustomEvent(name, { detail }));
  } catch (e) {
    /* ignore */
  }
}

/** Recreate one script element; resolves when the next one may run. */
function revive(el) {
  return new Promise((resolve) => {
    try {
      const s = document.createElement('script');
      Array.prototype.forEach.call(el.attributes, (a) => {
        if (OWN_ATTRS.indexOf(a.name) === -1) s.setAttribute(a.name, a.value);
      });
      if (el.nonce) s.nonce = el.nonce;
      const type = el.getAttribute('data-dscc-type');
      if (type) s.type = type;
      const src = el.getAttribute('data-dscc-src');
      const parent = el.parentNode;
      if (!parent) return resolve();
      if (src) {
        const isAsync = el.hasAttribute('async');
        s.async = isAsync; // scripts added from code are async unless told otherwise: keep the page's own order
        let timer = 0;
        const done = () => {
          clearTimeout(timer);
          resolve();
        };
        s.onload = done;
        s.onerror = done;
        timer = setTimeout(done, LOAD_TIMEOUT);
        s.src = src;
        parent.insertBefore(s, el);
        parent.removeChild(el);
        if (isAsync) done();
      } else {
        s.text = (el.textContent || '').replace(MARKER, '');
        parent.insertBefore(s, el);
        parent.removeChild(el);
        resolve();
      }
    } catch (e) {
      resolve();
    }
  });
}

/** Give an iframe or pixel its address back and drop its placeholder. */
function restore(el) {
  try {
    const src = el.getAttribute('data-dscc-src');
    const prev = el.previousElementSibling;
    if (prev && prev.classList && prev.classList.contains('dscc-placeholder')) prev.parentNode.removeChild(prev);
    el.removeAttribute('data-dscc-src');
    el.removeAttribute('hidden');
    el.removeAttribute('data-dscc-category');
    if (src) el.setAttribute('src', src);
  } catch (e) {
    /* ignore */
  }
}

export function createUnblocker(cfg) {
  const fired = {}; // services whose "service-enabled" event already went out
  let chain = Promise.resolve();
  let activated = false;

  async function process(rec) {
    const touched = {};
    const items = Array.prototype.slice.call(document.querySelectorAll(BLOCKED));
    for (let i = 0; i < items.length; i++) {
      const el = items[i];
      try {
        const slug = el.getAttribute('data-dscc-service') || '';
        if (!isAllowed(rec, el.getAttribute('data-dscc-category'), slug)) continue;
        touched[slug] = true;
        activated = true;
        if (el.tagName === 'SCRIPT') await revive(el);
        else restore(el);
      } catch (e) {
        /* keep going with the next tag */
      }
    }
    // Services newly allowed (with or without a tag on this page) and services that lost access.
    const cats = {};
    (cfg.services || []).forEach((svc) => {
      cats[svc.slug] = svc.cat;
      if (isAllowed(rec, svc.cat, svc.slug)) touched[svc.slug] = true;
    });
    Object.keys(fired).forEach((slug) => {
      if (slug in cats && !isAllowed(rec, cats[slug], slug)) delete fired[slug];
    });
    Object.keys(touched).forEach((slug) => {
      if (slug && !fired[slug]) {
        fired[slug] = true;
        fire('dscc:service-enabled', { service: slug });
      }
    });
  }

  return {
    /** Activate everything the record allows. Runs are queued, never overlap. Resolves when this run finished. */
    run(rec) {
      chain = chain.then(() => process(rec)).catch(() => {});
      return chain;
    },
    /** Put iframes (videos, maps) back without touching scripts or pixels; used for visitors who cannot be asked. */
    frames() {
      Array.prototype.forEach.call(document.querySelectorAll('iframe[data-dscc-src][data-dscc-category]'), restore);
    },
    /** True once at least one blocked tag was switched on in this page view. */
    activated() {
      return activated;
    },
  };
}
