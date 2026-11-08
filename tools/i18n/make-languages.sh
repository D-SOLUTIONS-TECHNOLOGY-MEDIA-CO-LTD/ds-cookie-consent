#!/bin/sh
# Regenerate languages/ds-cookie-consent.pot, the Vietnamese .po and both .mo files.
# Needs the wp-env containers to be running: wp-cli (wp i18n) runs inside the "cli" container.
# Source strings are English; Vietnamese comes from tools/i18n/vi-translations.json.
set -e
cd "$(dirname "$0")/../.."
PLUGIN_DIR=wp-content/plugins/ds_cookie_consent

npx wp-env run cli --env-cwd="$PLUGIN_DIR" wp i18n make-pot . languages/ds-cookie-consent.pot \
  --slug=ds-cookie-consent --domain=ds-cookie-consent \
  --exclude=assets,bin,data,docs,node_modules,plans,presets,reference,tests,tools,vendor,vendor-prefixed,test-results

php tools/i18n/make-vi-po.php languages/ds-cookie-consent.pot languages/ds-cookie-consent-vi.po --strict

npx wp-env run cli --env-cwd="$PLUGIN_DIR" wp i18n make-mo languages
