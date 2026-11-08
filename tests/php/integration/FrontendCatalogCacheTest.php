<?php
use DSolutions\CookieConsent\Activator;
use DSolutions\CookieConsent\Catalog\CookieRepository;
use DSolutions\CookieConsent\Catalog\FrontendCatalogCache;
use DSolutions\CookieConsent\Catalog\PlatformManager;
use DSolutions\CookieConsent\Catalog\ServiceRepository;
use DSolutions\CookieConsent\Frontend\Renderer;
use DSolutions\CookieConsent\Frontend\ViewModel;
use DSolutions\CookieConsent\Settings\Settings;

require_once __DIR__ . '/fixtures/PresetFixture.php';

/**
 * The front-end snapshot of confirmed services: one autoloaded option, only confirmed rows, rebuilt on any change,
 * and a page render costs no database query once it exists.
 */
class Dscc_Frontend_Catalog_Cache_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Activator::install();
		FrontendCatalogCache::rebuild();
		add_option( 'dscc_texts_vi', array(), '', 'yes' );
		add_option( 'dscc_texts_en', array(), '', 'yes' );
		Settings::save_group( 'appearance', array_merge( Settings::group( 'appearance' ), array( 'layout' => 'bar' ) ) );
	}

	private function slugs() {
		return wp_list_pluck( FrontendCatalogCache::services(), 'slug' );
	}

	/**
	 * Markup of the bar layout: it carries the vendor list and the per-service switches.
	 */
	private function render() {
		ob_start();
		Renderer::print_markup( ViewModel::build() );
		return ob_get_clean();
	}

	public function test_only_confirmed_services_and_cookies_are_included() {
		PlatformManager::enable( 'google-analytics' );
		PlatformManager::enable( 'meta-pixel' );
		PlatformManager::disable( 'meta-pixel' );
		$unclassified = ServiceRepository::upsert( array( 'slug' => 'littled-mystery', 'name' => 'LittleD Mystery', 'category_key' => 'unclassified', 'status' => 'confirmed' ) );
		CookieRepository::upsert( array( 'name' => '_mystery', 'service_id' => $unclassified, 'status' => 'confirmed' ) );
		$ga = ServiceRepository::find_by_slug( 'google-analytics' )['id'];
		CookieRepository::upsert( array( 'name' => '_lt_pending', 'service_id' => $ga, 'status' => 'new' ) );
		CookieRepository::upsert( array( 'name' => '_lt_off', 'service_id' => $ga, 'status' => 'ignored' ) );
		FrontendCatalogCache::rebuild();

		$this->assertSame( array( 'google-analytics' ), $this->slugs() );
		$names = wp_list_pluck( wp_list_pluck( FrontendCatalogCache::services()[0]['cookies'], 'name' ), 'vi' );
		$this->assertContains( '_ga_<mã>', $names, 'patterns are shown in the policy form' );
		$this->assertNotContains( '_lt_pending', $names );
		$this->assertNotContains( '_lt_off', $names );
	}

	public function test_snapshot_lives_in_one_autoloaded_option_with_both_languages() {
		global $wpdb;
		PlatformManager::enable( 'microsoft-clarity' );
		FrontendCatalogCache::rebuild();
		$stored = get_option( FrontendCatalogCache::OPTION );
		$this->assertIsArray( $stored );
		$this->assertContains( $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", FrontendCatalogCache::OPTION ) ), array( 'yes', 'on', 'auto-on', 'auto' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$cookie = $stored['services'][0]['cookies'][0];
		$this->assertNotSame( '', $cookie['purpose']['vi'] );
		$this->assertNotSame( '', $cookie['purpose']['en'] );
		$this->assertNotSame( $cookie['purpose']['vi'], $cookie['purpose']['en'] );
	}

	public function test_any_catalog_change_drops_the_snapshot_and_the_next_read_is_fresh() {
		PlatformManager::enable( 'hotjar' );
		FrontendCatalogCache::rebuild();
		$this->assertContains( 'hotjar', $this->slugs() );
		$this->assertIsArray( get_option( FrontendCatalogCache::OPTION ) );

		PlatformManager::disable( 'hotjar' );
		$this->assertFalse( get_option( FrontendCatalogCache::OPTION ), 'the stored snapshot is dropped as soon as the catalog changes' );
		$this->assertNotContains( 'hotjar', $this->slugs(), 'and a read afterwards rebuilds it' );
		$this->assertIsArray( get_option( FrontendCatalogCache::OPTION ) );

		// Editing a single cookie text also refreshes what visitors get.
		PlatformManager::enable( 'hotjar' );
		$id = CookieRepository::for_service( ServiceRepository::find_by_slug( 'hotjar' )['id'] )[0]['id'];
		CookieRepository::update( $id, array( 'purpose' => 'LittleD đã sửa' ) );
		$texts = wp_list_pluck( wp_list_pluck( FrontendCatalogCache::services()[0]['cookies'], 'purpose' ), 'vi' );
		$this->assertContains( 'LittleD đã sửa', $texts );
	}

	public function test_missing_english_text_falls_back_to_vietnamese() {
		$svc = ServiceRepository::upsert( array( 'slug' => 'littled-own', 'name' => 'LittleD Own', 'category_key' => 'necessary', 'status' => 'confirmed' ) );
		CookieRepository::upsert( array( 'name' => 'littled_pref', 'service_id' => $svc, 'status' => 'confirmed', 'purpose' => 'Ghi nhớ', 'duration_label' => '1 ngày' ) );
		FrontendCatalogCache::rebuild();
		$cookie = FrontendCatalogCache::services()[0]['cookies'][0];
		$this->assertSame( 'Ghi nhớ', $cookie['purpose']['en'] );
		$this->assertSame( '1 ngày', $cookie['duration']['en'] );
	}

	public function test_a_render_adds_no_database_queries_once_the_snapshot_exists() {
		global $wpdb;
		PlatformManager::enable( 'google-analytics' );
		PlatformManager::enable( 'meta-pixel' );
		FrontendCatalogCache::rebuild();
		$this->render(); // Warm unrelated option and locale caches.

		$before = $wpdb->num_queries;
		$html   = $this->render();
		$this->assertSame( 0, $wpdb->num_queries - $before, 'rendering the popup with a full catalog must not query the database' );
		$this->assertStringContainsString( 'data-dscc-service="google-analytics"', $html );
		$this->assertStringContainsString( 'data-dscc-service="meta-pixel"', $html );
	}

	public function test_popup_lists_confirmed_vendors_with_cookie_tables_and_d8_policy_links() {
		PlatformManager::enable( 'google-analytics' );
		FrontendCatalogCache::rebuild();
		$html = $this->render();
		$this->assertStringContainsString( 'data-dscc-service-row="google-analytics"', $html );
		$this->assertMatchesRegularExpression( '/<a href="https:\/\/policies\.google\.com\/privacy" target="_blank" rel="nofollow noopener noreferrer">/', $html );
		$this->assertStringContainsString( '_ga_&lt;', $html );
		$this->assertStringContainsString( 'data-dscc-category="analytics"', $html );
	}

	public function test_new_ignored_and_unclassified_never_reach_the_markup() {
		Dscc_Preset_Fixture::load();
		FrontendCatalogCache::rebuild();
		$html = $this->render();
		foreach ( array( 'intercom', 'cloudflare-turnstile' ) as $slug ) {
			$this->assertStringNotContainsString( 'data-dscc-service="' . $slug . '"', $html, $slug );
		}
		foreach ( array( '_ladipage_email', 'intercom-id', '_ladipage_name' ) as $name ) {
			$this->assertStringNotContainsString( $name, $html, $name );
		}
		$this->assertStringContainsString( 'data-dscc-service="google-analytics"', $html );
	}

	public function test_service_toggle_cap_still_protects_the_log_validation() {
		for ( $i = 1; $i <= 65; $i++ ) {
			ServiceRepository::upsert( array( 'slug' => 'littled-' . $i, 'name' => 'LittleD ' . $i, 'category_key' => 'analytics', 'status' => 'confirmed' ) );
		}
		FrontendCatalogCache::rebuild();
		$this->assertCount( 60, ViewModel::build()['services'] );
		$this->assertCount( 65, FrontendCatalogCache::services() );
	}

	public function test_dual_language_mode_prints_both_languages_for_catalog_texts_and_stays_identical() {
		PlatformManager::enable( 'microsoft-clarity' );
		FrontendCatalogCache::rebuild();
		$settings                                = Settings::all();
		$settings['advanced']['lang_source'] = 'client';
		Settings::replace( $settings );

		$html = $this->render();
		$this->assertMatchesRegularExpression( '/<td data-th="[^"]*"[^>]*>(<span data-dscc-lang="vi">[^<]*<\/span>)(<span data-dscc-lang="en">[^<]*<\/span>)<\/td>/', $html, 'purpose is printed in both languages' );
		$this->assertStringContainsString( 'Lưu ID người dùng Clarity', $html );
		$this->assertStringContainsString( 'Persists the Clarity user ID', $html );
		$_SERVER['HTTP_USER_AGENT'] = 'LittleD-Crawler/1.0';
		$this->assertSame( $html, $this->render() );

		$settings['advanced']['lang_source'] = 'server';
		Settings::replace( $settings );
		$single = $this->render();
		$this->assertStringNotContainsString( 'data-dscc-lang=', $single );
		unset( $_SERVER['HTTP_USER_AGENT'] );
	}

	public function test_services_beyond_the_switch_cap_get_a_note_and_stay_in_the_generated_policy_list() {
		for ( $i = 1; $i <= 62; $i++ ) {
			$svc = ServiceRepository::upsert( array( 'slug' => 'littled-' . $i, 'name' => sprintf( 'LittleD %02d', $i ), 'category_key' => 'analytics', 'status' => 'confirmed' ) );
			CookieRepository::upsert( array( 'name' => 'lt_cookie_' . $i, 'service_id' => $svc, 'status' => 'confirmed', 'purpose' => 'x' ) );
		}
		FrontendCatalogCache::rebuild();
		$vm = ViewModel::build();
		$this->assertCount( 60, $vm['services'] );
		$this->assertSame( 2, $vm['overflow'] );
		$html = $this->render();
		$this->assertStringContainsString( 'dscc-more-vendors', $html );
		$this->assertStringContainsString( '+2 more vendors', $html );
		$this->assertStringContainsString( 'data-dscc-action="open-tab" data-dscc-tab="cookies"', $html );
		$this->assertStringNotContainsString( 'data-dscc-service="littled-62"', $html, 'no switch beyond the cap' );
		$this->assertStringContainsString( 'lt_cookie_62', $html, 'the generated policy list still shows it' );
	}

	public function test_snapshot_carries_group_applies_and_insertion_order() {
		$svc = ServiceRepository::upsert( array( 'slug' => 'littled-ord', 'name' => 'LittleD Ord', 'category_key' => 'analytics', 'status' => 'confirmed' ) );
		CookieRepository::upsert( array( 'name' => 'z_first', 'service_id' => $svc, 'status' => 'confirmed', 'table_group' => 'g', 'applies_to' => 'Cửa hàng', 'applies_to_en' => 'Shop' ) );
		CookieRepository::upsert( array( 'name' => 'a_second', 'service_id' => $svc, 'status' => 'confirmed', 'table_group' => 'g' ) );
		FrontendCatalogCache::rebuild();
		$cookies = FrontendCatalogCache::services()[0]['cookies'];
		$this->assertSame( 'z_first', $cookies[0]['name']['vi'] );
		$this->assertSame( 'g', $cookies[0]['group'] );
		$this->assertSame( array( 'vi' => 'Cửa hàng', 'en' => 'Shop' ), $cookies[0]['applies'] );
		$this->assertGreaterThan( $cookies[0]['id'], $cookies[1]['id'] );
	}
}
