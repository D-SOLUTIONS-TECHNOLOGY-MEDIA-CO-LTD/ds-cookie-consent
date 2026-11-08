<?php
namespace DSolutions\CookieConsent\Tests\Unit;

use DSolutions\CookieConsent\Catalog\KnownDb;
use DSolutions\CookieConsent\Catalog\KnownDbValidator;
use PHPUnit\Framework\TestCase;

/**
 * Known-cookie database: the bundled file is valid and its hashes are reproducible in PHP; tampering and malformed
 * files are rejected; lookups use exact names before patterns.
 */
class KnownDbTest extends TestCase {

	private function bundled_json() {
		return (string) file_get_contents( dirname( __DIR__, 3 ) . '/data/known-cookies.json' );
	}

	private function sample( array $over = array() ) {
		$service = array_merge(
			array(
				'slug'             => 'littled-stats',
				'name'             => 'LittleD Stats',
				'category'         => 'analytics',
				'provider_company' => 'LittleD Co',
				'privacy_url'      => 'https://littled.example/privacy',
				'country'          => 'VN',
				'is_third_party'   => true,
				'script_patterns'  => array(
					'hosts'        => array( 'stats.littled.example' ),
					'src_regex'    => array( 'littled\.example/tag\.js' ),
					'inline_regex' => array(),
				),
				'cookies'          => array(
					array(
						'name'             => '_lt_id',
						'is_pattern'       => false,
						'storage'          => 'cookie',
						'duration_vi'      => '1 năm',
						'duration_en'      => '1 year',
						'duration_seconds' => 31536000,
						'purpose_vi'       => 'Nhận diện khách',
						'purpose_en'       => 'Identifies visitors',
						'domain_hint'      => '',
					),
					array(
						'name'             => '_lt_*',
						'is_pattern'       => true,
						'storage'          => 'cookie',
						'duration_vi'      => '1 ngày',
						'duration_en'      => '1 day',
						'duration_seconds' => 86400,
						'purpose_vi'       => 'Phiên',
						'purpose_en'       => 'Session',
						'domain_hint'      => '',
					),
				),
			),
			$over
		);
		return array(
			'version'      => '2026.11.07.1',
			'generated_at' => '2026-11-07T00:00:00Z',
			'services'     => array( $service ),
		);
	}

	public function test_bundled_file_is_valid() {
		$result = KnownDbValidator::parse( $this->bundled_json() );
		$this->assertSame( array(), $result['errors'] );
		$this->assertNotNull( $result['data'] );
		$this->assertGreaterThanOrEqual( 60, ( new KnownDb( $result['data'] ) )->cookie_count() );
	}

	public function test_php_recomputes_the_build_tools_services_hash() {
		$json = $this->bundled_json();
		$data = json_decode( $json, true );
		$this->assertSame( $data['sha256'], KnownDbValidator::services_hash( $json ) );
	}

	public function test_sidecar_is_the_sha256_of_the_whole_file() {
		$sidecar = (string) file_get_contents( dirname( __DIR__, 3 ) . '/data/known-cookies.json.sha256' );
		$this->assertSame( 1, preg_match( '/^([0-9a-f]{64})\s/', $sidecar, $m ) );
		$this->assertSame( hash( 'sha256', $this->bundled_json() ), $m[1] );
	}

	public function test_a_changed_payload_is_rejected_by_the_hash_check() {
		$json     = $this->bundled_json();
		$tampered = str_replace( 'Phân biệt người dùng', 'Phân biệt nguoi dung', $json, $count );
		$this->assertGreaterThan( 0, $count, 'fixture text must exist in the bundled file' );
		$result = KnownDbValidator::parse( $tampered );
		$this->assertNull( $result['data'] );
		$this->assertContains( 'sha256 does not match the services payload', $result['errors'] );
	}

	public function test_hash_keeps_empty_objects_distinct_from_empty_arrays() {
		$a = '{"services":[{"x":{}}]}';
		$b = '{"services":[{"x":[]}]}';
		$this->assertNotSame( KnownDbValidator::services_hash( $a ), KnownDbValidator::services_hash( $b ) );
		// Key order does not matter, array order does.
		$this->assertSame( KnownDbValidator::services_hash( '{"services":[{"a":1,"b":2}]}' ), KnownDbValidator::services_hash( '{"services":[{"b":2,"a":1}]}' ) );
		$this->assertNotSame( KnownDbValidator::services_hash( '{"services":[1,2]}' ), KnownDbValidator::services_hash( '{"services":[2,1]}' ) );
	}

	public function test_a_valid_sample_without_hash_field_parses() {
		$this->assertSame( array(), KnownDbValidator::parse( json_encode( $this->sample() ) )['errors'] );
	}

