<?php
use DSolutions\CookieConsent\Activator;
use DSolutions\CookieConsent\Catalog\CookieRepository;
use DSolutions\CookieConsent\Catalog\PlatformManager;
use DSolutions\CookieConsent\Catalog\ServiceRepository;

/**
 * Platform picker operations: tick imports a known platform and its cookies as confirmed, untick ignores them.
 */
class Dscc_Platform_Manager_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Activator::install();
	}

	public function test_ticking_a_known_platform_creates_a_confirmed_service_and_its_cookies() {
		$this->assertTrue( PlatformManager::enable( 'google-analytics' ) );
		$svc = ServiceRepository::find_by_slug( 'google-analytics' );
		$this->assertSame( 'confirmed', $svc['status'] );
		$this->assertSame( 'analytics', $svc['category_key'] );
		$this->assertSame( 'known_db', $svc['source'] );
		$this->assertSame( 1, $svc['block_enabled'] );
		$this->assertContains( 'google-analytics.com', $svc['script_patterns']['hosts'] );

		$cookies = CookieRepository::for_service( $svc['id'] );
		$this->assertCount( 4, $cookies );
		$by_name = array_column( $cookies, null, 'name' );
		$this->assertSame( 'confirmed', $by_name['_ga']['status'] );
		$this->assertSame( 1, $by_name['_ga_*']['name_is_pattern'] );
		$this->assertSame( '2 năm', $by_name['_ga']['duration_label'] );
		$this->assertSame( '2 years', $by_name['_ga']['duration_label_en'] );
		$this->assertNotSame( '', $by_name['_ga']['purpose'] );
	}

	public function test_necessary_platforms_are_never_blocked() {
		PlatformManager::enable( 'cloudflare' );
		$this->assertSame( 0, ServiceRepository::find_by_slug( 'cloudflare' )['block_enabled'] );
		ServiceRepository::set_block( 'cloudflare', true );
		$this->assertSame( 0, ServiceRepository::find_by_slug( 'cloudflare' )['block_enabled'] );
	}

	public function test_unticking_ignores_the_service_and_keeps_every_row() {
		PlatformManager::enable( 'meta-pixel' );
		$id = ServiceRepository::find_by_slug( 'meta-pixel' )['id'];
		$this->assertTrue( PlatformManager::disable( 'meta-pixel' ) );
		$this->assertSame( 'ignored', ServiceRepository::find_by_slug( 'meta-pixel' )['status'] );
		$cookies = CookieRepository::for_service( $id );
		$this->assertCount( 3, $cookies, 'nothing is deleted' );
		foreach ( $cookies as $cookie ) {
			$this->assertSame( 'ignored', $cookie['status'] );
		}
		$this->assertFalse( PlatformManager::disable( 'never-ticked' ) );
	}

	public function test_reticking_restores_confirmed_and_keeps_the_admins_block_choice() {
		PlatformManager::enable( 'hotjar' );
		ServiceRepository::set_block( 'hotjar', false );
		PlatformManager::disable( 'hotjar' );
		PlatformManager::enable( 'hotjar' );
		$svc = ServiceRepository::find_by_slug( 'hotjar' );
		$this->assertSame( 'confirmed', $svc['status'] );
		$this->assertSame( 0, $svc['block_enabled'] );
		foreach ( CookieRepository::for_service( $svc['id'] ) as $cookie ) {
			$this->assertSame( 'confirmed', $cookie['status'] );
		}
	}

	public function test_ticking_resolves_a_cookie_the_scanner_left_as_new() {
		$new = CookieRepository::upsert( array( 'name' => '_fbp', 'status' => 'new', 'source' => 'scan' ) );
		PlatformManager::enable( 'meta-pixel' );
		$row = CookieRepository::find( $new );
		$this->assertSame( 'confirmed', $row['status'] );
		$this->assertSame( ServiceRepository::find_by_slug( 'meta-pixel' )['id'], $row['service_id'] );
	}

	public function test_cookies_waiting_for_review_stay_new_when_the_platform_is_ticked() {
		PlatformManager::enable( 'hotjar' );
		$svc = ServiceRepository::find_by_slug( 'hotjar' );
		$new = CookieRepository::upsert( array( 'name' => '_hj_extra', 'service_id' => $svc['id'], 'status' => 'new', 'source' => 'scan' ) );
		PlatformManager::disable( 'hotjar' );
		PlatformManager::enable( 'hotjar' );
		$this->assertSame( 'new', CookieRepository::find( $new )['status'] );
	}

	public function test_unknown_slug_cannot_be_ticked() {
		$this->assertFalse( PlatformManager::enable( 'no-such-platform' ) );
		$this->assertNull( ServiceRepository::find_by_slug( 'no-such-platform' ) );
	}

	public function test_custom_platform_is_created_confirmed_with_validated_patterns() {
		$slug = PlatformManager::add_custom(
			array(
				'name'        => 'LittleD Chat',
				'category'    => 'marketing',
				'privacy_url' => 'https://littled.example/privacy',
				'hosts'       => "https://CDN.littled.example/widget.js\nnot a host!\n",
				'src_regex'   => "littled\\.example/chat\n(broken\na~b",
			)
		);
		$this->assertSame( 'littled-chat', $slug );
		$svc = ServiceRepository::find_by_slug( $slug );
		$this->assertSame( 'confirmed', $svc['status'] );
		$this->assertSame( 'manual', $svc['source'] );
		$this->assertSame( 'marketing', $svc['category_key'] );
		$this->assertSame( 1, $svc['block_enabled'] );
		$this->assertSame( array( 'cdn.littled.example' ), $svc['script_patterns']['hosts'] );
		$this->assertSame( array( 'littled\.example/chat' ), $svc['script_patterns']['src_regex'] );
	}

	public function test_custom_platform_never_takes_a_known_platform_slug() {
		$slug = PlatformManager::add_custom( array( 'name' => 'Google Analytics', 'category' => 'analytics' ) );
		$this->assertSame( 'google-analytics-2', $slug );
		PlatformManager::enable( 'google-analytics' );
		$this->assertSame( 'known_db', ServiceRepository::find_by_slug( 'google-analytics' )['source'] );
	}

	public function test_custom_platform_validation() {
		$this->assertWPError( PlatformManager::add_custom( array( 'name' => '', 'category' => 'analytics' ) ) );
		$this->assertWPError( PlatformManager::add_custom( array( 'name' => 'LittleD', 'category' => 'unclassified' ) ) );
	}

	public function test_overview_lists_known_and_custom_platforms_with_their_state() {
		PlatformManager::enable( 'microsoft-clarity' );
		ServiceRepository::upsert( array( 'slug' => 'microsoft-clarity', 'detected_via' => array( 'html' ) ) );
		PlatformManager::add_custom( array( 'name' => 'LittleD Tool', 'category' => 'functional' ) );
		CookieRepositoryHelper::site_holder();

		$o = PlatformManager::overview();
		$this->assertTrue( $o['microsoft-clarity']['enabled'] );
		$this->assertSame( array( 'html' ), $o['microsoft-clarity']['detected'] );
		$this->assertFalse( $o['google-analytics']['enabled'] );
		$this->assertSame( 1, $o['google-analytics']['block'] );
		$this->assertSame( 0, $o['cloudflare']['block'] );
		$this->assertTrue( $o['littled-tool']['custom'] );
		$this->assertArrayNotHasKey( 'site-necessary', $o, 'first-party holders are not platforms' );
		$this->assertGreaterThanOrEqual( 30, count( $o ) );
	}
}

