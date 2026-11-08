<?php
/**
 * Settings tab "Quét cookie": weekly auto scan, number of pages, beacon collection (opt-in, sample rate), email alerts.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Admin;

use DSolutions\CookieConsent\Scanner\ScanNotifier;
use DSolutions\CookieConsent\Settings\Settings;

defined( 'ABSPATH' ) || exit;

final class ScannerTab {

	const TAB = 'scanner';

	/**
	 * Register hooks.
	 */
	public static function register() {
		add_filter( 'dscc_admin_tabs', array( __CLASS__, 'add_tab' ) );
	}

	/**
	 * Add the tab (before "Nâng cao").
	 *
	 * @param array $tabs Registered tabs.
	 * @return array
	 */
	public static function add_tab( $tabs ) {
		return SettingsPage::insert_tab(
			$tabs,
			self::TAB,
			array(
				'label'  => __( 'Cookie scan', 'ds-cookie-consent' ),
				'render' => array( __CLASS__, 'render' ),
				'save'   => null,
			)
		);
	}

	/**
	 * Print the tab.
	 */
	public static function render() {
		$s         = Settings::group( 'scanner' );
		$recipient = ScanNotifier::recipient();
		include DSCC_DIR . 'templates/admin/tab-scanner.php';
	}
}
