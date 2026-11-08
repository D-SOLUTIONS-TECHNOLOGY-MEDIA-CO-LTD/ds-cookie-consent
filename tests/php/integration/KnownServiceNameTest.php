<?php
use DSolutions\CookieConsent\Activator;
use DSolutions\CookieConsent\Catalog\ServiceRepository;
use DSolutions\CookieConsent\Scanner\KnownServiceRegistry;

/**
 * Names stored in the catalog are data: they do not change with the language of the request that created the row.
 */
class Dscc_Known_Service_Name_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Activator::install();
	}

	public function tear_down() {
		remove_all_filters( 'locale' );
		unload_textdomain( 'ds-cookie-consent', true );
		parent::tear_down();
	}

	public function test_the_holder_of_unclassified_cookies_has_the_same_name_in_every_language() {
		global $wpdb;
		$names = array();
		foreach ( array( 'en_US', 'vi' ) as $locale ) {
			add_filter(
				'locale',
				static function () use ( $locale ) {
					return $locale;
				}
			);
			unload_textdomain( 'ds-cookie-consent', true );
			load_textdomain( 'ds-cookie-consent', DSCC_DIR . 'languages/ds-cookie-consent-vi.mo' );
			$wpdb->delete( \DSolutions\CookieConsent\Database\Schema::services_table(), array( 'slug' => 'site-unclassified' ) );
			$id          = KnownServiceRegistry::holder( 'unclassified' );
			$names[]     = ServiceRepository::find( $id )['name'];
			remove_all_filters( 'locale' );
		}
		$this->assertSame( array( KnownServiceRegistry::UNDETERMINED_NAME, KnownServiceRegistry::UNDETERMINED_NAME ), $names );
		$this->assertSame( 'Chưa xác định', KnownServiceRegistry::UNDETERMINED_NAME );
	}
}
