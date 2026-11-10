<?php
use DSolutions\CookieConsent\Activator;
use DSolutions\CookieConsent\Catalog\CookieActions;
use DSolutions\CookieConsent\Catalog\CookieQuery;
use DSolutions\CookieConsent\Catalog\CookieRepository;
use DSolutions\CookieConsent\Catalog\ServiceRepository;
use DSolutions\CookieConsent\Database\Schema;

/**
 * Services and cookies: schema, upsert by identity, pattern matching, category rules, bulk actions.
 */
class Dscc_Catalog_Repository_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Activator::install();
	}

	private function service( $slug = 'littled-stats', $category = 'analytics', array $over = array() ) {
		return ServiceRepository::upsert(
			array_merge(
				array(
					'slug'            => $slug,
					'name'            => 'LittleD ' . $slug,
					'category_key'    => $category,
					'privacy_url'     => 'https://littled.example/privacy',
					'script_patterns' => array(
						'hosts'     => array( 'stats.littled.example' ),
						'src_regex' => array( 'littled\.example/tag' ),
					),
					'status'          => 'confirmed',
				),
				$over
			)
		);
	}

	public function test_tables_have_the_documented_columns_and_the_identity_key() {
		global $wpdb;
		$services = $wpdb->get_col( 'SHOW COLUMNS FROM ' . Schema::services_table(), 0 ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		foreach ( array( 'slug', 'category_key', 'script_patterns', 'block_enabled', 'status', 'source', 'detected_via', 'created_at', 'updated_at' ) as $col ) {
			$this->assertContains( $col, $services, $col );
		}
		$cookies = $wpdb->get_col( 'SHOW COLUMNS FROM ' . Schema::cookies_table(), 0 ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		foreach ( array( 'service_id', 'name', 'name_is_pattern', 'storage', 'domain', 'duration_label_en', 'purpose_en', 'set_by', 'seen_count', 'seen_on_urls', 'status', 'source' ) as $col ) {
			$this->assertContains( $col, $cookies, $col );
		}
		$keys = $wpdb->get_results( 'SHOW INDEX FROM ' . Schema::cookies_table() . " WHERE Key_name = 'identity'", ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertSame( array( 'name', 'storage', 'domain' ), wp_list_pluck( $keys, 'Column_name' ) );
		$this->assertGreaterThanOrEqual( 3, (int) Schema::VERSION );
	}

	public function test_script_patterns_are_stored_as_a_json_object_and_read_back_normalised() {
		global $wpdb;
		$this->service();
		$raw = $wpdb->get_var( $wpdb->prepare( 'SELECT script_patterns FROM ' . Schema::services_table() . ' WHERE slug = %s', 'littled-stats' ) ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		$this->assertSame( '{', substr( $raw, 0, 1 ), 'stored as a JSON object, not a list' );
		$decoded = json_decode( $raw, true );
		$this->assertSame( array( 'hosts', 'src_regex', 'inline_regex' ), array_keys( $decoded ) );
		$svc = ServiceRepository::find_by_slug( 'littled-stats' );
		$this->assertSame( array( 'stats.littled.example' ), $svc['script_patterns']['hosts'] );
		$this->assertSame( array(), $svc['script_patterns']['inline_regex'] );
	}

	public function test_block_default_follows_category_and_necessary_is_never_blocked() {
		$this->service( 'littled-a', 'analytics' );
		$this->service( 'littled-n', 'necessary' );
		$this->service( 'littled-f', 'functional' );
		ServiceRepository::upsert( array( 'slug' => 'littled-own', 'name' => 'LittleD Own', 'category_key' => 'functional', 'is_third_party' => 0, 'status' => 'confirmed' ) );
		$this->assertSame( 1, ServiceRepository::find_by_slug( 'littled-a' )['block_enabled'] );
		$this->assertSame( 0, ServiceRepository::find_by_slug( 'littled-n' )['block_enabled'] );
		$this->assertSame( 1, ServiceRepository::find_by_slug( 'littled-f' )['block_enabled'], 'a third-party functional service (embed) is blocked until the Functional group is allowed' );
		$this->assertSame( 0, ServiceRepository::find_by_slug( 'littled-own' )['block_enabled'], 'the site\'s own functional scripts keep running' );
		ServiceRepository::set_block( 'littled-n', true );
		$this->assertSame( 0, ServiceRepository::find_by_slug( 'littled-n' )['block_enabled'] );
		ServiceRepository::upsert( array( 'slug' => 'littled-n', 'block_enabled' => 1 ) );
		$this->assertSame( 0, ServiceRepository::find_by_slug( 'littled-n' )['block_enabled'] );
		ServiceRepository::set_block( 'littled-a', false );
		$this->assertSame( 0, ServiceRepository::find_by_slug( 'littled-a' )['block_enabled'] );
	}

	public function test_service_input_is_validated_at_the_boundary() {
		$this->assertSame( 0, ServiceRepository::upsert( array( 'slug' => str_repeat( 'a', 41 ) ) ), 'slug longer than 40 is refused' );
		$this->assertSame( 0, ServiceRepository::upsert( array( 'name' => 'no slug' ) ) );
		$this->service( 'littled-x', 'nonsense', array( 'privacy_url' => 'javascript:alert(1)', 'status' => 'weird' ) );
		$svc = ServiceRepository::find_by_slug( 'littled-x' );
		$this->assertSame( 'unclassified', $svc['category_key'] );
		$this->assertSame( '', $svc['privacy_url'] );
		$this->assertSame( 'auto', $svc['status'] );
	}

	public function test_unique_slug_skips_existing_and_reserved_slugs() {
		$this->service( 'littled-stats' );
		$this->assertSame( 'littled-stats-2', ServiceRepository::unique_slug( 'LittleD Stats' ) );
		$this->assertSame( 'google-analytics-2', ServiceRepository::unique_slug( 'Google Analytics', array( 'google-analytics' ) ) );
		$this->assertSame( 'platform', ServiceRepository::unique_slug( '!!!' ) );
	}

	public function test_cookie_upsert_is_keyed_by_name_storage_and_domain() {
		$a = CookieRepository::upsert( array( 'name' => '_lt', 'purpose' => 'one' ) );
		$b = CookieRepository::upsert( array( 'name' => '_lt', 'purpose' => 'two' ) );
		$c = CookieRepository::upsert( array( 'name' => '_lt', 'domain' => '.littled.example' ) );
		$d = CookieRepository::upsert( array( 'name' => '_lt', 'storage' => 'localStorage' ) );
		$this->assertSame( $a, $b );
		$this->assertNotSame( $a, $c );
		$this->assertNotSame( $a, $d );
		$row = CookieRepository::find( $a );
		$this->assertSame( 'two', $row['purpose'] );
		$this->assertSame( 'new', $row['status'], 'new rows wait for review by default' );
		$this->assertSame( '.littled.example', CookieRepository::find( $c )['domain'] );
	}

	public function test_upsert_only_changes_the_fields_that_are_given() {
		$id = CookieRepository::upsert( array( 'name' => '_lt', 'purpose' => 'keep me', 'status' => 'confirmed', 'duration_seconds' => 60 ) );
		CookieRepository::upsert( array( 'name' => '_lt', 'duration_label' => '1 phút' ) );
		$row = CookieRepository::find( $id );
		$this->assertSame( 'keep me', $row['purpose'] );
		$this->assertSame( 'confirmed', $row['status'] );
		$this->assertSame( 60, $row['duration_seconds'] );
		$this->assertSame( '1 phút', $row['duration_label'] );
	}

	public function test_pattern_names_are_flagged_and_matched() {
		$svc = $this->service();
		$id  = CookieRepository::upsert( array( 'name' => '_lt_*', 'service_id' => $svc, 'status' => 'confirmed' ) );
		$this->assertSame( 1, CookieRepository::find( $id )['name_is_pattern'] );
		$this->assertSame( $id, CookieRepository::match_confirmed( '_lt_ABC123' )['id'] );
		$this->assertNull( CookieRepository::match_confirmed( '_lt' ) );
		$exact = CookieRepository::upsert( array( 'name' => '_lt_ABC123', 'service_id' => $svc, 'status' => 'confirmed' ) );
		$this->assertSame( $exact, CookieRepository::match_confirmed( '_lt_ABC123' )['id'], 'exact names win over patterns' );
		CookieRepository::set_status_many( array( $id, $exact ), 'ignored' );
		$this->assertNull( CookieRepository::match_confirmed( '_lt_ZZZ' ), 'only confirmed rows count as the site catalog' );
	}

	public function test_cookie_name_and_storage_are_sanitised_and_bounded() {
		$id = CookieRepository::upsert( array( 'name' => '<b>' . str_repeat( 'x', 150 ) . '</b>', 'storage' => 'bogus', 'domain' => 'Ex ample.COM;' ) );
		$row = CookieRepository::find( $id );
		$this->assertSame( 100, strlen( $row['name'] ) );
		$this->assertSame( 'cookie', $row['storage'] );
		$this->assertSame( 'example.com', $row['domain'] );
		$this->assertSame( 0, CookieRepository::upsert( array( 'name' => '   ' ) ) );
	}

	public function test_new_count_is_cached_and_busted_on_any_change() {
		$this->assertSame( 0, CookieRepository::count_new() );
		$this->assertSame( 0, (int) get_transient( 'dscc_new_cookies' ) );
		$id = CookieRepository::upsert( array( 'name' => '_lt_new' ) );
		$this->assertFalse( get_transient( 'dscc_new_cookies' ), 'a catalog change drops the cached count' );
		$this->assertSame( 1, CookieRepository::count_new() );
		CookieRepository::set_status_many( array( $id ), 'confirmed' );
		$this->assertSame( 0, CookieRepository::count_new() );
	}

	public function test_confirm_confirms_the_vendor_and_skips_cookies_with_no_vendor() {
		$svc = $this->service( 'littled-stats', 'analytics', array( 'status' => 'auto' ) );
		$a   = CookieRepository::upsert( array( 'name' => '_lt_a', 'service_id' => $svc ) );
		$b   = CookieRepository::upsert( array( 'name' => '_lt_b' ) );
		$res = CookieActions::confirm( array( $a, $b ) );
		$this->assertSame( array( 'changed' => 1, 'skipped' => 1 ), $res );
		$this->assertSame( 'confirmed', CookieRepository::find( $a )['status'] );
		$this->assertSame( 'new', CookieRepository::find( $b )['status'] );
		$this->assertSame( 'confirmed', ServiceRepository::find_by_slug( 'littled-stats' )['status'] );
	}

	public function test_move_category_moves_the_whole_vendor_or_assigns_the_site_holder() {
		$svc    = $this->service( 'littled-stats', 'analytics' );
		$a      = CookieRepository::upsert( array( 'name' => '_lt_a', 'service_id' => $svc, 'status' => 'confirmed' ) );
		$b      = CookieRepository::upsert( array( 'name' => '_lt_b', 'service_id' => $svc, 'status' => 'confirmed' ) );
		$orphan = CookieRepository::upsert( array( 'name' => '_lt_orphan' ) );

		$this->assertSame( 2, CookieActions::move_category( array( $a, $b ), 'marketing' ) );
		$this->assertSame( 'marketing', ServiceRepository::find_by_slug( 'littled-stats' )['category_key'] );
		$this->assertSame( 1, ServiceRepository::find_by_slug( 'littled-stats' )['block_enabled'] );

		CookieActions::move_category( array( $orphan ), 'necessary' );
		$holder = ServiceRepository::find_by_slug( 'site-necessary' );
		$this->assertSame( $holder['id'], CookieRepository::find( $orphan )['service_id'] );
		$this->assertSame( 'necessary', $holder['category_key'] );
		$this->assertSame( 0, $holder['block_enabled'] );
		$this->assertSame( 'new', CookieRepository::find( $orphan )['status'], 'moving does not confirm' );

		CookieActions::move_category( array( $orphan ), 'analytics' );
		$this->assertSame( 'analytics', CookieActions::category_of( CookieRepository::find( $orphan ) ) );
		$this->assertSame( 'necessary', ServiceRepository::find_by_slug( 'site-necessary' )['category_key'], 'the other holder is untouched' );
		$this->assertSame( 0, CookieActions::move_category( array( $a ), 'unclassified' ), 'only the four visitor categories can be assigned' );
	}

	public function test_quick_edit_updates_texts_and_category() {
		$svc = $this->service( 'littled-stats', 'analytics' );
		$id  = CookieRepository::upsert( array( 'name' => '_lt_a', 'service_id' => $svc, 'status' => 'confirmed' ) );
		$row = CookieActions::quick_edit(
			$id,
			array(
				'purpose'           => 'Mục đích LittleD',
				'purpose_en'        => 'LittleD purpose',
				'duration_label'    => '2 năm',
				'duration_label_en' => '2 years',
				'category'          => 'marketing',
			)
		);
		$this->assertSame( 'Mục đích LittleD', $row['purpose'] );
		$this->assertSame( '2 years', $row['duration_label_en'] );
		$this->assertSame( 'marketing', $row['category_key'] );
		$this->assertWPError( CookieActions::quick_edit( $id, array( 'category' => 'nope' ) ) );
		$this->assertWPError( CookieActions::quick_edit( 999999, array() ) );
	}

	public function test_add_manual_needs_a_vendor_or_a_category_and_creates_confirmed_rows() {
		$this->assertWPError( CookieActions::add_manual( array( 'name' => '' ) ) );
		$this->assertWPError( CookieActions::add_manual( array( 'name' => '_lt' ) ) );
		$this->assertWPError( CookieActions::add_manual( array( 'name' => '_lt', 'service' => 'missing' ) ) );
		$id  = CookieActions::add_manual( array( 'name' => 'LittleD_pref', 'category' => 'necessary', 'purpose' => 'Test', 'duration_label' => '1 năm' ) );
		$row = CookieRepository::find( $id );
		$this->assertSame( 'confirmed', $row['status'] );
		$this->assertSame( 'manual', $row['source'] );
		$this->assertSame( 'necessary', CookieActions::category_of( $row ) );
	}

	public function test_list_query_puts_new_first_and_filters() {
		$svc = $this->service( 'littled-stats', 'analytics' );
		CookieRepository::upsert( array( 'name' => 'a_confirmed', 'service_id' => $svc, 'status' => 'confirmed', 'source' => 'known_db' ) );
		CookieRepository::upsert( array( 'name' => 'z_new', 'status' => 'new', 'source' => 'scan' ) );
		CookieRepository::upsert( array( 'name' => 'b_ignored', 'service_id' => $svc, 'status' => 'ignored' ) );

		$all = CookieQuery::run( array( 'orderby' => 'name', 'order' => 'asc' ) );
		$this->assertSame( 3, $all['total'] );
		$this->assertSame( 'z_new', $all['rows'][0]['name'], 'new cookies come first whatever the order' );
		$this->assertSame( 'unclassified', $all['rows'][0]['category_key'] );

		$this->assertCount( 2, CookieQuery::run( array( 'category' => 'analytics' ) )['rows'] );
		$this->assertCount( 1, CookieQuery::run( array( 'status' => 'new' ) )['rows'] );
		$this->assertCount( 1, CookieQuery::run( array( 'source' => 'scan' ) )['rows'] );
		$this->assertCount( 1, CookieQuery::run( array( 'search' => 'b_ign' ) )['rows'] );
		$this->assertSame( 2, CookieQuery::run( array( 'search' => 'LittleD' ) )['total'], 'the search also matches the vendor name' );
		$this->assertCount( 1, CookieQuery::run( array( 'per_page' => 1 ) )['rows'] );
		$counts = CookieQuery::status_counts();
		$this->assertSame( 1, $counts['new'] );
		$this->assertSame( 1, $counts['confirmed'] );
		$this->assertSame( 1, $counts['ignored'] );
	}

	public function test_list_query_survives_hostile_input() {
		CookieRepository::upsert( array( 'name' => '_lt' ) );
		$res = CookieQuery::run(
			array(
				'search'  => "x' OR 1=1 -- %",
				'orderby' => 'name; DROP TABLE x',
				'order'   => 'sideways',
			)
		);
		$this->assertSame( 0, $res['total'] );
		$res = CookieQuery::run( array( 'status' => "new' OR '1'='1", 'category' => 'x', 'source' => 'y' ) );
		$this->assertSame( 1, $res['total'], 'unknown filter values are ignored' );
	}

	public function test_confirm_skips_cookies_of_an_unclassified_service() {
		$svc = $this->service( 'littled-unclassified', 'unclassified', array( 'status' => 'auto' ) );
		$a   = CookieRepository::upsert( array( 'name' => '_lt_u', 'service_id' => $svc ) );
		$res = CookieActions::confirm( array( $a ) );
		$this->assertSame( array( 'changed' => 0, 'skipped' => 1 ), $res );
		$this->assertSame( 'new', CookieRepository::find( $a )['status'] );
		$this->assertSame( 'auto', ServiceRepository::find_by_slug( 'littled-unclassified' )['status'] );
	}

	public function test_policy_table_fields_are_stored_and_bounded() {
		$id  = CookieRepository::upsert( array( 'name' => '_lt_pol', 'table_group' => 'Grp One!', 'applies_to' => 'littled.example, Web Table Ordering', 'applies_to_en' => str_repeat( 'x', 300 ) ) );
		$row = CookieRepository::find( $id );
		$this->assertSame( 'grpone', $row['table_group'] );
		$this->assertSame( 'littled.example, Web Table Ordering', $row['applies_to'] );
		$this->assertSame( 255, strlen( $row['applies_to_en'] ) );
		$res = CookieActions::quick_edit( $id, array( 'table_group' => 'moodle', 'applies_to' => 'LittleD' ) );
		$this->assertSame( 'moodle', $res['table_group'] );
		$this->assertSame( 'LittleD', $res['applies_to'] );
	}

	public function test_schema_has_the_policy_table_columns() {
		global $wpdb;
		$this->assertGreaterThanOrEqual( 3, (int) Schema::VERSION );
		$cols = $wpdb->get_col( 'SHOW COLUMNS FROM ' . Schema::cookies_table(), 0 ); // phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared
		foreach ( array( 'table_group', 'applies_to', 'applies_to_en' ) as $col ) {
			$this->assertContains( $col, $cols );
		}
	}
}
