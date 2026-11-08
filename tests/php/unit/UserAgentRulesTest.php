<?php
namespace DSolutions\CookieConsent\Tests\Unit;

use DSolutions\CookieConsent\Frontend\UserAgentRules;

class UserAgentRulesTest extends UnitTestCase {

	public function test_disabled_skip_bots_yields_empty_pattern() {
		$this->assertSame( '', UserAgentRules::bot_pattern( false ) );
	}

	public function test_common_crawlers_match_and_lighthouse_and_browsers_do_not() {
		$pattern = '/' . UserAgentRules::bot_pattern( true ) . '/i';

		$this->assertSame( 1, preg_match( $pattern, 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)' ) );
		$this->assertSame( 1, preg_match( $pattern, 'Mozilla/5.0 (compatible; bingbot/2.0; +http://www.bing.com/bingbot.htm)' ) );
		$this->assertSame( 1, preg_match( $pattern, 'Mozilla/5.0 (compatible; YandexImages/3.0)' ) );

		// Lighthouse must see the banner so performance audits measure the real page.
		$this->assertSame( 0, preg_match( $pattern, 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) Chrome-Lighthouse' ) );
		$this->assertSame( 0, preg_match( $pattern, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Chrome/120.0 Safari/537.36 LittleD-Browser' ) );
	}
}
