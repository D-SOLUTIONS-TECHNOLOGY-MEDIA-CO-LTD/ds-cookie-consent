#!/usr/bin/env bash
# Installs ds-cookie-consent.zip into the wp-env TESTS site (port 8889) under the folder name ds-cookie-consent, activates
# it, checks that it works, then puts the site back: the development copy (folder ds_cookie_consent, mounted from this
# repository) is deactivated while the zip copy is tested because both define the same constants, and is activated again
# at the end. When the site had no plugin tables before the check (the usual state of the tests site), the zip copy's own
# uninstall routine runs at the end with "delete data" switched on, so the tables, options and cron events that the
# activation created are removed again; otherwise the data is left alone and only the folder is removed.
#
# Usage: bash tools/release/check-zip-activation.sh [path-to-zip]     (default: ./ds-cookie-consent.zip)
# Needs the wp-env containers to be running; it never starts a second instance.
set -euo pipefail
cd "$(dirname "$0")/../.."

ZIP=${1:-ds-cookie-consent.zip}
[ -f "$ZIP" ] || { echo "check-zip-activation: $ZIP not found (run npm run release:zip first)" >&2; exit 1; }

# The repository is mounted at wp-content/plugins/<repo folder> in the containers; the zip must be inside it.
REPO_DIR=$(basename "$PWD")
case "$ZIP" in /*) echo "check-zip-activation: give a path inside the repository" >&2; exit 1 ;; esac
IN_CONTAINER="wp-content/plugins/$REPO_DIR/$ZIP"
DEV_SLUG=$REPO_DIR

wpt() { npx wp-env run tests-cli "$@" 2>&1 | grep -v -e '^ℹ' -e '^✔' -e '^$' || true; }

echo "==> state before"
BEFORE=$(wpt wp plugin list --fields=name,status --format=csv | sort)
echo "$BEFORE"

FRESH=0
if [ "$(wpt wp db query "SHOW TABLES LIKE '%dscc_%'" | grep -c dscc_ || true)" = "0" ]; then
  FRESH=1
fi

DEV_WAS_ACTIVE=0
if [ "$(wpt wp plugin get "$DEV_SLUG" --field=status)" = "active" ]; then
  DEV_WAS_ACTIVE=1
fi

restore() {
  echo "==> restoring the site"
  wpt wp plugin deactivate ds-cookie-consent >/dev/null || true
  if [ "$FRESH" = "1" ]; then
    wpt wp eval 'define( "WP_UNINSTALL_PLUGIN", "ds-cookie-consent/ds-cookie-consent.php" ); update_option( "dscc_settings", array( "advanced" => array( "delete_data_on_uninstall" => true ) ) ); require WP_PLUGIN_DIR . "/ds-cookie-consent/uninstall.php";' >/dev/null || true
  fi
  npx wp-env run tests-cli rm -rf wp-content/plugins/ds-cookie-consent >/dev/null 2>&1 || true
  if [ "$DEV_WAS_ACTIVE" = "1" ]; then
    wpt wp plugin activate "$DEV_SLUG" >/dev/null || true
  fi
  AFTER=$(wpt wp plugin list --fields=name,status --format=csv | sort)
  LEFT=$(wpt wp db query "SHOW TABLES LIKE '%dscc_%'" | grep -c dscc_ || true)
  if [ "$AFTER" = "$BEFORE" ] && { [ "$FRESH" = "0" ] || [ "$LEFT" = "0" ]; }; then
    echo "site restored: plugin list is identical to the one before and no plugin table was left behind"
  else
    echo "WARNING: plugin list differs from the one before:" >&2
    echo "$AFTER" >&2
  fi
}
trap restore EXIT

echo "==> installing the zip as ds-cookie-consent"
npx wp-env run tests-cli rm -rf wp-content/plugins/ds-cookie-consent >/dev/null 2>&1 || true
if [ "$DEV_WAS_ACTIVE" = "1" ]; then
  wpt wp plugin deactivate "$DEV_SLUG" >/dev/null
fi
wpt wp plugin install "$IN_CONTAINER" --activate

STATUS=$(wpt wp plugin get ds-cookie-consent --field=status)
VERSION=$(wpt wp plugin get ds-cookie-consent --field=version)
echo "status=$STATUS version=$VERSION"
[ "$STATUS" = "active" ] || { echo "check-zip-activation: the plugin did not activate" >&2; exit 1; }

echo "==> checks"
CHECK=$(wpt wp eval '
$ok = array();
$ok["dir"]      = DSCC_DIR === WP_PLUGIN_DIR . "/ds-cookie-consent/";
$ok["version"]  = DSCC_VERSION === get_plugin_data( DSCC_FILE )["Version"];
$ok["db"]       = \DSolutions\CookieConsent\Database\Schema::VERSION === get_option( "dscc_db_version" );
global $wpdb;
foreach ( array( "dscc_consent_log", "dscc_services", "dscc_cookies", "dscc_scan_runs" ) as $t ) {
	$ok["table_" . $t] = $wpdb->prefix . $t === $wpdb->get_var( $wpdb->prepare( "SHOW TABLES LIKE %s", $wpdb->prefix . $t ) );
}
$ok["cap"]      = get_role( "administrator" )->has_cap( "dscc_view_log" );
$ok["prefixed"] = is_readable( DSCC_DIR . "vendor-prefixed/autoload.php" );
$ok["vi_mo"]    = is_readable( DSCC_DIR . "languages/ds-cookie-consent-vi.mo" );
$ok["preset_file"] = is_readable( DSCC_DIR . "presets/d-solutions-standard.json" );
$ok["no_dev_files"] = ! file_exists( DSCC_DIR . "tests" ) && ! file_exists( DSCC_DIR . "reference" ) && ! file_exists( DSCC_DIR . "SPEC.md" ) && ! file_exists( DSCC_DIR . "tools" ) && ! file_exists( DSCC_DIR . "plans" );
ob_start();
do_action( "wp_footer" );
$ok["banner"] = false !== strpos( ob_get_clean(), "id=\"dscc\"" );
foreach ( $ok as $k => $v ) { echo "check:" . $k . "=" . ( $v ? "pass" : "fail" ) . "\n"; }
')
echo "$CHECK" | grep '^check:' || true
if [ "$(echo "$CHECK" | grep -c '^check:.*=pass$')" -lt 12 ] || echo "$CHECK" | grep -q '^check:.*=fail$'; then
  echo "check-zip-activation: at least one check failed" >&2
  exit 1
fi
echo "check-zip-activation: ok"
