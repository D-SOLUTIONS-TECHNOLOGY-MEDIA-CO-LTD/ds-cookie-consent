<?php
use DSolutions\CookieConsent\Frontend\HeadScript;
use DSolutions\CookieConsent\Frontend\Renderer;
use DSolutions\CookieConsent\Frontend\ViewModel;
use DSolutions\CookieConsent\Settings\Settings;
use DSolutions\CookieConsent\Settings\SettingsSchema;

class Dscc_Rendering_Test extends WP_UnitTestCase {

	private function markup( array $appearance = array() ) {
		$current = Settings::group( 'appearance' );
		$vm      = ViewModel::build( array( 'appearance' => SettingsSchema::sanitize_group( 'appearance', array_merge( $current, $appearance ), $current ) ) );
		ob_start();
		Renderer::print_markup( $vm );
		return ob_get_clean();
	}

	public function test_card_markup_has_no_pre_ticked_toggle_and_widget_in_dom() {
		$html = $this->markup( array( 'layout' => 'card', 'widget_enabled' => false ) );
		$this->assertStringContainsString( 'data-layout="card"', $html );
		$this->assertStringContainsString( 'class="dscc-widget"', $html, 'card layout always renders the shield widget' );
		$this->assertDoesNotMatchRegularExpression( '/<input[^>]*type="checkbox"[^>]*checked/i', $html );
		$this->assertStringContainsString( 'data-dscc-action="reject"', $html );
		$this->assertStringContainsString( 'id="dscc-data"', $html );
		$this->assertStringContainsString( 'id="dscc-tpl-modal"', $html );
	}

	public function test_bar_markup_has_reject_beside_accept_and_no_close_button_on_banner() {
		$html = $this->markup( array( 'layout' => 'bar', 'button_order' => 'accept_last' ) );
		$this->assertStringContainsString( 'data-layout="bar"', $html );
		$bar = substr( $html, strpos( $html, 'class="dscc-bar"' ), strpos( $html, 'class="dscc-overlay"' ) - strpos( $html, 'class="dscc-bar"' ) );
		$this->assertStringContainsString( 'data-dscc-action="accept"', $bar );
		$this->assertStringContainsString( 'data-dscc-action="reject"', $bar );
		$this->assertStringNotContainsString( 'data-dscc-action="close"', $bar );
		$this->assertLessThan( strpos( $bar, 'data-dscc-action="accept"' ), strpos( $bar, 'data-dscc-action="customize"' ), 'accept_last puts Accept on the far right' );
	}

	public function test_button_order_is_auto_by_layout() {
		$this->assertSame( 'accept_last', ViewModel::build()['order'] );
		$bar = ViewModel::build( array( 'appearance' => array_merge( Settings::group( 'appearance' ), array( 'layout' => 'bar' ) ) ) );
		$this->assertSame( 'accept_first', $bar['order'] );
	}

	public function test_css_variables_are_derived_from_settings() {
		$vm = ViewModel::build();
		$this->assertStringContainsString( '--dscc-primary:#005ea3', $vm['style'] );
		$this->assertStringContainsString( '--dscc-primary-hover:#00538f', $vm['style'] );
		$this->assertStringContainsString( '--dscc-reject-bg:#e0ecf4', $vm['style'] );
	}

	public function test_services_from_catalog_render_toggles_and_external_policy_link() {
		add_filter(
			'dscc_catalog_services',
			static function () {
				return array(
					array(
						'slug'        => 'littled-ga',
						'name'        => 'LittleD Analytics',
						'category'    => 'analytics',
						'privacy_url' => 'https://policies.example/privacy',
						'cookies'     => array( array( 'name' => '_littled', 'duration' => '1 nam', 'purpose' => 'Test' ) ),
					),
				);
			}
		);
		$html = $this->markup( array( 'layout' => 'bar' ) );
		$this->assertStringContainsString( 'data-dscc-service="littled-ga"', $html );
		$this->assertStringContainsString( 'rel="nofollow noopener noreferrer"', $html );
		$this->assertStringContainsString( 'target="_blank"', $html );
		remove_all_filters( 'dscc_catalog_services' );
	}

	public function test_head_script_is_compact_and_json_safe() {
		$advanced                     = Settings::group( 'advanced' );
		$advanced['skip_ua_patterns'] = array( 'LittleD-App</script>' );
		$script                       = HeadScript::build( HeadScript::config( $advanced ) );
		$this->assertLessThan( 1700, strlen( $script ) );
		$this->assertStringNotContainsString( '</script>', $script );
		$this->assertStringContainsString( "gtag('consent','default'", $script );
	}
}
