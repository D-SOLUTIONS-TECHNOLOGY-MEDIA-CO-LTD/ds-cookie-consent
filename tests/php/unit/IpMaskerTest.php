<?php
namespace DSolutions\CookieConsent\Tests\Unit;

use DSolutions\CookieConsent\Consent\IpMasker;
use PHPUnit\Framework\TestCase;

class IpMaskerTest extends TestCase {

	public function test_ipv4_masks_last_octet() {
		$this->assertSame( '203.0.113.0', IpMasker::mask( '203.0.113.77' ) );
	}

	public function test_ipv6_keeps_48_bits() {
		$this->assertSame( '2001:db8:abcd::', IpMasker::mask( '2001:0db8:abcd:0012:0000:0000:0000:0001' ) );
		$this->assertSame( '2001:db8:abcd::', IpMasker::mask( '2001:db8:abcd:12::1' ) );
	}

	public function test_ipv4_mapped_ipv6_treated_as_ipv4() {
		$this->assertSame( '198.51.100.0', IpMasker::mask( '::ffff:198.51.100.9' ) );
	}

	public function test_invalid_returns_empty() {
		$this->assertSame( '', IpMasker::mask( 'not-an-ip' ) );
		$this->assertSame( '', IpMasker::mask( '' ) );
		$this->assertSame( '', IpMasker::mask( '999.1.1.1' ) );
	}
}
