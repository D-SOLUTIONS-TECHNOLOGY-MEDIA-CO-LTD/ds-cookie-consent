<?php
namespace DSolutions\CookieConsent\Tests\Unit;

use DSolutions\CookieConsent\Settings\SettingsSchema;

class SettingsSchemaTest extends UnitTestCase {

	public function test_defaults_follow_owner_decisions() {
		$d = SettingsSchema::defaults();
		$this->assertSame( 'card', $d['appearance']['layout'] );
		$this->assertTrue( $d['appearance']['per_service_toggle'] );
		$this->assertSame( 'iframe', $d['content']['policy_embed_mode'] );
		$this->assertSame( array(), $d['advanced']['skip_ua_patterns'] );
		$this->assertSame( array(), $d['advanced']['hide_selectors'] );
		$this->assertSame( 'dscc_consent', $d['advanced']['cookie_name'] );
		$this->assertSame( 180, $d['advanced']['consent_days'] );
		$this->assertSame( gmdate( 'Y-m' ), $d['advanced']['policy_version'] );
		$this->assertTrue( $d['advanced']['skip_bots'] );
		$this->assertTrue( $d['advanced']['show_functional'], 'the Functional group is shown separately by default' );
		$this->assertTrue( $d['scanner']['notify_violations'] );
		$this->assertSame( '#1e3a8a', $d['appearance']['color_primary'] );
		$this->assertSame( '#fafaf7', $d['appearance']['color_modal_bg'] );
		$this->assertSame( 'report', $d['blocking']['unclassified_cookie_mode'], 'owner decision 2026-11-07: unclassified cookies are reported, not blocked, by default' );
	}

	public function test_no_client_specific_defaults() {
		$blob = strtolower( json_encode( SettingsSchema::defaults() ) );
		foreach ( array( 'fabiapp', 'pancake', 'ip' . 'os' ) as $needle ) {
			$this->assertStringNotContainsString( $needle, $blob );
		}
	}

	public function test_enum_and_int_clamp() {
		$cur = SettingsSchema::defaults()['appearance'];
		$out = SettingsSchema::sanitize_group(
			'appearance',
			array(
				'layout'          => 'popup',
				'overlay_opacity' => '999',
				'logo_height'     => '-5',
				'widget_side'     => 'right',
			),
			$cur
		);
		$this->assertSame( 'card', $out['layout'] );
		$this->assertSame( 60, $out['overlay_opacity'] );
		$this->assertSame( 12, $out['logo_height'] );
		$this->assertSame( 'right', $out['widget_side'] );
	}

	public function test_colors_validated_with_fallback_and_optional_empty() {
		$cur = SettingsSchema::defaults()['appearance'];
		$out = SettingsSchema::sanitize_group(
			'appearance',
			array(
				'color_primary'       => 'javascript:alert(1)',
				'color_text'          => '#ABC',
				'color_primary_hover' => '',
				'color_reject_bg'     => '#00ff00',
			),
			$cur
		);
		$this->assertSame( '#1e3a8a', $out['color_primary'] );
		$this->assertSame( '#aabbcc', $out['color_text'] );
		$this->assertSame( '', $out['color_primary_hover'] );
		$this->assertSame( '#00ff00', $out['color_reject_bg'] );
	}

	public function test_checkbox_absent_means_off_within_submitted_group() {
		$cur = SettingsSchema::defaults()['appearance'];
		$out = SettingsSchema::sanitize_group( 'appearance', array( 'layout' => 'bar' ), $cur );
		$this->assertFalse( $out['widget_enabled'] );
		$out = SettingsSchema::sanitize_group( 'appearance', array( 'widget_enabled' => '1' ), $cur );
		$this->assertTrue( $out['widget_enabled'] );
	}

	public function test_group_not_submitted_is_untouched() {
		$cur = SettingsSchema::defaults();
		$out = SettingsSchema::sanitize( array( 'content' => array( 'policy_embed_mode' => 'link' ) ), $cur );
		$this->assertSame( 'link', $out['content']['policy_embed_mode'] );
		$this->assertSame( $cur['appearance'], $out['appearance'] );
	}

