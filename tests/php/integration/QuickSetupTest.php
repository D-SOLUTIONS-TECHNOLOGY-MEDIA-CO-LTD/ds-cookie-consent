<?php
use DSolutions\CookieConsent\Activator;
use DSolutions\CookieConsent\Admin\ComplianceReport;
use DSolutions\CookieConsent\Admin\QuickSetup;
use DSolutions\CookieConsent\Blocker\CookieSafelist;
use DSolutions\CookieConsent\Blocker\ScriptRules;
use DSolutions\CookieConsent\Catalog\CookieActions;
use DSolutions\CookieConsent\Catalog\FrontendCatalogCache;
use DSolutions\CookieConsent\Scanner\CookieLookup;
use DSolutions\CookieConsent\Scanner\FindingStore;
use DSolutions\CookieConsent\Settings\Settings;

/**
 * Quick setup of unclassified cookies from the compliance report, and the default of the unclassified-cookie mode.
 */
class Dscc_Quick_Setup_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Activator::install();
		FindingStore::reset();
		FrontendCatalogCache::rebuild();
		$this->mode( 'report' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
	}

	private function mode( $mode ) {
		Settings::save_group(
			'blocking',
			array_merge(
				Settings::group( 'blocking' ),
				array(
					'unclassified_cookie_mode' => $mode,
					'keep_cookies'             => array(),
				)
			)
		);
		ScriptRules::flush();
	}

	private function unclassified( $name ) {
		FindingStore::merge_cookies( array( array( 'name' => $name, 'set_by' => 'server', 'url' => '/' ) ), array( 'via' => 'server' ) );
		return (int) CookieLookup::find( CookieLookup::rows( array( $name ), 'cookie' ), $name )['id'];
	}

	private function listed( $name ) {
		ScriptRules::flush();
		return array_column( ScriptRules::active()['cookies'], 3, 0 )[ $name ] ?? null;
	}

	private function category( $id ) {
		return CookieActions::category_of( \DSolutions\CookieConsent\Catalog\CookieRepository::find( $id ) );
	}

	public function test_the_default_mode_is_report_also_for_installs_that_never_saved_the_field() {
		update_option( 'dscc_settings', array( 'blocking' => array( 'mode' => 'block' ) ) );
		Settings::flush();
		$this->assertSame( 'report', Settings::get( 'blocking', 'unclassified_cookie_mode' ) );
		$this->assertSame( 'report', ComplianceReport::build()['mode'] );
		$this->mode( 'block' );
		$this->assertSame( 'block', ComplianceReport::build()['mode'] );
	}

	public function test_report_mode_does_not_block_a_new_cookie_and_block_mode_does() {
		$this->unclassified( 'LittleD_unk' );
		$this->assertNull( $this->listed( 'LittleD_unk' ) );
		$this->mode( 'block' );
		$this->assertSame( 'marketing', $this->listed( 'LittleD_unk' ) );
	}

	public function test_necessary_files_confirms_and_removes_the_row_from_the_report() {
		$this->mode( 'block' );
		$id = $this->unclassified( 'LittleD_nec' );
		$this->assertSame( 1, ComplianceReport::build()['unclassified_n'] );
		$res = QuickSetup::apply( array( $id ), 'necessary' );
		$this->assertSame( array( $id ), $res['done'] );
		$this->assertSame( 'necessary', $this->category( $id ) );
		$this->assertSame( 0, ComplianceReport::build()['unclassified_n'] );
		$this->assertNull( $this->listed( 'LittleD_nec' ), 'necessary cookies are never blocked' );
		$this->assertSame( 'confirmed', \DSolutions\CookieConsent\Catalog\CookieRepository::find( $id )['status'] );
	}

	public function test_analytics_and_marketing_are_confirmed_and_enforced_even_in_report_mode() {
		$a = $this->unclassified( 'LittleD_an' );
		$m = $this->unclassified( 'LittleD_mk' );
		$this->assertNull( $this->listed( 'LittleD_an' ) );
		QuickSetup::apply( array( $a ), 'analytics' );
		QuickSetup::apply( array( $m ), 'marketing' );
		$this->assertSame( 'analytics', $this->listed( 'LittleD_an' ) );
		$this->assertSame( 'marketing', $this->listed( 'LittleD_mk' ) );
		$this->assertSame( 0, ComplianceReport::build()['unclassified_n'] );
	}

	public function test_keep_adds_the_name_to_the_keep_list_and_files_it_as_necessary() {
		$id  = $this->unclassified( 'LittleD_keep' );
		$res = QuickSetup::apply( array( $id ), 'keep' );
		$this->assertSame( array( $id ), $res['done'] );
		$this->assertSame( array( 'LittleD_keep' ), Settings::get( 'blocking', 'keep_cookies' ) );
		$this->assertContains( 'LittleD_keep', CookieSafelist::patterns() );
		$this->assertSame( 'necessary', $this->category( $id ) );
	}

	public function test_keep_refuses_names_the_list_cannot_hold() {
		$id = $this->unclassified( 'ab' );
		$this->assertSame( array( $id ), QuickSetup::apply( array( $id ), 'keep' )['failed'] );
		$this->assertSame( array(), Settings::get( 'blocking', 'keep_cookies' ) );
		$this->assertSame( 'unclassified', $this->category( $id ) );
	}

	public function test_ignore_removes_the_row_from_the_unclassified_list() {
		$id = $this->unclassified( 'LittleD_ign' );
		QuickSetup::apply( array( $id ), 'ignore' );
		$this->assertSame( 0, ComplianceReport::build()['unclassified_n'] );
		$this->assertSame( 'ignored', \DSolutions\CookieConsent\Catalog\CookieRepository::find( $id )['status'] );
	}

	public function test_bulk_applies_to_every_id_and_unknown_actions_or_classified_rows_fail() {
		$ids = array( $this->unclassified( 'LittleD_b1' ), $this->unclassified( 'LittleD_b2' ) );
		$this->assertSame( $ids, QuickSetup::apply( $ids, 'necessary' )['done'] );

		$again = $this->unclassified( 'LittleD_b3' );
		$this->assertSame( array( $again ), QuickSetup::apply( array( $again ), 'delete' )['failed'] );
		$this->assertSame( array( $ids[0] ), QuickSetup::apply( array( $ids[0] ), 'marketing' )['failed'], 'already classified rows are not touched' );
		$this->assertSame( 'necessary', $this->category( $ids[0] ) );
	}

	public function test_the_report_template_has_controls_only_for_unclassified_rows() {
		$id = $this->unclassified( 'LittleD_tpl' );
		FindingStore::merge_cookies( array( array( 'name' => '_fbp', 'set_by' => 'server' ) ), array( 'via' => 'server' ) );
		$compliance = ComplianceReport::build();
		ob_start();
		require DSCC_DIR . 'templates/admin/compliance-block.php';
		$html = ob_get_clean();
		$this->assertStringContainsString( 'id="dscc-quick-form"', $html );
		$this->assertStringContainsString( 'data-dscc-cookie-id="' . $id . '"', $html );
		$this->assertStringContainsString( 'value="marketing:' . $id . '"', $html );
		$this->assertStringContainsString( 'Report only', $html );
		$this->assertStringContainsString( 'dscc-blocking-unclassified_cookie_mode', $html );
	}

	public function test_the_report_escapes_an_ampersand_in_an_unclassified_cookie_name() {
		$this->unclassified( 'LittleD&x(1)' );
		$compliance = ComplianceReport::build();
		ob_start();
		require DSCC_DIR . 'templates/admin/compliance-block.php';
		$html = ob_get_clean();
		$this->assertStringContainsString( 'LittleD&amp;x(1)', $html );
		$this->assertStringNotContainsString( 'LittleD&x(1)', $html );
	}

	public function test_the_buttons_name_the_action_and_the_cookie_for_screen_readers() {
		$this->unclassified( 'LittleD_aria' );
		$compliance = ComplianceReport::build();
		ob_start();
		require DSCC_DIR . 'templates/admin/compliance-block.php';
		$html = ob_get_clean();
		$this->assertStringContainsString( 'aria-label="Marketing LittleD_aria"', $html );
		$this->assertStringContainsString( 'aria-label="Ignored LittleD_aria"', $html );
		$this->assertStringContainsString( 'aria-label="LittleD_aria"', $html );
	}

	public function test_a_full_keep_list_refuses_before_anything_is_changed() {
		$names = array();
		for ( $i = 0; $i < QuickSetup::MAX_KEEP; $i++ ) {
			$names[] = 'LittleD_keep_' . $i;
		}
		Settings::save_group( 'blocking', array_merge( Settings::group( 'blocking' ), array( 'keep_cookies' => $names ) ) );
		$id  = $this->unclassified( 'LittleD_one_too_many' );
		$res = QuickSetup::apply( array( $id ), 'keep' );
		$this->assertSame( array( $id ), $res['failed'] );
		$this->assertSame( 'unclassified', $this->category( $id ) );
		$this->assertSame( 'new', \DSolutions\CookieConsent\Catalog\CookieRepository::find( $id )['status'] );
		$this->assertCount( QuickSetup::MAX_KEEP, Settings::get( 'blocking', 'keep_cookies' ) );
	}
}
