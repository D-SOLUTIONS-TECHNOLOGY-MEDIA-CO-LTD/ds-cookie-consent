<?php
namespace DSolutions\CookieConsent\Tests\Unit;

use DSolutions\CookieConsent\Consent\LogValidator;
use PHPUnit\Framework\TestCase;

class LogValidatorTest extends TestCase {

	private function base( array $over = array() ) {
		return array_merge(
			array(
				'id'     => 'LittleD-0001-aaaa',
				'choice' => 'accept_all',
				'c'      => '{"analytics":1,"marketing":1}',
				's'      => '{"ga":1}',
				'v'      => '2026-11',
				'lang'   => 'vi',
				'url'    => 'https://littled.example/gia?x=1#top',
				'src'    => 'banner',
			),
			$over
		);
	}

	public function test_valid_submission_is_normalised() {
		$r = LogValidator::validate( $this->base() );
		$this->assertTrue( $r['ok'] );
		$this->assertSame( '/gia', $r['data']['page_url'] );
		$this->assertSame( 1, $r['data']['analytics'] );
		$this->assertSame( '{"ga":1}', $r['data']['services_json'] );
	}

	public function test_all_four_choices_accepted() {
		$this->assertTrue( LogValidator::validate( $this->base( array( 'choice' => 'custom', 'c' => '{"analytics":1,"marketing":0}', 's' => '' ) ) )['ok'] );
		$this->assertTrue( LogValidator::validate( $this->base( array( 'choice' => 'reject_all', 'c' => '{"analytics":0,"marketing":0}', 's' => '{"ga":0}' ) ) )['ok'] );
		$this->assertTrue( LogValidator::validate( $this->base( array( 'choice' => 'withdraw', 'c' => '{"analytics":0,"marketing":0}', 's' => '', 'src' => 'api' ) ) )['ok'] );
	}

	public function test_unknown_choice_rejected() {
		$this->assertSame( 'bad_choice', LogValidator::validate( $this->base( array( 'choice' => 'accept' ) ) )['error'] );
	}

	public function test_choice_must_match_flags() {
		$this->assertSame( 'choice_mismatch', LogValidator::validate( $this->base( array( 'c' => '{"analytics":1,"marketing":0}' ) ) )['error'] );
		$this->assertSame( 'choice_mismatch', LogValidator::validate( $this->base( array( 'choice' => 'reject_all', 's' => '' ) ) )['error'] );
	}

	public function test_bad_id_version_src_services() {
		$this->assertSame( 'bad_id', LogValidator::validate( $this->base( array( 'id' => 'x' ) ) )['error'] );
		$this->assertSame( 'bad_id', LogValidator::validate( $this->base( array( 'id' => "abc'; DROP TABLE x;--" ) ) )['error'] );
		$this->assertSame( 'bad_version', LogValidator::validate( $this->base( array( 'v' => '' ) ) )['error'] );
		$this->assertSame( 'bad_src', LogValidator::validate( $this->base( array( 'src' => 'migrated' ) ) )['error'] );
		$this->assertSame( 'bad_services', LogValidator::validate( $this->base( array( 's' => '{"G A":1}' ) ) )['error'] );
		$this->assertSame( 'bad_categories', LogValidator::validate( $this->base( array( 'c' => 'nope' ) ) )['error'] );
		$this->assertSame( 'bad_categories', LogValidator::validate( $this->base( array( 'c' => '{"analytics":1}' ) ) )['error'] );
	}

	public function test_cookie_values_style_payload_rejected() {
		// Category flags must be 0/1, never arbitrary strings.
		$this->assertSame( 'bad_categories', LogValidator::validate( $this->base( array( 'c' => '{"analytics":"GA1.2.123","marketing":1}' ) ) )['error'] );
	}

	public function test_withdraw_requires_every_flag_off_including_services() {
		$ok = LogValidator::validate( $this->base( array( 'choice' => 'withdraw', 'c' => '{"analytics":0,"marketing":0}', 's' => '{"ga":0,"pixel":0}', 'src' => 'widget' ) ) );
		$this->assertTrue( $ok['ok'] );
		$this->assertSame( 'withdraw', $ok['data']['choice'] );
		$this->assertSame( 'choice_mismatch', LogValidator::validate( $this->base( array( 'choice' => 'withdraw', 'c' => '{"analytics":0,"marketing":0}', 's' => '{"ga":1}' ) ) )['error'] );
		$this->assertSame( 'choice_mismatch', LogValidator::validate( $this->base( array( 'choice' => 'withdraw', 'c' => '{"analytics":0,"marketing":1}', 's' => '' ) ) )['error'] );
	}

	public function test_service_slug_and_count_limits_match_the_catalog_output() {
		$slug40 = str_repeat( 'a', 40 );
		$this->assertTrue( LogValidator::validate( $this->base( array( 's' => '{"' . $slug40 . '":1}' ) ) )['ok'] );
		$this->assertSame( 'bad_services', LogValidator::validate( $this->base( array( 's' => '{"' . $slug40 . 'a":1}' ) ) )['error'] );

		$sixty = array();
		for ( $i = 0; $i < 60; $i++ ) {
			$sixty[ 's' . $i ] = 1;
		}
		$this->assertTrue( LogValidator::validate( $this->base( array( 's' => $sixty ) ) )['ok'] );
		$sixty['s60'] = 1;
		$this->assertSame( 'bad_services', LogValidator::validate( $this->base( array( 's' => $sixty ) ) )['error'] );
	}

	public function test_path_only() {
		$this->assertSame( '/a/b', LogValidator::path_only( '/a/b?q=1' ) );
		$this->assertSame( '', LogValidator::path_only( '' ) );
	}
}
