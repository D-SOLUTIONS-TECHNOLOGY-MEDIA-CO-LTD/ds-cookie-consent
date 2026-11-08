<?php
use DSolutions\CookieConsent\Catalog\KnownDb;
use DSolutions\CookieConsent\Catalog\KnownDbUpdater;
use DSolutions\CookieConsent\Catalog\KnownDbValidator;

/**
 * Remote known-database update: stored in uploads/dscc only when the SHA-256 proofs hold.
 */
class Dscc_Known_Db_Updater_Test extends WP_UnitTestCase {

	const URL = 'https://203.0.113.5/known-cookies.json'; // A literal public IP: no DNS lookup is needed.

	/** @var array url => array(code, body) */
	private $responses = array();

	public function set_up() {
		parent::set_up();
		$this->cleanup();
		add_filter( 'pre_http_request', array( $this, 'fake_http' ), 10, 3 );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'fake_http' ), 10 );
		$this->cleanup();
		parent::tear_down();
	}

	private function cleanup() {
		if ( is_file( KnownDb::stored_path() ) ) {
			wp_delete_file( KnownDb::stored_path() );
		}
		delete_option( KnownDbUpdater::META_OPTION );
		KnownDb::flush();
	}

	public function fake_http( $pre, $args, $url ) {
		if ( ! isset( $this->responses[ $url ] ) ) {
			return array(
				'headers'  => array(),
				'body'     => '',
				'response' => array( 'code' => 404, 'message' => 'Not Found' ),
			);
		}
		return array(
			'headers'  => array(),
			'body'     => $this->responses[ $url ][1],
			'response' => array( 'code' => $this->responses[ $url ][0], 'message' => 'OK' ),
		);
	}

	/**
	 * A newer database built from the bundled one, with a correct sha256 field.
	 */
	private function newer_json( $version = '2099.01.01.1', $sign = true ) {
		$data            = json_decode( (string) file_get_contents( KnownDb::bundled_path() ), true );
		$data['version'] = $version;
		$data['services'][0]['name'] = 'LittleD Renamed';
		unset( $data['sha256'] );
		$flags = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
		if ( $sign ) {
			$data['sha256'] = KnownDbValidator::services_hash( wp_json_encode( $data, $flags ) );
		}
		return wp_json_encode( $data, $flags );
	}

	public function test_a_valid_file_with_a_matching_sha256_field_is_stored_and_used() {
		$json                       = $this->newer_json();
		$this->responses[ self::URL ] = array( 200, $json );
		$result                     = KnownDbUpdater::update( self::URL );
		$this->assertIsArray( $result );
		$this->assertSame( '2099.01.01.1', $result['version'] );
		$this->assertFileExists( KnownDb::stored_path() );
		$this->assertSame( $json, file_get_contents( KnownDb::stored_path() ) );
		$this->assertSame( '2099.01.01.1', KnownDb::load()->version() );
		$this->assertSame( 'LittleD Renamed', KnownDb::load()->services()[0]['name'] );
		$meta = get_option( KnownDbUpdater::META_OPTION );
		$this->assertSame( self::URL, $meta['url'] );
	}

	public function test_a_wrong_sha256_field_rejects_the_update_and_stores_nothing() {
		$json = $this->newer_json();
		$bad  = str_replace( '"LittleD Renamed"', '"LittleD Tampered"', $json );
		$this->responses[ self::URL ] = array( 200, $bad );
		$result                     = KnownDbUpdater::update( self::URL );
		$this->assertWPError( $result );
		$this->assertSame( 'dscc_db_invalid', $result->get_error_code() );
		$this->assertFileDoesNotExist( KnownDb::stored_path() );
		$this->assertNotSame( '2099.01.01.1', KnownDb::load()->version() );
	}

	public function test_a_wrong_sidecar_rejects_the_update() {
		$json                                = $this->newer_json();
		$this->responses[ self::URL ]          = array( 200, $json );
		$this->responses[ self::URL . '.sha256' ] = array( 200, str_repeat( 'a', 64 ) . "  known-cookies.json\n" );
		$result                              = KnownDbUpdater::update( self::URL );
		$this->assertWPError( $result );
		$this->assertSame( 'dscc_db_sidecar', $result->get_error_code() );
		$this->assertFileDoesNotExist( KnownDb::stored_path() );
	}

	public function test_a_correct_sidecar_alone_is_enough_when_the_file_has_no_sha256_field() {
		$json                                = $this->newer_json( '2099.02.02.2', false );
		$this->responses[ self::URL ]          = array( 200, $json );
		$this->responses[ self::URL . '.sha256' ] = array( 200, hash( 'sha256', $json ) . "  known-cookies.json\n" );
		$this->assertIsArray( KnownDbUpdater::update( self::URL ) );
		$this->assertSame( '2099.02.02.2', KnownDb::load()->version() );
	}

	public function test_a_file_with_no_proof_at_all_is_rejected() {
		$this->responses[ self::URL ] = array( 200, $this->newer_json( '2099.03.03.3', false ) );
		$result                     = KnownDbUpdater::update( self::URL );
		$this->assertWPError( $result );
		$this->assertSame( 'dscc_db_unverifiable', $result->get_error_code() );
		$this->assertFileDoesNotExist( KnownDb::stored_path() );
	}

	public function test_non_https_urls_and_download_failures_are_refused() {
		$this->assertSame( 'dscc_db_url', KnownDbUpdater::update( 'http://203.0.113.5/k.json' )->get_error_code() );
		$this->assertSame( 'dscc_db_url', KnownDbUpdater::update( '' )->get_error_code() );
		$this->assertSame( 'dscc_db_download', KnownDbUpdater::update( self::URL )->get_error_code() );
		$this->responses[ self::URL ] = array( 500, 'oops' );
		$this->assertSame( 'dscc_db_download', KnownDbUpdater::update( self::URL )->get_error_code() );
	}

	public function test_malformed_content_is_rejected() {
		$this->responses[ self::URL ] = array( 200, '{"version":"9","services":[]}' );
		$this->assertSame( 'dscc_db_invalid', KnownDbUpdater::update( self::URL )->get_error_code() );
		$this->responses[ self::URL ] = array( 200, '<html>not json</html>' );
		$this->assertSame( 'dscc_db_invalid', KnownDbUpdater::update( self::URL )->get_error_code() );
	}

	public function test_an_older_download_never_replaces_the_bundled_database() {
		$this->responses[ self::URL ] = array( 200, $this->newer_json( '2000.01.01.1' ) );
		$this->assertIsArray( KnownDbUpdater::update( self::URL ) );
		$this->assertNotSame( '2000.01.01.1', KnownDb::load()->version() );
	}

	public function test_a_tampered_stored_copy_is_ignored_on_load() {
		$this->responses[ self::URL ] = array( 200, $this->newer_json() );
		KnownDbUpdater::update( self::URL );
		file_put_contents( KnownDb::stored_path(), str_replace( 'LittleD Renamed', 'LittleD Tampered', (string) file_get_contents( KnownDb::stored_path() ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		KnownDb::flush();
		$this->assertNotSame( '2099.01.01.1', KnownDb::load()->version(), 'the bundled file is used when the stored copy fails its hash check' );
	}

	public function test_configured_url_comes_from_the_setting_and_the_filter() {
		$this->assertSame( '', KnownDbUpdater::configured_url() );
		add_filter(
			'dscc_known_db_url',
			static function () {
				return 'https://data.littled.example/x.json';
			}
		);
		$this->assertSame( 'https://data.littled.example/x.json', KnownDbUpdater::configured_url() );
		remove_all_filters( 'dscc_known_db_url' );
	}

	public function test_a_sidecar_that_fails_with_anything_but_404_aborts_the_update() {
		$this->responses[ self::URL ]             = array( 200, $this->newer_json() );
		$this->responses[ self::URL . '.sha256' ] = array( 500, 'oops' );
		$result                                   = KnownDbUpdater::update( self::URL );
		$this->assertWPError( $result );
		$this->assertSame( 'dscc_db_download', $result->get_error_code() );
		$this->assertFileDoesNotExist( KnownDb::stored_path() );
	}

	public function test_sidecar_url_keeps_the_query_string() {
		$this->assertSame( 'https://203.0.113.5/k.json.sha256', KnownDbUpdater::sidecar_url( 'https://203.0.113.5/k.json' ) );
		$this->assertSame( 'https://203.0.113.5/k.json.sha256?ref=main&x=1', KnownDbUpdater::sidecar_url( 'https://203.0.113.5/k.json?ref=main&x=1' ) );
		$url                                       = 'https://203.0.113.5/k.json?ref=main';
		$json                                      = $this->newer_json( '2099.04.04.4', false );
		$this->responses[ $url ]                   = array( 200, $json );
		$this->responses[ 'https://203.0.113.5/k.json.sha256?ref=main' ] = array( 200, hash( 'sha256', $json ) . "  k.json\n" );
		$this->assertIsArray( KnownDbUpdater::update( $url ) );
	}
}
