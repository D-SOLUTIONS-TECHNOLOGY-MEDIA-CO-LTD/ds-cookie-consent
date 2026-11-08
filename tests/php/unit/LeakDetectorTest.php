<?php
namespace DSolutions\CookieConsent\Tests\Unit;

use DSolutions\CookieConsent\Scanner\LeakDetector;
use PHPUnit\Framework\TestCase;

/**
 * Leak = an analytics/marketing cookie in a browser whose own consent denies it.
 */
class LeakDetectorTest extends TestCase {

	private function finding( $name, $category, $slug = '' ) {
		return array(
			'name'         => $name,
			'storage'      => 'cookie',
			'category'     => $category,
			'service_slug' => $slug,
		);
	}

	public function test_marketing_cookie_with_marketing_denied_is_a_leak() {
		$leaks = LeakDetector::find( array( $this->finding( '_fbp', 'marketing', 'meta-pixel' ) ), array( 'c' => array( 'analytics' => 1, 'marketing' => 0 ) ) );
		$this->assertCount( 1, $leaks );
		$this->assertSame( '_fbp', $leaks[0]['name'] );
	}

	public function test_allowed_category_is_not_a_leak() {
		$this->assertSame( array(), LeakDetector::find( array( $this->finding( '_fbp', 'marketing', 'meta-pixel' ) ), array( 'c' => array( 'analytics' => 0, 'marketing' => 1 ) ) ) );
	}

	public function test_a_vendor_switched_off_inside_an_allowed_category_is_a_leak() {
		$consent = array(
			'c' => array( 'analytics' => 1, 'marketing' => 0 ),
			's' => array( 'microsoft-clarity' => 0 ),
		);
		$leaks   = LeakDetector::find( array( $this->finding( '_clck', 'analytics', 'microsoft-clarity' ), $this->finding( '_ga', 'analytics', 'google-analytics' ) ), $consent );
		$this->assertSame( array( '_clck' ), array_column( $leaks, 'name' ) );
	}

	public function test_necessary_functional_and_unclassified_are_never_leaks() {
		$all = array(
			$this->finding( 'wp-wpml_current_language', 'necessary' ),
			$this->finding( 'x', 'functional' ),
			$this->finding( 'LittleD_unknown', 'unclassified' ),
		);
		$this->assertSame( array(), LeakDetector::find( $all, array( 'c' => array( 'analytics' => 0, 'marketing' => 0 ) ) ) );
	}

	public function test_missing_category_flag_counts_as_denied() {
		$this->assertCount( 1, LeakDetector::find( array( $this->finding( '_ga', 'analytics', 'google-analytics' ) ), array( 'c' => array() ) ) );
	}

	public function test_an_ignored_cookie_is_never_a_leak() {
		$finding           = $this->finding( '_fbp', 'marketing', 'meta-pixel' );
		$finding['status'] = 'ignored';
		$this->assertSame( array(), LeakDetector::find( array( $finding ), array( 'c' => array( 'analytics' => 0, 'marketing' => 0 ) ) ) );
	}
}
