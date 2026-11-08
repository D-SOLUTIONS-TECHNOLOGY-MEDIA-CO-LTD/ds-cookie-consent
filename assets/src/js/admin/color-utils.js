/** JS port of src/Settings/ColorUtils.php (kept in step by hand; formulas are tiny). */

export function normalizeHex(v) {
  if (typeof v !== 'string') return null;
  v = v.trim().toLowerCase();
  let m = v.match(/^#([0-9a-f]{3})$/);
  if (m) return '#' + m[1].split('').map((c) => c + c).join('');
  m = v.match(/^#[0-9a-f]{6}$/);
  return m ? v : null;
}

function rgb(hex) {
  return [1, 3, 5].map((i) => parseInt(hex.slice(i, i + 2), 16));
}

function toHex(ch) {
  return '#' + ch.map((c) => Math.max(0, Math.min(255, Math.round(c))).toString(16).padStart(2, '0')).join('');
}

export function luminance(hex) {
  const l = rgb(hex).map((c) => {
    c /= 255;
    return c <= 0.03928 ? c / 12.92 : Math.pow((c + 0.055) / 1.055, 2.4);
  });
  return 0.2126 * l[0] + 0.7152 * l[1] + 0.0722 * l[2];
}

export function contrast(a, b) {
  const la = luminance(a);
  const lb = luminance(b);
  return (Math.max(la, lb) + 0.05) / (Math.min(la, lb) + 0.05);
}

export function darken(hex, amount) {
  return toHex(rgb(hex).map((c) => c * (1 - amount)));
}

export function tintOnWhite(hex, amount) {
  return toHex(rgb(hex).map((c) => c * amount + 255 * (1 - amount)));
}
