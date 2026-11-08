<?php
namespace DSolutions\CookieConsent\Tests\Unit;

use DSolutions\CookieConsent\Scanner\BeaconValidator;
use PHPUnit\Framework\TestCase;

/**
 * Beacon payload validation: names, keys and hosts only; strict on shape and size.
 */
class BeaconValidatorTest extends TestCase {

	private function payload( array $over = array() ) {
		return array_merge(
			array(
				'c' => array( '_ga', '_fbp' ),
				'l' => array( 'LittleD_key' ),
				'x' => array(),
				'h' => array( 'www.clarity.ms' ),
				's' => array(
					'c' => array( 'analytics' => 1, 'marketing' => 0 ),
					's' => array( 'google-analytics' => 1 ),
				),
				'u' => '/shop/?utm=1#frag',
			),
			$over
		);
	}

	public function test_valid_payload_is_normalised() {
		$r = BeaconValidator::validate( json_encode( $this->payload() ) );
		$this->assertTrue( $r['ok'] );
		$this->assertSame( array( '_ga', '_fbp' ), $r['data']['cookies'] );
		$this->assertSame( array( 'LittleD_key' ), $r['data']['local'] );
		$this->assertSame( array( 'www.clarity.ms' ), $r['data']['hosts'] );
		$this->assertSame( 0, $r['data']['consent']['c']['marketing'] );
		$this->assertSame( '/shop/', $r['data']['path'], 'query string and fragment are dropped' );
	}

	public function test_wordpress_admin_cookies_are_dropped() {
		$r = BeaconValidator::validate( $this->payload( array( 'c' => array( 'wordpress_logged_in_abc', 'wp-settings-1', 'wp-settings-time-1', 'wp_lang', 'wordpress_test_cookie', '_ga' ) ) ) );
		$this->assertSame( array( '_ga' ), $r['data']['cookies'] );
	}

	public function test_names_with_markup_or_control_characters_are_dropped_not_stored() {
		$r = BeaconValidator::validate( $this->payload( array( 'c' => array( '<script>', 'a"b', "a\x01b", 'ok_name', str_repeat( 'x', 101 ) ) ) ) );
		$this->assertSame( array( 'ok_name' ), $r['data']['cookies'] );
	}

	public function test_storage_keys_follow_the_same_rules_as_cookie_names() {
		$r = BeaconValidator::validate( $this->payload( array( 'l' => array( 'my key', '<b>', 'littled-key', 'a*' ) ) ) );
		$this->assertSame( array( 'littled-key' ), $r['data']['local'] );
	}

	/**
	 * @dataProvider unsafe_names
	 */
	public function test_observed_names_are_literal( $name ) {
		$r = BeaconValidator::validate( $this->payload( array( 'c' => array( $name, 'ok_name' ), 'l' => array( $name ), 'x' => array( $name ) ) ) );
		$this->assertSame( array( 'ok_name' ), $r['data']['cookies'], $name );
		$this->assertSame( array(), $r['data']['local'] );
		$this->assertSame( array(), $r['data']['session'] );
	}

	public function unsafe_names() {
		return array(
			'star'            => array( '*' ),
			'prefix star'     => array( 'a*' ),
			'inner star'      => array( '_ga_*x' ),
			'question'        => array( '?' ),
			'question inside' => array( 'a?b' ),
			'equals'          => array( 'LittleD=secretvalue' ),
			'semicolon'       => array( 'a;b' ),
			'comma'           => array( 'a,b' ),
			'space'           => array( 'a b' ),
			'tab'             => array( "a\tb" ),
			'email'           => array( 'user@example.test' ),
			'uuid'            => array( 'sess_123e4567-e89b-12d3-a456-426614174000' ),
			'long digits'     => array( 'uid_12345678' ),
			'long hex'        => array( 'token_0123456789abcdef' ),
		);
	}

	public function test_short_digit_runs_and_short_hex_are_kept() {
		$r = BeaconValidator::validate( $this->payload( array( 'c' => array( '_hjSessionUser_1234567', '_ym_d', 'abcdef0123456' ) ) ) );
		$this->assertSame( array( '_hjSessionUser_1234567', '_ym_d', 'abcdef0123456' ), $r['data']['cookies'] );
	}

	public function test_hosts_must_look_like_hosts() {
		$r = BeaconValidator::validate( $this->payload( array( 'h' => array( 'WWW.Clarity.MS', 'localhost', 'a b.com', 'http://x.com', 'good-host.example.com' ) ) ) );
		$this->assertSame( array( 'www.clarity.ms', 'good-host.example.com' ), $r['data']['hosts'] );
	}

	/**
	 * @dataProvider bad_payloads
	 */
	public function test_structural_problems_reject_the_payload( $raw, $error ) {
		$r = BeaconValidator::validate( $raw );
		$this->assertFalse( $r['ok'] );
		$this->assertSame( $error, $r['error'] );
	}

	public function bad_payloads() {
		$p = $this->payload();
		return array(
			'not json'            => array( 'nope', 'bad_payload' ),
			'too many cookies'    => array( array_merge( $p, array( 'c' => array_map( 'strval', range( 1, 61 ) ) ) ), 'bad_cookies' ),
			'cookie not string'   => array( array_merge( $p, array( 'c' => array( 5 ) ) ), 'bad_cookies' ),
			'hosts not a list'    => array( array_merge( $p, array( 'h' => 'x.com' ) ), 'bad_hosts' ),
			'no consent'          => array( array_merge( $p, array( 's' => null ) ), 'bad_consent' ),
			'no marketing flag'   => array( array_merge( $p, array( 's' => array( 'c' => array( 'analytics' => 1 ) ) ) ), 'bad_consent' ),
			'flag not a flag'     => array( array_merge( $p, array( 's' => array( 'c' => array( 'analytics' => 'yes', 'marketing' => 0 ) ) ) ), 'bad_consent' ),
			'bad service slug'    => array( array_merge( $p, array( 's' => array( 'c' => array( 'analytics' => 1, 'marketing' => 1 ), 's' => array( 'Bad Slug' => 1 ) ) ) ), 'bad_consent' ),
			'oversized raw json'  => array( str_repeat( 'a', 4097 ), 'too_large' ),
		);
	}
}
