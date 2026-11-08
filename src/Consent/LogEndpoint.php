<?php
/**
 * Public endpoint that stores one consent decision: admin-ajax (primary, works when REST is blocked)
 * plus a REST mirror. Pages are cached, so no nonce; origin/referer, size and rate limit protect it.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Consent;

use DSolutions\CookieConsent\Settings\Settings;

defined( 'ABSPATH' ) || exit;

final class LogEndpoint {

	const RATE_MAX    = 10;
	const RATE_WINDOW = 60;

	/**
	 * Register hooks.
	 */
	public static function register() {
		add_action( 'wp_ajax_dscc_log', array( __CLASS__, 'handle_ajax' ) );
		add_action( 'wp_ajax_nopriv_dscc_log', array( __CLASS__, 'handle_ajax' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest' ) );
		add_action( 'dscc_cleanup_log', array( __CLASS__, 'cleanup' ) );
	}

	/**
	 * Daily retention job.
	 */
	public static function cleanup() {
		$months = (int) Settings::get( 'advanced', 'retention_months', 24 );
		ConsentLogRepository::delete_older_than( $months );
	}

	/**
	 * REST mirror: POST /wp-json/dscc/v1/log (form or JSON body).
	 */
	public static function register_rest() {
		register_rest_route(
			'dscc/v1',
			'/log',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'handle_rest' ),
				'permission_callback' => '__return_true', // Public by design: protected by origin check, size limit and rate limit.
			)
		);
	}

	/**
	 * Handle the admin-ajax request.
	 */
	public static function handle_ajax() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- public endpoint on cacheable pages; protected by origin check, size and rate limit.
		$params = array();
		foreach ( array( 'id', 'choice', 'c', 's', 'v', 'lang', 'url', 'src' ) as $key ) {
			if ( isset( $_POST[ $key ] ) && is_string( $_POST[ $key ] ) ) {
				$params[ $key ] = sanitize_text_field( wp_unslash( $_POST[ $key ] ) );
			}
		}
		// phpcs:enable WordPress.Security.NonceVerification.Missing
		$length = isset( $_SERVER['CONTENT_LENGTH'] ) ? (int) $_SERVER['CONTENT_LENGTH'] : strlen( (string) wp_json_encode( $params ) );

		$result = self::process( $params, $length );
		wp_send_json( $result['body'], $result['status'] );
	}

	/**
	 * REST handler.
	 *
	 * @param \WP_REST_Request $request Request.
	 * @return \WP_REST_Response
	 */
	public static function handle_rest( $request ) {
		$params = array();
		foreach ( array( 'id', 'choice', 'c', 's', 'v', 'lang', 'url', 'src' ) as $key ) {
			$value = $request->get_param( $key );
			if ( is_array( $value ) ) {
				$value = wp_json_encode( $value ); // Allow JSON bodies with nested objects for c and s.
			}
			if ( is_string( $value ) ) {
				$params[ $key ] = sanitize_text_field( $value );
			}
		}
		$result = self::process( $params, strlen( (string) $request->get_body() ) );
		return new \WP_REST_Response( $result['body'], $result['status'] );
	}

	/**
	 * Shared pipeline: size, origin, validation, rate limit, insert.
	 *
	 * @param array $params Sanitised request fields.
	 * @param int   $length Request body length in bytes.
	 * @return array {status: int, body: array}
	 */
	public static function process( array $params, $length ) {
		if ( ! RequestGuard::body_within_limit( $length ) ) {
			return self::response( 413, 'too_large' );
		}

		$origin  = isset( $_SERVER['HTTP_ORIGIN'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ORIGIN'] ) ) : '';
		$referer = isset( $_SERVER['HTTP_REFERER'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
		if ( ! RequestGuard::origin_allowed( $origin, $referer, self::allowed_hosts() ) ) {
			return self::response( 403, 'bad_origin' );
		}

		$check = LogValidator::validate( $params );
		if ( ! $check['ok'] ) {
			return self::response( 400, $check['error'] );
		}

		$masked = IpMasker::mask( self::client_ip() );
		if ( ! RateLimiter::allow( 'log|' . $masked, self::RATE_MAX, self::RATE_WINDOW ) ) {
			RateLimitStats::record();
			return self::response( 429, 'rate_limited' );
		}

		$row               = $check['data'];
		$row['ip_masked']  = $masked;
		$row['user_agent'] = self::user_agent();
		if ( false === ConsentLogRepository::insert( $row ) ) {
			return self::response( 500, 'db_error' );
		}
		return array(
			'status' => 200,
			'body'   => array( 'ok' => true ),
		);
	}

	/**
	 * Error response shape.
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

	/**
	 * Hosts a same-site request may come from.
	 *
	 * @return string[]
	 */
	public static function allowed_hosts() {
		$hosts = array();
		foreach ( array( home_url(), site_url() ) as $url ) {
			$host = wp_parse_url( $url, PHP_URL_HOST );
			if ( $host ) {
				$hosts[] = $host;
			}
		}
		if ( ! empty( $_SERVER['HTTP_HOST'] ) ) {
			$host = wp_parse_url( 'http://' . sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ), PHP_URL_HOST );
			if ( $host ) {
				$hosts[] = $host;
			}
		}
		return array_unique( $hosts );
	}

	/**
	 * Client IP: REMOTE_ADDR, or the trusted proxy header chosen in the settings. Sites with other
	 * setups can still correct it via the dscc_client_ip filter.
	 *
	 * @return string
	 */
	public static function client_ip() {
		$mode = (string) Settings::get( 'advanced', 'trusted_ip_header', 'none' );
		$ip   = ClientIp::pick( $_SERVER, $mode ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- each value is validated with FILTER_VALIDATE_IP.
		return (string) apply_filters( 'dscc_client_ip', $ip );
	}

	/**
	 * User agent truncated to 255 characters.
	 *
	 * @return string
	 */
	private static function user_agent() {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		return function_exists( 'mb_substr' ) ? mb_substr( $ua, 0, 255 ) : substr( $ua, 0, 255 );
	}
}
