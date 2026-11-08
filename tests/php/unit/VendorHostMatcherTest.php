<?php
namespace DSolutions\CookieConsent\Tests\Unit;

use DSolutions\CookieConsent\Catalog\KnownDb;
use DSolutions\CookieConsent\Scanner\VendorHostMatcher;
use PHPUnit\Framework\TestCase;

/**
 * Host-level vendor matching: the host itself or a sub domain of a `script_patterns.hosts` entry.
 */
class VendorHostMatcherTest extends TestCase {

	private function db() {
		return KnownDb::from_file( dirname( __DIR__, 3 ) . '/data/known-cookies.json' );
	}

	private function slugs( $host ) {
		return array_column( VendorHostMatcher::services_for( $this->db(), $host ), 'slug' );
	}

	public function test_meta_pixel_host_without_a_script_path_matches() {
		$this->assertSame( array( 'meta-pixel' ), $this->slugs( 'connect.facebook.net' ) );
	}

	public function test_sub_domains_match_but_look_alike_hosts_do_not() {
		$this->assertContains( 'microsoft-clarity', $this->slugs( 'www.clarity.ms' ) );
		$this->assertContains( 'microsoft-clarity', $this->slugs( 'clarity.ms' ) );
		$this->assertSame( array(), $this->slugs( 'notclarity.ms' ) );
		$this->assertSame( array(), $this->slugs( 'clarity.ms.evil.example' ) );
		$this->assertSame( array(), $this->slugs( 'localhost' ) );
	}

	public function test_necessary_vendors_are_matched_too_the_caller_decides_what_is_watched() {
		$this->assertContains( 'google-recaptcha', $this->slugs( 'www.recaptcha.net' ) );
	}
}
