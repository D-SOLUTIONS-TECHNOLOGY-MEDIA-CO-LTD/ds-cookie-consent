<?php
namespace DSolutions\CookieConsent\Tests\Unit;

use DSolutions\CookieConsent\Frontend\HeadScript;
use DSolutions\CookieConsent\Settings\SettingsSchema;

/**
 * The inline head script must never exceed 1500 bytes, whatever the admin configured.
 */
class HeadScriptTest extends UnitTestCase {

	private function advanced( array $over = array() ) {
		return array_merge(
			array(
				'cookie_name'      => 'dscc_consent',
				'policy_version'   => '2026-11',
				'consent_days'     => 180,
				'skip_ua_patterns' => array(),
				'skip_bots'        => true,
				'consent_regions'  => array(),
				'url_passthrough'  => false,
			),
			$over
		);
	}

	private function worst() {
		$patterns = array();
		$regions  = array();
		for ( $i = 0; $i < 30; $i++ ) {
			$patterns[] = str_repeat( "&'\\", 33 ) . $i;
		}
		for ( $i = 0; $i < 20; $i++ ) { // Joined length 139 of the 140 character cap.
			$regions[] = 'US-' . chr( 65 + ( $i % 26 ) ) . chr( 65 + intdiv( $i, 26 ) ) . 'Z';
		}
		return $this->advanced(
			array(
				'cookie_name'      => str_repeat( 'c', SettingsSchema::MAX_COOKIE_NAME ),
				'policy_version'   => str_repeat( 'v', 20 ),
				'consent_days'     => 730,
				'url_passthrough'  => true,
				'skip_ua_patterns' => $patterns,
				'consent_regions'  => $regions,
			)
		);
	}

	public function test_worst_case_config_stays_within_budget() {
		$script = HeadScript::build( HeadScript::config( $this->worst() ) );
		$this->assertLessThanOrEqual( HeadScript::MAX_BYTES, strlen( $script ) );
	}

	public function test_every_region_up_to_the_cap_stays_in_the_head_config_in_the_worst_case() {
		$adv = $this->worst();
		$cfg = HeadScript::config( $adv );
		$this->assertLessThanOrEqual( SettingsSchema::MAX_REGION_CHARS, strlen( $cfg['r'] ) );
		$this->assertSame( implode( ',', $adv['consent_regions'] ), $cfg['r'] );
		$this->assertLessThanOrEqual( HeadScript::MAX_BYTES, strlen( HeadScript::build( $cfg ) ) );
	}

	public function test_typical_config_keeps_every_user_pattern_and_region() {
		$adv = $this->advanced(
			array(
				'skip_ua_patterns' => array( 'LittleD-App', 'LittleD-Kiosk' ),
				'consent_regions'  => array( 'VN', 'US-CA' ),
			)
		);
		$cfg = HeadScript::config( $adv );
		$this->assertSame( 'LittleD-App|LittleD-Kiosk', $cfg['u'] );
		$this->assertSame( 'VN,US-CA', $cfg['r'] );
	}

	public function test_script_never_contains_a_closing_script_tag() {
		$cfg = HeadScript::config( $this->advanced( array( 'skip_ua_patterns' => array( '</script>x' ) ) ) );
		$this->assertStringNotContainsString( '</script>', HeadScript::build( $cfg ) );
	}
}
