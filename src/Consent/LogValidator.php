<?php
/**
 * Validates and normalises one consent log submission. Pure: no WordPress calls.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Consent;

final class LogValidator {

	/**
	 * Recorded choices. "withdraw" is a switch to all-off after something optional was allowed (or an API reset);
	 * "reject_all" is all-off without prior consent. The server cannot see the earlier consent, so both only
	 * have to agree with their flags: every optional flag and service flag must be 0.
	 */
	const CHOICES = array( 'accept_all', 'reject_all', 'custom', 'withdraw' );

	/** Sources a visitor browser may report. */
	const CLIENT_SOURCES = array( 'banner', 'prefs', 'widget', 'api' );

	const MAX_SERVICES = 60;

	/**
	 * Validate raw (already unslashed) fields.
	 *
	 * @param array $in Raw input: id, choice, c, s, v, lang, url, src.
	 * @return array {ok: bool, error: string, data: array}
	 */
	public static function validate( array $in ) {
		$fail = static function ( $code ) {
			return array(
				'ok'    => false,
				'error' => $code,
				'data'  => array(),
			);
		};

		$id = isset( $in['id'] ) && is_string( $in['id'] ) ? $in['id'] : '';
		if ( ! preg_match( '/^[a-zA-Z0-9\-]{8,64}$/', $id ) ) {
			return $fail( 'bad_id' );
		}

		$choice = isset( $in['choice'] ) && is_string( $in['choice'] ) ? $in['choice'] : '';
		if ( ! in_array( $choice, self::CHOICES, true ) ) {
			return $fail( 'bad_choice' );
		}

		$cats = self::decode_map( isset( $in['c'] ) ? $in['c'] : null, 8 );
		if ( null === $cats || ! array_key_exists( 'analytics', $cats ) || ! array_key_exists( 'marketing', $cats ) ) {
			return $fail( 'bad_categories' );
		}
		$services = array();
		if ( isset( $in['s'] ) && '' !== $in['s'] && null !== $in['s'] ) {
			$services = self::decode_map( $in['s'], self::MAX_SERVICES );
			if ( null === $services ) {
				return $fail( 'bad_services' );
			}
			foreach ( array_keys( $services ) as $slug ) {
				if ( ! preg_match( '/^[a-z0-9_\-]{1,40}$/', (string) $slug ) ) {
					return $fail( 'bad_services' );
				}
			}
		}

		// The recorded choice must agree with the recorded flags (the log is evidence).
		$all_flags = array_merge( array_values( $cats ), array_values( $services ) );
		if ( 'accept_all' === $choice && in_array( 0, $all_flags, true ) ) {
			return $fail( 'choice_mismatch' );
		}
		if ( in_array( $choice, array( 'reject_all', 'withdraw' ), true ) && in_array( 1, $all_flags, true ) ) {
			return $fail( 'choice_mismatch' );
		}

		$version = isset( $in['v'] ) && is_string( $in['v'] ) ? $in['v'] : '';
		if ( ! preg_match( '/^[A-Za-z0-9._\-]{1,20}$/', $version ) ) {
			return $fail( 'bad_version' );
		}

		$lang = isset( $in['lang'] ) && is_string( $in['lang'] ) ? strtolower( $in['lang'] ) : '';
		$lang = substr( (string) preg_replace( '/[^a-z0-9_\-]/', '', $lang ), 0, 10 );

		$src = isset( $in['src'] ) && is_string( $in['src'] ) ? $in['src'] : 'banner';
		if ( ! in_array( $src, self::CLIENT_SOURCES, true ) ) {
			return $fail( 'bad_src' );
		}

		return array(
			'ok'    => true,
			'error' => '',
			'data'  => array(
				'consent_id'     => $id,
				'choice'         => $choice,
				'analytics'      => $cats['analytics'],
				'marketing'      => $cats['marketing'],
				'services_json'  => empty( $services ) ? null : self::encode( $services ),
				'policy_version' => $version,
				'lang'           => $lang,
				'page_url'       => self::path_only( isset( $in['url'] ) ? $in['url'] : '' ),
				'src'            => $src,
			),
		);
	}

	/**
	 * Reduce a URL or path to its path, without query string or fragment.
	 *
	 * @param mixed $url Raw URL.
	 * @return string
	 */
	public static function path_only( $url ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return '';
		}
		$path = parse_url( $url, PHP_URL_PATH ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure helper.
		if ( ! is_string( $path ) || '' === $path ) {
			return '';
		}
		$path = (string) preg_replace( '/[\x00-\x1F\x7F]/', '', $path );
		return substr( $path, 0, 255 );
	}

	/**
	 * Decode a JSON object (string or array) of name => 0|1 flags.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $max   Maximum entries.
	 * @return array|null Normalised map or null when invalid.
	 */
	private static function decode_map( $value, $max ) {
		if ( is_string( $value ) ) {
			if ( strlen( $value ) > 2048 ) {
				return null;
			}
			$value = json_decode( $value, true );
		}
		if ( ! is_array( $value ) || count( $value ) > $max ) {
			return null;
		}
		$out = array();
		foreach ( $value as $key => $flag ) {
			if ( ! is_string( $key ) || ! ( is_int( $flag ) || is_bool( $flag ) || '0' === $flag || '1' === $flag ) ) {
				return null;
			}
			$out[ $key ] = $flag ? 1 : 0;
		}
		return $out;
	}

	/**
	 * JSON encode with or without WordPress loaded (unit tests run without it).
	 *
	 * @param mixed $value Value.
	 * @return string
	 */
	private static function encode( $value ) {
		return function_exists( 'wp_json_encode' ) ? (string) wp_json_encode( $value ) : (string) json_encode( $value ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}
}
