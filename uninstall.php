<?php
/**
 * Uninstall: always remove options; drop the log and catalog tables only when the admin opted in.
 *
 * @package DSolutions\CookieConsent
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$dscc_settings = get_option( 'dscc_settings' );
$dscc_purge    = is_array( $dscc_settings ) && ! empty( $dscc_settings['advanced']['delete_data_on_uninstall'] );

foreach ( array( 'dscc_settings', 'dscc_texts_vi', 'dscc_texts_en', 'dscc_db_version', 'dscc_last_scan', 'dscc_notices', 'dscc_resolved', 'dscc_blocked_daily', 'dscc_frontend_catalog', 'dscc_known_db_meta', 'dscc_block_rules', 'dscc_blocker_perf', 'dscc_blocker_failopen', 'dscc_observed', 'dscc_alerts', 'dscc_scan_lock', 'dscc_scan_mail_at', 'dscc_db_upgrade_lock' ) as $dscc_option ) {
	delete_option( $dscc_option );
}

wp_clear_scheduled_hook( 'dscc_cleanup_log' );
wp_clear_scheduled_hook( 'dscc_scan_weekly' );
wp_unschedule_hook( 'dscc_scan_tick' );
delete_transient( 'dscc_new_cookies' );
delete_transient( 'dscc_beacon_new' );
delete_site_transient( 'dscc_gh_release' );

// The downloaded known-cookie database is a cache of remote data; it goes with the plugin.
$dscc_uploads = wp_upload_dir( null, false );
wp_delete_file( trailingslashit( $dscc_uploads['basedir'] ) . 'dscc/known-cookies.json' );

global $wpdb;

// Rate-limit buckets and stored import previews are transients; remove them together with their timeouts.
// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- one-off cleanup of this plugin's own transients.
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_dscc\_rl\_%' OR option_name LIKE '\_transient\_timeout\_dscc\_rl\_%' OR option_name LIKE '\_transient\_dscc\_import\_%' OR option_name LIKE '\_transient\_timeout\_dscc\_import\_%'" );

// The log capability is removed whether or not the log data is kept.
$dscc_roles = wp_roles();
foreach ( array_keys( $dscc_roles->roles ) as $dscc_role_name ) {
	$dscc_roles->remove_cap( $dscc_role_name, 'dscc_view_log' );
}

if ( $dscc_purge ) {
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table name is built from the trusted prefix.
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'dscc_consent_log' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names are built from the trusted prefix.
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'dscc_cookies' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names are built from the trusted prefix.
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'dscc_services' );
	// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- table names are built from the trusted prefix.
	$wpdb->query( 'DROP TABLE IF EXISTS ' . $wpdb->prefix . 'dscc_scan_runs' );
}
