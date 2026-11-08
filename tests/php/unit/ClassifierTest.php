<?php
namespace DSolutions\CookieConsent\Tests\Unit;

use DSolutions\CookieConsent\Catalog\Classifier;
use DSolutions\CookieConsent\Catalog\KnownDb;
use PHPUnit\Framework\TestCase;

/**
 * Classification order (spec 6.3): site catalog, then known database (exact, pattern), then script host, then unclassified.
 */
class ClassifierTest extends TestCase {

	private function known() {
		return KnownDb::from_json( (string) file_get_contents( dirname( __DIR__, 3 ) . '/data/known-cookies.json' ) );
	}

	private function classifier( array $site = array() ) {
		return new Classifier(
			$this->known(),
			static function ( $name, $storage ) use ( $site ) {
				return isset( $site[ $storage . ':' . $name ] ) ? $site[ $storage . ':' . $name ] : null;
			}
		);
	}

	public function test_the_sites_confirmed_catalog_wins_over_the_known_database() {
		$c   = $this->classifier(
			array(
				'cookie:_ga' => array(
					'service_slug' => 'littled-own-stats',
					'category'     => 'necessary',
				),
			)
		);
		$res = $c->classify( array( 'name' => '_ga' ) );
		$this->assertSame( 'confirmed', $res['match'] );
		$this->assertSame( 'confirmed', $res['status'] );
		$this->assertSame( 'necessary', $res['category'] );
		$this->assertSame( 'littled-own-stats', $res['service_slug'] );
	}

	public function test_known_database_exact_name_is_auto() {
		$res = $this->classifier()->classify( array( 'name' => '_fbp' ) );
		$this->assertSame( 'known_exact', $res['match'] );
		$this->assertSame( 'auto', $res['status'] );
		$this->assertSame( 'marketing', $res['category'] );
		$this->assertSame( 'meta-pixel', $res['service_slug'] );
		$this->assertSame( '_fbp', $res['known_cookie']['name'] );
	}

	public function test_known_database_pattern_name_is_auto() {
		$res = $this->classifier()->classify( array( 'name' => '_ga_Q1W2E3' ) );
		$this->assertSame( 'known_pattern', $res['match'] );
		$this->assertSame( 'auto', $res['status'] );
		$this->assertSame( 'analytics', $res['category'] );
	}

	public function test_exact_match_beats_pattern_match() {
		$this->assertSame( 'known_exact', $this->classifier()->classify( array( 'name' => '_ga' ) )['match'] );
	}

	public function test_unknown_cookie_with_a_known_script_host_is_attributed_but_new() {
		$res = $this->classifier()->classify(
			array(
				'name' => 'LittleD_unknown_cookie',
				'host' => 'www.clarity.ms',
			)
		);
		$this->assertSame( 'script_host', $res['match'] );
		$this->assertSame( 'new', $res['status'] );
		$this->assertSame( 'microsoft-clarity', $res['service_slug'] );
		$this->assertSame( 'analytics', $res['category'] );
		$this->assertNull( $res['known_cookie'] );
	}

	public function test_script_without_a_cookie_name_is_classified_by_src_or_inline() {
		$res = $this->classifier()->classify( array( 'src' => 'https://static.hotjar.com/c/hotjar-1.js' ) );
		$this->assertSame( 'script_host', $res['match'] );
		$this->assertSame( 'hotjar', $res['service_slug'] );
	}

	public function test_nothing_matches_means_unclassified_and_new() {
		$res = $this->classifier()->classify(
			array(
				'name' => 'LittleD_mystery',
				'host' => 'cdn.littled.example',
			)
		);
		$this->assertSame( 'unclassified', $res['match'] );
		$this->assertSame( 'new', $res['status'] );
		$this->assertSame( 'unclassified', $res['category'] );
		$this->assertSame( '', $res['service_slug'] );
	}

	public function test_storage_kind_is_part_of_the_match() {
		$res = $this->classifier()->classify(
			array(
				'name'    => '_fbp',
				'storage' => 'localStorage',
			)
		);
		$this->assertSame( 'unclassified', $res['match'] );
	}

	public function test_without_a_known_database_only_the_site_catalog_applies() {
		$c = new Classifier(
			null,
			static function () {
				return null;
			}
		);
		$this->assertSame( 'unclassified', $c->classify( array( 'name' => '_ga' ) )['match'] );
	}
}
