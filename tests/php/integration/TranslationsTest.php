<?php
/**
 * Admin strings are English in the source; the Vietnamese catalog restores the Vietnamese admin text.
 */
class Dscc_Translations_Test extends WP_UnitTestCase {

	const DOMAIN = 'ds-cookie-consent';

	public function tear_down() {
		unload_textdomain( self::DOMAIN, true );
		remove_all_filters( 'locale' );
		parent::tear_down();
	}

	private function load_vi() {
		unload_textdomain( self::DOMAIN );
		return load_textdomain( self::DOMAIN, DSCC_DIR . 'languages/ds-cookie-consent-vi.mo' );
	}

	public function test_the_files_exist() {
		foreach ( array( 'ds-cookie-consent.pot', 'ds-cookie-consent-vi.po', 'ds-cookie-consent-vi.mo' ) as $file ) {
			$this->assertFileExists( DSCC_DIR . 'languages/' . $file );
		}
	}

	public function test_english_is_the_source_language() {
		unload_textdomain( self::DOMAIN );
		$this->assertSame( 'Scan now', __( 'Scan now', self::DOMAIN ) );
		$this->assertSame( 'Settings saved.', __( 'Settings saved.', self::DOMAIN ) );
	}

	public function test_vietnamese_admin_text_is_restored_by_the_catalog() {
		$this->assertTrue( $this->load_vi() );
		$expected = array(
			'Scan now'                                   => 'Quét ngay',
			'Settings saved.'                            => 'Đã lưu cài đặt.',
			'Script blocking'                            => 'Chặn script',
			'Platforms'                                  => 'Nền tảng',
			'Appearance'                                 => 'Giao diện',
			'Save changes'                               => 'Lưu thay đổi',
			'Ask every visitor to choose again'          => 'Yêu cầu tất cả khách chọn lại',
			'Compliance alerts'                          => 'Cảnh báo tuân thủ',
		);
		foreach ( $expected as $source => $vietnamese ) {
			// phpcs:ignore WordPress.WP.I18n.NonSingularStringLiteralText -- the test feeds the catalog.
			$this->assertSame( $vietnamese, __( $source, self::DOMAIN ), $source );
		}
	}

	public function test_plural_strings_use_the_vietnamese_form() {
		$this->load_vi();
		$this->assertSame( '1 cookie chưa phân loại', sprintf( _n( '%d unclassified cookie', '%d unclassified cookies', 1, self::DOMAIN ), 1 ) );
		$this->assertSame( '7 cookie chưa phân loại', sprintf( _n( '%d unclassified cookie', '%d unclassified cookies', 7, self::DOMAIN ), 7 ) );
	}

	public function test_the_plugin_loads_the_catalog_for_a_vietnamese_locale() {
		// $reloadable keeps just-in-time loading possible, as on a real request.
		unload_textdomain( self::DOMAIN, true );
		add_filter(
			'locale',
			static function () {
				return 'vi';
			}
		);
		( \DSolutions\CookieConsent\Plugin::instance() )->load_textdomain();
		$this->assertSame( 'Quét ngay', __( 'Scan now', self::DOMAIN ) );
	}

	public function test_every_catalog_entry_is_translated() {
		$po = (string) file_get_contents( DSCC_DIR . 'languages/ds-cookie-consent-vi.po' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$this->assertStringNotContainsString( 'msgstr ""' . "\n\n", $po . "\n", 'no empty translation' );
		$this->assertSame( 0, preg_match( '/msgid "[^"]+"\nmsgstr ""\n/', $po ), 'no untranslated entry' );
	}
}