	public function test_structure_errors_are_reported() {
		$bad = array(
			'not json'            => '{oops',
			'empty'               => '',
			'no services'         => json_encode( array_merge( $this->sample(), array( 'services' => array() ) ) ),
			'bad slug'            => json_encode( $this->sample( array( 'slug' => 'Bad Slug' ) ) ),
			'http policy url'     => json_encode( $this->sample( array( 'privacy_url' => 'http://littled.example' ) ) ),
			'bad category'        => json_encode( $this->sample( array( 'category' => 'unclassified' ) ) ),
			'broken regex'        => json_encode( $this->sample( array( 'script_patterns' => array( 'hosts' => array(), 'src_regex' => array( '(x' ), 'inline_regex' => array() ) ) ) ),
			'tilde in regex'      => json_encode( $this->sample( array( 'script_patterns' => array( 'hosts' => array(), 'src_regex' => array( 'a~b' ), 'inline_regex' => array() ) ) ) ),
			'upper-case host'     => json_encode( $this->sample( array( 'script_patterns' => array( 'hosts' => array( 'Bad.Example' ), 'src_regex' => array(), 'inline_regex' => array() ) ) ) ),
			'cookies not array'   => json_encode( $this->sample( array( 'cookies' => 'x' ) ) ),
		);
		foreach ( $bad as $label => $json ) {
			$result = KnownDbValidator::parse( $json );
			$this->assertNull( $result['data'], $label );
			$this->assertNotEmpty( $result['errors'], $label );
		}
	}

	public function test_pattern_flag_must_match_the_name() {
		$sample                                = $this->sample();
		$sample['services'][0]['cookies'][0]['is_pattern'] = true;
		$this->assertNotEmpty( KnownDbValidator::parse( json_encode( $sample ) )['errors'] );
	}

	public function test_duplicate_slugs_are_rejected() {
		$sample               = $this->sample();
		$sample['services'][] = $sample['services'][0];
		$this->assertContains( 'services[1]: duplicate slug', KnownDbValidator::parse( json_encode( $sample ) )['errors'] );
	}

	public function test_lookup_prefers_exact_names_over_patterns() {
		$db  = KnownDb::from_json( json_encode( $this->sample() ) );
		$hit = $db->find_cookie( '_lt_id' );
		$this->assertTrue( $hit['exact'] );
		$this->assertSame( '1 năm', $hit['cookie']['duration_vi'] );
		$hit = $db->find_cookie( '_lt_session' );
		$this->assertFalse( $hit['exact'] );
		$this->assertSame( '_lt_*', $hit['cookie']['name'] );
		$this->assertNull( $db->find_cookie( '_lt_id', 'localStorage' ) );
		$this->assertNull( $db->find_cookie( 'other' ) );
	}

	public function test_bundled_lookups_resolve_real_cookies() {
		$db = KnownDb::from_json( $this->bundled_json() );
		$ga = $db->find_cookie( '_ga_ABC123' );
		$this->assertSame( 'google-analytics', $ga['service']['slug'] );
		$this->assertSame( 'marketing', $db->find_cookie( '_fbp' )['service']['category'] );
		$this->assertSame( 'microsoft-clarity', $db->find_service_by_script( 'https://www.clarity.ms/tag/abc' )['slug'] );
		$this->assertNull( $db->find_service_by_script( 'https://cdn.littled.example/app.js' ) );
	}

	public function test_the_same_cookie_name_in_two_services_is_rejected() {
		$sample               = $this->sample();
		$second               = $sample['services'][0];
		$second['slug']       = 'littled-other';
		$sample['services'][] = $second;
		$errors               = KnownDbValidator::parse( json_encode( $sample ) )['errors'];
		$this->assertContains( 'services[1]: duplicate cookie cookie:_lt_id', $errors );
	}

	public function test_optional_cookie_fields_must_be_strings() {
		$sample = $this->sample();
		$sample['services'][0]['cookies'][0]['table_group'] = 'grp';
		$sample['services'][0]['cookies'][0]['applies_to']  = 'littled.example';
		$this->assertSame( array(), KnownDbValidator::parse( json_encode( $sample ) )['errors'] );
		$sample['services'][0]['cookies'][0]['applies_to_en'] = 5;
		$this->assertNotEmpty( KnownDbValidator::parse( json_encode( $sample ) )['errors'] );
	}

	public function test_remote_regexes_with_nested_quantifiers_are_rejected() {
		$sample = $this->sample( array( 'script_patterns' => array( 'hosts' => array(), 'src_regex' => array( '(a+)+$' ), 'inline_regex' => array() ) ) );
		$this->assertNotEmpty( KnownDbValidator::parse( json_encode( $sample ) )['errors'] );
	}
}
