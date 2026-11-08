/**
 * Mirror of PHP LinkHelper::external_policy_link(): links to third-party platform policies
 * always open in a new tab with rel="nofollow noopener noreferrer".
 */
export const EXTERNAL_REL = 'nofollow noopener noreferrer';

/**
 * Build an anchor element (never via innerHTML). Returns null for non-http(s) URLs.
 */
export function externalPolicyLink(url, label) {
  try {
    if (!/^https?:\/\//i.test(String(url || ''))) return null;
    const a = document.createElement('a');
    a.href = url;
    a.target = '_blank';
    a.rel = EXTERNAL_REL;
    a.textContent = label;
    return a;
  } catch (e) {
    return null;
  }
}
