<?php
use DSolutions\CookieConsent\Update\GitHubUpdater;

/**
 * The updater against a stubbed GitHub: no request leaves the test container.
 */
class Dscc_GitHub_Updater_Test extends WP_UnitTestCase {

	/** @var array[] Requests the stub saw: url, args. */
	private $requests = array();

	/** @var array|null Canned answer for the API call. */
	private $answer;

	/** @var callable|null When set, decides the answer per request: fn( $url, $args ) => answer. */
	private $router;

	public function set_up() {
		parent::set_up();
		$this->requests = array();
		$this->router   = null;
		$this->answer   = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array(),
			'body'     => $this->fixture( 'release-latest' ),
		);
		delete_site_transient( GitHubUpdater::CACHE_KEY );
		add_filter( 'pre_http_request', array( $this, 'stub_http' ), 10, 3 );
	}

	public function tear_down() {
		remove_filter( 'pre_http_request', array( $this, 'stub_http' ), 10 );
		delete_site_transient( GitHubUpdater::CACHE_KEY );
		remove_all_filters( 'dscc_update_repo' );
		remove_all_filters( 'dscc_github_token' );
		unset( $_GET['force-check'] );
		delete_site_transient( 'update_plugins' );
		set_current_screen( 'front' );
		parent::tear_down();
	}

	public function stub_http( $pre, $args, $url ) {
		$this->requests[] = array(
			'url'  => $url,
			'args' => $args,
		);
		return $this->router ? call_user_func( $this->router, $url, $args ) : $this->answer;
	}

	private function fixture( $name ) {
		return (string) file_get_contents( dirname( __DIR__ ) . '/fixtures/github/' . $name . '.json' );
	}

	private function transient() {
		return (object) array(
			'last_checked' => time(),
			'checked'      => array( plugin_basename( DSCC_FILE ) => DSCC_VERSION ),
			'response'     => array(),
			'no_update'    => array(),
		);
	}

	public function test_a_newer_release_is_added_to_the_core_update_list() {
		$result = GitHubUpdater::inject_update( $this->transient() );
		$base   = plugin_basename( DSCC_FILE );
		$this->assertArrayHasKey( $base, $result->response );
		$item = $result->response[ $base ];
		$this->assertSame( '1.2.0', $item->new_version );
		$this->assertSame( 'ds-cookie-consent', $item->slug );
		$this->assertSame( 'https://github.com/LittleD-org/littled-cookie/releases/download/v1.2.0/ds-cookie-consent.zip', $item->package );
		$this->assertArrayNotHasKey( $base, $result->no_update );
		$this->assertCount( 1, $this->requests );
		$this->assertSame( 'https://api.github.com/repos/D-SOLUTIONS-TECHNOLOGY-MEDIA-CO-LTD/ds-cookie-consent/releases/latest', $this->requests[0]['url'] );
	}

	public function test_the_answer_is_cached_for_twelve_hours() {
		GitHubUpdater::inject_update( $this->transient() );
		GitHubUpdater::inject_update( $this->transient() );
		GitHubUpdater::latest();
		$this->assertCount( 1, $this->requests, 'one request for three lookups' );

		$cached = get_site_transient( GitHubUpdater::CACHE_KEY );
		$this->assertSame( 'D-SOLUTIONS-TECHNOLOGY-MEDIA-CO-LTD/ds-cookie-consent', $cached['repo'] );
		$this->assertSame( '1.2.0', $cached['release']['version'] );
		$timeout = (int) get_option( '_site_transient_timeout_' . GitHubUpdater::CACHE_KEY );
		$this->assertEqualsWithDelta( time() + 12 * HOUR_IN_SECONDS, $timeout, 30 );
	}

	public function test_the_cache_is_dropped_when_the_repository_changes() {
		GitHubUpdater::latest();
		add_filter(
			'dscc_update_repo',
			static function () {
				return 'LittleD-org/other-repo';
			}
		);
		GitHubUpdater::latest();
		$this->assertCount( 2, $this->requests );
		$this->assertStringContainsString( '/repos/LittleD-org/other-repo/', $this->requests[1]['url'] );
	}

	public function test_an_installed_or_newer_version_is_listed_as_no_update() {
		$this->answer['body'] = str_replace( 'v1.2.0', 'v' . DSCC_VERSION, $this->fixture( 'release-latest' ) );
		$result               = GitHubUpdater::inject_update( $this->transient() );
		$this->assertArrayNotHasKey( plugin_basename( DSCC_FILE ), $result->response );
		$this->assertArrayHasKey( plugin_basename( DSCC_FILE ), $result->no_update, 'the Plugins screen can still offer auto-updates' );
	}

	public function test_failures_are_cached_for_an_hour_and_never_break_the_update_check() {
		foreach ( array(
			array( 'response' => array( 'code' => 403 ), 'body' => '{"message":"rate limit"}', 'headers' => array() ),
			array( 'response' => array( 'code' => 404 ), 'body' => '{"message":"Not Found"}', 'headers' => array() ),
			array( 'response' => array( 'code' => 200 ), 'body' => '<html>', 'headers' => array() ),
		) as $answer ) {
			delete_site_transient( GitHubUpdater::CACHE_KEY );
			$this->answer = $answer;
			$result       = GitHubUpdater::inject_update( $this->transient() );
			$this->assertEmpty( $result->response );
		}
		$this->answer = new WP_Error( 'http_request_failed', 'timeout' );
		delete_site_transient( GitHubUpdater::CACHE_KEY );
		$this->assertEmpty( GitHubUpdater::inject_update( $this->transient() )->response );
		$timeout = (int) get_option( '_site_transient_timeout_' . GitHubUpdater::CACHE_KEY );
		$this->assertEqualsWithDelta( time() + HOUR_IN_SECONDS, $timeout, 30, 'a transport error is retried after an hour' );
	}

	public function test_writes_of_the_transient_without_a_real_check_use_the_cache_only() {
		$bare = (object) array( 'response' => array() );
		GitHubUpdater::inject_update( $bare );
		$this->assertCount( 0, $this->requests, 'no request when core is not checking' );
	}

	public function test_an_invalid_repository_switches_the_updater_off() {
		add_filter(
			'dscc_update_repo',
			static function () {
				return 'https://evil.example/x';
			}
		);
		$this->assertSame( '', GitHubUpdater::repo() );
		$this->assertNull( GitHubUpdater::latest() );
		$this->assertCount( 0, $this->requests );
		$result = GitHubUpdater::inject_update( $this->transient() );
		$this->assertEmpty( $result->response );
		$this->assertEmpty( $result->no_update );
	}

	public function test_the_default_repository_is_the_public_d_solutions_repository() {
		$this->assertSame( 'D-SOLUTIONS-TECHNOLOGY-MEDIA-CO-LTD/ds-cookie-consent', GitHubUpdater::DEFAULT_REPO );
		$this->assertSame( 'D-SOLUTIONS-TECHNOLOGY-MEDIA-CO-LTD/ds-cookie-consent', GitHubUpdater::repo() );
	}

	public function test_no_token_means_no_authorization_header() {
		GitHubUpdater::latest();
		$headers = $this->requests[0]['args']['headers'];
		$this->assertArrayNotHasKey( 'Authorization', $headers );
		$this->assertSame( 'application/vnd.github+json', $headers['Accept'] );
		$this->assertStringStartsWith( 'DS-Cookie-Consent/', $headers['User-Agent'] );
	}

	public function test_plugin_information_for_the_view_details_popup() {
		$info = GitHubUpdater::plugin_information( false, 'plugin_information', (object) array( 'slug' => 'ds-cookie-consent' ) );
		$this->assertIsObject( $info );
		$this->assertSame( '1.2.0', $info->version );
		$this->assertSame( 'https://github.com/LittleD-org/littled-cookie/releases/download/v1.2.0/ds-cookie-consent.zip', $info->download_link );
		$this->assertStringContainsString( 'Fixed LittleD banner focus', $info->sections['changelog'] );
		$this->assertStringNotContainsString( '<b>', $info->sections['changelog'], 'release notes are escaped' );

		$other = GitHubUpdater::plugin_information( false, 'plugin_information', (object) array( 'slug' => 'akismet' ) );
		$this->assertFalse( $other, 'other plugins are not answered' );
		$this->assertFalse( GitHubUpdater::plugin_information( false, 'query_plugins', (object) array( 'slug' => 'ds-cookie-consent' ) ) );
	}

	public function test_the_package_of_this_plugin_is_checked_against_the_published_digest() {
		GitHubUpdater::latest();
		$package = 'https://github.com/LittleD-org/littled-cookie/releases/download/v1.2.0/ds-cookie-consent.zip';
		$file    = wp_tempnam( 'littled-package' );
		file_put_contents( $file, 'LittleD zip bytes' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents
		// The stub answers the download request by streaming to the target file.
		$this->answer = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array(),
			'body'     => '',
			'filename' => $file,
		);
		$result = GitHubUpdater::download_package( false, $package, null, array( 'plugin' => plugin_basename( DSCC_FILE ) ) );
		$this->assertWPError( $result, 'the fixture digest is not the digest of these bytes' );
		$this->assertSame( 'dscc_bad_digest', $result->get_error_code() );

		$this->assertFalse( GitHubUpdater::download_package( false, 'https://example.test/other.zip', null, array() ), 'foreign packages are left to core' );
		$this->assertSame( 'kept', GitHubUpdater::download_package( 'kept', $package, null, array() ), 'an earlier reply is kept' );
		$this->assertFalse( GitHubUpdater::download_package( false, $package, null, array( 'plugin' => 'akismet/akismet.php' ) ), 'another plugin is left to core' );
	}

	public function test_the_extracted_folder_is_renamed_to_the_installed_plugin_folder() {
		require_once ABSPATH . 'wp-admin/includes/file.php';
		WP_Filesystem();
		global $wp_filesystem;
		$work = trailingslashit( get_temp_dir() ) . 'littled-upgrade-' . wp_generate_password( 6, false );
		$wp_filesystem->mkdir( $work );
		$wp_filesystem->mkdir( $work . '/LittleD-org-ds-cookie-consent-abc123' );
		$wp_filesystem->put_contents( $work . '/LittleD-org-ds-cookie-consent-abc123/ds-cookie-consent.php', '<?php // LittleD' );

		$extra  = array( 'plugin' => plugin_basename( DSCC_FILE ) );
		$source = GitHubUpdater::fix_source_folder( $work . '/LittleD-org-ds-cookie-consent-abc123/', $work, null, $extra );
		$this->assertSame( trailingslashit( $work ) . dirname( plugin_basename( DSCC_FILE ) ) . '/', $source );
		$this->assertTrue( $wp_filesystem->exists( $source . 'ds-cookie-consent.php' ) );
		$this->assertFalse( $wp_filesystem->exists( $work . '/LittleD-org-ds-cookie-consent-abc123' ) );

		// Already right: untouched. Another plugin: untouched.
		$this->assertSame( $source, GitHubUpdater::fix_source_folder( $source, $work, null, $extra ) );
		$this->assertSame( '/x/y/', GitHubUpdater::fix_source_folder( '/x/y/', $work, null, array( 'plugin' => 'akismet/akismet.php' ) ) );

		// A package without the plugin file is refused.
		$wp_filesystem->mkdir( $work . '/wrong' );
		$bad = GitHubUpdater::fix_source_folder( $work . '/wrong/', $work, null, $extra );
		$this->assertWPError( $bad );
		$this->assertSame( 'dscc_bad_package', $bad->get_error_code() );
		$wp_filesystem->delete( $work, true );
	}

	public function test_the_hooks_are_registered() {
		$this->assertNotFalse( has_filter( 'pre_set_site_transient_update_plugins', array( GitHubUpdater::class, 'inject_update' ) ) );
		$this->assertNotFalse( has_filter( 'plugins_api', array( GitHubUpdater::class, 'plugin_information' ) ) );
		$this->assertNotFalse( has_filter( 'upgrader_source_selection', array( GitHubUpdater::class, 'fix_source_folder' ) ) );
		$this->assertNotFalse( has_filter( 'upgrader_pre_download', array( GitHubUpdater::class, 'download_package' ) ) );
		$this->assertNotFalse( has_action( 'upgrader_process_complete', array( \DSolutions\CookieConsent\Activator::class, 'after_update' ) ) );
	}

	/* ------------------------------------------------------------ hardening */

	private function release_body_with_digest( $digest ) {
		$doc                             = json_decode( $this->fixture( 'release-latest' ), true );
		$doc['assets'][0]['digest']      = 'sha256:' . $digest;
		return (string) wp_json_encode( $doc );
	}

	public function test_the_release_request_refuses_redirects_and_a_redirect_is_an_error() {
		$this->answer = array(
			'response' => array( 'code' => 302 ),
			'headers'  => array( 'location' => 'https://evil.example.test/releases' ),
			'body'     => '',
		);
		$this->assertNull( GitHubUpdater::latest() );
		$this->assertSame( 0, $this->requests[0]['args']['redirection'], 'a redirect is never followed' );
		$timeout = (int) get_option( '_site_transient_timeout_' . GitHubUpdater::CACHE_KEY );
		$this->assertEqualsWithDelta( time() + HOUR_IN_SECONDS, $timeout, 30 );
		$this->assertCount( 1, $this->requests, 'the redirect target was not requested' );
	}

	public function test_a_malformed_answer_is_cached_for_an_hour_not_twelve() {
		$this->answer = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array(),
			'body'     => '<html>maintenance</html>',
		);
		$this->assertNull( GitHubUpdater::latest() );
		$timeout = (int) get_option( '_site_transient_timeout_' . GitHubUpdater::CACHE_KEY );
		$this->assertEqualsWithDelta( time() + HOUR_IN_SECONDS, $timeout, 30 );

		delete_site_transient( GitHubUpdater::CACHE_KEY );
		$this->answer = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array(),
			'body'     => $this->fixture( 'release-latest' ),
		);
		GitHubUpdater::latest();
		$timeout = (int) get_option( '_site_transient_timeout_' . GitHubUpdater::CACHE_KEY );
		$this->assertEqualsWithDelta( time() + 12 * HOUR_IN_SECONDS, $timeout, 30, 'a real release keeps the long cache' );
	}

	public function test_the_cache_bypass_of_check_again_needs_the_update_plugins_capability() {
		GitHubUpdater::latest();
		set_current_screen( 'dashboard' );
		$_GET['force-check'] = '1';

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'subscriber' ) ) );
		GitHubUpdater::latest();
		$this->assertCount( 1, $this->requests, 'a subscriber cannot make the site call GitHub' );

		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		GitHubUpdater::latest();
		$this->assertCount( 2, $this->requests, 'an administrator can force a fresh check' );
		wp_set_current_user( 0 );
	}

	public function test_the_token_goes_only_to_the_api_host_and_not_to_the_download_redirect() {
		add_filter(
			'dscc_github_token',
			static function () {
				return 'LittleD-token-123';
			}
		);
		$bytes        = 'LittleD zip bytes';
		$this->answer = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array(),
			'body'     => $this->release_body_with_digest( hash( 'sha256', $bytes ) ),
		);
		$release = GitHubUpdater::latest();
		$this->assertSame( 'Bearer LittleD-token-123', $this->requests[0]['args']['headers']['Authorization'], 'the API call is authenticated' );
		$this->assertSame( 'https://api.github.com/repos/LittleD-org/littled-cookie/releases/assets/7001', $release['api_url'] );

		// With a token the update points at the asset API URL; the storage host answers a redirect.
		$this->requests = array();
		$this->router   = static function ( $url, $args ) use ( $bytes ) {
			if ( 0 === strpos( $url, 'https://api.github.com/' ) ) {
				return array(
					'response' => array( 'code' => 302 ),
					'headers'  => array( 'location' => 'https://objects.githubusercontent.example.test/signed?token=LittleD-signed' ),
					'body'     => '',
				);
			}
			file_put_contents( $args['filename'], $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents
			return array(
				'response' => array( 'code' => 200 ),
				'headers'  => array(),
				'body'     => '',
				'filename' => $args['filename'],
			);
		};
		$file = GitHubUpdater::download_package( false, $release['api_url'], null, array( 'plugin' => plugin_basename( DSCC_FILE ) ) );
		$this->assertIsString( $file, 'the digest matched and the file was handed to the upgrader' );
		$this->assertSame( $bytes, file_get_contents( $file ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		wp_delete_file( $file );

		$this->assertCount( 2, $this->requests );
		$this->assertSame( 'Bearer LittleD-token-123', $this->requests[0]['args']['headers']['Authorization'] );
		$this->assertSame( 0, $this->requests[0]['args']['redirection'], 'the redirect is followed by hand' );
		$this->assertStringStartsWith( 'https://objects.githubusercontent.example.test/', $this->requests[1]['url'] );
		$this->assertArrayNotHasKey( 'Authorization', $this->requests[1]['args']['headers'] ?? array(), 'the storage host never sees the token' );
		$this->assertStringNotContainsString( 'LittleD-token-123', $this->requests[1]['url'], 'the token is never put in a URL' );
	}

	public function test_a_public_asset_download_carries_no_credential_even_when_a_token_exists() {
		add_filter(
			'dscc_github_token',
			static function () {
				return 'LittleD-token-456';
			}
		);
		$bytes        = 'LittleD public zip';
		$this->answer = array(
			'response' => array( 'code' => 200 ),
			'headers'  => array(),
			'body'     => $this->release_body_with_digest( hash( 'sha256', $bytes ) ),
		);
		$release        = GitHubUpdater::latest();
		$this->requests = array();
		$this->router   = static function ( $url, $args ) use ( $bytes ) {
			file_put_contents( $args['filename'], $bytes ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents
			return array(
				'response' => array( 'code' => 200 ),
				'headers'  => array(),
				'body'     => '',
				'filename' => $args['filename'],
			);
		};
		$file = GitHubUpdater::download_package( false, $release['package'], null, array( 'plugin' => plugin_basename( DSCC_FILE ) ) );
		$this->assertIsString( $file );
		wp_delete_file( $file );
		$this->assertCount( 1, $this->requests );
		$this->assertSame( $release['package'], $this->requests[0]['url'] );
		$this->assertArrayNotHasKey( 'Authorization', $this->requests[0]['args']['headers'] ?? array() );
	}

	public function test_the_update_item_carries_what_the_download_needs() {
		$result = GitHubUpdater::inject_update( $this->transient() );
		$item   = $result->response[ plugin_basename( DSCC_FILE ) ];
		$this->assertSame( 'https://github.com/LittleD-org/littled-cookie/releases/download/v1.2.0/ds-cookie-consent.zip', $item->dscc_package );
		$this->assertSame( 'https://api.github.com/repos/LittleD-org/littled-cookie/releases/assets/7001', $item->dscc_api_url );
		$this->assertSame( str_repeat( 'a', 64 ), $item->dscc_digest );
	}

	public function test_the_digest_is_still_checked_after_the_release_cache_expired() {
		$result = GitHubUpdater::inject_update( $this->transient() );
		set_site_transient( 'update_plugins', $result );
		delete_site_transient( GitHubUpdater::CACHE_KEY );
		$package = $result->response[ plugin_basename( DSCC_FILE ) ]->package;

		$this->router = static function ( $url, $args ) {
			file_put_contents( $args['filename'], 'LittleD tampered bytes' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_put_contents_file_put_contents
			return array(
				'response' => array( 'code' => 200 ),
				'headers'  => array(),
				'body'     => '',
				'filename' => $args['filename'],
			);
		};
		$reply = GitHubUpdater::download_package( false, $package, null, array( 'plugin' => plugin_basename( DSCC_FILE ) ) );
		$this->assertWPError( $reply );
		$this->assertSame( 'dscc_bad_digest', $reply->get_error_code(), 'without the cache the stored digest still applies' );
	}

	public function test_an_update_of_this_plugin_with_unknown_package_information_is_refused() {
		delete_site_transient( GitHubUpdater::CACHE_KEY );
		delete_site_transient( 'update_plugins' );
		$reply = GitHubUpdater::download_package( false, 'https://github.com/LittleD-org/littled-cookie/releases/download/v9.9.9/ds-cookie-consent.zip', null, array( 'plugin' => plugin_basename( DSCC_FILE ) ) );
		$this->assertWPError( $reply, 'the check is never skipped because nothing is known' );
		$this->assertSame( 'dscc_unknown_package', $reply->get_error_code() );
		$this->assertCount( 0, $this->requests );
	}

	public function test_the_frontend_makes_no_http_request() {
		$plugin = new ReflectionClass( \DSolutions\CookieConsent\Plugin::class );
		$flag   = $plugin->getProperty( 'booted' );
		$flag->setAccessible( true );
		$instance = \DSolutions\CookieConsent\Plugin::instance();
		$flag->setValue( $instance, false );
		$instance->boot();

		$this->go_to( home_url( '/' ) );
		$level = ob_get_level();
		foreach ( array( 'init', 'wp_loaded', 'template_redirect', 'wp_enqueue_scripts', 'wp_head', 'wp_footer' ) as $hook ) {
			ob_start();
			do_action( $hook );
			ob_end_clean();
		}
		while ( ob_get_level() > $level ) {
			ob_end_clean(); // The blocker starts its own buffer on template_redirect.
		}
		$this->assertCount( 0, $this->requests, 'boot and a page render never call GitHub' );
	}

	public function test_a_release_without_a_digest_shows_an_admin_notice() {
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		set_current_screen( 'plugins' );
		$this->answer['body'] = $this->fixture( 'release-no-digest' );
		GitHubUpdater::latest();

		ob_start();
		GitHubUpdater::digest_notice();
		$html = ob_get_clean();
		$this->assertStringContainsString( 'no published SHA-256 checksum', $html );
		$this->assertStringContainsString( '1.2.0', $html );

		// A release with a digest, another screen, or a user who cannot update: silence.
		set_current_screen( 'dashboard' );
		ob_start();
		GitHubUpdater::digest_notice();
		$this->assertSame( '', ob_get_clean() );
		set_current_screen( 'plugins' );
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'editor' ) ) );
		ob_start();
		GitHubUpdater::digest_notice();
		$this->assertSame( '', ob_get_clean() );
		wp_set_current_user( 0 );

		delete_site_transient( GitHubUpdater::CACHE_KEY );
		$this->answer['body'] = $this->fixture( 'release-latest' );
		GitHubUpdater::latest();
		wp_set_current_user( self::factory()->user->create( array( 'role' => 'administrator' ) ) );
		ob_start();
		GitHubUpdater::digest_notice();
		$this->assertSame( '', ob_get_clean(), 'a release with a digest needs no notice' );
		wp_set_current_user( 0 );
	}
}
