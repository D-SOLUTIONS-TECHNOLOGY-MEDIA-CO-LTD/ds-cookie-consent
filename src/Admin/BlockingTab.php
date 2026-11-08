<?php
/**
 * Settings tab "Chặn script": blocking mode, Tag Manager mode, exclusions, unclassified handling, reload after
 * withdrawal, what the blocker covers now, the observe-only report, the performance warning and the integrations
 * found on the site.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Admin;

use DSolutions\CookieConsent\Blocker\FailOpenLog;
use DSolutions\CookieConsent\Blocker\ObservedMatches;
use DSolutions\CookieConsent\Blocker\PerfMonitor;
use DSolutions\CookieConsent\Catalog\ServiceRepository;
use DSolutions\CookieConsent\Integrations\Registry;
use DSolutions\CookieConsent\Settings\Settings;

defined( 'ABSPATH' ) || exit;

final class BlockingTab {

	const TAB = 'blocking';

	/**
	 * Register hooks.
	 */
	public static function register() {
		add_filter( 'dscc_admin_tabs', array( __CLASS__, 'add_tab' ) );
		add_action( 'admin_post_dscc_observed_reset', array( __CLASS__, 'handle_reset' ) );
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
				'label'  => __( 'Script blocking', 'ds-cookie-consent' ),
				'render' => array( __CLASS__, 'render' ),
				'save'   => array( __CLASS__, 'save' ),
			)
		);
	}

	/**
	 * Print the tab.
	 */
	public static function render() {
		$s           = Settings::group( 'blocking' );
		$perf        = PerfMonitor::warning();
		$failopen    = FailOpenLog::latest();
		$observed    = ObservedMatches::load();
		$integration = Registry::status();
		$platforms   = array();
		foreach ( ServiceRepository::all() as $service ) {
			if ( 'ignored' === $service['status'] || 'necessary' === $service['category_key'] || ! array_filter( $service['script_patterns'] ) ) {
				continue;
			}
			$platforms[] = $service;
		}
		$reset_url = add_query_arg( 'dscc_observed_nonce', wp_create_nonce( 'dscc_observed_reset' ), admin_url( 'admin-post.php?action=dscc_observed_reset' ) );
		include DSCC_DIR . 'templates/admin/tab-blocking.php';
	}

	/**
	 * Save the tab. A new save clears the slow-page and fail-open warnings so the next measurement starts fresh, and leaving the
	 * observe-only mode drops its report.
	 *
	 * @param array $input Unslashed dscc[] input.
	 */
	public static function save( array $input ) {
		Settings::save_group( 'blocking', isset( $input['blocking'] ) && is_array( $input['blocking'] ) ? $input['blocking'] : array() );
		PerfMonitor::clear();
		FailOpenLog::clear();
		if ( 'block' === Settings::get( 'blocking', 'mode' ) ) {
			ObservedMatches::clear();
		}
	}

	/**
	 * Delete the observe-only report.
	 */
	public static function handle_reset() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'ds-cookie-consent' ), 403 );
		}
		check_admin_referer( 'dscc_observed_reset', 'dscc_observed_nonce' );
		ObservedMatches::clear();
		wp_safe_redirect( SettingsPage::url( self::TAB, array( 'dscc_msg' => 'observed_reset' ) ) );
		exit;
	}
}
