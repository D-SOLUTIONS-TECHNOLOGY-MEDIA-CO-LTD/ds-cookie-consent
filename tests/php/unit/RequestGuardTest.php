<?php
namespace DSolutions\CookieConsent\Tests\Unit;

use DSolutions\CookieConsent\Consent\RequestGuard;
use PHPUnit\Framework\TestCase;

class RequestGuardTest extends TestCase {

	private $hosts = array( 'littled.example', 'www.littled.example' );

	public function test_same_host_origin_allowed() {
		$this->assertTrue( RequestGuard::origin_allowed( 'https://littled.example', '', $this->hosts ) );
		$this->assertTrue( RequestGuard::origin_allowed( 'https://LittleD.example:8443', '', array( 'littled.example' ) ) );
	}

	public function test_foreign_origin_rejected_even_with_good_referer() {
		$this->assertFalse( RequestGuard::origin_allowed( 'https://evil.example', 'https://littled.example/page', $this->hosts ) );
	}

	public function test_referer_used_when_origin_missing() {
		$this->assertTrue( RequestGuard::origin_allowed( '', 'https://www.littled.example/a?b=1', $this->hosts ) );
		$this->assertFalse( RequestGuard::origin_allowed( '', 'https://evil.example/a', $this->hosts ) );
	}

	public function test_missing_headers_and_null_origin_rejected() {
		$this->assertFalse( RequestGuard::origin_allowed( '', '', $this->hosts ) );
		$this->assertFalse( RequestGuard::origin_allowed( 'null', '', $this->hosts ) );
	}

	public function test_lookalike_host_rejected() {
		$this->assertFalse( RequestGuard::same_host( 'https://littled.example.evil.com', $this->hosts ) );
	}

	public function test_body_limit() {
		$this->assertTrue( RequestGuard::body_within_limit( 4096 ) );
		$this->assertFalse( RequestGuard::body_within_limit( 4097 ) );
		$this->assertFalse( RequestGuard::body_within_limit( -1 ) );
	}
}
