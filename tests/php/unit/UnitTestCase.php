<?php
namespace DSolutions\CookieConsent\Tests\Unit;

use Brain\Monkey;
use Brain\Monkey\Functions;
use Yoast\PHPUnitPolyfills\TestCases\TestCase;

/**
 * Base class: boots Brain Monkey and stubs the few WordPress sanitising helpers used by pure classes.
 */
abstract class UnitTestCase extends TestCase {

	protected function set_up() {
		parent::set_up();
		Monkey\setUp();
		Functions\stubs(
			array(
				'sanitize_text_field'     => static function ( $s ) {
					return trim( strip_tags( (string) $s ) );
				},
				'sanitize_textarea_field' => static function ( $s ) {
					return trim( strip_tags( (string) $s ) );
				},
				'esc_url_raw'             => static function ( $s ) {
					return (string) $s;
				},
				'wp_json_encode'          => static function ( $v ) {
					return json_encode( $v );
				},
			)
		);
	}

	protected function tear_down() {
		Monkey\tearDown();
		parent::tear_down();
	}
}
