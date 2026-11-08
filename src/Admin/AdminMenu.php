<?php
/**
 * Admin menu: top-level "Cookie Consent" (Tổng quan, Cài đặt, Log) and the Settings > Cookie Consent shortcut.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Admin;

use DSolutions\CookieConsent\Catalog\CookieRepository;

defined( 'ABSPATH' ) || exit;

final class AdminMenu {

	/**
	 * Register hooks.
	 */
	public static function register() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menus' ) );
	}

	/**
	 * Build the menu tree.
	 */
	public static function add_menus() {
		// The badge counts cookies waiting for review (status new).
		$new   = CookieRepository::count_new();
		$badge = $new > 0 ? ' <span class="update-plugins count-' . (int) $new . '"><span class="plugin-count">' . (int) $new . '</span></span>' : '';
		add_menu_page(
			__( 'Cookie Consent', 'ds-cookie-consent' ),
			esc_html__( 'Cookie Consent', 'ds-cookie-consent' ) . $badge,
			'dscc_view_log',
			'dscc',
			array( DashboardPage::class, 'render' ),
			'dashicons-shield',
			81
		);
		add_submenu_page( 'dscc', __( 'Overview', 'ds-cookie-consent' ), __( 'Overview', 'ds-cookie-consent' ), 'dscc_view_log', 'dscc', array( DashboardPage::class, 'render' ) );
		add_submenu_page( 'dscc', __( 'Settings', 'ds-cookie-consent' ), __( 'Settings', 'ds-cookie-consent' ), 'manage_options', SettingsPage::SLUG, array( SettingsPage::class, 'render' ) );
		add_submenu_page( 'dscc', __( 'Log', 'ds-cookie-consent' ), __( 'Log', 'ds-cookie-consent' ), 'dscc_view_log', LogPage::SLUG, array( LogPage::class, 'render' ) );

		// Settings > Cookie Consent forwards to the real settings screen.
		$hook = add_options_page( __( 'Cookie Consent', 'ds-cookie-consent' ), __( 'Cookie Consent', 'ds-cookie-consent' ), 'manage_options', 'dscc-settings-link', '__return_null' );
		if ( $hook ) {
			add_action(
				'load-' . $hook,
				static function () {
					wp_safe_redirect( SettingsPage::url() );
					exit;
				}
			);
		}
	}
}
