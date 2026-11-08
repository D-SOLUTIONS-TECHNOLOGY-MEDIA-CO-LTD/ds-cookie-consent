<?php
/**
 * REST endpoint `POST /wp-json/dscc/v1/scan-result`: receives the report of the headless deep scan (tools/deep-scan).
 * Authentication is WordPress core's Application Passwords (HTTP Basic); the user needs `manage_options`. The body is
 * limited to 2 MB and validated strictly (DeepScanValidator); cookie values are neither accepted nor stored.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Scanner;

defined( 'ABSPATH' ) || exit;

final class DeepScanEndpoint {

	const MAX_BODY = 2097152;

	/**
	 * Register hooks.
	 */
	public static function register() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest' ) );
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'limit_body' ), 10, 3 );
	}

	/**
	 * Refuse an oversized body before anything is done with it (WordPress parses JSON before it checks permissions).
	 *
	 * @param mixed            $result  Pre-dispatch result.
	 * @param \WP_REST_Server  $server  Server.
	 * @param \WP_REST_Request $request Request.
	 * @return mixed A 413 error for this route when the body is too large.
	 */
	public static function limit_body( $result, $server, $request ) {
		if ( '/dscc/v1/scan-result' === $request->get_route() && strlen( (string) $request->get_body() ) > self::MAX_BODY ) {
			return new \WP_Error( 'dscc_too_large', __( 'The report exceeds 2 MB.', 'ds-cookie-consent' ), array( 'status' => 413 ) );
		}
		return $result;
	}

	/**
	 * Register the route.
	 */
	public static function register_rest() {
		register_rest_route(
			'dscc/v1',
			'/scan-result',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle' ),
				'permission_callback' => array( __CLASS__, 'check_permission' ),
			)
		);
	}

	/**
	 * Only an administrator (manage_options) may push a report.
	 *
	 * @return true|\WP_Error 401 when nobody is authenticated, 403 for a user without the capability.
	 */
	public static function check_permission() {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		return new \WP_Error(
			'dscc_forbidden',
			__( 'You must sign in with an administrator\'s Application Password.', 'ds-cookie-consent' ),
			array( 'status' => is_user_logged_in() ? 403 : 401 )
		);
	}

	/**
	 * Handle a report.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public static function handle( $request ) {
		$raw = json_decode( (string) $request->get_body(), true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			return new \WP_Error( 'dscc_bad_payload', __( 'The body is not valid JSON.', 'ds-cookie-consent' ), array( 'status' => 400 ) );
		}
		$check = DeepScanValidator::validate( $raw );
		if ( ! $check['ok'] ) {
			return new \WP_Error(
				'unsupported_schema' === $check['error'] ? 'dscc_unsupported_schema' : 'dscc_bad_payload',
				sprintf(
					/* translators: 1: error code, 2: field path */
					__( 'Invalid report (%1$s: %2$s).', 'ds-cookie-consent' ),
					$check['error'],
					$check['message']
				),
				array( 'status' => 400 )
			);
		}
		if ( ! DeepScanIngest::is_own_site( $check['data']['site'] ) ) {
			return new \WP_Error( 'dscc_site_mismatch', __( 'The report belongs to another site.', 'ds-cookie-consent' ), array( 'status' => 422 ) );
		}
		$result = DeepScanIngest::run( $check['data'] );
		if ( ! $result['run_id'] ) {
			return new \WP_Error( 'dscc_store_failed', __( 'Could not save the scan result.', 'ds-cookie-consent' ), array( 'status' => 500 ) );
		}
		return new \WP_REST_Response( array_merge( array( 'ok' => true ), $result ), 200 );
	}
}
