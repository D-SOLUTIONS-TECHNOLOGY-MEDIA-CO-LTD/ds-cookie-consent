/**
 * Moves focus back after a popup or card closes: the element that opened it when it is still
 * connected and visible, otherwise the next candidate (usually the shield widget), otherwise nothing.
 */

function usable(node) {
  try {
    if (!node || node === document.body || node === document.documentElement) return false;
    if (!node.isConnected || typeof node.focus !== 'function' || node.disabled) return false;
    const cs = getComputedStyle(node);
    if (cs.visibility === 'hidden' || cs.display === 'none') return false;
    return node.getClientRects().length > 0;
  } catch (e) {
    return false;
  }
}

/**
 * Focus the first usable candidate.
 * @param {Array<Element|null>} candidates in order of preference
 * @returns {Element|null} the focused element or null when nothing could take focus
 */
export function restoreFocus(candidates) {
  for (let i = 0; i < candidates.length; i++) {
    const node = candidates[i];
    if (usable(node)) {
      try {
        node.focus();
        return node;
      } catch (e) {
        /* try the next candidate */
      }
    }
  }
  return null;
}
