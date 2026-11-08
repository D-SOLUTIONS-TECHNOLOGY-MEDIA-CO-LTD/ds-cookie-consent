<?php
namespace DSolutions\CookieConsent\Tests\Unit;

use DSolutions\CookieConsent\Blocker\CookieSafelist;
use DSolutions\CookieConsent\Catalog\KnownDb;
use PHPUnit\Framework\TestCase;

/**
 * The cookies the blocker never touches must not be classified as analytics or marketing by the known database, or the
 * scanner would file them under a category that suggests blocking.
 */
class KnownDbSafelistTest extends TestCase {

	public function test_no_safelisted_name_is_an_analytics_or_marketing_cookie_in_the_known_database() {
		$known = KnownDb::from_json( (string) file_get_contents( dirname( __DIR__, 3 ) . '/data/known-cookies.json' ) );
		foreach ( $known->services() as $service ) {
			foreach ( $service['cookies'] as $cookie ) {
				if ( CookieSafelist::matches( $cookie['name'], CookieSafelist::PATTERNS ) ) {
					$this->assertContains( $service['category'], array( 'necessary', 'functional' ), $service['slug'] . ' / ' . $cookie['name'] );
				}
			}
		}
	}
}
