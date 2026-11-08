<?php
/**
 * Downloads a Google Tag Manager container from the one address it is served from and reads it. Only container ids
 * that were found on this site's own pages are ever requested, and only on www.googletagmanager.com.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Scanner;

defined( 'ABSPATH' ) || exit;

final class GtmContainerReader {

	const TIMEOUT  = 15;
	const MAX_BODY = 1572864;

	/**
	 * Is this a container id?
	 *
	 * @param string $id Candidate.
	 * @return bool
	 */
	public static function valid_id( $id ) {
		return is_string( $id ) && 1 === preg_match( '/^GTM-[A-Z0-9]{4,12}$/', $id );
	}

	/**
	 * Address the container is served from.
	 *
	 * @param string $id Container id.
	 * @return string
	 */
	public static function url( $id ) {
		return 'https://www.googletagmanager.com/gtm.js?id=' . $id;
	}

	/**
	 * Fetch and read a container.
	 *
	 * @param string $id Container id (GTM-XXXX).
	 * @return array {ok: bool, error: string, tag_ids: string[], urls: string[]}
	 */
	public static function read( $id ) {
		$out = array(
			'ok'      => false,
			'error'   => '',
			'tag_ids' => array(),
			'urls'    => array(),
		);
		if ( ! self::valid_id( $id ) ) {
			$out['error'] = 'bad_id';
			return $out;
		}
		$response = wp_remote_get(
			self::url( $id ),
			array(
				'timeout'             => self::TIMEOUT,
				'redirection'         => 0,
				'user-agent'          => ServerScanner::USER_AGENT,
				'cookies'             => array(),
				'limit_response_size' => self::MAX_BODY,
			)
		);
		if ( is_wp_error( $response ) ) {
			$out['error'] = substr( sanitize_text_field( $response->get_error_message() ), 0, 160 );
			return $out;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = (string) wp_remote_retrieve_body( $response );
		if ( 200 !== $code || '' === $body ) {
			$out['error'] = 'http_' . $code;
			return $out;
		}
		$parsed = GtmParser::parse( $body );
		return array(
			'ok'      => true,
			'error'   => '',
			'tag_ids' => $parsed['tag_ids'],
			'urls'    => $parsed['urls'],
		);
	}
}
