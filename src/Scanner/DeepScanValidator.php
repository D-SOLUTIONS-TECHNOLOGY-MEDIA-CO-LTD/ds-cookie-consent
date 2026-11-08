<?php
/**
 * Strict validation of a headless deep scan report (tools/deep-scan, `schema: 1`). Pure: no WordPress calls.
 *
 * Every object is checked against a whitelist of keys: an unknown key (a cookie `value`, above all) rejects the whole
 * payload, so a value can never reach the database by accident. Items are checked by DeepScanFields. The home page of
 * the first phase must have been loaded (`pages` not empty), and `scanned_at` must be an ISO-8601 time that is at most
 * ten minutes ahead of the clock and at most 30 days old.
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Scanner;

final class DeepScanValidator {

	const SCHEMA      = 1;
	const PHASES      = array( 'before_choice', 'after_reject', 'after_accept' );
	const MAX_PAGES   = 20;
	const MAX_COOKIES = 500;
	const MAX_KEYS    = 300;
	const MAX_HOSTS   = 400;
	const MAX_SET     = 100;
	const MAX_ERRORS  = 50;
	const MAX_FUTURE  = 600;
	const MAX_AGE     = 2592000;
	const ISO_8601    = '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|[+-]\d{2}:\d{2})$/';

	/**
	 * Machine readable error code of the first failure.
	 *
	 * @var string
	 */
	private $code = '';

	/**
	 * Where it failed.
	 *
	 * @var string
	 */
	private $where = '';

	/**
	 * Validate and normalise a decoded report.
	 *
	 * @param mixed    $raw Decoded JSON.
	 * @param int|null $now Unix time (default now).
	 * @return array {ok: bool, error: string, message: string, data: array}
	 */
	public static function validate( $raw, $now = null ) {
		$self = new self();
		$data = $self->run( $raw, null === $now ? time() : (int) $now );
		return array(
			'ok'      => null !== $data,
			'error'   => $self->code,
			'message' => $self->where,
			'data'    => null !== $data ? $data : array(),
		);
	}

	/**
	 * Check the top level.
	 *
	 * @param mixed $raw Decoded JSON.
	 * @param int   $now Unix time.
	 * @return array|null
	 */
	private function run( $raw, $now ) {
		if ( ! is_array( $raw ) || ( $raw && array_keys( $raw ) === range( 0, count( $raw ) - 1 ) ) ) {
			return $this->fail( 'bad_payload', 'body' );
		}
		if ( ! array_key_exists( 'schema', $raw ) || self::SCHEMA !== $raw['schema'] ) {
			return $this->fail( 'unsupported_schema', 'schema' );
		}
		$unknown = DeepScanFields::unknown_key( $raw, array( 'schema', 'site', 'scanned_at', 'tool_version', 'plugin_detected', 'banner_found', 'phases', 'errors' ) );
		if ( null !== $unknown ) {
			return $this->fail( 'unknown_field', $unknown );
		}
		$site = $this->site( isset( $raw['site'] ) ? $raw['site'] : null );
		if ( '' === $site ) {
			return $this->fail( 'bad_field', 'site' );
		}
		$when = $this->scanned_at( isset( $raw['scanned_at'] ) ? $raw['scanned_at'] : null, $now );
		if ( null === $when ) {
			return $this->fail( 'bad_field', 'scanned_at' );
		}
		foreach ( array( 'plugin_detected', 'banner_found' ) as $flag ) {
			if ( isset( $raw[ $flag ] ) && ! is_bool( $raw[ $flag ] ) ) {
				return $this->fail( 'bad_field', $flag );
			}
		}
		$version = isset( $raw['tool_version'] ) ? $raw['tool_version'] : '';
		if ( ! is_string( $version ) || strlen( $version ) > 32 ) {
			return $this->fail( 'bad_field', 'tool_version' );
		}
		$errors = $this->errors( isset( $raw['errors'] ) ? $raw['errors'] : array() );
		if ( null === $errors ) {
			return null;
		}
		$phases = $this->phases( isset( $raw['phases'] ) ? $raw['phases'] : null );
		if ( null === $phases ) {
			return null;
		}
		return array(
			'schema'          => self::SCHEMA,
			'site'            => $site,
			'scanned_at'      => $when,
			'tool_version'    => $version,
			'plugin_detected' => ! empty( $raw['plugin_detected'] ),
			'banner_found'    => ! empty( $raw['banner_found'] ),
			'errors'          => $errors,
			'phases'          => $phases,
		);
	}

	/**
	 * The three phases.
	 *
	 * @param mixed $raw Raw phases object.
	 * @return array|null
	 */
	private function phases( $raw ) {
		if ( ! is_array( $raw ) ) {
			return $this->fail( 'bad_field', 'phases' );
		}
		$unknown = DeepScanFields::unknown_key( $raw, self::PHASES );
		if ( null !== $unknown ) {
			return $this->fail( 'unknown_field', 'phases.' . $unknown );
		}
		$out = array();
		foreach ( self::PHASES as $name ) {
			if ( ! isset( $raw[ $name ] ) ) {
				return $this->fail( 'bad_field', 'phases.' . $name );
			}
			$phase = $this->phase( $raw[ $name ], 'phases.' . $name );
			if ( null === $phase ) {
				return null;
			}
			$out[ $name ] = $phase;
		}
		if ( ! $out['before_choice']['pages'] ) {
			return $this->fail( 'bad_field', 'phases.before_choice.pages (no page was loaded)' );
		}
		return $out;
	}

	/**
	 * One phase.
	 *
	 * @param mixed  $phase Raw phase.
	 * @param string $path  Path for messages.
	 * @return array|null
	 */
	private function phase( $phase, $path ) {
		if ( ! is_array( $phase ) ) {
			return $this->fail( 'bad_field', $path );
		}
		$unknown = DeepScanFields::unknown_key( $phase, array( 'pages', 'cookies', 'storage', 'hosts', 'set_cookies' ) );
		if ( null !== $unknown ) {
			return $this->fail( 'unknown_field', $path . '.' . $unknown );
		}
		foreach ( array( 'pages', 'cookies', 'storage', 'hosts' ) as $key ) {
			if ( ! isset( $phase[ $key ] ) || ! is_array( $phase[ $key ] ) ) {
				return $this->fail( 'bad_field', $path . '.' . $key );
			}
		}
		$storage = $phase['storage'];
		$unknown = DeepScanFields::unknown_key( $storage, array( 'local', 'session' ) );
		if ( null !== $unknown ) {
			return $this->fail( 'unknown_field', $path . '.storage.' . $unknown );
		}
		$sources = array(
			'pages'       => array( $phase['pages'], self::MAX_PAGES, 'page' ),
			'cookies'     => array( $phase['cookies'], self::MAX_COOKIES, 'cookie' ),
			'hosts'       => array( $phase['hosts'], self::MAX_HOSTS, 'host_item' ),
			'set_cookies' => array( isset( $phase['set_cookies'] ) ? $phase['set_cookies'] : array(), self::MAX_SET, 'set_cookie' ),
			'local'       => array( isset( $storage['local'] ) ? $storage['local'] : array(), self::MAX_KEYS, 'storage_entry' ),
			'session'     => array( isset( $storage['session'] ) ? $storage['session'] : array(), self::MAX_KEYS, 'storage_entry' ),
		);
		$out     = array();
		foreach ( $sources as $key => $def ) {
			$items = $this->items( $def[0], $def[1], $def[2], $path . ( in_array( $key, array( 'local', 'session' ), true ) ? '.storage.' : '.' ) . $key );
			if ( null === $items ) {
				return null;
			}
			$out[ $key ] = $items;
		}
		return $out;
	}

	/**
	 * Apply an item validator of DeepScanFields to every element of a list.
	 *
	 * @param mixed  $items  Raw list.
	 * @param int    $max    Largest allowed size.
	 * @param string $method DeepScanFields method.
	 * @param string $path   Path for messages.
	 * @return array|null
	 */
	private function items( $items, $max, $method, $path ) {
		if ( ! is_array( $items ) || count( $items ) > $max || ( $items && array_keys( $items ) !== range( 0, count( $items ) - 1 ) ) ) {
			return $this->fail( 'bad_field', $path );
		}
		$out = array();
		foreach ( $items as $i => $item ) {
			$clean = call_user_func( array( DeepScanFields::class, $method ), $item );
			if ( is_string( $clean ) && ( 'bad_field' === $clean || 0 === strpos( $clean, 'unknown_field:' ) ) ) {
				$unknown = 0 === strpos( $clean, 'unknown_field:' );
				return $this->fail( $unknown ? 'unknown_field' : 'bad_field', $path . '[' . $i . ']' . ( $unknown ? '.' . substr( $clean, 14 ) : '' ) );
			}
			$out[] = $clean;
		}
		return $out;
	}

	/**
	 * ISO-8601 time, not from the future (clock skew allowed) and not stale.
	 *
	 * @param mixed $value Raw value.
	 * @param int   $now   Unix time.
	 * @return int|null
	 */
	private function scanned_at( $value, $now ) {
		if ( ! is_string( $value ) || strlen( $value ) > 40 || ! preg_match( self::ISO_8601, $value ) ) {
			return null;
		}
		$time = strtotime( $value );
		return false !== $time && $time <= $now + self::MAX_FUTURE && $time >= $now - self::MAX_AGE ? (int) $time : null;
	}

	/**
	 * The error messages the tool collected, shortened.
	 *
	 * @param mixed $errors Raw list.
	 * @return string[]|null
	 */
	private function errors( $errors ) {
		if ( ! is_array( $errors ) || count( $errors ) > self::MAX_ERRORS ) {
			return $this->fail( 'bad_field', 'errors' );
		}
		$out = array();
		foreach ( $errors as $error ) {
			if ( ! is_string( $error ) ) {
				return $this->fail( 'bad_field', 'errors' );
			}
			$out[] = substr( $error, 0, 300 );
		}
		return array_values( $out );
	}

	/**
	 * Site address: scheme and host (and port) only.
	 *
	 * @param mixed $site Raw value.
	 * @return string '' when invalid.
	 */
	private function site( $site ) {
		if ( null === DeepScanFields::page_url( $site ) || strlen( $site ) > 255 ) {
			return '';
		}
		$scheme = strtolower( (string) preg_replace( '~^(https?)://.*$~i', '$1', $site ) );
		$host   = parse_url( $site, PHP_URL_HOST ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure helper.
		$port   = parse_url( $site, PHP_URL_PORT ); // phpcs:ignore WordPress.WP.AlternativeFunctions.parse_url_parse_url -- pure helper.
		return is_string( $host ) && null !== DeepScanFields::host( $host ) ? $scheme . '://' . strtolower( $host ) . ( $port ? ':' . (int) $port : '' ) : '';
	}

	/**
	 * Remember the first failure.
	 *
	 * @param string $code  Error code.
	 * @param string $where Path.
	 * @return null
	 */
	private function fail( $code, $where ) {
		if ( '' === $this->code ) {
			$this->code  = $code;
			$this->where = $where;
		}
		return null;
	}
}
