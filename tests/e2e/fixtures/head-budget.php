<?php
/**
 * E2E fixture (wp eval-file): stores a 64 character cookie name plus 120 regions, a combination that no longer fits the
 * 1500 byte head budget. Written straight to the options table because the normal write path refuses names longer than
 * 32 characters and lists longer than the region cap.
 */

global $wpdb;

$settings = get_option( 'dscc_settings' );
$settings['advanced']['cookie_name']     = str_repeat( 'c', 64 );
$settings['advanced']['consent_regions'] = array_fill( 0, 120, 'US-A12' );

$wpdb->update( $wpdb->options, array( 'option_value' => maybe_serialize( $settings ) ), array( 'option_name' => 'dscc_settings' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
wp_cache_delete( 'alloptions', 'options' );
wp_cache_delete( 'dscc_settings', 'options' );
echo "head budget fixture ok\n";