/**
 * Creates the first-party holder service the same way the admin cookie actions do.
 */
class CookieRepositoryHelper {
	public static function site_holder() {
		ServiceRepository::site_service( 'necessary' );
	}

	public function test_the_picker_form_ticks_unticks_and_stores_block_without_php_warnings() {
		$this->assertTrue( defined( 'WP_DEBUG' ) && WP_DEBUG, 'the suite runs with WP_DEBUG on, so any notice or warning fails the test' );
		\DSolutions\CookieConsent\Admin\PlatformsTab::apply( array( 'hotjar', 'meta-pixel', 'no-such' ), array( 'hotjar', 'meta-pixel' ), array( 'hotjar' ) );
		$this->assertSame( 'confirmed', ServiceRepository::find_by_slug( 'hotjar' )['status'] );
		$this->assertSame( 1, ServiceRepository::find_by_slug( 'hotjar' )['block_enabled'] );
		$this->assertSame( 0, ServiceRepository::find_by_slug( 'meta-pixel' )['block_enabled'] );
		// The untick path.
		\DSolutions\CookieConsent\Admin\PlatformsTab::apply( array( 'hotjar', 'meta-pixel' ), array( 'meta-pixel' ), array() );
		$this->assertSame( 'ignored', ServiceRepository::find_by_slug( 'hotjar' )['status'] );
		$this->assertSame( 'confirmed', ServiceRepository::find_by_slug( 'meta-pixel' )['status'] );
		$this->assertNull( ServiceRepository::find_by_slug( 'no-such' ) );
	}

