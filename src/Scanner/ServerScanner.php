<?php
/**
 * Fetches one of the site's own pages the way a first-time visitor's browser would: browser user agent, empty cookie
 * jar. Redirects are followed by hand (at most 3) and only inside this site, so the scan can never be steered to
 * another host. Records every Set-Cookie header (never a value) and returns the HTML for the platform detection.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Scanner;

defined( 'ABSPATH' ) || exit;

final class ServerScanner {

	const TIMEOUT      = 15;
	const MAX_REDIRECT = 3;
	const MAX_BODY     = 2097152;
	const USER_AGENT   = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36';

	/**
	 * Fetch one page.
	 *
	 * @param string $url Own-site address.
	 * @return array {url, path, code, error, cookies: array[] (parsed Set-Cookie), held: string[], html}
	 */
	public static function fetch( $url ) {
		$result  = array(
			'url'     => (string) $url,
			'path'    => (string) wp_parse_url( (string) $url, PHP_URL_PATH ),
			'code'    => 0,
			'error'   => '',
			'cookies' => array(),
			'held'    => array(),
			'html'    => '',
		);
		$current = (string) $url;
		for ( $hop = 0; $hop <= self::MAX_REDIRECT; $hop++ ) {
			if ( ! UrlCollector::is_own_url( $current ) ) {
				$result['error'] = 'foreign_host';
				return $result;
			}
			$response = wp_remote_get( add_query_arg( ScanRequest::PARAM, ScanRequest::token(), $current ), self::args() );
			if ( is_wp_error( $response ) ) {
				$result['error'] = substr( sanitize_text_field( $response->get_error_message() ), 0, 160 );
				return $result;
			}
			foreach ( self::set_cookie_values( $response ) as $value ) {
				$parsed = SetCookieParser::parse( $value );
				if ( $parsed && ! $parsed['deleted'] ) {
					$result['cookies'][] = $parsed;
				}
			}
			$held = (string) wp_remote_retrieve_header( $response, strtolower( ScanRequest::HEADER ) );
			foreach ( array_filter( array_map( 'trim', explode( ',', $held ) ) ) as $name ) {
				$result['held'][] = $name;
			}
			$code = (int) wp_remote_retrieve_response_code( $response );
			if ( in_array( $code, array( 301, 302, 303, 307, 308 ), true ) ) {
				$location = (string) wp_remote_retrieve_header( $response, 'location' );
				if ( '' === $location ) {
					$result['code'] = $code;
					return $result;
				}
				$current = \WP_Http::make_absolute_url( $location, $current );
				continue;
			}
			$result['code'] = $code;
			$type           = (string) wp_remote_retrieve_header( $response, 'content-type' );
			if ( 200 === $code && ( '' === $type || false !== stripos( $type, 'html' ) ) ) {
				$result['html'] = (string) wp_remote_retrieve_body( $response );
			}
			if ( $code >= 400 ) {
				$result['error'] = 'http_' . $code;
			}
			$result['held'] = array_values( array_unique( $result['held'] ) );
			return $result;
		}
		$result['error'] = 'too_many_redirects';
		return $result;
	}

	/**
	 * Request arguments: no redirects (followed by hand), empty jar, browser user agent, TLS verification as WordPress
	 * decides (https_ssl_verify).
	 *
	 * @return array
	 */
	private static function args() {
		return (array) apply_filters(
			'dscc_scan_request_args',
			array(
				'timeout'             => self::TIMEOUT,
				'redirection'         => 0,
				'user-agent'          => (string) apply_filters( 'dscc_scan_user_agent', self::USER_AGENT ),
				'headers'             => array(
					'Accept'          => 'text/html,application/xhtml+xml;q=0.9,*/*;q=0.8',
					'Accept-Language' => 'vi-VN,vi;q=0.9,en;q=0.8',
				),
				'cookies'             => array(),
				'limit_response_size' => self::MAX_BODY,
			)
		);
	}

	/**
	 * Every Set-Cookie header value of a response (WordPress flattens repeated headers; the response object keeps them).
	 *
	 * @param array $response wp_remote_get() result.
	 * @return string[]
	 */
	public static function set_cookie_values( $response ) {
		$object = isset( $response['http_response'] ) && is_object( $response['http_response'] ) && method_exists( $response['http_response'], 'get_response_object' ) ? $response['http_response']->get_response_object() : null;
		if ( $object && isset( $object->headers ) && is_object( $object->headers ) && method_exists( $object->headers, 'getValues' ) ) {
			$values = $object->headers->getValues( 'set-cookie' );
			return is_array( $values ) ? array_map( 'strval', $values ) : array();
		}
		$header = wp_remote_retrieve_header( $response, 'set-cookie' );
		if ( is_array( $header ) ) {
			return array_map( 'strval', array_values( $header ) );
		}
		return '' === (string) $header ? array() : array( (string) $header );
	}
}
