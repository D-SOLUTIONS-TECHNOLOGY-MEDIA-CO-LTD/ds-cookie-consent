/**
 * Platform picker: client-side search and category filter over the platform cards, live "selected" styling.
 * The cards are server-rendered; this only hides/shows them.
 */

/** Lower-case and strip diacritics so "mạng xã hội" and "mang xa hoi" both match. */
function fold(text) {
  return String(text).toLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
}

export function initPlatformPicker() {
  const search = document.getElementById('dscc-platform-search');
  const category = document.getElementById('dscc-platform-category');
  const form = document.getElementById('dscc-platforms-form');
  if (!search || !category || !form) return;

  const cards = Array.prototype.slice.call(form.querySelectorAll('.dscc-platform'));
  const groups = Array.prototype.slice.call(form.querySelectorAll('.dscc-platform-group'));
  const empty = document.getElementById('dscc-platform-empty');
  const count = document.getElementById('dscc-platform-count');

  function apply() {
    const q = fold(search.value.trim());
    const c = category.value;
    let shown = 0;
    cards.forEach((card) => {
      const ok = (!c || card.getAttribute('data-dscc-category') === c) && (!q || fold(card.getAttribute('data-dscc-search')).indexOf(q) !== -1);
      card.hidden = !ok;
      if (ok) shown += 1;
    });
    groups.forEach((g) => {
      g.hidden = !g.querySelector('.dscc-platform:not([hidden])');
    });
    if (empty) empty.hidden = shown > 0;
    if (count) count.textContent = shown + ' / ' + cards.length;
  }

  search.addEventListener('input', apply);
  category.addEventListener('change', apply);
  // The search box must never submit the platform form.
  search.addEventListener('keydown', (e) => {
    if (e.key === 'Enter') e.preventDefault();
  });
  form.addEventListener('change', (e) => {
    const t = e.target;
    if (t && t.name === 'dscc_platform_on[]') t.closest('.dscc-platform').classList.toggle('is-on', t.checked);
  });
  apply();
}
