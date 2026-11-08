<?php
namespace DSolutions\CookieConsent\Tests\Unit;

use DSolutions\CookieConsent\Consent\ClientIp;
use PHPUnit\Framework\TestCase;

class ClientIpTest extends TestCase {

	public function test_default_mode_ignores_proxy_headers() {
		$server = array(
			'REMOTE_ADDR'          => '10.0.0.5',
			'HTTP_X_FORWARDED_FOR' => '203.0.113.9',
			'HTTP_CF_CONNECTING_IP' => '203.0.113.10',
		);
		$this->assertSame( '10.0.0.5', ClientIp::pick( $server, 'none' ) );
		$this->assertSame( '10.0.0.5', ClientIp::pick( $server, 'unknown-mode' ) );
	}

	public function test_cloudflare_header_is_used_only_when_selected() {
		$server = array(
			'REMOTE_ADDR'           => '10.0.0.5',
			'HTTP_CF_CONNECTING_IP' => '203.0.113.10',
		);
		$this->assertSame( '203.0.113.10', ClientIp::pick( $server, 'cf-connecting-ip' ) );
		$this->assertSame( '10.0.0.5', ClientIp::pick( $server, 'x-forwarded-for' ), 'a different header is not read' );
	}

	public function test_forwarded_for_takes_the_first_entry() {
		$server = array(
			'REMOTE_ADDR'          => '10.0.0.5',
			'HTTP_X_FORWARDED_FOR' => '203.0.113.9, 198.51.100.7, 10.0.0.5',
		);
		$this->assertSame( '203.0.113.9', ClientIp::pick( $server, 'x-forwarded-for' ) );
		$server['HTTP_X_FORWARDED_FOR'] = '2001:db8::1, 10.0.0.5';
		$this->assertSame( '2001:db8::1', ClientIp::pick( $server, 'x-forwarded-for' ) );
	}

	public function test_invalid_or_missing_header_falls_back_to_remote_addr() {
		$server = array(
			'REMOTE_ADDR'          => '10.0.0.5',
			'HTTP_X_FORWARDED_FOR' => 'not-an-ip, 203.0.113.9',
		);
		$this->assertSame( '10.0.0.5', ClientIp::pick( $server, 'x-forwarded-for' ) );
		$this->assertSame( '10.0.0.5', ClientIp::pick( array( 'REMOTE_ADDR' => '10.0.0.5' ), 'cf-connecting-ip' ) );
		$this->assertSame( '', ClientIp::pick( array(), 'none' ) );
		$this->assertSame( '', ClientIp::pick( array( 'REMOTE_ADDR' => 'garbage' ), 'none' ) );
	}
}
