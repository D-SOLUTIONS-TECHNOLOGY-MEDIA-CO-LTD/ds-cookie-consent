<?php
/**
 * E2E fixture (wp eval-file): puts the dev site into a known state.
 * Usage: wp eval-file <path> <layout> [key=value ...]
 * Extra pairs patch settings as group.key=value, e.g. advanced.skip_ua_patterns=LittleD-App
 * The special pair site.locale=<locale> sets the language of the admin user (default vi: the admin texts are checked in
 * Vietnamese, which proves the Vietnamese catalog restores the previous admin text; site.locale=en_US checks the
 * English source). The site language itself stays as it is, so visitors are not affected.
 */

global $wpdb;

// The catalog tables arrive with the DB upgrade (normally run on admin_init).
\DSolutions\CookieConsent\Activator::install();

$layout = isset( $args[0] ) ? $args[0] : 'card';

$settings = \DSolutions\CookieConsent\Settings\SettingsSchema::defaults();
$settings['appearance']['layout']         = $layout;
$settings['advanced']['policy_version']   = '2026-11';

$locale = 'vi';
foreach ( array_slice( $args, 1 ) as $pair ) {
	list( $path, $value ) = explode( '=', $pair, 2 );
	if ( 'site.locale' === $path ) {
		$locale = $value;
		continue;
	}
	list( $group, $key )  = explode( '.', $path, 2 );
	if ( in_array( $key, array( 'skip_ua_patterns', 'hide_selectors', 'exclusions', 'keep_cookies' ), true ) ) {
		$settings[ $group ][ $key ] = array_filter( explode( '|', $value ) );
	} elseif ( in_array( $value, array( '0', '1' ), true ) && is_bool( $settings[ $group ][ $key ] ) ) {
		$settings[ $group ][ $key ] = '1' === $value;
	} elseif ( is_int( $settings[ $group ][ $key ] ) ) {
		$settings[ $group ][ $key ] = (int) $value;
	} else {
		$settings[ $group ][ $key ] = $value;
	}
}

update_option( 'dscc_settings', $settings, true );
delete_option( 'dscc_texts_vi' );
delete_option( 'dscc_texts_en' );
delete_option( 'dscc_blocked_daily' );
delete_option( 'dscc_resolved' );
delete_option( 'dscc_frontend_catalog' );
delete_option( 'dscc_block_rules' );
delete_option( 'dscc_blocker_perf' );
delete_option( 'dscc_observed' );
delete_option( 'dscc_known_db_meta' );
delete_option( 'dscc_last_scan' );
delete_option( 'dscc_alerts' );
delete_option( 'dscc_scan_lock' );
delete_option( 'dscc_scan_mail_at' );
delete_transient( 'dscc_beacon_new' );
wp_unschedule_hook( 'dscc_scan_tick' );
wp_clear_scheduled_hook( 'dscc_scan_weekly' );
delete_transient( 'dscc_new_cookies' );

// Empty the log and the rate-limit buckets.
$wpdb->query( 'TRUNCATE TABLE ' . $wpdb->prefix . 'dscc_consent_log' ); // phpcs:ignore
$wpdb->query( 'TRUNCATE TABLE ' . $wpdb->prefix . 'dscc_cookies' ); // phpcs:ignore
$wpdb->query( 'TRUNCATE TABLE ' . $wpdb->prefix . 'dscc_services' ); // phpcs:ignore
$wpdb->query( 'TRUNCATE TABLE ' . $wpdb->prefix . 'dscc_scan_runs' ); // phpcs:ignore
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '\_transient\_dscc\_rl\_%' OR option_name LIKE '\_transient\_timeout\_dscc\_rl\_%'" ); // phpcs:ignore

// Make sure the front page is a plain static view and the policy cookie page exists for iframe tests.
update_option( 'blog_public', 0 );
$dscc_admin = get_user_by( 'login', 'admin' );
if ( $dscc_admin ) {
	if ( 'en_US' === $locale ) {
		delete_user_meta( $dscc_admin->ID, 'locale' );
	} else {
		update_user_meta( $dscc_admin->ID, 'locale', $locale );
	}
}
echo 'reset ok: ' . $layout . "\n";
