<?php
use DSolutions\CookieConsent\Activator;
use DSolutions\CookieConsent\Consent\ConsentLogRepository;
use DSolutions\CookieConsent\Consent\LogExporter;

class Dscc_Log_Export_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Activator::install();
	}

	public function test_csv_has_bom_header_and_neutralised_formulas() {
		ConsentLogRepository::insert(
			array(
				'consent_id'     => 'LittleD-csv-0001',
				'choice'         => 'accept_all',
				'analytics'      => 1,
				'marketing'      => 1,
				'services_json'  => '{"ga":1}',
				'policy_version' => '2026-11',
				'lang'           => 'vi',
				'page_url'       => '/trang-chu',
				'ip_masked'      => '203.0.113.0',
				'user_agent'     => '=HYPERLINK("http://x","y")',
				'src'            => 'banner',
			)
		);

		$stream = fopen( 'php://memory', 'w+' );
		$count  = LogExporter::write( $stream, array() );
		rewind( $stream );
		$csv = stream_get_contents( $stream );
		fclose( $stream );

		$this->assertSame( 1, $count );
		$this->assertSame( "\xEF\xBB\xBF", substr( $csv, 0, 3 ) );
		$lines = str_getcsv( substr( $csv, 3 ), "\n" );
		$this->assertStringStartsWith( 'created_at_utc,choice,analytics,marketing', $lines[0] );
		$this->assertStringContainsString( 'accept_all', $lines[1] );
		$this->assertStringContainsString( "'=HYPERLINK", $csv, 'user agent starting with = must be prefixed' );
	}

	public function test_filters_apply() {
		foreach ( array( 'accept_all', 'reject_all' ) as $i => $choice ) {
			$flag = 'accept_all' === $choice ? 1 : 0;
			ConsentLogRepository::insert(
				array(
					'consent_id'     => 'LittleD-flt-000' . $i,
					'choice'         => $choice,
					'analytics'      => $flag,
					'marketing'      => $flag,
					'policy_version' => '2026-11',
					'lang'           => 'vi',
					'page_url'       => '/',
					'src'            => 'banner',
				)
			);
		}
		$stream = fopen( 'php://memory', 'w+' );
		$count  = LogExporter::write( $stream, array( 'choice' => 'reject_all' ) );
		fclose( $stream );
		$this->assertSame( 1, $count );
	}
}
