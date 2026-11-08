<?php
use DSolutions\CookieConsent\Settings\SvgSanitizer;

class Dscc_Svg_Sanitizer_Test extends WP_UnitTestCase {

	public function test_library_is_loaded_from_the_prefixed_namespace() {
		$this->assertTrue( SvgSanitizer::is_available() );
	}

	public function test_scripts_and_event_handlers_are_removed() {
		$dirty = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 10 10" onload="alert(1)"><script>alert(2)</script><a href="javascript:alert(3)"><rect width="10" height="10"/></a></svg>';
		$clean = SvgSanitizer::sanitize( $dirty );
		$this->assertIsString( $clean );
		$this->assertStringContainsString( '<svg', $clean );
		$this->assertStringNotContainsString( 'script', strtolower( $clean ) );
		$this->assertStringNotContainsString( 'onload', strtolower( $clean ) );
		$this->assertStringNotContainsString( 'javascript:', strtolower( $clean ) );
	}

	public function test_remote_references_are_removed() {
		$dirty = '<svg xmlns="http://www.w3.org/2000/svg" xmlns:xlink="http://www.w3.org/1999/xlink" viewBox="0 0 10 10">'
			. '<image xlink:href="https://tracker.littled.example/pixel.png" width="1" height="1"/>'
			. '<use xlink:href="https://littled.example/other.svg#a"/><rect width="10" height="10"/></svg>';
		$clean = SvgSanitizer::sanitize( $dirty );
		$this->assertIsString( $clean );
		$this->assertStringNotContainsString( 'littled.example', $clean );
		$this->assertStringContainsString( '<rect', $clean );
	}

	public function test_non_svg_input_is_rejected() {
		$this->assertFalse( SvgSanitizer::sanitize( '' ) );
		$this->assertFalse( SvgSanitizer::sanitize( '<html><body>not an svg</body></html>' ) );
		$this->assertFalse( SvgSanitizer::sanitize( 'garbage' ) );
	}

	public function test_svg_mime_is_offered_to_administrators_only() {
		$admin = self::factory()->user->create( array( 'role' => 'administrator' ) );
		$editor = self::factory()->user->create( array( 'role' => 'editor' ) );
		wp_set_current_user( $editor );
		$this->assertArrayNotHasKey( 'svg', \DSolutions\CookieConsent\Admin\SvgUploadGuard::allow_svg_mime( array() ) );
		wp_set_current_user( $admin );
		$this->assertArrayHasKey( 'svg', \DSolutions\CookieConsent\Admin\SvgUploadGuard::allow_svg_mime( array() ) );
	}
}