	public function test_radius_font_and_custom_css() {
		$cur = SettingsSchema::defaults()['appearance'];
		$out = SettingsSchema::sanitize_group(
			'appearance',
			array(
				'button_radius' => '12',
				'font'          => 'Inter; } body{display:none',
				'custom_css'    => '.a{color:red}</style><script>x</script>',
			),
			$cur
		);
		$this->assertSame( '12', $out['button_radius'] );
		$this->assertStringNotContainsString( ';', $out['font'] );
		$this->assertStringNotContainsString( '}', $out['font'] );
		$this->assertStringNotContainsString( '<', $out['custom_css'] );
		$out = SettingsSchema::sanitize_group( 'appearance', array( 'button_radius' => 'square' ), $cur );
		$this->assertSame( '', $out['button_radius'] );
	}

	public function test_font_with_quotes_semicolons_or_braces_is_rejected_not_rewritten() {
		$cur = SettingsSchema::defaults()['appearance'];
		$cur['font'] = 'Inter, sans-serif';
		foreach ( array( "Inter'; } body{display:none", 'Inter"x', 'Inter;', 'a{b}', 'url(x)' ) as $bad ) {
			$out = SettingsSchema::sanitize_group( 'appearance', array( 'font' => $bad ), $cur );
			$this->assertSame( 'Inter, sans-serif', $out['font'], $bad );
		}
		$out = SettingsSchema::sanitize_group( 'appearance', array( 'font' => 'Open Sans, Helvetica Neue, sans-serif' ), $cur );
		$this->assertSame( 'Open Sans, Helvetica Neue, sans-serif', $out['font'] );
		$out = SettingsSchema::sanitize_group( 'appearance', array( 'font' => '  ' ), $cur );
		$this->assertSame( 'inherit', $out['font'] );
	}

	public function test_refused_custom_css_keeps_previous_value_and_is_reported_once() {
		$cur               = SettingsSchema::defaults()['appearance'];
		$cur['custom_css'] = '.keep{color:red}';
		SettingsSchema::take_rejections();

		$out = SettingsSchema::sanitize_group( 'appearance', array( 'custom_css' => '.a{color:red' ), $cur );
		$this->assertSame( '.keep{color:red}', $out['custom_css'] );
		$this->assertSame( array( 'custom_css' => 'unbalanced' ), SettingsSchema::take_rejections() );
		$this->assertSame( array(), SettingsSchema::take_rejections() );

		$out = SettingsSchema::sanitize_group( 'appearance', array( 'custom_css' => '' ), $cur );
		$this->assertSame( '', $out['custom_css'], 'clearing the field is allowed' );
		$this->assertSame( array(), SettingsSchema::take_rejections() );
	}

	public function test_new_advanced_settings_have_safe_defaults_and_enum_checks() {
		$adv = SettingsSchema::defaults()['advanced'];
		$this->assertSame( 'vi', $adv['default_lang'] );
		$this->assertSame( 'server', $adv['lang_source'] );
		$this->assertSame( 'none', $adv['trusted_ip_header'] );

		$out = SettingsSchema::sanitize_group( 'advanced', array( 'default_lang' => 'fr', 'lang_source' => 'client', 'trusted_ip_header' => 'x-real-ip' ), $adv );
		$this->assertSame( 'vi', $out['default_lang'] );
		$this->assertSame( 'client', $out['lang_source'] );
		$this->assertSame( 'none', $out['trusted_ip_header'] );
		$out = SettingsSchema::sanitize_group( 'advanced', array( 'default_lang' => 'en', 'trusted_ip_header' => 'cf-connecting-ip' ), $adv );
		$this->assertSame( 'en', $out['default_lang'] );
		$this->assertSame( 'cf-connecting-ip', $out['trusted_ip_header'] );
	}

