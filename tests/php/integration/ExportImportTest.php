<?php
use DSolutions\CookieConsent\Activator;
use DSolutions\CookieConsent\Catalog\CookieRepository;
use DSolutions\CookieConsent\Catalog\ServiceRepository;
use DSolutions\CookieConsent\Consent\ConsentLogRepository;
use DSolutions\CookieConsent\Database\Schema;
use DSolutions\CookieConsent\Settings\Settings;
use DSolutions\CookieConsent\Transfer\ExportBuilder;
use DSolutions\CookieConsent\Transfer\ImportPlanner;
use DSolutions\CookieConsent\Transfer\ImportReader;
use DSolutions\CookieConsent\Transfer\Presets;

/**
 * Export, preview and import of the configuration.
 */
class Dscc_Export_Import_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		global $wpdb;
		Activator::install(); // Temporary tables, like every other test of the suite.
		Settings::flush();
		$wpdb->query( 'DELETE FROM ' . Schema::cookies_table() ); // phpcs:ignore WordPress.DB
		$wpdb->query( 'DELETE FROM ' . Schema::services_table() ); // phpcs:ignore WordPress.DB
		delete_option( 'dscc_texts_vi' );
		delete_option( 'dscc_texts_en' );
	}

	public function tear_down() {
		parent::tear_down();
		Settings::flush(); // The settings cache is static and survives the rollback.
	}

	private function seed_site() {
		$service = ServiceRepository::upsert(
			array(
				'slug'             => 'littled-analytics',
				'name'             => 'LittleD Analytics',
				'category_key'     => 'analytics',
				'provider_company' => 'LittleD Co',
				'privacy_url'      => 'https://littled.example/privacy',
				'script_patterns'  => array(
					'hosts'        => array( 'cdn.littled.example' ),
					'src_regex'    => array(),
					'inline_regex' => array(),
				),
				'status'           => 'confirmed',
				'source'           => 'manual',
			)
		);
		CookieRepository::upsert(
			array(
				'service_id'        => $service,
				'name'              => '_littled_id',
				'duration_label'    => '1 năm',
				'duration_label_en' => '1 year',
				'duration_seconds'  => 31536000,
				'purpose'           => 'LittleD mục đích',
				'purpose_en'        => 'LittleD purpose',
				'status'            => 'confirmed',
				'source'            => 'manual',
			)
		);
		CookieRepository::upsert(
			array(
				'service_id' => 0,
				'name'       => 'littled_orphan',
				'status'     => 'new',
			)
		);
		Settings::patch_group(
			'appearance',
			array(
				'color_primary' => '#112233',
				'layout'        => 'bar',
			)
		);
		Settings::patch_group( 'advanced', array( 'skip_ua_patterns' => array( 'LittleD-App' ) ) );
		update_option( 'dscc_texts_vi', array( 'accept_all' => 'LittleD đồng ý' ) );
	}

	private function wipe_catalog() {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . Schema::cookies_table() ); // phpcs:ignore WordPress.DB
		$wpdb->query( 'DELETE FROM ' . Schema::services_table() ); // phpcs:ignore WordPress.DB
		delete_option( 'dscc_texts_vi' );
		Settings::patch_group(
			'appearance',
			array(
				'color_primary' => '#005ea3',
				'layout'        => 'card',
			)
		);
		Settings::patch_group( 'advanced', array( 'skip_ua_patterns' => array() ) );
	}

	private function export_without_time() {
		$doc = ExportBuilder::build();
		unset( $doc['exported_at'] );
		return $doc;
	}

	public function test_the_export_has_the_documented_shape_and_leaves_out_site_state() {
		$this->seed_site();
		$doc = ExportBuilder::build();
		$this->assertSame( 'dscc-export', $doc['format'] );
		$this->assertSame( 1, $doc['version'] );
		$this->assertMatchesRegularExpression( '/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/', $doc['exported_at'] );
		$this->assertSame( 'bar', $doc['settings']['appearance']['layout'] );
		$this->assertSame( array( 'LittleD-App' ), $doc['settings']['advanced']['skip_ua_patterns'] );
		$this->assertSame( 'LittleD đồng ý', $doc['texts']['vi']->accept_all );
		$this->assertSame( 'littled-analytics', $doc['services'][0]['slug'] );
		$this->assertTrue( $doc['services'][0]['block_enabled'] );
		$this->assertSame( array( 'cdn.littled.example' ), $doc['services'][0]['script_patterns']['hosts'] );

		foreach ( array( 'logo_id', 'logo_popup_id' ) as $key ) {
			$this->assertArrayNotHasKey( $key, $doc['settings']['appearance'] );
		}
		foreach ( array( 'policy_version', 'trusted_ip_header', 'delete_data_on_uninstall' ) as $key ) {
			$this->assertArrayNotHasKey( $key, $doc['settings']['advanced'] );
		}
		$this->assertArrayNotHasKey( 'notify_email', $doc['settings']['scanner'] );
		$this->assertArrayNotHasKey( 'cookie_policy_page_id', $doc['settings']['content'] );

		$names = wp_list_pluck( $doc['cookies'], 'name' );
		$this->assertContains( '_littled_id', $names );
		$this->assertContains( 'littled_orphan', $names );
		$orphan = $doc['cookies'][ array_search( 'littled_orphan', $names, true ) ];
		$this->assertSame( '', $orphan['service_slug'] );
		$this->assertJson( ExportBuilder::to_json() );
	}

	public function test_export_then_import_gives_an_identical_export() {
		$this->seed_site();
		$first = $this->export_without_time();
		$json  = ExportBuilder::to_json();

		$this->wipe_catalog();
		$this->assertNotEquals( $first, $this->export_without_time(), 'the wipe really changed the site' );

		$doc = ImportReader::read( $json );
		$this->assertIsArray( $doc );
		ImportPlanner::apply( $doc );

		$this->assertEquals( $first, $this->export_without_time(), 'round trip is identical' );

		// A second import changes nothing: the preview says so.
		$plan = ImportPlanner::plan( ImportReader::read( $json ) );
		$this->assertSame( 0, $plan['changes'] );
		$this->assertSame( 0, $plan['services']['new'] + $plan['services']['updated'] );
		$this->assertSame( 0, $plan['cookies']['new'] + $plan['cookies']['updated'] );
		$this->assertSame( $first['services'][0]['slug'], ServiceRepository::find_by_slug( 'littled-analytics' )['slug'] );
	}

	public function test_the_preview_counts_new_updated_and_unchanged_without_writing() {
		$this->seed_site();
		$doc = ImportReader::read( ExportBuilder::to_json() );

		$doc['services']['littled-analytics']['name']                 = 'LittleD Analytics 2';
		$doc['services']['littled-new']                               = array(
			'slug'         => 'littled-new',
			'name'         => 'LittleD New',
			'category_key' => 'marketing',
		);
		$doc['cookies'][]                                             = array(
			'service_slug' => 'littled-new',
			'name'         => '_littled_new',
			'storage'      => 'cookie',
		);
		$doc['cookies'][]                                             = array(
			'service_slug' => 'no-such-platform',
			'name'         => '_littled_lost',
		);
		$doc['settings']['appearance']['color_primary']               = '#445566';
		$doc['texts']['en']['accept_all']                             = 'LittleD accept';

		$plan = ImportPlanner::plan( $doc );
		$this->assertSame( 1, $plan['services']['new'] );
		$this->assertSame( 1, $plan['services']['updated'] );
		$this->assertSame( 1, $plan['cookies']['new'] );
		$this->assertSame( 1, $plan['cookies']['skipped'] );
		$this->assertSame( 2, $plan['cookies']['unchanged'] );
		$this->assertSame( 1, $plan['settings']['count'] );
		$this->assertSame( 'appearance', $plan['settings']['changes'][0]['group'] );
		$this->assertSame( 'color_primary', $plan['settings']['changes'][0]['key'] );
		$this->assertSame( '#112233', $plan['settings']['changes'][0]['old'] );
		$this->assertSame( '#445566', $plan['settings']['changes'][0]['new'] );
		$this->assertSame( 1, $plan['texts']['en'] );
		$this->assertNotEmpty( $plan['warnings'], 'the cookie of an unknown platform is reported' );

		// Planning wrote nothing.
		$this->assertSame( 'LittleD Analytics', ServiceRepository::find_by_slug( 'littled-analytics' )['name'] );
		$this->assertNull( ServiceRepository::find_by_slug( 'littled-new' ) );
		$this->assertSame( '#112233', Settings::get( 'appearance', 'color_primary' ) );
	}

	public function test_import_changes_only_what_the_file_names_and_never_deletes() {
		$this->seed_site();
		Settings::patch_group( 'appearance', array( 'widget_enabled' => true ) );
		$json = (string) wp_json_encode(
			array(
				'format'   => 'dscc-export',
				'version'  => 1,
				'settings' => array( 'appearance' => array( 'button_order' => 'accept_first' ) ),
				'services' => array(
					array(
						'slug' => 'littled-extra',
						'name' => 'LittleD Extra',
					),
				),
			)
		);
		ImportPlanner::apply( ImportReader::read( $json ) );

		$this->assertSame( 'accept_first', Settings::get( 'appearance', 'button_order' ) );
		$this->assertTrue( Settings::get( 'appearance', 'widget_enabled' ), 'a checkbox the file does not mention is not switched off' );
		$this->assertSame( '#112233', Settings::get( 'appearance', 'color_primary' ) );
		$this->assertNotFalse( ServiceRepository::find_by_slug( 'littled-extra' ) );
		$this->assertNotFalse( ServiceRepository::find_by_slug( 'littled-analytics' ), 'platforms missing from the file stay' );
		$this->assertNotNull( CookieRepository::find_identity( '_littled_id' ) );
		$this->assertSame( 'LittleD đồng ý', Settings::text_overrides( 'vi' )['accept_all'], 'text overrides are merged, not wiped' );
	}

	public function test_import_never_touches_the_consent_log() {
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . Schema::log_table() ); // phpcs:ignore WordPress.DB
		ConsentLogRepository::insert(
			array(
				'consent_id'     => 'LittleD-log',
				'choice'         => 'accept_all',
				'analytics'      => 1,
				'marketing'      => 1,
				'policy_version' => '2026-11',
				'lang'           => 'vi',
				'page_url'       => '/',
				'src'            => 'banner',
			)
		);
		$this->seed_site();
		ImportPlanner::apply( ImportReader::read( ExportBuilder::to_json() ) );
		$this->assertSame( 1, (int) $wpdb->get_var( 'SELECT COUNT(*) FROM ' . Schema::log_table() ) ); // phpcs:ignore WordPress.DB
		$wpdb->query( 'DELETE FROM ' . Schema::log_table() ); // phpcs:ignore WordPress.DB
	}

	public function test_site_bound_and_unknown_settings_in_a_file_are_ignored_with_a_warning() {
		$before = Settings::group( 'advanced' );
		$json   = (string) wp_json_encode(
			array(
				'format'   => 'dscc-export',
				'version'  => 1,
				'settings' => array(
					'advanced'   => array(
						'policy_version'    => 'LittleD-evil',
						'trusted_ip_header' => 'x-forwarded-for',
						'made_up'           => 1,
						'consent_days'      => 30,
					),
					'not_a_group' => array( 'x' => 1 ),
				),
			)
		);
		$doc = ImportReader::read( $json );
		$this->assertIsArray( $doc );
		$this->assertSame( array( 'advanced' => array( 'consent_days' => 30 ) ), $doc['settings'] );
		$this->assertNotEmpty( $doc['warnings'] );
		ImportPlanner::apply( $doc );
		$after = Settings::group( 'advanced' );
		$this->assertSame( 30, $after['consent_days'] );
		$this->assertSame( $before['policy_version'], $after['policy_version'] );
		$this->assertSame( $before['trusted_ip_header'], $after['trusted_ip_header'] );
	}

	public function test_values_in_a_file_go_through_the_normal_sanitising() {
		$json = (string) wp_json_encode(
			array(
				'format'   => 'dscc-export',
				'version'  => 1,
				'settings' => array(
					'appearance' => array(
						'color_primary' => 'javascript:alert(1)',
						'layout'        => 'sideways',
						'custom_css'    => 'a{',
					),
					'advanced'   => array( 'cookie_name' => 'bad name;' ),
				),
				'services' => array(
					array(
						'slug'         => 'littled-xss',
						'name'         => '<script>x</script>LittleD',
						'category_key' => 'weird',
						'privacy_url'  => 'javascript:alert(1)',
					),
				),
			)
		);
		$plan = ImportPlanner::apply( ImportReader::read( $json ) );
		$this->assertSame( '#005ea3', Settings::get( 'appearance', 'color_primary' ) );
		$this->assertSame( 'card', Settings::get( 'appearance', 'layout' ) );
		$this->assertSame( 'dscc_consent', Settings::get( 'advanced', 'cookie_name' ) );
		$service = ServiceRepository::find_by_slug( 'littled-xss' );
		$this->assertSame( 'LittleD', $service['name'] );
		$this->assertSame( 'unclassified', $service['category_key'] );
		$this->assertSame( '', $service['privacy_url'] );
		$this->assertNotEmpty( $plan['warnings'] );
	}

	/**
	 * @dataProvider malformed_documents
	 */
	public function test_malformed_input_is_rejected( $input, $code ) {
		$result = ImportReader::read( $input );
		$this->assertWPError( $result );
		$this->assertSame( $code, $result->get_error_code() );
	}

	public function malformed_documents() {
		$ok = array(
			'format'  => 'dscc-export',
			'version' => 1,
		);
		return array(
			'empty'                  => array( '', 'dscc_import_empty' ),
			'whitespace'             => array( "  \n", 'dscc_import_empty' ),
			'not json'               => array( '<?php echo 1;', 'dscc_import_bad_json' ),
			'json list'              => array( '[1,2,3]', 'dscc_import_bad_json' ),
			'json scalar'            => array( '"dscc-export"', 'dscc_import_bad_json' ),
			'empty object'           => array( '{}', 'dscc_import_bad_json' ),
			'wrong format'           => array( '{"format":"other","version":1}', 'dscc_import_bad_format' ),
			'missing format'         => array( '{"version":1}', 'dscc_import_bad_format' ),
			'future version'         => array( '{"format":"dscc-export","version":2}', 'dscc_import_bad_version' ),
			'version as text'        => array( '{"format":"dscc-export","version":"1"}', 'dscc_import_bad_version' ),
			'zero version'           => array( '{"format":"dscc-export","version":0}', 'dscc_import_bad_version' ),
			'settings not an object' => array( (string) wp_json_encode( $ok + array( 'settings' => 'x' ) ), 'dscc_import_bad_section' ),
			'services as object'     => array( (string) wp_json_encode( $ok + array( 'services' => array( 'a' => array( 'slug' => 'x' ) ) ) ), 'dscc_import_bad_section' ),
			'service not an object'  => array( (string) wp_json_encode( $ok + array( 'services' => array( 'x' ) ) ), 'dscc_import_bad_section' ),
			'service without name'   => array( (string) wp_json_encode( $ok + array( 'services' => array( array( 'slug' => 'littled' ) ) ) ), 'dscc_import_bad_service' ),
			'service without slug'   => array( (string) wp_json_encode( $ok + array( 'services' => array( array( 'name' => 'LittleD' ) ) ) ), 'dscc_import_bad_service' ),
			'duplicate service'      => array(
				(string) wp_json_encode(
					$ok + array(
						'services' => array(
							array(
								'slug' => 'littled',
								'name' => 'A',
							),
							array(
								'slug' => 'littled',
								'name' => 'B',
							),
						),
					)
				),
				'dscc_import_duplicate_service',
			),
			'cookie without name'    => array( (string) wp_json_encode( $ok + array( 'cookies' => array( array( 'storage' => 'cookie' ) ) ) ), 'dscc_import_bad_cookie' ),
			'cookie bad service ref' => array( (string) wp_json_encode( $ok + array( 'cookies' => array( array( 'name' => 'x', 'service_slug' => array( 1 ) ) ) ) ), 'dscc_import_bad_cookie' ),
			'duplicate cookie'       => array( (string) wp_json_encode( $ok + array( 'cookies' => array( array( 'name' => 'x' ), array( 'name' => 'x' ) ) ) ), 'dscc_import_duplicate_cookie' ),
			'too large'              => array( '{"format":"' . str_repeat( 'x', ImportReader::MAX_BYTES ) . '"}', 'dscc_import_too_large' ),
			'too deeply nested'      => array( '{"format":"dscc-export","version":1,"settings":' . str_repeat( '[', 40 ) . str_repeat( ']', 40 ) . '}', 'dscc_import_bad_json' ),
			'array input'            => array( array( 'format' => 'dscc-export' ), 'dscc_import_empty' ),
		);
	}

	public function test_a_byte_order_mark_in_front_of_the_json_is_tolerated() {
		$doc = ImportReader::read( "\xEF\xBB\xBF" . (string) wp_json_encode( array( 'format' => 'dscc-export', 'version' => 1 ) ) );
		$this->assertIsArray( $doc );
	}

	public function test_a_failed_read_changes_nothing() {
		$this->seed_site();
		$before = $this->export_without_time();
		$this->assertWPError( ImportReader::read( '{"format":"dscc-export","version":1,"services":[{"slug":"ok","name":"Ok"},{"slug":"","name":"Bad"}]}' ) );
		$this->assertEquals( $before, $this->export_without_time() );
	}

	public function test_the_bundled_preset_is_listed_and_importable_but_only_on_request() {
		$this->assertArrayHasKey( 'd-solutions-standard', Presets::all() );
		$this->assertArrayNotHasKey( 'ip' . 'os', Presets::all() );
		$this->assertNull( Presets::json( '../ds-cookie-consent' ), 'only files of the presets folder are reachable' );
		$this->assertNull( Presets::json( 'nope' ) );

		$this->assertSame( 0, count( ServiceRepository::all() ), 'nothing is loaded until an admin asks' );
		$settings_before = Settings::all();
		$doc             = ImportReader::read( Presets::json( 'd-solutions-standard' ) );
		$this->assertIsArray( $doc );
		$plan = ImportPlanner::plan( $doc );
		$this->assertSame( 13, $plan['services']['new'] );
		$this->assertSame( 0, count( ServiceRepository::all() ), 'a preview alone writes nothing' );

		ImportPlanner::apply( $doc );
		foreach ( array( 'ds-cookie-consent', 'wordpress-core', 'google-tag-manager', 'google-analytics', 'google-ads', 'meta-pixel', 'tiktok-pixel', 'microsoft-clarity', 'zalo', 'google-recaptcha', 'youtube', 'woocommerce' ) as $slug ) {
			$this->assertNotFalse( ServiceRepository::find_by_slug( $slug ), $slug );
		}
		$this->assertEquals( $settings_before, Settings::all(), 'the preset changes no setting' );

		$again = ImportPlanner::plan( ImportReader::read( Presets::json( 'd-solutions-standard' ) ) );
		$this->assertSame( 0, $again['changes'], 'a second import of the preset is a no-op' );
	}

	public function test_the_preset_label_follows_the_admin_language() {
		$vi = static function () {
			return 'vi';
		};
		add_filter( 'locale', $vi );
		$this->assertSame( 'D-Solutions — Mẫu tiêu chuẩn', Presets::all()['d-solutions-standard']['label'] );
		$this->assertSame( 'D-Solutions — Mẫu tiêu chuẩn', ImportReader::read( Presets::json( 'd-solutions-standard' ) )['preset']['label'] );
		remove_filter( 'locale', $vi );

		$this->assertSame( 'D-Solutions — Standard', Presets::all()['d-solutions-standard']['label'] );
		$this->assertSame( 'D-Solutions — Standard', ImportReader::read( Presets::json( 'd-solutions-standard' ) )['preset']['label'] );
	}
}
