<?php
use DSolutions\CookieConsent\Activator;
use DSolutions\CookieConsent\Database\Schema;
use DSolutions\CookieConsent\Support\OptionLock;
use DSolutions\CookieConsent\Update\GitHubUpdater;

/**
 * The schema upgrade runs on any request (a background update never visits wp-admin) and only once at a time.
 */
class Dscc_Schema_Upgrade_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		delete_option( Activator::UPGRADE_LOCK );
	}

	public function tear_down() {
		global $wpdb;
		parent::tear_down();
		// ALTER TABLE in these tests commits what they installed; remove it so later test classes start without a schema.
		delete_option( 'dscc_db_version' );
		delete_option( Activator::UPGRADE_LOCK );
		$wpdb->query( 'COMMIT' );
	}

	public function test_a_stale_schema_version_is_fixed_on_a_front_end_request() {
		global $wpdb;
		$this->assertFalse( is_admin(), 'this is not an admin request' );
		Activator::install();
		$table = Schema::cookies_table();
		$wpdb->query( "ALTER TABLE {$table} DROP COLUMN table_group" ); // phpcs:ignore WordPress.DB
		update_option( 'dscc_db_version', '1' );
		$this->assertNotContains( 'table_group', $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 ) ); // phpcs:ignore WordPress.DB

		$this->assertTrue( Activator::ensure_schema() );

		$this->assertContains( 'table_group', $wpdb->get_col( "SHOW COLUMNS FROM {$table}", 0 ) ); // phpcs:ignore WordPress.DB
		$this->assertSame( Schema::VERSION, get_option( 'dscc_db_version' ) );
		$this->assertFalse( get_option( Activator::UPGRADE_LOCK ), 'the lock is released' );
	}

	public function test_the_check_does_nothing_when_the_version_is_current() {
		Activator::install();
		$this->assertFalse( Activator::ensure_schema() );
	}

	public function test_the_plugin_runs_the_check_when_it_boots() {
		update_option( 'dscc_db_version', '1' );
		$plugin = new ReflectionClass( \DSolutions\CookieConsent\Plugin::class );
		$boot   = $plugin->getMethod( 'boot' );
		$flag   = $plugin->getProperty( 'booted' );
		$flag->setAccessible( true );
		$instance = \DSolutions\CookieConsent\Plugin::instance();
		$flag->setValue( $instance, false );
		$boot->invoke( $instance );
		$this->assertSame( Schema::VERSION, get_option( 'dscc_db_version' ), 'boot() upgrades without admin_init' );
	}

	public function test_a_second_request_waits_while_the_first_is_upgrading() {
		update_option( 'dscc_db_version', '1' );
		$this->assertTrue( OptionLock::acquire( Activator::UPGRADE_LOCK, Activator::LOCK_TTL ) );
		$this->assertFalse( Activator::ensure_schema(), 'the other request holds the lock' );
		$this->assertSame( '1', get_option( 'dscc_db_version' ) );
		OptionLock::release( Activator::UPGRADE_LOCK );
		$this->assertTrue( Activator::ensure_schema() );
	}

	public function test_a_lock_left_by_a_dead_request_is_taken_over() {
		update_option( 'dscc_db_version', '1' );
		update_option( Activator::UPGRADE_LOCK, (string) ( time() - Activator::LOCK_TTL - 5 ) );
		$this->assertTrue( Activator::ensure_schema() );
		$this->assertSame( Schema::VERSION, get_option( 'dscc_db_version' ) );
	}

	public function test_the_version_option_is_autoloaded_so_the_check_costs_no_query() {
		Activator::install();
		$alloptions = wp_load_alloptions();
		$this->assertArrayHasKey( 'dscc_db_version', $alloptions );
	}

	public function test_after_this_plugin_is_updated_the_release_cache_is_dropped_and_the_site_is_poked_once() {
		$calls = array();
		add_filter(
			'pre_http_request',
			static function ( $pre, $args, $url ) use ( &$calls ) {
				$calls[] = array( $url, $args );
				return array(
					'response' => array( 'code' => 200 ),
					'headers'  => array(),
					'body'     => '',
				);
			},
			10,
			3
		);
		set_site_transient( GitHubUpdater::CACHE_KEY, array( 'repo' => 'x/y' ), HOUR_IN_SECONDS );

		Activator::after_update( null, array( 'action' => 'update', 'type' => 'plugin', 'plugins' => array( 'akismet/akismet.php' ) ) );
		$this->assertNotFalse( get_site_transient( GitHubUpdater::CACHE_KEY ), 'other plugins do not matter' );
		$this->assertCount( 0, $calls );

		Activator::after_update( null, array( 'action' => 'update', 'type' => 'theme', 'themes' => array( 'x' ) ) );
		$this->assertCount( 0, $calls );

		Activator::after_update( null, array( 'action' => 'update', 'type' => 'plugin', 'plugins' => array( plugin_basename( DSCC_FILE ) ) ) );
		$this->assertFalse( get_site_transient( GitHubUpdater::CACHE_KEY ) );
		$this->assertCount( 1, $calls );
		$this->assertStringStartsWith( trailingslashit( home_url() ) . '?dscc_upgrade=', $calls[0][0], 'a fresh query string gets past a page cache' );
		$this->assertFalse( $calls[0][1]['blocking'] );
	}

	public function test_a_failing_upgrade_is_caught_keeps_the_lock_as_a_backoff_and_does_not_retry_at_once() {
		update_option( 'dscc_db_version', '1' );
		$attempts = 0;
		$boom     = static function ( $query ) use ( &$attempts ) {
			if ( preg_match( '/^\s*(SHOW|CREATE|ALTER|DESCRIBE)\b/i', $query ) ) {
				++$attempts;
				throw new RuntimeException( 'LittleD simulated database failure' );
			}
			return $query;
		};
		add_filter( 'query', $boom, 1 );
		$logged = ini_get( 'error_log' );
		$sink   = tempnam( sys_get_temp_dir(), 'littled' );
		ini_set( 'error_log', $sink ); // phpcs:ignore WordPress.PHP.IniSet.Risky -- keeps the expected log line out of the test output.

		$this->assertFalse( Activator::ensure_schema(), 'the exception does not escape' );
		$this->assertGreaterThan( 0, $attempts );
		$first = $attempts;
		$this->assertNotFalse( get_option( Activator::UPGRADE_LOCK ), 'the lock stays as a backoff' );
		$this->assertSame( '1', get_option( 'dscc_db_version' ), 'the version is not advanced' );

		$this->assertFalse( Activator::ensure_schema(), 'the next request waits' );
		$this->assertSame( $first, $attempts, 'no second attempt while the lock is fresh' );

		ini_set( 'error_log', (string) $logged ); // phpcs:ignore WordPress.PHP.IniSet.Risky
		$log = (string) file_get_contents( $sink ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		unlink( $sink ); // phpcs:ignore WordPress.WP.AlternativeFunctions.unlink_unlink
		$this->assertSame( 1, substr_count( $log, 'schema upgrade failed' ), 'logged once' );
		remove_filter( 'query', $boom, 1 );

		// After the backoff expires the upgrade works again.
		update_option( Activator::UPGRADE_LOCK, (string) ( time() - Activator::LOCK_TTL - 5 ) );
		$this->assertTrue( Activator::ensure_schema() );
		$this->assertSame( Schema::VERSION, get_option( 'dscc_db_version' ) );
	}
}
