<?php
/**
 * Validation of a known-cookie database file (bundled, or downloaded from a remote URL) and the SHA-256 of
 * its services payload. The structure rules and the canonical hash mirror tools/build-known-db/lib/{schema,canonical}.mjs:
 * the hash covers the "services" array, object keys sorted recursively, compact JSON, unescaped unicode and slashes.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Catalog;

final class KnownDbValidator {

	const CATEGORIES   = array( 'necessary', 'functional', 'analytics', 'marketing' );
	const MAX_BYTES    = 2097152;
	const MAX_SERVICES = 500;
	const MAX_COOKIES  = 200;

	/**
	 * Parse and validate a database file.
	 *
	 * @param string $json Raw file contents.
	 * @return array{data: array|null, errors: string[]} data is null unless errors is empty.
	 */
	public static function parse( $json ) {
		$json = (string) $json;
		if ( '' === $json || strlen( $json ) > self::MAX_BYTES ) {
			return self::fail( 'file is empty or larger than ' . self::MAX_BYTES . ' bytes' );
		}
		$data = json_decode( $json, true );
		if ( ! is_array( $data ) ) {
			return self::fail( 'not valid JSON' );
		}
		$errors = self::errors( $data );
		if ( ! $errors && isset( $data['sha256'] ) ) {
			$expected = self::services_hash( $json );
			if ( ! hash_equals( $expected, strtolower( (string) $data['sha256'] ) ) ) {
				$errors[] = 'sha256 does not match the services payload';
			}
		}
		return array(
			'data'   => $errors ? null : $data,
			'errors' => $errors,
		);
	}

	/**
	 * SHA-256 of the canonical services payload, recomputed from the raw file.
	 *
	 * @param string $json Raw file contents.
	 * @return string Hex digest, or '' when the file has no services array.
	 */
	public static function services_hash( $json ) {
		$raw = json_decode( (string) $json ); // Objects stay objects, so {} and [] keep their shape.
		if ( ! $raw instanceof \stdClass || ! isset( $raw->services ) || ! is_array( $raw->services ) ) {
			return '';
		}
		$canonical = json_encode( self::sort_keys( $raw->services ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_LINE_TERMINATORS ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- byte-exact canonical form; wp_json_encode adds nothing here and this file also runs in unit tests.
		return hash( 'sha256', (string) $canonical );
	}

	/**
	 * Structure errors of a decoded database.
	 *
	 * @param array $db Decoded file.
	 * @return string[]
	 */
	public static function errors( array $db ) {
		$errors = array();
		if ( empty( $db['version'] ) || ! is_string( $db['version'] ) ) {
			$errors[] = 'version required';
		}
		if ( empty( $db['generated_at'] ) || ! is_string( $db['generated_at'] ) || false === strtotime( $db['generated_at'] ) ) {
			$errors[] = 'generated_at must be a date';
		}
		if ( isset( $db['sha256'] ) && ( ! is_string( $db['sha256'] ) || ! preg_match( '/^[0-9a-f]{64}$/', $db['sha256'] ) ) ) {
			$errors[] = 'sha256 must be 64 lowercase hex characters';
		}
		if ( empty( $db['services'] ) || ! is_array( $db['services'] ) ) {
			return array_merge( $errors, array( 'services must be a non-empty array' ) );
		}
		if ( count( $db['services'] ) > self::MAX_SERVICES ) {
			return array_merge( $errors, array( 'too many services' ) );
		}
		$slugs       = array();
		$cookie_keys = array();
		foreach ( array_values( $db['services'] ) as $i => $service ) {
			$where  = 'services[' . $i . ']';
			$errors = array_merge( $errors, KnownDbServiceRules::service_errors( $service, $where ) );
			$errors = array_merge( $errors, self::duplicate_cookie_errors( $service, $where, $cookie_keys ) );
			if ( is_array( $service ) && isset( $service['slug'] ) && is_string( $service['slug'] ) ) {
				if ( isset( $slugs[ $service['slug'] ] ) ) {
					$errors[] = $where . ': duplicate slug';
				}
				$slugs[ $service['slug'] ] = true;
			}
		}
		return $errors;
	}

	/**
	 * A cookie name may belong to one service only, per storage kind: lookups by name would be ambiguous otherwise.
	 *
	 * @param mixed  $service     Service entry.
	 * @param string $where       Location label.
	 * @param array  $cookie_keys "storage:name" => true for cookies seen so far (updated).
	 * @return string[]
	 */
	private static function duplicate_cookie_errors( $service, $where, array &$cookie_keys ) {
		$errors = array();
		if ( ! is_array( $service ) || empty( $service['cookies'] ) || ! is_array( $service['cookies'] ) ) {
			return $errors;
		}
		foreach ( $service['cookies'] as $cookie ) {
			if ( ! is_array( $cookie ) || ! isset( $cookie['name'], $cookie['storage'] ) || ! is_string( $cookie['name'] ) || ! is_string( $cookie['storage'] ) ) {
				continue;
			}
			$key = $cookie['storage'] . ':' . $cookie['name'];
			if ( isset( $cookie_keys[ $key ] ) ) {
				$errors[] = $where . ': duplicate cookie ' . $key;
			}
			$cookie_keys[ $key ] = true;
		}
		return $errors;
	}

	/**
	 * Sort object keys recursively (arrays keep their order).
	 *
	 * @param mixed $value Decoded JSON (objects as stdClass).
	 * @return mixed
	 */
	private static function sort_keys( $value ) {
		if ( is_array( $value ) ) {
			return array_map( array( __CLASS__, 'sort_keys' ), $value );
		}
		if ( $value instanceof \stdClass ) {
			$vars = get_object_vars( $value );
			ksort( $vars, SORT_STRING );
			$out = new \stdClass();
			foreach ( $vars as $key => $item ) {
				$out->{$key} = self::sort_keys( $item );
			}
			return $out;
		}
		return $value;
	}

	/**
	 * Failure result.
	 *
	 * @param string $message Error.
	 * @return array
	 */
	private static function fail( $message ) {
		return array(
			'data'   => null,
			'errors' => array( $message ),
		);
	}
}
