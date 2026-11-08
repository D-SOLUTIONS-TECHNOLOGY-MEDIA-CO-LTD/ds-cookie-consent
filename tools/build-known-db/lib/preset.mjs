// Builds presets/d-solutions-standard.json (dscc-export format) from the known-cookie database plus the preset definition.
// Field names follow the data model: services (category_key, ...) and cookies (service_slug, name_is_pattern, ...).

export function buildPreset(definition, knownDb) {
  const bySlug = new Map(knownDb.services.map((s) => [s.slug, s]));
  const services = [];
  const cookies = [];

  for (const entry of definition.services) {
    const known = bySlug.get(entry.slug);
    if (!known && !entry.definition) throw new Error(`preset service "${entry.slug}" is not in known-cookies.json and has no inline definition`);
    const base = known || { ...entry.definition, slug: entry.slug, script_patterns: { hosts: [], src_regex: [], inline_regex: [] }, cookies: [] };
    const source = entry.source || 'known_db';

    services.push({
      slug: base.slug,
      name: entry.name || base.name,
      category_key: base.category,
      provider_company: base.provider_company,
      privacy_url: base.privacy_url,
      is_third_party: base.is_third_party,
      transfers_abroad: base.country !== 'VN',
      country: base.country,
      script_patterns: base.script_patterns,
      status: entry.status,
      source,
    });

    for (const ref of entry.cookies) {
      // Inline cookie definitions win; otherwise the known-db record is the source of truth.
      const c = { ...(base.cookies.find((k) => k.name === ref.name && (ref.storage || k.storage) === k.storage) || {}), ...ref };
      if (!c.purpose_vi || !c.duration_vi) throw new Error(`preset cookie "${ref.name}" of "${entry.slug}" not found in known-cookies.json`);
      cookies.push({
        service_slug: base.slug,
        name: c.name,
        name_is_pattern: c.name.includes('*'),
        storage: c.storage || 'cookie',
        domain: ref.domain || c.domain_hint || '',
        path: '/',
        duration_label: c.duration_vi,
        duration_label_en: c.duration_en,
        duration_seconds: c.duration_seconds === undefined ? null : c.duration_seconds,
        purpose: c.purpose_vi,
        purpose_en: c.purpose_en,
        http_only: false,
        set_by: 'unknown',
        status: ref.status,
        source,
        table_group: c.table_group || '',
        applies_to: c.applies_to || '',
        applies_to_en: c.applies_to_en || c.applies_to || '',
      });
    }
  }

  // row_order ("slug|cookie name") lists the cookies whose rows must come first, in policy order; the rest keep their
  // natural order. The table shortcode orders rows by cookie id, so this is the order of the printed table.
  const rank = new Map((definition.row_order || []).map((id, i) => [id, i]));
  const key = (c) => `${c.service_slug}|${c.name}`;
  for (const id of rank.keys()) if (!cookies.some((c) => key(c) === id)) throw new Error(`row_order entry "${id}" is not a preset cookie`);
  const ordered = [...cookies.filter((c) => rank.has(key(c))).sort((a, b) => rank.get(key(a)) - rank.get(key(b))), ...cookies.filter((c) => !rank.has(key(c)))];

  return {
    format: 'dscc-export',
    version: 1,
    exported_at: definition.exported_at,
    preset: definition.preset,
    settings: definition.settings,
    texts: { vi: {}, en: {} },
    services,
    cookies: ordered,
  };
}
