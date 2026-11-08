/**
 * Shield widget: visibility rules plus avoidance of third-party floating elements
 * (hide while the banner is open, raise above them afterwards).
 */

export function createWidget(root, cfg, allow) {
  const el = root.querySelector('.dscc-widget');
  const baseBottom = root.style.getPropertyValue('--dscc-widget-bottom') || '20px';
  let wanted = false;
  let timers = [];

  /** Hide configured selectors while the banner is open (one rule per selector so a bad one cannot break the rest). */
  function installHideRules() {
    try {
      if (!cfg.hide.length) return;
      const style = document.createElement('style');
      style.setAttribute('data-dscc-ignore', '');
      document.head.appendChild(style);
      cfg.hide.forEach((sel) => {
        try {
          style.sheet.insertRule('html.dscc-open ' + sel + '{visibility:hidden!important}', style.sheet.cssRules.length);
        } catch (e) {
          /* invalid selector: skip */
        }
      });
    } catch (e) {
      /* ignore */
    }
  }

  /** Raise the widget above a configured element that sits in the same corner. */
  function avoid() {
    try {
      if (!el || !cfg.raise || !cfg.hide.length || el.hidden) return;
      const right = root.getAttribute('data-widget-side') === 'right';
      const x = parseInt(getComputedStyle(root).getPropertyValue('--dscc-widget-x'), 10) || 20;
      const bottom = parseInt(baseBottom, 10) || 20;
      const zone = x + 42 + 8;
      let raiseTo = null;
      cfg.hide.forEach((sel) => {
        let node = null;
        try {
          node = document.querySelector(sel);
        } catch (e) {
          return;
        }
        if (!node || root.contains(node)) return;
        const r = node.getBoundingClientRect();
        const cs = getComputedStyle(node);
        if (!r.width || !r.height || cs.visibility === 'hidden' || cs.display === 'none') return;
        const sameSide = right ? r.right > window.innerWidth - zone : r.left < zone;
        const overlaps = r.bottom > window.innerHeight - bottom - 40 && r.top < window.innerHeight;
        if (sameSide && overlaps) {
          const need = window.innerHeight - r.top + 12;
          raiseTo = raiseTo === null ? need : Math.max(raiseTo, need);
        }
      });
      root.style.setProperty('--dscc-widget-bottom', raiseTo === null ? baseBottom : raiseTo + 'px');
    } catch (e) {
      /* ignore */
    }
  }

  function scheduleAvoid() {
    timers.forEach(clearTimeout);
    timers = [0, 800, 2500].map((ms) => setTimeout(avoid, ms)); // chat widgets often load late
  }

  window.addEventListener('resize', () => {
    if (wanted) avoid();
  });
  installHideRules();

  return {
    el,
    isVisible: () => !!el && !el.hidden,
    /** Show the widget (with a small pop animation when asked). */
    show(pop) {
      if (!el || !cfg.widget.enabled || (allow && !allow())) return;
      wanted = true;
      el.hidden = false;
      if (pop) {
        el.classList.remove('is-pop');
        void el.offsetWidth; // restart the animation
        el.classList.add('is-pop');
      }
      scheduleAvoid();
    },
    hide() {
      wanted = false;
      if (el) el.hidden = true;
    },
  };
}
