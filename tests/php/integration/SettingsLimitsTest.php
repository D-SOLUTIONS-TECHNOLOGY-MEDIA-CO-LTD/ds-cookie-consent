<?php
use DSolutions\CookieConsent\Settings\Settings;
use DSolutions\CookieConsent\Settings\SettingsSchema;

/**
 * Limits that keep the head script within its byte budget hold for every write of the option,
 * not only for the settings form (imports and other code call update_option directly).
 */
class Dscc_Settings_Limits_Test extends WP_UnitTestCase {

	private function codes( $n ) {
		$codes = array();
		for ( $i = 0; $i < $n; $i++ ) {
			$codes[] = chr( 65 + intdiv( $i, 26 ) ) . chr( 65 + ( $i % 26 ) );
		}
		return $codes;
	}

	public function test_direct_update_option_keeps_at_most_forty_regions() {
		$settings                                 = Settings::all();
		$settings['advanced']['consent_regions'] = $this->codes( 55 );
		update_option( SettingsSchema::OPTION, $settings );
		$this->assertCount( SettingsSchema::MAX_REGIONS, get_option( SettingsSchema::OPTION )['advanced']['consent_regions'] );
		$this->assertCount( SettingsSchema::MAX_REGIONS, Settings::group( 'advanced' )['consent_regions'] );
	}

	public function test_direct_update_option_respects_the_character_cap() {
		$settings                                 = Settings::all();
		$settings['advanced']['consent_regions'] = array_map(
			static function ( $i ) {
				return 'US-' . chr( 65 + $i ) . 'AZ';
			},
			range( 0, 24 )
		);
		update_option( SettingsSchema::OPTION, $settings );
		$stored = get_option( SettingsSchema::OPTION )['advanced']['consent_regions'];
		$this->assertLessThanOrEqual( SettingsSchema::MAX_REGION_CHARS, strlen( implode( ',', $stored ) ) );
		$this->assertSame( 20, count( $stored ) );
	}

	public function test_direct_update_option_cannot_set_an_invalid_cookie_name_and_keeps_the_stored_one() {
		$settings                              = Settings::all();
		$settings['advanced']['cookie_name'] = 'littled_consent';
		update_option( SettingsSchema::OPTION, $settings );
		$settings['advanced']['cookie_name'] = str_repeat( 'a', 70 );
		update_option( SettingsSchema::OPTION, $settings );
		$this->assertSame( 'littled_consent', get_option( SettingsSchema::OPTION )['advanced']['cookie_name'] );
	}

	public function test_a_valid_save_is_untouched() {
		$settings                                 = Settings::all();
		$settings['advanced']['consent_regions'] = array( 'VN', 'US-CA' );
		$settings['advanced']['cookie_name']     = 'littled_consent';
		update_option( SettingsSchema::OPTION, $settings );
		$stored = get_option( SettingsSchema::OPTION )['advanced'];
		$this->assertSame( array( 'VN', 'US-CA' ), $stored['consent_regions'] );
		$this->assertSame( 'littled_consent', $stored['cookie_name'] );
	}
}
