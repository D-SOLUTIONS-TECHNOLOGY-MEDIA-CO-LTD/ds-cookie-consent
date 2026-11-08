<?php
use DSolutions\CookieConsent\Activator;
use DSolutions\CookieConsent\Admin\TransferTab;
use DSolutions\CookieConsent\Catalog\ServiceRepository;
use DSolutions\CookieConsent\Settings\Settings;

/**
 * Capability and nonce paths of the Import / Export screen, and the rule that an expired or missing
 * import token writes nothing.
 */
class Dscc_Admin_Tab_Guards_Test extends WP_UnitTestCase {

	/** @var callable[] Filters added by a test, removed in tear_down. */
	private $cleanup = array();

	public function set_up() {
		parent::set_up();
		Activator::install();
		Settings::flush();
		$_POST    = array();
		$_REQUEST = array();
		$_GET     = array();
		global $wpdb;
		$wpdb->query( 'DELETE FROM ' . \DSolutions\CookieConsent\Database\Schema::services_table() ); // phpcs:ignore WordPress.DB
	}

	public function tear_down() {
		$_POST    = array();
		$_REQUEST = array();
		$_GET     = array();
		wp_set_current_user( 0 );
		Settings::flush();
		parent::tear_down();
	}

	private function as_role( $role ) {
		wp_set_current_user( self::factory()->user->create( array( 'role' => $role ) ) );
	}

	/**
	 * Run a handler that ends in wp_die() or a redirect. Returns ['die' => message|null, 'redirect' => url|null, 'out' => string].
	 */
	private function capture( $fn ) {
		$result = array(
			'die'      => null,
			'redirect' => null,
			'out'      => '',
		);
		$die    = static function () {
			return static function ( $message ) {
				throw new Dscc_Guard_Stop( 'die', is_string( $message ) ? $message : 'wp_error' );
			};
		};
		$redir  = static function ( $location ) {
			throw new Dscc_Guard_Stop( 'redirect', (string) $location );
		};
		$ajax   = static function () {
			return true;
		};
		add_filter( 'wp_die_handler', $die );
		add_filter( 'wp_die_ajax_handler', $die );
		add_filter( 'wp_redirect', $redir );
		add_filter( 'wp_doing_ajax', $ajax );
		ob_start();
		try {
			call_user_func( $fn );
		} catch ( Dscc_Guard_Stop $stop ) {
			$result[ $stop->kind ] = $stop->detail;
		}
		$result['out'] = (string) ob_get_clean();
		remove_filter( 'wp_die_handler', $die );
		remove_filter( 'wp_die_ajax_handler', $die );
		remove_filter( 'wp_redirect', $redir );
		remove_filter( 'wp_doing_ajax', $ajax );
		return $result;
	}

	private function nonce( $action, $field = '_wpnonce' ) {
		$_REQUEST[ $field ] = wp_create_nonce( $action );
	}

	/* ------------------------------------------------------------ export */

	public function test_export_needs_the_capability_and_a_nonce() {
		$this->as_role( 'editor' );
		$this->nonce( 'dscc_export' );
		$r = $this->capture( array( TransferTab::class, 'handle_export' ) );
		$this->assertNotNull( $r['die'], 'an editor is refused' );
		$this->assertStringNotContainsString( 'dscc-export', $r['out'] );

		$this->as_role( 'administrator' );
		$_REQUEST = array();
		$r        = $this->capture( array( TransferTab::class, 'handle_export' ) );
		$this->assertNotNull( $r['die'], 'no nonce, no download' );
		$this->assertStringNotContainsString( 'dscc-export', $r['out'] );
	}

	/* ------------------------------------------------------------ preview and apply */

	public function test_preview_is_refused_without_capability_or_nonce_and_writes_nothing() {
		$_POST['dscc_import_preset'] = 'd-solutions-standard';
		$this->as_role( 'editor' );
		$this->nonce( 'dscc_import_preview' );
		$this->assertNotNull( $this->capture( array( TransferTab::class, 'handle_preview' ) )['die'] );

		$this->as_role( 'administrator' );
		$_REQUEST = array();
		$this->assertNotNull( $this->capture( array( TransferTab::class, 'handle_preview' ) )['die'], 'a missing nonce is refused' );
		$this->assertSame( array(), ServiceRepository::all() );
		$this->assertFalse( get_transient( 'dscc_import_' . get_current_user_id() . '_x' ) );
	}

