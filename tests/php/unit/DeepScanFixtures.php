<?php
namespace DSolutions\CookieConsent\Tests\Unit;

/**
 * Builds deep scan reports in the shape tools/deep-scan emits (schema 1).
 */
final class DeepScanFixtures {

	/**
	 * Report time of every fixture in the process: a minute ago.
	 *
	 * @var int|null
	 */
	private static $scanned_at = null;

	public static function scanned_at() {
		if ( null === self::$scanned_at ) {
			self::$scanned_at = time() - 60;
		}
		return self::$scanned_at;
	}

	public static function phase( array $cookies = array(), array $hosts = array(), array $set_cookies = array() ) {
		return array(
			'pages'       => array( 'http://localhost:8888/' ),
			'cookies'     => $cookies,
			'storage'     => array(
				'local'   => array(),
				'session' => array(),
			),
			'hosts'       => $hosts,
			'set_cookies' => $set_cookies,
		);
	}

	public static function cookie( $name, $domain = 'localhost', $expires = -1 ) {
		return array(
			'name'     => $name,
			'domain'   => $domain,
			'path'     => '/',
			'httpOnly' => false,
			'secure'   => false,
			'sameSite' => 'Lax',
			'expires'  => $expires,
		);
	}

	public static function report( array $phases = array() ) {
		return array(
			'schema'          => 1,
			'site'            => 'http://localhost:8888',
			'scanned_at'      => gmdate( 'Y-m-d\TH:i:s.000\Z', self::scanned_at() ),
			'tool_version'    => '1.0.0',
			'plugin_detected' => true,
			'banner_found'    => true,
			'phases'          => array_merge(
				array(
					'before_choice' => self::phase(),
					'after_reject'  => self::phase(),
					'after_accept'  => self::phase(),
				),
				$phases
			),
			'errors'          => array(),
		);
	}
}
