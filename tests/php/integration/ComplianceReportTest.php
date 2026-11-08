<?php
use DSolutions\CookieConsent\Activator;
use DSolutions\CookieConsent\Admin\ComplianceReport;
use DSolutions\CookieConsent\Admin\DashboardPage;
use DSolutions\CookieConsent\Catalog\CookieRepository;
use DSolutions\CookieConsent\Catalog\FrontendCatalogCache;
use DSolutions\CookieConsent\Catalog\PlatformManager;
use DSolutions\CookieConsent\Scanner\AlertStore;
use DSolutions\CookieConsent\Scanner\FindingStore;
use DSolutions\CookieConsent\Scanner\ScanRunRepository;
use DSolutions\CookieConsent\Settings\Settings;

/**
 * Compliance alerts: set before consent, held by the guard, leaks, unclassified, present but not in the cookie policy.
 */
class Dscc_Compliance_Report_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Activator::install();
		FindingStore::reset();
		FrontendCatalogCache::rebuild();
		delete_option( AlertStore::OPTION );
		Settings::save_group( 'content', array_merge( Settings::group( 'content' ), array( 'cookie_policy_page_id' => 0, 'cookie_policy_url' => '' ) ) );
	}

	private function scan_with( array $cookies ) {
		FindingStore::merge_cookies(
			array_map(
				static function ( $name ) {
					return array( 'name' => $name, 'set_by' => 'server', 'url' => '/' );
				},
				array_keys( $cookies )
			),
			array( 'via' => 'server' )
		);
		$report = array( 'cookies' => array() );
		foreach ( $cookies as $name => $held ) {
			$report['cookies'][ $name ] = array( 'name' => $name, 'held' => $held, 'urls' => 2 );
		}
		return ScanRunRepository::create( 'server', array( 'done' => 3, 'failed' => 0, 'cookies' => count( $cookies ), 'new_cookies' => 0 ), $report, 'done' );
	}

	public function test_set_before_consent_lists_unheld_analytics_marketing_and_unclassified_cookies() {
		$this->scan_with(
			array(
				'_fbp'                     => false,
				'_clck'                    => true,
				'wp-wpml_current_language' => false,
				'LittleD_unknown'          => false,
			)
		);
		$r = ComplianceReport::build();
		$this->assertSame( array( '_fbp', 'LittleD_unknown' ), array_column( $r['before_consent'], 'name' ) );
		$this->assertSame( array( 'marketing', 'unclassified' ), array_column( $r['before_consent'], 'category' ) );
		$this->assertSame( array( '_clck' ), array_column( $r['held'], 'name' ), 'held cookies are information, not a violation' );
		$this->assertGreaterThan( 0, $r['total'] );
	}

	public function test_reclassifying_a_cookie_changes_the_report() {
		$this->scan_with( array( 'LittleD_unknown' => false ) );
		$this->assertCount( 1, ComplianceReport::build()['before_consent'] );
		$id = CookieRepository::find_identity( 'LittleD_unknown', 'cookie', '' )['id'];
		\DSolutions\CookieConsent\Catalog\CookieActions::move_category( array( $id ), 'necessary' );
		$this->assertCount( 0, ComplianceReport::build()['before_consent'] );
	}

	public function test_unclassified_cookies_are_counted_until_ignored() {
		FindingStore::merge_cookies( array( array( 'name' => 'LittleD_a', 'set_by' => 'client' ), array( 'name' => 'LittleD_b', 'set_by' => 'client' ) ), array( 'via' => 'beacon' ) );
		$this->assertSame( 2, ComplianceReport::build()['unclassified_n'] );
		\DSolutions\CookieConsent\Catalog\CookieActions::ignore( array( CookieRepository::find_identity( 'LittleD_a', 'cookie', '' )['id'] ) );
		$this->assertSame( 1, ComplianceReport::build()['unclassified_n'] );
	}

	public function test_leaks_come_from_the_alert_store() {
		AlertStore::record_leaks( array( array( 'name' => '_fbp', 'storage' => 'cookie', 'category' => 'marketing', 'service_slug' => 'meta-pixel' ) ), 'beacon' );
		$r = ComplianceReport::build();
		$this->assertSame( array( '_fbp' ), array_column( $r['leaks'], 'name' ) );
		$this->assertSame( 1, $r['total'] );
	}

	public function test_cookies_missing_from_the_policy_page_are_listed() {
		PlatformManager::enable( 'google-analytics' ); // _ga, _ga_*, _gat_UA-*, _gid confirmed.
		FindingStore::merge_cookies(
			array(
				array( 'name' => '_ga', 'set_by' => 'client' ),
				array( 'name' => '_gid', 'set_by' => 'client' ),
				array( 'name' => '_ga_ABC123', 'set_by' => 'client' ),
				array( 'name' => 'wp-settings-1', 'set_by' => 'client' ),
			),
			array( 'via' => 'beacon' )
		);
		$page = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '<p>Chúng tôi dùng cookie _ga và _ga_&lt;mã&gt; để thống kê.</p>' ) );
		Settings::save_group( 'content', array_merge( Settings::group( 'content' ), array( 'cookie_policy_page_id' => $page ) ) );

		$r = ComplianceReport::build();
		$this->assertContains( '_gid', $r['not_in_policy'] );
		$this->assertNotContains( '_ga', $r['not_in_policy'] );
		$this->assertNotContains( '_ga_*', $r['not_in_policy'], 'a pattern is found by its prefix' );
		$this->assertNotContains( 'wp-settings-1', $r['not_in_policy'], 'admin cookies are not policy material' );
		$this->assertSame( 'ok', $r['policy_state'] );
	}

	public function test_the_cookie_table_shortcode_on_the_policy_page_counts_as_mentioning_confirmed_cookies() {
		PlatformManager::enable( 'google-analytics' );
		FindingStore::merge_cookies( array( array( 'name' => '_gid', 'set_by' => 'client' ), array( 'name' => '_fbp', 'set_by' => 'client' ) ), array( 'via' => 'server' ) );
		$page = self::factory()->post->create( array( 'post_type' => 'page', 'post_status' => 'publish', 'post_content' => '[dscc_cookie_table]' ) );
		Settings::save_group( 'content', array_merge( Settings::group( 'content' ), array( 'cookie_policy_page_id' => $page ) ) );
		FrontendCatalogCache::rebuild();

		$r = ComplianceReport::build();
		$this->assertNotContains( '_gid', $r['not_in_policy'], 'confirmed cookies are in the generated table' );
		$this->assertContains( '_fbp', $r['not_in_policy'], '_fbp is automatic, not confirmed, so the table does not list it' );
	}

	public function test_without_a_policy_page_the_check_is_skipped_not_failed() {
		FindingStore::merge_cookies( array( array( 'name' => '_ga', 'set_by' => 'client' ) ), array( 'via' => 'beacon' ) );
		$r = ComplianceReport::build();
		$this->assertSame( 'no_page', $r['policy_state'] );
		$this->assertSame( array(), $r['not_in_policy'] );
	}

	public function test_the_overview_screen_renders_the_alerts_counts_and_last_scan() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		wp_get_current_user()->add_cap( 'dscc_view_log' );
		$this->scan_with( array( '_fbp' => false ) );
		update_option( 'dscc_last_scan', time() );
		ob_start();
		DashboardPage::render();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'id="dscc-compliance"', $html );
		$this->assertStringContainsString( 'notice-error', $html );
		$this->assertStringContainsString( '_fbp', $html );
		$this->assertStringContainsString( 'id="dscc-overview"', $html );
		$this->assertStringContainsString( 'id="dscc-overview-scan"', $html );
		$this->assertStringContainsString( 'dscc-chart', $html, 'the 7 and 30 day bars are still there' );
	}

	public function test_a_policy_mention_of_one_cookie_does_not_cover_a_longer_name() {
		$this->assertTrue( \DSolutions\CookieConsent\Admin\PolicyPageText::mentions( 'cookie _ga, _gid', '_ga' ) );
		$this->assertFalse( \DSolutions\CookieConsent\Admin\PolicyPageText::mentions( 'cookie _gat_UA, _gid', '_ga' ) );
		$this->assertTrue( \DSolutions\CookieConsent\Admin\PolicyPageText::mentions( 'cookie _ga_<mã>', '_ga_*' ) );
		$this->assertFalse( \DSolutions\CookieConsent\Admin\PolicyPageText::mentions( 'xx_ga yy', '_ga' ) );
	}
}