	public function test_preview_then_apply_imports_the_preset_only_on_the_second_step() {
		$this->as_role( 'administrator' );
		$_POST['dscc_import_preset'] = 'd-solutions-standard';
		$this->nonce( 'dscc_import_preview' );
		$r = $this->capture( array( TransferTab::class, 'handle_preview' ) );
		$this->assertNotNull( $r['redirect'] );
		parse_str( (string) wp_parse_url( $r['redirect'], PHP_URL_QUERY ), $query );
		$this->assertSame( 'transfer', $query['tab'] );
		$token = $query['dscc_import'];
		$this->assertNotEmpty( $token );
		$this->assertSame( array(), ServiceRepository::all(), 'the preview wrote nothing' );

		// Another user cannot apply this preview.
		$first_user = get_current_user_id();
		$this->as_role( 'administrator' );
		$_POST      = array( 'dscc_import_token' => $token );
		$this->nonce( 'dscc_import_apply' );
		$other = $this->capture( array( TransferTab::class, 'handle_apply' ) );
		$this->assertNotNull( $other['redirect'] );
		$this->assertSame( array(), ServiceRepository::all(), 'a preview belongs to the user who made it' );

		wp_set_current_user( $first_user );
		$_POST = array( 'dscc_import_token' => $token );
		$this->nonce( 'dscc_import_apply' );
		$done = $this->capture( array( TransferTab::class, 'handle_apply' ) );
		$this->assertNotNull( $done['redirect'] );
		$this->assertNotNull( ServiceRepository::find_by_slug( 'wordpress-core' ) );
		$this->assertSame( 'dscc_consent', Settings::get( 'advanced', 'cookie_name' ), 'the preset changes no setting' );

		// The token is single use.
		$count = count( ServiceRepository::all() );
		$_POST = array( 'dscc_import_token' => $token );
		$this->nonce( 'dscc_import_apply' );
		$this->capture( array( TransferTab::class, 'handle_apply' ) );
		$this->assertCount( $count, ServiceRepository::all() );
		Settings::patch_group( 'advanced', array( 'cookie_name' => 'dscc_consent', 'skip_ua_patterns' => array() ) );
		Settings::patch_group( 'appearance', array( 'button_order' => '' ) );
	}

	public function test_apply_with_an_expired_missing_or_forged_token_writes_nothing() {
		$this->as_role( 'administrator' );
		$user = get_current_user_id();
		$doc  = array(
			'settings' => array( 'appearance' => array( 'color_primary' => '#445566' ) ),
			'texts'    => array(),
			'services' => array( 'littled-late' => array( 'slug' => 'littled-late', 'name' => 'LittleD Late' ) ),
			'cookies'  => array(),
			'warnings' => array(),
			'preset'   => array( 'label' => '', 'description' => '' ),
		);
		set_transient( 'dscc_import_' . $user . '_LittleDexpiredtoken', array( 'doc' => $doc, 'plan' => array() ), 1800 );
		delete_transient( 'dscc_import_' . $user . '_LittleDexpiredtoken' ); // What an expired transient looks like.

		foreach ( array( 'LittleDexpiredtoken', '', 'nope', '../../etc', 'LittleDforged' ) as $token ) {
			$_POST = array( 'dscc_import_token' => $token );
			$this->nonce( 'dscc_import_apply' );
			$r = $this->capture( array( TransferTab::class, 'handle_apply' ) );
			$this->assertNotNull( $r['redirect'], 'back to the tab with an error' );
			$this->assertArrayNotHasKey( 'dscc_import', (array) wp_parse_args( (string) wp_parse_url( (string) $r['redirect'], PHP_URL_QUERY ) ) );
		}
		$this->assertNull( ServiceRepository::find_by_slug( 'littled-late' ) );
		$this->assertNotSame( '#445566', Settings::get( 'appearance', 'color_primary' ) );
		$flash = \DSolutions\CookieConsent\Admin\Flash::take();
		$this->assertSame( 'error', $flash[0]['type'] );
		$this->assertStringContainsString( 'expired', $flash[0]['message'] );
	}

	public function test_apply_is_refused_without_the_capability_or_the_nonce() {
		$this->as_role( 'administrator' );
		$token = 'LittleDtoken1';
		set_transient( 'dscc_import_' . get_current_user_id() . '_' . $token, array( 'doc' => array( 'settings' => array(), 'texts' => array(), 'services' => array( 'littled-no' => array( 'slug' => 'littled-no', 'name' => 'No' ) ), 'cookies' => array(), 'warnings' => array(), 'preset' => array() ), 'plan' => array() ), 1800 );
		$_POST    = array( 'dscc_import_token' => $token );
		$_REQUEST = array();
		$this->assertNotNull( $this->capture( array( TransferTab::class, 'handle_apply' ) )['die'], 'no nonce' );
		$this->nonce( 'dscc_import_preview' );
		$this->assertNotNull( $this->capture( array( TransferTab::class, 'handle_apply' ) )['die'], 'a nonce of another action' );
		$this->assertNull( ServiceRepository::find_by_slug( 'littled-no' ) );
	}
}

/**
 * Stops a handler at wp_die() or a redirect.
 */
class Dscc_Guard_Stop extends Exception {

	/** @var string die|redirect */
	public $kind;

	/** @var string Message or location. */
	public $detail;

	public function __construct( $kind, $detail ) {
		parent::__construct( $detail );
		$this->kind   = $kind;
		$this->detail = $detail;
	}
}
