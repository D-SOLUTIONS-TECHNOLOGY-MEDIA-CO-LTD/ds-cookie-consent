<?php
namespace DSolutions\CookieConsent\Tests\Unit;

use DSolutions\CookieConsent\Update\ReleaseParser;
use PHPUnit\Framework\TestCase;

/**
 * Release feed parsing and version compare, driven by recorded GitHub responses.
 */
final class ReleaseParserTest extends TestCase {

	private function fixture( $name ) {
		return (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/github/' . $name . '.json' );
	}

	public function test_a_normal_release_is_parsed() {
		$release = ReleaseParser::parse( $this->fixture( 'release-latest' ), 'ds-cookie-consent.zip' );
		$this->assertSame( '1.2.0', $release['version'] );
		$this->assertSame( 'v1.2.0', $release['tag'] );
		$this->assertSame( 'https://github.com/LittleD-org/littled-cookie/releases/download/v1.2.0/ds-cookie-consent.zip', $release['package'] );
		$this->assertSame( 'https://api.github.com/repos/LittleD-org/littled-cookie/releases/assets/7001', $release['api_url'] );
		$this->assertSame( str_repeat( 'a', 64 ), $release['digest'] );
		$this->assertSame( 412345, $release['size'] );
		$this->assertStringContainsString( 'Fixed LittleD banner focus', $release['notes'] );
	}

	public function test_a_release_without_a_digest_is_still_usable() {
		$release = ReleaseParser::parse( $this->fixture( 'release-no-digest' ), 'ds-cookie-consent.zip' );
		$this->assertSame( '', $release['digest'] );
		$this->assertSame( '1.2.0', $release['version'] );
	}

	/**
	 * @dataProvider unusable_responses
	 */
	public function test_unusable_responses_give_null( $body ) {
		$this->assertNull( ReleaseParser::parse( $body, 'ds-cookie-consent.zip' ) );
	}

	public function unusable_responses() {
		$dir = dirname( __DIR__ ) . '/fixtures/github/';
		return array(
			'draft'                => array( (string) file_get_contents( $dir . 'release-draft.json' ) ),
			'pre-release'          => array( (string) file_get_contents( $dir . 'release-prerelease.json' ) ),
			'asset missing'        => array( (string) file_get_contents( $dir . 'release-no-asset.json' ) ),
			'tag is not a version' => array( (string) file_get_contents( $dir . 'release-bad-tag.json' ) ),
			'plain http download'  => array( (string) file_get_contents( $dir . 'release-insecure-asset.json' ) ),
			'not json'             => array( '<html>rate limited</html>' ),
			'json scalar'          => array( '"x"' ),
			'empty'                => array( '' ),
			'api error object'     => array( '{"message":"Not Found","documentation_url":"https://docs.github.com"}' ),
		);
	}

	public function test_asset_name_must_match_exactly() {
		$this->assertNull( ReleaseParser::parse( $this->fixture( 'release-latest' ), 'other.zip' ) );
	}

	public function test_versions_are_read_from_tags() {
		$this->assertSame( '1.0.0', ReleaseParser::version_from_tag( 'v1.0.0' ) );
		$this->assertSame( '2.3', ReleaseParser::version_from_tag( '2.3' ) );
		$this->assertSame( '1.0.0-rc1', ReleaseParser::version_from_tag( 'V1.0.0-rc1' ) );
		$this->assertSame( '', ReleaseParser::version_from_tag( 'release-1' ) );
		$this->assertSame( '', ReleaseParser::version_from_tag( 'v1.0.0; rm -rf' ) );
		$this->assertSame( '', ReleaseParser::version_from_tag( null ) );
	}

	public function test_version_compare() {
		$this->assertTrue( ReleaseParser::is_newer( '1.0.1', '1.0.0' ) );
		$this->assertTrue( ReleaseParser::is_newer( '1.10.0', '1.9.9' ) );
		$this->assertTrue( ReleaseParser::is_newer( '1.0.0', '1.0.0-rc1' ) );
		$this->assertFalse( ReleaseParser::is_newer( '1.0.0', '1.0.0' ) );
		$this->assertFalse( ReleaseParser::is_newer( '0.9.0', '1.0.0' ) );
		$this->assertFalse( ReleaseParser::is_newer( '', '1.0.0' ) );
	}

	public function test_repository_slugs() {
		$this->assertTrue( ReleaseParser::valid_repo( 'd-solutions/ds-cookie-consent' ) );
		$this->assertTrue( ReleaseParser::valid_repo( 'LittleD_org/littled.cookie' ) );
		foreach ( array( '', 'nope', 'a/b/c', '../x/y', 'a b/c', 'https://github.com/a/b', 'a/b?x=1', 5, null ) as $bad ) {
			$this->assertFalse( ReleaseParser::valid_repo( $bad ), var_export( $bad, true ) );
		}
	}
}
