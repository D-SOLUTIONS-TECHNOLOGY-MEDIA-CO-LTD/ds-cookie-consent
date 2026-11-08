<?php
namespace DSolutions\CookieConsent\Tests\Unit;

use DSolutions\CookieConsent\Blocker\ServerCookieGuard;
use PHPUnit\Framework\TestCase;

class ServerCookieGuardTest extends TestCase {

	/** Catalog cookies as ScriptRules::active() lists them: [name, storage, slug, category]. */
	private function catalog() {
		return array(
			array( '_fbp', 'cookie', 'meta-pixel', 'marketing' ),
			array( '_ga', 'cookie', 'google-analytics', 'analytics' ),
			array( '_ga_*', 'cookie', 'google-analytics', 'analytics' ),
			array( 'sbjs_*', 'localStorage', 'woocommerce-order-attribution', 'analytics' ),
		);
	}

	private function consent( array $c, array $s = array() ) {
		return array(
			'v'   => '2026-11',
			'id'  => 'LittleD-id',
			't'   => time(),
			'c'   => $c,
			's'   => $s,
			'src' => 'banner',
		);
	}

	public function test_fbp_is_removed_without_consent_and_wpml_language_cookie_is_kept() {
		$lines = array(
			'Set-Cookie: _fbp=fb.1.1700000000.123; expires=Wed, 07 Feb 2027 10:00:00 GMT; Max-Age=7776000; path=/; SameSite=Lax',
			'Set-Cookie: wp-wpml_current_language=vi; expires=Sun, 08 Nov 2026 10:00:00 GMT; Max-Age=86400; path=/',
		);
		$split = ServerCookieGuard::split( $lines, $this->catalog(), null );
		$this->assertSame( array( $lines[0] ), $split['removed'] );
		$this->assertSame( array( $lines[1] ), $split['keep'] );
		$this->assertSame( array( '_fbp' ), $split['names'] );
	}

	public function test_everything_is_kept_when_marketing_is_granted() {
		$lines = array( 'Set-Cookie: _fbp=fb.1.1.2; path=/', 'Set-Cookie: wp-wpml_current_language=vi; path=/' );
		$split = ServerCookieGuard::split( $lines, $this->catalog(), $this->consent( array( 'analytics' => 0, 'marketing' => 1 ) ) );
		$this->assertSame( array(), $split['removed'] );
		$this->assertSame( $lines, $split['keep'] );
	}

	public function test_a_service_switched_off_is_removed_even_when_its_category_is_on() {
		$lines = array( 'Set-Cookie: _fbp=x; path=/' );
		$split = ServerCookieGuard::split( $lines, $this->catalog(), $this->consent( array( 'analytics' => 0, 'marketing' => 1 ), array( 'meta-pixel' => 0 ) ) );
		$this->assertCount( 1, $split['removed'] );
	}

	public function test_pattern_names_cookies_of_the_wrong_category_and_web_storage_entries() {
		$lines = array(
			'Set-Cookie: _ga_ABC123=GS1.1.1; path=/',
			'Set-Cookie: sbjs_current=1; path=/', // Listed as localStorage in the catalog: not a cookie rule.
			'Set-Cookie: _fbp=x; path=/',
		);
		$analytics_only = $this->consent( array( 'analytics' => 1, 'marketing' => 0 ) );
		$split          = ServerCookieGuard::split( $lines, $this->catalog(), $analytics_only );
		$this->assertSame( array( $lines[2] ), $split['removed'] );
		$this->assertSame( array( $lines[0], $lines[1] ), $split['keep'] );
	}

	public function test_deleting_a_cookie_and_unknown_cookies_always_pass() {
		$lines = array(
			'Set-Cookie: _fbp=deleted; expires=Thu, 01-Jan-1970 00:00:01 GMT; Max-Age=0; path=/',
			'Set-Cookie: _ga=; Max-Age=0; path=/',
			'Set-Cookie: something_else=1; path=/',
			'Set-Cookie: wordpress_logged_in_abc=u|1|2; path=/; HttpOnly',
		);
		$split = ServerCookieGuard::split( $lines, $this->catalog(), null );
		$this->assertSame( array(), $split['removed'] );
		$this->assertSame( $lines, $split['keep'] );
	}

	public function test_a_bare_deleted_value_is_a_normal_cookie_but_a_real_deletion_passes() {
		$bare = ServerCookieGuard::split( array( 'Set-Cookie: _fbp=deleted; path=/' ), $this->catalog(), null );
		$this->assertCount( 1, $bare['removed'] );
		$real = ServerCookieGuard::split( array( 'Set-Cookie: _fbp=deleted; expires=Thu, 01-Jan-1970 00:00:01 GMT; Max-Age=0; path=/' ), $this->catalog(), null );
		$this->assertSame( array(), $real['removed'] );
	}

	public function test_safelisted_names_are_never_removed_even_when_the_catalog_lists_them() {
		$catalog = array_merge(
			$this->catalog(),
			array(
				array( 'wp_woocommerce_session_*', 'cookie', 'littled-unknown', 'marketing' ),
				array( 'PHPSESSID', 'cookie', 'littled-unknown', 'marketing' ),
				array( 'comment_author_*', 'cookie', 'littled-unknown', 'marketing' ),
				array( 'wc_facebook_signals_state', 'cookie', 'meta-pixel', 'marketing' ),
				array( 'dscc_consent', 'cookie', 'littled-unknown', 'marketing' ),
			)
		);
		$lines = array(
			'Set-Cookie: wp_woocommerce_session_abc=1; path=/',
			'Set-Cookie: PHPSESSID=x; path=/',
			'Set-Cookie: comment_author_hash=LittleD; path=/',
			'Set-Cookie: wc_facebook_signals_state=held; path=/',
			'Set-Cookie: wordpress_logged_in_x=1; path=/',
			'Set-Cookie: wp-settings-1=x; path=/',
			'Set-Cookie: woocommerce_items_in_cart=1; path=/',
			'Set-Cookie: dscc_consent=%7B%7D; path=/',
		);
		$split = ServerCookieGuard::split( $lines, $catalog, null, array_merge( \DSolutions\CookieConsent\Blocker\CookieSafelist::PATTERNS, array( 'dscc_consent' ) ) );
		$this->assertSame( array(), $split['removed'] );
	}

	public function test_cookie_name_parsing() {
		$this->assertSame( '_fbp', ServerCookieGuard::cookie_name( 'Set-Cookie: _fbp=abc; path=/' ) );
		$this->assertSame( '_fbp', ServerCookieGuard::cookie_name( 'set-cookie:   _fbp=abc' ) );
		$this->assertSame( '', ServerCookieGuard::cookie_name( 'Set-Cookie: nonsense' ) );
	}
}
