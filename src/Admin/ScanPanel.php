<?php
/**
 * The "Quét ngay" panel at the top of the Cookie tab: start button, progress, last scan result, platforms found, last
 * deep scan, plus the admin AJAX endpoints behind it (nonce + manage_options) and the compliance reset action.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Admin;

use DSolutions\CookieConsent\Catalog\ServiceRepository;
use DSolutions\CookieConsent\Scanner\AlertStore;
use DSolutions\CookieConsent\Scanner\FindingStore;
use DSolutions\CookieConsent\Scanner\ScanRunner;
use DSolutions\CookieConsent\Scanner\ScanRunRepository;

defined( 'ABSPATH' ) || exit;

final class ScanPanel {

	const NONCE = 'dscc_scan';

	/**
	 * Register hooks.
	 */
	public static function register() {
		add_action( 'wp_ajax_dscc_scan_start', array( __CLASS__, 'ajax_start' ) );
		add_action( 'wp_ajax_dscc_scan_status', array( __CLASS__, 'ajax_status' ) );
		add_action( 'admin_post_dscc_alerts_clear', array( __CLASS__, 'handle_alerts_clear' ) );
	}

	/**
	 * Data for the panel template.
	 *
	 * @return array {last, deep, running, platforms: array[] {name, via[]}, nonce}
	 */
	public static function data() {
		$last      = ScanRunRepository::latest( 'server', 'done' );
		$platforms = array();
		if ( $last && ! empty( $last['report']['services'] ) ) {
			foreach ( $last['report']['services'] as $slug => $via ) {
				$service     = ServiceRepository::find_by_slug( $slug );
				$platforms[] = array(
					'name' => $service ? $service['name'] : $slug,
					'via'  => array_keys( (array) $via ),
				);
			}
		}
		return array(
			'last'      => $last,
			'deep'      => ScanRunRepository::latest( 'deep', 'done' ),
			'running'   => ScanRunRepository::latest( 'server', 'running' ),
			'platforms' => $platforms,
			'gtm'       => $last && ! empty( $last['report']['gtm'] ) ? $last['report']['gtm'] : array(),
			'dropped'   => FindingStore::dropped_count(),
			'unknown'   => $last && ! empty( $last['report']['unknown_hosts'] ) ? $last['report']['unknown_hosts'] : array(),
		);
	}

	/**
	 * Values for the admin script.
	 *
	 * @return array
	 */
	public static function script_data() {
		return array(
			'ajax'  => admin_url( 'admin-ajax.php' ),
			'nonce' => wp_create_nonce( self::NONCE ),
		);
	}

	/**
	 * Start (or resume) a scan.
	 */
	public static function ajax_start() {
		self::guard();
		$result = ScanRunner::start( 'manual' );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error( array( 'message' => $result->get_error_message() ), 400 );
		}
		wp_send_json_success( $result );
	}

	/**
	 * Work one batch and report progress (the admin screen polls this).
	 */
	public static function ajax_status() {
		self::guard();
		$id = isset( $_POST['id'] ) ? absint( $_POST['id'] ) : 0; // phpcs:ignore WordPress.Security.NonceVerification.Missing -- verified in guard().
		if ( function_exists( 'set_time_limit' ) ) {
			set_time_limit( 120 ); // A batch is five pages of up to 15 seconds each.
		}
		wp_send_json_success( ScanRunner::tick( $id ) );
	}

	/**
	 * Forget the stored leak alerts.
	 */
	public static function handle_alerts_clear() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'ds-cookie-consent' ), 403 );
		}
		check_admin_referer( 'dscc_alerts_clear' );
		AlertStore::clear();
		FindingStore::clear_dropped();
		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : admin_url( 'admin.php?page=dscc' ) );
		exit;
	}

	/**
	 * Nonce and capability check for the AJAX actions.
	 */
	private static function guard() {
		check_ajax_referer( self::NONCE, 'nonce' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to perform this action.', 'ds-cookie-consent' ) ), 403 );
		}
	}
}
