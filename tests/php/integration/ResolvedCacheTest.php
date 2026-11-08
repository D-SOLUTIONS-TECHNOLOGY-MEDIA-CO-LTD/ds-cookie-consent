<?php
use DSolutions\CookieConsent\Frontend\Renderer;
use DSolutions\CookieConsent\Frontend\ResolvedCache;
use DSolutions\CookieConsent\Frontend\ViewModel;
use DSolutions\CookieConsent\Settings\Settings;

/**
 * Logo URLs/sizes and policy page URLs are resolved once into the autoloaded option dscc_resolved,
 * so a front-end render adds no database queries.
 */
class Dscc_Resolved_Cache_Test extends WP_UnitTestCase {

	/** @var int */
	private $page;

	/** @var int */
	private $logo;

	public function set_up() {
		parent::set_up();
		$this->page = self::factory()->post->create(
			array(
				'post_type'   => 'page',
				'post_status' => 'publish',
				'post_title'  => 'LittleD Cookie Policy',
				'post_name'   => 'littled-cookie-policy',
			)
		);
		$this->logo = self::factory()->attachment->create_object(
			array(
				'file'           => 'littled-logo.png',
				'post_mime_type' => 'image/png',
			)
		);
		wp_update_attachment_metadata(
			$this->logo,
			array(
				'width'  => 200,
				'height' => 50,
				'file'   => 'littled-logo.png',
			)
		);
		// Autoloaded, like on a real site after the first text edit; a missing option would cost a query per request.
		add_option( 'dscc_texts_vi', array(), '', 'yes' );
		add_option( 'dscc_texts_en', array(), '', 'yes' );
		Settings::save_group( 'appearance', array_merge( Settings::group( 'appearance' ), array( 'logo_id' => $this->logo ) ) );
		Settings::save_group( 'content', array_merge( Settings::group( 'content' ), array( 'cookie_policy_page_id' => $this->page ) ) );
	}

	private function render() {
		ob_start();
		Renderer::print_markup( ViewModel::build() );
		return ob_get_clean();
	}

	/**
	 * Drop the page and the attachment from the object cache so resolving them again would cost queries.
	 */
	private function forget_objects() {
		clean_post_cache( $this->page );
		clean_post_cache( $this->logo );
		wp_cache_delete( $this->logo, 'post_meta' );
	}

	public function test_settings_save_stores_resolved_values_in_an_autoloaded_option() {
		global $wpdb;
		$this->assertIsArray( get_option( ResolvedCache::OPTION ) );
		$this->assertContains( $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", ResolvedCache::OPTION ) ), array( 'yes', 'on', 'auto-on', 'auto' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$data = ResolvedCache::get();
		$this->assertSame( wp_get_attachment_url( $this->logo ), $data['logo']['url'] );
		$this->assertSame( 200, $data['logo']['w'] );
		$this->assertSame( 50, $data['logo']['h'] );
		$this->assertSame( get_permalink( $this->page ), $data['cookie_url'] );
	}

	public function test_logo_markup_carries_intrinsic_size() {
		$this->assertStringContainsString( 'width="200" height="50"', $this->render() );
	}

	public function test_render_adds_no_database_queries_once_resolved() {
		global $wpdb;
		$this->render(); // Warm unrelated option and locale caches.

		$this->forget_objects();
		$before = $wpdb->num_queries;
		ResolvedCache::resolve( Settings::group( 'appearance' ), Settings::group( 'content' ) );
		$this->assertGreaterThan( 0, $wpdb->num_queries - $before, 'control: live resolving does hit the database' );

		$this->forget_objects();
		$before = $wpdb->num_queries;
		$html   = $this->render();
		$this->assertSame( 0, $wpdb->num_queries - $before, 'footer render must not query the database' );
		$this->assertStringContainsString( 'littled-logo.png', $html );
	}

	public function test_policy_url_follows_page_changes() {
		$this->set_permalink_structure( '/%postname%/' );
		wp_update_post(
			array(
				'ID'        => $this->page,
				'post_name' => 'littled-cookies-renamed',
			)
		);
		$this->assertStringContainsString( 'littled-cookies-renamed', ResolvedCache::get()['cookie_url'] );

		wp_trash_post( $this->page );
		$this->assertSame( '', ResolvedCache::get()['cookie_url'], 'an unpublished page is not linked' );
	}

	public function test_logo_follows_attachment_deletion() {
		wp_delete_attachment( $this->logo, true );
		$this->assertSame( '', ResolvedCache::get()['logo']['url'] );
	}

	public function test_unrelated_post_changes_do_not_rebuild_the_cache() {
		$stored = get_option( ResolvedCache::OPTION );
		$other  = self::factory()->post->create( array( 'post_status' => 'publish' ) );
		wp_update_post(
			array(
				'ID'         => $other,
				'post_title' => 'LittleD other',
			)
		);
		$this->assertSame( $stored, get_option( ResolvedCache::OPTION ) );
	}

	public function test_stale_fingerprint_rebuilds_lazily() {
		$empty = array(
			'url' => 'x',
			'w'   => 0,
			'h'   => 0,
		);
		update_option(
			ResolvedCache::OPTION,
			array(
				'key'  => 'stale',
				'data' => array(
					'logo'        => $empty,
					'logo_popup'  => $empty,
					'cookie_url'  => 'x',
					'privacy_url' => 'x',
				),
			),
			true
		);
		$this->assertSame( get_permalink( $this->page ), ResolvedCache::get()['cookie_url'] );
	}
}