	public function test_reticking_never_overwrites_what_the_admin_edited() {
		PlatformManager::enable( 'hotjar' );
		$svc = ServiceRepository::find_by_slug( 'hotjar' );
		ServiceRepository::upsert( array( 'slug' => 'hotjar', 'name' => 'LittleD Heatmaps', 'script_patterns' => array( 'hosts' => array( 'cdn.littled.example' ) ) ) );
		ServiceRepository::set_category( $svc['id'], 'marketing' );
		$cookie = CookieRepository::for_service( $svc['id'] )[0];
		CookieRepository::update( $cookie['id'], array( 'purpose' => 'LittleD đã sửa', 'duration_label' => '9 ngày' ) );

		PlatformManager::disable( 'hotjar' );
		PlatformManager::enable( 'hotjar' );

		$after = ServiceRepository::find_by_slug( 'hotjar' );
		$this->assertSame( 'LittleD Heatmaps', $after['name'] );
		$this->assertSame( 'marketing', $after['category_key'] );
		$this->assertSame( array( 'cdn.littled.example' ), $after['script_patterns']['hosts'] );
		$this->assertSame( 'confirmed', $after['status'] );
		$row = CookieRepository::find( $cookie['id'] );
		$this->assertSame( 'LittleD đã sửa', $row['purpose'] );
		$this->assertSame( '9 ngày', $row['duration_label'] );
		$this->assertSame( 'confirmed', $row['status'] );
	}

	public function test_reticking_fills_fields_that_are_empty() {
		PlatformManager::enable( 'hotjar' );
		$svc    = ServiceRepository::find_by_slug( 'hotjar' );
		$cookie = CookieRepository::for_service( $svc['id'] )[0];
		CookieRepository::update( $cookie['id'], array( 'purpose' => '', 'purpose_en' => '' ) );
		PlatformManager::enable( 'hotjar' );
		$this->assertNotSame( '', CookieRepository::find( $cookie['id'] )['purpose'] );
	}

	public function test_custom_slug_never_collides_with_the_first_party_holders() {
		$this->assertSame( 'site-marketing-2', PlatformManager::add_custom( array( 'name' => 'Site Marketing', 'category' => 'marketing' ) ) );
		$this->assertNull( ServiceRepository::find_by_slug( 'site-marketing' ) );
		$this->assertSame( 'platform', ServiceRepository::unique_slug( 'Đồng Hồ' ) === '' ? '' : 'platform' );
	}

	public function test_unique_slug_uses_the_same_form_that_is_stored() {
		$slug = PlatformManager::add_custom( array( 'name' => 'Công cụ LittleD', 'category' => 'analytics' ) );
		$this->assertSame( sanitize_key( $slug ), $slug );
		$this->assertNotNull( ServiceRepository::find_by_slug( $slug ) );
		$again = PlatformManager::add_custom( array( 'name' => 'Công cụ LittleD', 'category' => 'analytics' ) );
		$this->assertNotSame( $slug, $again );
		$this->assertNotNull( ServiceRepository::find_by_slug( $again ) );
	}
}