	public function test_advanced_lists_cookie_name_and_version() {
		$cur = SettingsSchema::defaults()['advanced'];
		$out = SettingsSchema::sanitize_group(
			'advanced',
			array(
				'skip_ua_patterns' => "LittleD-App\n(unclosed\n\nLittleD-App\n" . str_repeat( 'a', 200 ),
				'hide_selectors'   => "#chat-root\n.a{color:red}\n#x > .y",
				'cookie_name'      => 'bad name;',
				'cookie_domain'    => '.littled.example',
				'policy_version'   => '2026-12',
				'consent_days'     => '0',
				'consent_regions'  => "VN\nus-ca\nUS-CA",
			),
			$cur
		);
		$this->assertSame( array( 'LittleD-App' ), $out['skip_ua_patterns'] );
		$this->assertSame( array( '#chat-root', '#x > .y' ), $out['hide_selectors'] );
		$this->assertSame( 'dscc_consent', $out['cookie_name'] );
		$this->assertSame( '.littled.example', $out['cookie_domain'] );
		$this->assertSame( '2026-12', $out['policy_version'] );
		$this->assertSame( 1, $out['consent_days'] );
		$this->assertSame( array( 'VN', 'US-CA' ), $out['consent_regions'] );
	}

	public function test_regions_are_capped_by_count_and_reported() {
		SettingsSchema::take_rejections();
		$cur   = SettingsSchema::defaults()['advanced'];
		$codes = array();
		for ( $i = 0; $i < 45; $i++ ) {
			$codes[] = chr( 65 + intdiv( $i, 26 ) ) . chr( 65 + ( $i % 26 ) );
		}
		$out = SettingsSchema::sanitize_group( 'advanced', array( 'consent_regions' => implode( "\n", $codes ) ), $cur );
		$this->assertCount( SettingsSchema::MAX_REGIONS, $out['consent_regions'] );
		$this->assertSame( array_slice( $codes, 0, SettingsSchema::MAX_REGIONS ), $out['consent_regions'] );
		$this->assertSame( array( 'consent_regions' => 'too_many' ), SettingsSchema::take_rejections() );
	}

	public function test_regions_are_capped_by_joined_length_and_exactly_at_the_cap_is_accepted() {
		SettingsSchema::take_rejections();
		$cur = SettingsSchema::defaults()['advanced'];
		$ok  = array();
		for ( $i = 0; $i < 20; $i++ ) {
			$ok[] = 'US-' . chr( 65 + $i ) . 'AZ'; // 6 characters each: 20 * 6 + 19 commas = 139.
		}
		$out = SettingsSchema::sanitize_group( 'advanced', array( 'consent_regions' => implode( "\n", $ok ) ), $cur );
		$this->assertSame( $ok, $out['consent_regions'] );
		$this->assertSame( array(), SettingsSchema::take_rejections() );

		$too_long = array_merge( $ok, array( 'US-UAZ', 'US-VAZ' ) );
		$out      = SettingsSchema::sanitize_group( 'advanced', array( 'consent_regions' => implode( "\n", $too_long ) ), $cur );
		$this->assertLessThanOrEqual( SettingsSchema::MAX_REGION_CHARS, strlen( implode( ',', $out['consent_regions'] ) ) );
		$this->assertSame( $ok, $out['consent_regions'] );
		$this->assertSame( array( 'consent_regions' => 'too_long' ), SettingsSchema::take_rejections() );
	}

	public function test_cookie_name_longer_than_the_head_budget_allows_falls_back_to_the_default() {
		$cur = SettingsSchema::defaults()['advanced'];
		$out = SettingsSchema::sanitize_group( 'advanced', array( 'cookie_name' => str_repeat( 'a', SettingsSchema::MAX_COOKIE_NAME + 1 ) ), $cur );
		$this->assertSame( 'dscc_consent', $out['cookie_name'] );
		$out = SettingsSchema::sanitize_group( 'advanced', array( 'cookie_name' => str_repeat( 'a', SettingsSchema::MAX_COOKIE_NAME ) ), $cur );
		$this->assertSame( str_repeat( 'a', SettingsSchema::MAX_COOKIE_NAME ), $out['cookie_name'] );
	}

