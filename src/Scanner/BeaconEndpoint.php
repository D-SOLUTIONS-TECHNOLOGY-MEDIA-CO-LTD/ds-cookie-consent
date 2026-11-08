<?php
/**
 * Public endpoint for the beacon: admin-ajax `dscc_beacon` (primary) and the REST mirror `dscc/v1/beacon`.
 * Only registered for visitors who are not logged in (no `wp_ajax_dscc_beacon`). Protected by the setting (opt-in),
 * a 4 KB body limit, origin check, strict validation, a per-network and a global rate limit. No IP address is stored.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Scanner;

use DSolutions\CookieConsent\Consent\IpMasker;
use DSolutions\CookieConsent\Consent\LogEndpoint;
use DSolutions\CookieConsent\Consent\RateLimiter;
use DSolutions\CookieConsent\Consent\RequestGuard;
use DSolutions\CookieConsent\Settings\Settings;

defined( 'ABSPATH' ) || exit;

final class BeaconEndpoint {

	const RATE_MAX      = 3;
	const RATE_WINDOW   = 60;
	const GLOBAL_MAX    = 120;
	const GLOBAL_WINDOW = 60;

	/**
	 * Register hooks.
	 */
	public static function register() {
		add_action( 'wp_ajax_nopriv_dscc_beacon', array( __CLASS__, 'handle_ajax' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest' ) );
	}

	/**
	 * Is collection switched on?
	 *
	 * @return bool
	 */
	public static function enabled() {
		return (bool) Settings::get( 'scanner', 'beacon_enabled', false ) && self::rate() > 0;
	}

	/**
	 * Share of page views that report (0-100).
	 *
	 * @return int
	 */
	public static function rate() {
		return max( 0, min( 100, (int) Settings::get( 'scanner', 'beacon_sample', 5 ) ) );
	}

	/**
	 * REST mirror: POST /wp-json/dscc/v1/beacon.
	 */
	public static function register_rest() {
		register_rest_route(
			'dscc/v1',
			'/beacon',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_rest' ),
				'permission_callback' => '__return_true', // Public by design: protected by the opt-in setting, origin check, size limit and rate limits.
			)
		);
	}

	/**
	 * Handle the admin-ajax request.
	 */
	public static function handle_ajax() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- public endpoint on cacheable pages; protected by origin check, size and rate limits.
		$raw = isset( $_POST['d'] ) && is_string( $_POST['d'] ) ? wp_unslash( $_POST['d'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- parsed as JSON and validated field by field by BeaconValidator.
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$length = isset( $_SERVER['CONTENT_LENGTH'] ) ? (int) $_SERVER['CONTENT_LENGTH'] : strlen( $raw );
		$result = self::process( $raw, $length );
		wp_send_json( $result['body'], $result['status'] );
	}

	/**
	 * REST handler.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function handle_rest( $request ) {
		$value = $request->get_param( 'd' );
		$raw   = is_array( $value ) ? (string) wp_json_encode( $value ) : ( is_string( $value ) ? $value : '' );
		$res   = self::process( $raw, strlen( (string) $request->get_body() ) );
		return new \WP_REST_Response( $res['body'], $res['status'] );
	}

	/**
	 * Shared pipeline.
	 *
	 * @param string $raw    JSON payload.
	 * @param int    $length Request body length in bytes.
	 * @return array {status: int, body: array}
	 */
	public static function process( $raw, $length ) {
		if ( ! self::enabled() ) {
			return self::response( 403, 'disabled' );
		}
		if ( is_user_logged_in() ) {
			return self::response( 403, 'logged_in' );
		}
		if ( ! RequestGuard::body_within_limit( $length ) ) {
			return self::response( 413, 'too_large' );
		}
		$origin  = isset( $_SERVER['HTTP_ORIGIN'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) : '';
		$referer = isset( $_SERVER['HTTP_REFERER'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
		if ( ! RequestGuard::origin_allowed( $origin, $referer, LogEndpoint::allowed_hosts() ) ) {
			return self::response( 403, 'bad_origin' );
		}
		$check = BeaconValidator::validate( $raw );
		if ( ! $check['ok'] ) {
			return self::response( 400, $check['error'] );
		}
		$masked = IpMasker::mask( LogEndpoint::client_ip() );
		if ( ! RateLimiter::allow( 'beacon|' . $masked, self::RATE_MAX, self::RATE_WINDOW ) || ! RateLimiter::allow( 'beacon|all', self::GLOBAL_MAX, self::GLOBAL_WINDOW ) ) {
			return self::response( 429, 'rate_limited' );
		}
		BeaconCollector::ingest( $check['data'] );
		return array(
			'status' => 200,
			'body'   => array( 'ok' => true ),
		);
	}

	/**
	 * Response shape.
	 *
	 * @param int    $status HTTP status.
	 * @param string $code   Machine readable code.
	 * @return array
	 */
	private static function response( $status, $code ) {
		return array(
			'status' => $status,
			'body'   => array(
				'ok'    => false,
				'error' => $code,
			),
		);
	}
}
