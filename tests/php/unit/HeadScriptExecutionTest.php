<?php
namespace DSolutions\CookieConsent\Tests\Unit;

use DSolutions\CookieConsent\Frontend\HeadScript;

/**
 * Runs the built head script in Node with a fake document.cookie, so the cookie parsing of the real script
 * (not a copy of it) is covered: the consent cookie must be found whatever its position in document.cookie.
 */
class HeadScriptExecutionTest extends UnitTestCase {

	private function advanced() {
		return array(
			'cookie_name'      => 'dscc_consent',
			'policy_version'   => '2026-11',
			'consent_days'     => 180,
			'skip_ua_patterns' => array(),
			'skip_bots'        => true,
			'consent_regions'  => array(),
			'url_passthrough'  => false,
		);
	}

	/**
	 * Execute the head script for a cookie header and return {consent, events, defaults}.
	 */
	private function run_head( $cookie_header ) {
		$adv    = $this->advanced();
		$script = HeadScript::build( HeadScript::config( $adv ) );
		$js     = 'const vm=require("vm");const d={cookie:' . json_encode( $cookie_header ) . ',documentElement:{className:""}};'
			. 'const w={document:d,navigator:{userAgent:"LittleD"}};w.window=w;vm.createContext(w);vm.runInContext(' . json_encode( $script ) . ',w);'
			. 'const L=w.dataLayer.map(a=>a&&a.event?a:Array.prototype.slice.call(a));'
			. 'process.stdout.write(JSON.stringify({consent:w.DSCC_BOOT.consent,layer:L}));';
		$proc = proc_open( array( 'node', '-e', $js ), array( 1 => array( 'pipe', 'w' ), 2 => array( 'pipe', 'w' ) ), $pipes );
		if ( ! is_resource( $proc ) ) {
			$this->markTestSkipped( 'node is not available' );
		}
		$out = stream_get_contents( $pipes[1] );
		$err = stream_get_contents( $pipes[2] );
		proc_close( $proc );
		$this->assertSame( '', $err, $err );
		return json_decode( $out, true );
	}

	private function cookie( array $over = array() ) {
		return rawurlencode( json_encode( array_merge( array( 'v' => '2026-11', 'id' => 'LittleD-1', 't' => time(), 'c' => array( 'analytics' => 1, 'marketing' => 0 ), 's' => array( 'ga' => 1 ), 'src' => 'banner' ), $over ) ) );
	}

	private function find_event( array $layer, $name ) {
		foreach ( $layer as $item ) {
			if ( is_array( $item ) && isset( $item['event'] ) && $name === $item['event'] ) {
				return $item;
			}
		}
		return null;
	}

	private function default_call( array $layer ) {
		foreach ( $layer as $item ) {
			if ( is_array( $item ) && isset( $item[0], $item[1] ) && 'consent' === $item[0] && 'default' === $item[1] ) {
				return $item[2];
			}
		}
		return null;
	}

	public function test_consent_cookie_is_restored_when_it_is_not_the_first_cookie() {
		foreach ( array( '', 'a=1; ', 'a=1; b=2; ' ) as $prefix ) {
			$res = $this->run_head( $prefix . 'dscc_consent=' . $this->cookie() . '; z=9' );
			$this->assertNotNull( $res['consent'], 'prefix "' . $prefix . '"' );
			$this->assertSame( true, $res['consent']['a'] );
			$this->assertSame( false, $res['consent']['m'] );
			$this->assertSame( 'granted', $this->default_call( $res['layer'] )['analytics_storage'] );
			$this->assertSame( 'denied', $this->default_call( $res['layer'] )['ad_storage'] );
			$event = $this->find_event( $res['layer'], 'dscc_consent_update' );
			$this->assertNotNull( $event, 'prefix "' . $prefix . '"' );
			$this->assertTrue( $event['dscc_analytics'] );
			$this->assertSame( array( 'ga' => 1 ), $event['dscc_services'] );
		}
	}

	public function test_a_cookie_whose_name_only_ends_like_ours_is_not_mistaken_for_it() {
		$res = $this->run_head( 'x_dscc_consent=' . $this->cookie() );
		$this->assertNull( $res['consent'] );
		$this->assertSame( 'denied', $this->default_call( $res['layer'] )['analytics_storage'] );
	}

	public function test_old_two_flag_cookie_is_ignored() {
		$old = rawurlencode( json_encode( array( 'v' => '2026-11', 'a' => true, 'm' => false, 't' => gmdate( 'c' ), 'id' => 'LittleD-2' ) ) );
		$res = $this->run_head( 'a=1; dscc_consent=' . $old . '; b=2' );
		$this->assertNull( $res['consent'] );
		$this->assertSame( 'denied', $this->default_call( $res['layer'] )['analytics_storage'] );
	}

	public function test_expired_or_other_version_cookie_is_ignored() {
		$this->assertNull( $this->run_head( 'a=1; dscc_consent=' . $this->cookie( array( 't' => time() - 200 * 86400 ) ) )['consent'] );
		$this->assertNull( $this->run_head( 'a=1; dscc_consent=' . $this->cookie( array( 'v' => 'old' ) ) )['consent'] );
	}
}
