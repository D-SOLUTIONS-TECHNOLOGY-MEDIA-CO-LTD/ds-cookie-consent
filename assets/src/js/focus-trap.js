/**
 * Stack of focus traps: only the top-most container wraps Tab / Shift+Tab.
 */

const stack = [];
let bound = false;

const FOCUSABLE = 'a[href],button:not([disabled]),input:not([disabled]),select,textarea,[tabindex]:not([tabindex="-1"])';

function focusables(el) {
  return Array.prototype.filter.call(el.querySelectorAll(FOCUSABLE), (n) => n.offsetParent !== null && !n.closest('[hidden]'));
}

function onKeydown(e) {
  if (e.key !== 'Tab' || !stack.length) return;
  try {
    const el = stack[stack.length - 1];
    const f = focusables(el);
    if (!f.length) {
      e.preventDefault();
      return;
    }
    const first = f[0];
    const last = f[f.length - 1];
    const active = document.activeElement;
    if (!el.contains(active)) {
      first.focus();
      e.preventDefault();
    } else if (e.shiftKey && active === first) {
      last.focus();
      e.preventDefault();
    } else if (!e.shiftKey && active === last) {
      first.focus();
      e.preventDefault();
    }
  } catch (err) {
    /* ignore */
  }
}

/**
 * Activate a trap on a container; returns a release function.
 * Idempotent: trapping a container that is already on the stack does not add a second entry, and
 * release removes every occurrence, so a repeated open can never leave Tab blocked after closing.
 */
export function trapFocus(el) {
  if (!bound) {
    document.addEventListener('keydown', onKeydown, true);
    bound = true;
  }
  if (stack.indexOf(el) < 0) stack.push(el);
  return function release() {
    let i = stack.indexOf(el);
    while (i >= 0) {
      stack.splice(i, 1);
      i = stack.indexOf(el);
    }
  };
}