	public function test_an_invalid_cookie_name_keeps_the_stored_one_and_is_reported() {
		SettingsSchema::take_rejections();
		$cur                = SettingsSchema::defaults()['advanced'];
		$cur['cookie_name'] = 'littled_consent_with_a_rather_long_stored_name_of_48';
		$out                = SettingsSchema::sanitize_group( 'advanced', array( 'cookie_name' => str_repeat( 'a', 40 ) ), $cur );
		$this->assertSame( $cur['cookie_name'], $out['cookie_name'], 'a 33-64 character stored name survives' );
		$this->assertSame( array( 'cookie_name' => 'too_long' ), SettingsSchema::take_rejections() );
		$out = SettingsSchema::sanitize_group( 'advanced', array( 'cookie_name' => 'bad name;' ), $cur );
		$this->assertSame( $cur['cookie_name'], $out['cookie_name'] );
		$this->assertSame( array( 'cookie_name' => 'invalid' ), SettingsSchema::take_rejections() );
		$out = SettingsSchema::sanitize_group( 'advanced', array( 'cookie_name' => 'littled_ok' ), $cur );
		$this->assertSame( 'littled_ok', $out['cookie_name'] );
		$this->assertSame( array(), SettingsSchema::take_rejections() );
	}

	public function test_known_db_url_must_be_https() {
		SettingsSchema::take_rejections();
		$cur                 = SettingsSchema::defaults()['advanced'];
		$cur['known_db_url'] = 'https://data.littled.example/k.json';
		foreach ( array( 'http://data.littled.example/k.json', '/relative/k.json', 'javascript:alert(1)', 'ftp://x.example/k.json' ) as $bad ) {
			$out = SettingsSchema::sanitize_group( 'advanced', array( 'known_db_url' => $bad ), $cur );
			$this->assertSame( $cur['known_db_url'], $out['known_db_url'], $bad );
			$this->assertSame( array( 'known_db_url' => 'not_https' ), SettingsSchema::take_rejections(), $bad );
		}
		$out = SettingsSchema::sanitize_group( 'advanced', array( 'known_db_url' => 'https://data.littled.example/other.json?v=1' ), $cur );
		$this->assertSame( 'https://data.littled.example/other.json?v=1', $out['known_db_url'] );
		$this->assertSame( '', SettingsSchema::sanitize_group( 'advanced', array( 'known_db_url' => '' ), $cur )['known_db_url'] );
	}

	public function test_url_fields_reject_dangerous_schemes() {
		$cur = SettingsSchema::defaults()['content'];
		$out = SettingsSchema::sanitize_group( 'content', array( 'cookie_policy_url' => 'javascript:alert(1)', 'privacy_url' => '//evil.example/x' ), $cur );
		$this->assertSame( '', $out['cookie_policy_url'] );
		$this->assertSame( '', $out['privacy_url'] );
		$out = SettingsSchema::sanitize_group( 'content', array( 'cookie_policy_url' => '/chinh-sach-cookie/', 'privacy_url' => 'https://littled.example/privacy' ), $cur );
		$this->assertSame( '/chinh-sach-cookie/', $out['cookie_policy_url'] );
		$this->assertSame( 'https://littled.example/privacy', $out['privacy_url'] );
	}

	public function test_sanitize_texts_drops_unknown_default_equal_and_tags() {
		$default_accept = \DSolutions\CookieConsent\Settings\DefaultTexts::all( 'vi' )['accept'];
		$out            = SettingsSchema::sanitize_texts(
			'vi',
			array(
				'accept'  => $default_accept,
				'reject'  => 'LittleD <b>No</b>',
				'unknown' => 'x',
				'title'   => '',
			)
		);
		$this->assertSame( array( 'reject' => 'LittleD No' ), $out );
	}
}
