<?php
use DSolutions\CookieConsent\Frontend\HeadScript;
use DSolutions\CookieConsent\Frontend\Renderer;
use DSolutions\CookieConsent\Frontend\ServiceCatalog;
use DSolutions\CookieConsent\Frontend\ViewModel;
use DSolutions\CookieConsent\Settings\Settings;

/**
 * Markup guarantees: identical HTML for every visitor (page-cache safe), both languages in the markup when
 * the browser picks the language, theme overrides for partials, catalog limits.
 */
class Dscc_Markup_Contract_Test extends WP_UnitTestCase {

	public function tear_down() {
		remove_all_filters( 'dscc_catalog_services' );
		remove_all_filters( 'wpml_current_language' );
		unset( $_COOKIE['dscc_consent'], $_SERVER['HTTP_USER_AGENT'] );
		$this->set_advanced(
			array(
				'lang_source'  => 'server',
				'default_lang'  => 'vi',
			)
		);
		parent::tear_down();
	}

	private function set_advanced( array $over ) {
		Settings::save_group( 'advanced', array_merge( Settings::group( 'advanced' ), $over ) );
	}

	private function page() {
		ob_start();
		HeadScript::print_script();
		Renderer::print_markup( ViewModel::build() );
		return ob_get_clean();
	}

	public function test_markup_is_identical_for_anonymous_consented_and_bot_requests() {
		$this->go_to( home_url( '/' ) );
		$anon = $this->page();

		$_COOKIE['dscc_consent'] = rawurlencode( '{"v":"2026-11","id":"LittleD-0001-aaaa","t":' . time() . ',"c":{"analytics":1,"marketing":0},"s":{},"src":"banner"}' );
		$consented               = $this->page();

		unset( $_COOKIE['dscc_consent'] );
		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';
		$bot                        = $this->page();

		$this->assertNotSame( '', $anon );
		$this->assertSame( $anon, $consented );
		$this->assertSame( $anon, $bot );
	}

	public function test_dual_mode_prints_both_languages_and_stays_identical_across_requests() {
		$this->set_advanced( array( 'lang_source' => 'client' ) );
		$this->go_to( home_url( '/' ) );
		$first = $this->page();
		$this->assertStringContainsString( 'data-dscc-lang="vi"', $first );
		$this->assertStringContainsString( 'data-dscc-lang="en"', $first );
		$this->assertStringContainsString( 'translate="no"', $first );
		$this->assertStringContainsString( 'data-dscc-attr="aria-label"', $first );
		$this->assertStringContainsString( 'Đồng ý', $first );
		$this->assertMatchesRegularExpression( '/"dual":true/', $first );

		$_SERVER['HTTP_USER_AGENT'] = 'LittleD-Crawler/1.0';
		$this->assertSame( $first, $this->page() );
	}

	public function test_single_language_mode_has_no_language_spans() {
		$this->go_to( home_url( '/' ) );
		$html = $this->page();
		$this->assertStringNotContainsString( 'data-dscc-lang=', $html );
		$this->assertStringNotContainsString( 'translate="no"', $html );
		$this->assertMatchesRegularExpression( '/"dual":false/', $html );
	}

	public function test_dual_mode_keeps_text_overrides_per_language() {
		update_option( 'dscc_texts_en', array( 'accept_all' => 'LittleD Yes' ), true );
		update_option( 'dscc_texts_vi', array( 'accept_all' => 'LittleD Dong y' ), true );
		$this->set_advanced( array( 'lang_source' => 'client' ) );
		$html = $this->page();
		$this->assertStringContainsString( '<span data-dscc-lang="en">LittleD Yes</span>', $html );
		$this->assertStringContainsString( '<span data-dscc-lang="vi">LittleD Dong y</span>', $html );
		delete_option( 'dscc_texts_en' );
		delete_option( 'dscc_texts_vi' );
	}

	public function test_default_language_setting_drives_server_language() {
		add_filter(
			'wpml_current_language',
			static function () {
				return 'fr';
			}
		);
		$this->assertSame( 'vi', ViewModel::build()['lang'] );
		$this->set_advanced( array( 'default_lang' => 'en' ) );
		$this->assertSame( 'en', ViewModel::build()['lang'] );
	}

	public function test_no_legacy_aliases_reach_the_head_or_client_data() {
		$this->go_to( home_url( '/' ) );
		$html = $this->page();
		$this->assertStringContainsString( 'dscc_consent_update', $html );
		$this->assertStringNotContainsString( '"legacy"', $html );
		$this->assertDoesNotMatchRegularExpression( '/(^|[^a-z])' . 'ip' . 'os/i', $html );
	}

	public function test_partials_use_the_theme_override() {
		$theme = trailingslashit( get_temp_dir() ) . 'littled-theme-' . wp_generate_password( 6, false );
		$dir   = $theme . '/ds-cookie-consent/partials';
		wp_mkdir_p( $dir );
		file_put_contents( $dir . '/widget.php', '<?php echo "<i class=\"littled-widget-override\"></i>";' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		$point = static function () use ( $theme ) {
			return $theme;
		};
		add_filter( 'stylesheet_directory', $point );
		add_filter( 'template_directory', $point );
		try {
			$this->assertStringContainsString( 'littled-widget-override', $this->page() );
		} finally {
			remove_filter( 'stylesheet_directory', $point );
			remove_filter( 'template_directory', $point );
			unlink( $dir . '/widget.php' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
			rmdir( $dir ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			rmdir( $theme . '/ds-cookie-consent' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
			rmdir( $theme ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_rmdir
		}
		$this->assertStringNotContainsString( 'littled-widget-override', $this->page() );
	}

	public function test_catalog_output_respects_slug_and_count_limits() {
		add_filter(
			'dscc_catalog_services',
			static function () {
				$items = array(
					array(
						'slug'     => str_repeat( 'a', 41 ),
						'name'     => 'LittleD Too Long',
						'category' => 'analytics',
					),
					array(
						'slug'     => 'littled-dup',
						'name'     => 'LittleD One',
						'category' => 'analytics',
					),
					array(
						'slug'     => 'LITTLED-DUP',
						'name'     => 'LittleD Two',
						'category' => 'analytics',
					),
				);
				for ( $i = 0; $i < 80; $i++ ) {
					$items[] = array(
						'slug'     => 'littled-' . $i,
						'name'     => 'LittleD ' . $i,
						'category' => 'marketing',
					);
				}
				return $items;
			}
		);
		$services = ServiceCatalog::services( false );
		$slugs    = wp_list_pluck( $services, 'slug' );
		$this->assertCount( ServiceCatalog::MAX_SERVICES, $services );
		$this->assertNotContains( str_repeat( 'a', 41 ), $slugs );
		$this->assertCount( 1, array_keys( $slugs, 'littled-dup', true ) );
		foreach ( $slugs as $slug ) {
			$this->assertMatchesRegularExpression( '/^[a-z0-9_\-]{1,40}$/', $slug );
		}
	}
}
