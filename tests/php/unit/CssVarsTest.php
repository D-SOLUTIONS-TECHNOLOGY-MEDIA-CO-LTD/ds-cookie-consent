<?php
namespace DSolutions\CookieConsent\Tests\Unit;

use DSolutions\CookieConsent\Frontend\CssVars;
use DSolutions\CookieConsent\Settings\SettingsSchema;

/**
 * Colours are validated again at output, so a hand-edited option cannot inject CSS into the style attribute.
 */
class CssVarsTest extends UnitTestCase {

	protected function set_up() {
		parent::set_up();
		\Brain\Monkey\Functions\stubs( array( 'apply_filters' => static function ( $hook, $value ) {
			return $value;
		} ) );
	}

	public function test_invalid_stored_colours_fall_back_to_defaults() {
		$a                  = SettingsSchema::defaults()['appearance'];
		$a['color_primary'] = 'red;}</style><script>';
		$a['color_text']    = 'url(javascript:x)';
		$a['color_bar_bg']  = '#12';
		$a['color_modal_bg'] = '#ABCDEF';
		$map                = CssVars::map( $a, 'card' );
		$this->assertSame( '#1e3a8a', $map['--dscc-primary'] );
		$this->assertSame( '#1f2937', $map['--dscc-text'] );
		$this->assertSame( '#f9fafb', $map['--dscc-bar-bg'] );
		$this->assertSame( '#abcdef', $map['--dscc-modal-bg'] );
		$this->assertStringNotContainsString( '<', CssVars::style( $a, 'card' ) );
	}

	public function test_font_that_is_not_plain_names_becomes_inherit() {
		$a         = SettingsSchema::defaults()['appearance'];
		$a['font'] = "Inter';}body{x:y";
		$this->assertSame( 'inherit', CssVars::map( $a, 'bar' )['--dscc-font'] );
		$a['font'] = 'Inter, sans-serif';
		$this->assertSame( 'Inter, sans-serif', CssVars::map( $a, 'bar' )['--dscc-font'] );
	}

	public function test_radius_defaults_depend_on_layout() {
		$a = SettingsSchema::defaults()['appearance'];
		$this->assertSame( '999px', CssVars::map( $a, 'bar' )['--dscc-radius'] );
		$this->assertSame( '8px', CssVars::map( $a, 'card' )['--dscc-radius'] );
	}
}
