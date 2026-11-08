<?php
/**
 * Keeps the weekly auto scan event in line with the setting `scanner.auto_scan`.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Scanner;

use DSolutions\CookieConsent\Settings\Settings;
use DSolutions\CookieConsent\Settings\SettingsSchema;

defined( 'ABSPATH' ) || exit;

final class ScanScheduler {

	const HOOK = 'dscc_scan_weekly';

	/**
	 * Register hooks.
	 */
	public static function register() {
		add_action( 'update_option_' . SettingsSchema::OPTION, array( __CLASS__, 'sync' ) );
		add_action( 'add_option_' . SettingsSchema::OPTION, array( __CLASS__, 'sync' ) );
		add_action( 'admin_init', array( __CLASS__, 'sync' ) );
	}

	/**
	 * Schedule or clear the weekly event.
	 */
	public static function sync() {
		Settings::flush();
		$weekly    = 'weekly' === Settings::get( 'scanner', 'auto_scan', 'off' );
		$scheduled = wp_next_scheduled( self::HOOK );
		if ( $weekly && ! $scheduled ) {
			wp_schedule_event( time() + DAY_IN_SECONDS, 'weekly', self::HOOK );
		} elseif ( ! $weekly && $scheduled ) {
			wp_clear_scheduled_hook( self::HOOK );
		}
	}

	/**
	 * Remove every scan event (deactivation, uninstall).
	 */
	public static function clear() {
		wp_clear_scheduled_hook( self::HOOK );
		wp_unschedule_hook( 'dscc_scan_tick' );
	}
}
