<?php
use DSolutions\CookieConsent\Activator;
use DSolutions\CookieConsent\Blocker\FailOpenLog;
use DSolutions\CookieConsent\Blocker\ObservedMatches;
use DSolutions\CookieConsent\Blocker\OutputBuffer;
use DSolutions\CookieConsent\Blocker\PerfMonitor;
use DSolutions\CookieConsent\Blocker\Placeholder;
use DSolutions\CookieConsent\Blocker\ScriptRules;
use DSolutions\CookieConsent\Catalog\CookieRepository;
use DSolutions\CookieConsent\Consent\ConsentReader;
use DSolutions\CookieConsent\Catalog\FrontendCatalogCache;
use DSolutions\CookieConsent\Catalog\PlatformManager;
use DSolutions\CookieConsent\Catalog\ServiceRepository;
use DSolutions\CookieConsent\Frontend\ClientData;
use DSolutions\CookieConsent\Frontend\HeadScript;
use DSolutions\CookieConsent\Frontend\Renderer;
use DSolutions\CookieConsent\Frontend\ViewModel;
use DSolutions\CookieConsent\Settings\Settings;

/**
 * Rules snapshot, settings applied to it, the output buffer on front requests only, observe mode, the slow page
 * warning and the per-service switch rule.
 */
class Dscc_Blocker_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Activator::install();
		FrontendCatalogCache::rebuild();
		add_option( 'dscc_texts_vi', array(), '', 'yes' );
		add_option( 'dscc_texts_en', array(), '', 'yes' );
		$this->blocking( array() );
		ScriptRules::flush();
		OutputBuffer::reset();
		Placeholder::flush();
	}

	public function tear_down() {
		ScriptRules::flush();
		OutputBuffer::reset();
		unset( $_COOKIE['dscc_consent'] );
		parent::tear_down();
	}

	/** Patch the blocking settings group. */
	private function blocking( array $values ) {
		$current = Settings::group( 'blocking' );
		Settings::save_group( 'blocking', array_merge( $current, $values ) );
	}

	private function slugs( array $rules ) {
		return wp_list_pluck( $rules, 's' );
	}

	public function test_rules_cover_ticked_platforms_with_blocking_on_and_never_the_necessary_ones() {
		foreach ( array( 'google-analytics', 'meta-pixel', 'wpml', 'cloudflare-turnstile', 'contact-form-7' ) as $slug ) {
			PlatformManager::enable( $slug );
		}
		$active = ScriptRules::active();
		$this->assertEqualsCanonicalizing( array( 'google-analytics', 'meta-pixel' ), $this->slugs( $active['rules'] ) );

		ServiceRepository::set_block( 'meta-pixel', false );
		$this->assertSame( array( 'google-analytics' ), $this->slugs( ScriptRules::active()['rules'] ), 'switching "block until consent" off removes the rule' );
	}

	public function test_forms_and_anti_abuse_widgets_are_never_blocked_even_when_forced() {
		PlatformManager::enable( 'contact-form-7' );
		PlatformManager::enable( 'google-recaptcha' );
		ServiceRepository::upsert(
			array(
				'slug'          => 'google-recaptcha',
				'category_key'  => 'marketing',
				'block_enabled' => 1,
			)
		);
		ServiceRepository::set_block( 'contact-form-7', true );
		$this->assertSame( array(), ScriptRules::active()['rules'] );
	}

	public function test_gtm_is_blocked_only_in_block_mode_and_then_released_by_analytics_or_marketing() {
		PlatformManager::enable( 'google-tag-manager' );
		$this->assertSame( array(), ScriptRules::active()['rules'], 'default: GTM + Consent Mode, the loader is not blocked' );

		$this->blocking( array( 'gtm_mode' => 'block' ) );
		$rules = ScriptRules::active()['rules'];
		$this->assertSame( array( 'google-tag-manager' ), $this->slugs( $rules ) );
		$this->assertSame( 'analytics marketing', $rules[0]['c'] );
	}

	public function test_unclassified_services_follow_the_setting_and_are_blocked_as_marketing() {
		ServiceRepository::upsert(
			array(
				'slug'            => 'littled-unknown',
				'name'            => 'LittleD Unknown',
				'category_key'    => 'unclassified',
				'status'          => 'auto',
				'script_patterns' => array( 'hosts' => array( 'tracker.littled.test' ) ),
			)
		);
		$rules = ScriptRules::active()['rules'];
		$this->assertSame( 'marketing', $rules[0]['c'] );

		$this->blocking( array( 'block_unclassified' => false ) );
		$this->assertSame( array(), ScriptRules::active()['rules'] );
	}

	public function test_ignored_services_and_a_hidden_functional_category_are_not_blocked() {
		PlatformManager::enable( 'google-analytics' );
		PlatformManager::enable( 'google-site-kit' );
		ServiceRepository::set_block( 'google-site-kit', true );
		$this->assertContains( 'google-site-kit', $this->slugs( ScriptRules::active()['rules'] ), 'the Functional group is visible by default, so its services are blocked until it is allowed' );

		Settings::save_group( 'advanced', array_merge( Settings::group( 'advanced' ), array( 'show_functional' => false ) ) );
		$this->assertSame( array( 'google-analytics' ), $this->slugs( ScriptRules::active()['rules'] ), 'functional is merged into necessary when the group is turned off' );

		PlatformManager::disable( 'google-analytics' );
		$this->assertNotContains( 'google-analytics', $this->slugs( ScriptRules::active()['rules'] ) );
	}

	public function test_youtube_is_blocked_under_the_functional_category_and_its_placeholder_asks_for_it() {
		PlatformManager::enable( 'youtube' );
		$rules = ScriptRules::active()['rules'];
		$this->assertSame( array( 'youtube' ), $this->slugs( $rules ) );
		$this->assertSame( 'functional', $rules[0]['c'] );

		$this->go_to( home_url( '/' ) );
		OutputBuffer::start();
		ob_end_clean();
		$page = '<!doctype html><html><body><iframe src="https://www.youtube.com/embed/LittleD000"></iframe></body></html>';
		$out  = OutputBuffer::callback( $page, PHP_OUTPUT_HANDLER_START | PHP_OUTPUT_HANDLER_FINAL );
		$this->assertStringContainsString( 'data-dscc-category="functional"', $out );
		$this->assertStringContainsString( 'data-dscc-enable-cat="functional"', $out );

		Settings::save_group( 'advanced', array_merge( Settings::group( 'advanced' ), array( 'show_functional' => false ) ) );
		$this->assertSame( array(), ScriptRules::active()['rules'], 'with the Functional group turned off YouTube is no longer blocked' );
	}

	public function test_the_tag_manager_never_gets_a_switch_of_its_own_in_the_popup() {
		PlatformManager::enable( 'google-tag-manager' );
		PlatformManager::enable( 'youtube' );
		$by_slug = array_column( FrontendCatalogCache::services(), null, 'slug' );
		$this->assertFalse( $by_slug['google-tag-manager']['blockable'] );
		$this->assertTrue( $by_slug['youtube']['blockable'] );
	}

	public function test_only_services_with_a_switch_are_tracked_one_by_one_in_the_client_data() {
		PlatformManager::enable( 'google-tag-manager' );
		PlatformManager::enable( 'youtube' );
		$cats = array_column( ClientData::build( ViewModel::build() )['cats'], null, 'key' );
		$this->assertSame( array( 'youtube' ), $cats['functional']['services'], 'the tag manager follows the analytics/marketing choice, not the Functional group' );
	}

	public function test_rules_live_in_one_autoloaded_option_and_reading_them_costs_no_query() {
		global $wpdb;
		PlatformManager::enable( 'google-analytics' );
		ScriptRules::rebuild();
		ScriptRules::flush();
		wp_load_alloptions(); // The state of a real request: autoloaded options are already in memory.
		$this->assertContains( $wpdb->get_var( $wpdb->prepare( "SELECT autoload FROM {$wpdb->options} WHERE option_name = %s", ScriptRules::OPTION ) ), array( 'yes', 'on' ) );

		$before = $wpdb->num_queries;
		ScriptRules::active();
		ScriptRules::purge_list();
		$this->assertSame( 0, $wpdb->num_queries - $before );
	}

	public function test_a_catalog_change_drops_and_rebuilds_the_rules() {
		PlatformManager::enable( 'google-analytics' );
		$this->assertCount( 1, ScriptRules::active()['rules'] );
		$this->assertNotFalse( get_option( ScriptRules::OPTION ) );
		PlatformManager::enable( 'microsoft-clarity' );
		$this->assertCount( 2, ScriptRules::active()['rules'] );
	}

	public function test_purge_list_groups_cookie_names_per_service_for_the_browser() {
		PlatformManager::enable( 'meta-pixel' );
		$groups = ScriptRules::purge_list();
		$this->assertSame( 'meta-pixel', $groups[0]['s'] );
		$this->assertSame( 'marketing', $groups[0]['c'] );
		$this->assertContains( array( '_fbp', 'cookie' ), $groups[0]['k'] );
	}

	public function test_buffer_starts_only_on_front_requests_with_active_rules() {
		$this->go_to( home_url( '/' ) );
		$this->assertFalse( OutputBuffer::start(), 'no rule, no buffer' );

		PlatformManager::enable( 'google-analytics' );
		$level = ob_get_level();
		$this->assertTrue( OutputBuffer::start() );
		$this->assertSame( $level + 1, ob_get_level() );
		ob_end_clean();

		set_current_screen( 'dashboard' );
		$this->assertFalse( OutputBuffer::start(), 'never in the admin' );
		set_current_screen( 'front' );

		add_filter( 'wp_doing_ajax', '__return_true' );
		$this->assertFalse( OutputBuffer::start(), 'never in AJAX' );
		remove_filter( 'wp_doing_ajax', '__return_true' );

		$this->go_to( home_url( '/?feed=rss2' ) );
		$this->assertFalse( OutputBuffer::start(), 'never in a feed' );

		$this->go_to( home_url( '/?dscc_preview=1' ) );
		$this->assertFalse( OutputBuffer::start(), 'never in the admin preview' );
	}

	public function test_callback_rewrites_a_whole_html_page_only() {
		PlatformManager::enable( 'google-analytics' );
		$this->go_to( home_url( '/' ) );
		OutputBuffer::start();
		ob_end_clean();
		$page = '<!doctype html><html><head><script src="https://www.googletagmanager.com/gtag/js?id=G-LITTLED000"></script></head><body></body></html>';

		$out = OutputBuffer::callback( $page, PHP_OUTPUT_HANDLER_START | PHP_OUTPUT_HANDLER_FINAL );
		$this->assertStringContainsString( 'type="text/plain" data-dscc-category="analytics" data-dscc-service="google-analytics"', $out );

		$this->assertFalse( OutputBuffer::callback( $page, PHP_OUTPUT_HANDLER_FINAL ), 'a chunk after an earlier flush is passed through' );
		$this->assertFalse( OutputBuffer::callback( '{"url":"https://www.googletagmanager.com/gtag/js?id=G-LITTLED000"}', PHP_OUTPUT_HANDLER_START | PHP_OUTPUT_HANDLER_FINAL ), 'JSON is not a page' );
	}

	public function test_the_page_is_identical_for_every_visitor() {
		PlatformManager::enable( 'google-analytics' );
		PlatformManager::enable( 'youtube' );
		$this->go_to( home_url( '/' ) );
		OutputBuffer::start();
		ob_end_clean();
		$page = '<!doctype html><html><body><iframe src="https://www.youtube.com/embed/LittleD000"></iframe><script src="https://www.googletagmanager.com/gtag/js?id=G-LITTLED000"></script></body></html>';
		$flag = PHP_OUTPUT_HANDLER_START | PHP_OUTPUT_HANDLER_FINAL;

		$anonymous = OutputBuffer::callback( $page, $flag );
		$_COOKIE['dscc_consent'] = wp_json_encode(
			array(
				'v'   => Settings::get( 'advanced', 'policy_version' ),
				'id'  => 'LittleD-visitor',
				't'   => time(),
				'c'   => array( 'analytics' => 1, 'marketing' => 1 ),
				's'   => array(),
				'src' => 'banner',
			)
		);
		$consented = OutputBuffer::callback( $page, $flag );
		$this->assertSame( $anonymous, $consented );
		$this->assertStringContainsString( 'class="dscc-placeholder"', $anonymous );
		$this->assertStringContainsString( 'data-dscc-enable="youtube"', $anonymous );
	}

	public function test_observe_mode_changes_nothing_and_records_a_bounded_report() {
		PlatformManager::enable( 'google-analytics' );
		PlatformManager::enable( 'youtube' );
		$this->blocking( array( 'mode' => 'observe' ) );
		$this->go_to( home_url( '/' ) );
		OutputBuffer::start();
		ob_end_clean();
		$page = '<!doctype html><html><body><iframe src="https://www.youtube.com/embed/LittleD000"></iframe><script src="https://www.googletagmanager.com/gtag/js?id=G-LITTLED000"></script></body></html>';

		$this->assertSame( $page, OutputBuffer::callback( $page, PHP_OUTPUT_HANDLER_START | PHP_OUTPUT_HANDLER_FINAL ) );
		$report = ObservedMatches::load();
		$this->assertEqualsCanonicalizing( array( 'google-analytics', 'youtube' ), array_keys( $report['items'] ) );
		$this->assertSame( 1, $report['items']['youtube']['pages'] );

		ObservedMatches::clear();
		for ( $i = 0; $i < 60; $i++ ) {
			ObservedMatches::record( array( array( 'service' => 'littled-' . $i, 'category' => 'marketing', 'kind' => 'script', 'sample' => str_repeat( 'x', 400 ) ) ) );
		}
		$report = ObservedMatches::load();
		$this->assertCount( ObservedMatches::MAX_SERVICES, $report['items'] );
		for ( $i = 0; $i < 10; $i++ ) {
			ObservedMatches::record( array( array( 'service' => 'littled-0', 'category' => 'marketing', 'kind' => 'script', 'sample' => 'sample ' . $i ) ) );
		}
		$samples = ObservedMatches::load()['items']['littled-0']['samples'];
		$this->assertLessThanOrEqual( ObservedMatches::MAX_SAMPLES, count( $samples ) );
		foreach ( $samples as $sample ) {
			$this->assertLessThanOrEqual( ObservedMatches::MAX_SAMPLE_LENGTH, strlen( $sample ) );
		}
	}

	public function test_slow_pages_are_recorded_once_per_window_and_expire() {
		$this->assertFalse( PerfMonitor::record( 12.0, 1000, '/fast/', 1000 ) );
		$this->assertTrue( PerfMonitor::record( 45.5, 90000, '/slow/', time() ) );
		$this->assertFalse( PerfMonitor::record( 31.0, 90000, '/slower-but-lower/', time() ), 'sampled: one write per window unless it is worse' );
		$this->assertTrue( PerfMonitor::record( 80.0, 90000, '/worse/', time() ) );
		$this->assertSame( '/worse/', PerfMonitor::warning()['path'] );

		update_option( PerfMonitor::OPTION, array( 'ms' => 50, 'bytes' => 1, 'path' => '/old/', 'time' => time() - 8 * DAY_IN_SECONDS ), false );
		$this->assertNull( PerfMonitor::warning(), 'a week-old warning is not shown' );
		PerfMonitor::record( 99.0, 1, '/x/', time() );
		PerfMonitor::clear();
		$this->assertNull( PerfMonitor::warning() );
	}

	public function test_slow_threshold_is_read_from_the_filter_so_the_buffer_can_record() {
		PlatformManager::enable( 'google-analytics' );
		$this->go_to( home_url( '/' ) );
		OutputBuffer::start();
		ob_end_clean();
		add_filter(
			'dscc_blocker_slow_ms',
			static function () {
				return -1;
			}
		);
		OutputBuffer::callback( '<html><body><p>LittleD</p></body></html>', PHP_OUTPUT_HANDLER_START | PHP_OUTPUT_HANDLER_FINAL );
		$this->assertNotNull( PerfMonitor::warning() );
	}

	/**
	 * Markup of the bar layout.
	 */
	private function popup() {
		Settings::save_group( 'appearance', array_merge( Settings::group( 'appearance' ), array( 'layout' => 'bar' ) ) );
		ob_start();
		Renderer::print_markup( ViewModel::build() );
		return ob_get_clean();
	}

	public function test_a_service_without_script_patterns_gets_no_switch_but_still_purges_with_its_category() {
		PlatformManager::enable( 'google-analytics' );
		$id = ServiceRepository::upsert(
			array(
				'slug'         => 'littled-ga-no-patterns',
				'name'         => 'LittleD GA4 policy row',
				'category_key' => 'analytics',
				'status'       => 'confirmed',
			)
		);
		CookieRepository::upsert( array( 'name' => '_littled_policy', 'service_id' => $id, 'status' => 'confirmed' ) );
		FrontendCatalogCache::rebuild();

		$html = $this->popup();
		$this->assertStringContainsString( 'data-dscc-service="google-analytics"', $html, 'a service with patterns keeps its switch' );
		$this->assertStringNotContainsString( 'data-dscc-service="littled-ga-no-patterns"', $html );
		$this->assertStringContainsString( 'data-dscc-service-row="littled-ga-no-patterns"', $html, 'it is still listed with its cookies' );

		$purged = wp_list_pluck( ScriptRules::purge_list(), 's' );
		$this->assertContains( 'littled-ga-no-patterns', $purged, 'its cookies are deleted together with its category' );
	}

	public function test_a_service_with_blocking_switched_off_loses_its_switch() {
		PlatformManager::enable( 'microsoft-clarity' );
		ServiceRepository::set_block( 'microsoft-clarity', false );
		FrontendCatalogCache::rebuild();
		$this->assertStringNotContainsString( 'data-dscc-service="microsoft-clarity"', $this->popup() );
	}

	public function test_client_data_carries_purge_list_reload_and_integration_flags() {
		PlatformManager::enable( 'meta-pixel' );
		$data = ClientData::build( ViewModel::build() );
		$this->assertSame( 'block', $data['block']['mode'] );
		$this->assertTrue( $data['block']['reload'] );
		$this->assertSame( 'meta-pixel', $data['block']['purge'][0]['s'] );
		$this->assertSame( array( 'wpc' => 0, 'fbs' => 0 ), $data['int'] );

		$this->blocking( array( 'mode' => 'observe', 'reload_on_withdraw' => false ) );
		$data = ClientData::build( ViewModel::build() );
		$this->assertSame( array(), $data['block']['purge'], 'observe mode deletes nothing' );
		$this->assertFalse( $data['block']['reload'] );
	}

	public function test_chat_widget_selectors_join_the_hide_list_when_the_platform_is_ticked() {
		$this->assertNotContains( '#pancake-chat-plugin-root', ClientData::build( ViewModel::build() )['hide'] );
		PlatformManager::enable( 'pancake-chat' );
		FrontendCatalogCache::rebuild();
		$this->assertContains( '#pancake-chat-plugin-root', ClientData::build( ViewModel::build() )['hide'] );
	}

	public function test_head_script_budget_warning_never_drops_data() {
		$adv = Settings::group( 'advanced' );
		$this->assertFalse( HeadScript::over_budget( $adv ) );

		$long                   = $adv;
		$long['cookie_name']    = str_repeat( 'c', 64 );
		$long['consent_regions'] = array_fill( 0, 120, 'US-A12' ); // More than the schema allows: only a direct option write can get here.
		$this->assertTrue( HeadScript::over_budget( $long ) );
		$this->assertCount( 120, explode( ',', HeadScript::config( $long )['r'] ), 'all regions are still in the script' );
		$this->assertSame( str_repeat( 'c', 64 ), HeadScript::config( $long )['n'] );
	}

	public function test_placeholder_markup_is_same_for_any_visitor_and_escapes_everything() {
		add_filter(
			'locale',
			static function () {
				return 'vi';
			}
		);
		Placeholder::flush();
		$a = Placeholder::html( array( 'service' => 'youtube', 'category' => 'marketing', 'src' => 'https://www.youtube.com/embed/x', 'width' => '560', 'height' => '315' ) );
		$b = Placeholder::html( array( 'service' => 'youtube', 'category' => 'marketing', 'src' => 'https://www.youtube.com/embed/x', 'width' => '560', 'height' => '315' ) );
		$this->assertSame( $a, $b );
		$this->assertStringContainsString( 'Bật cookie Tiếp thị để xem video.', $a );
		$this->assertStringContainsString( '--dscc-ph-w:560px', $a );
		$evil = Placeholder::html( array( 'service' => 'x"><script>', 'category' => 'marketing', 'src' => '', 'width' => '1"onload=x', 'height' => '' ) );
		$this->assertStringNotContainsString( '<script>', $evil );
		$this->assertStringNotContainsString( 'onload=x', $evil );
	}

	public function test_safelisted_cookies_never_reach_the_purge_list_or_the_guard_rules() {
		$id = ServiceRepository::upsert(
			array(
				'slug'         => 'littled-greedy',
				'name'         => 'LittleD Greedy',
				'category_key' => 'unclassified',
				'status'       => 'auto',
			)
		);
		foreach ( array( 'wp_woocommerce_session_abc', 'PHPSESSID', 'comment_author_x', 'wordpress_logged_in_x', 'wp-settings-1', 'woocommerce_cart_hash', 'wc_facebook_signals_state', 'dscc_consent', '_littled_tracker' ) as $name ) {
			CookieRepository::upsert( array( 'name' => $name, 'service_id' => $id, 'status' => 'auto' ) );
		}
		ScriptRules::rebuild();
		ScriptRules::flush();
		$names = wp_list_pluck( ScriptRules::active()['cookies'], 0 );
		$this->assertSame( array( '_littled_tracker' ), $names );
		$keep = ClientData::build( ViewModel::build() )['block']['keep'];
		$this->assertContains( 'PHPSESSID', $keep );
		$this->assertContains( 'dscc_consent', $keep );
		$this->assertContains( 'wp_*', $keep );
	}

	public function test_fail_open_reasons_are_recorded_once_per_window_and_shown_until_cleared() {
		$this->assertNull( FailOpenLog::latest() );
		$this->assertTrue( FailOpenLog::record( 'pcre', '/big/', time() ) );
		$this->assertFalse( FailOpenLog::record( 'pcre', '/big/', time() ), 'same reason inside the window is not written again' );
		$this->assertTrue( FailOpenLog::record( 'partial_flush', '/flush/', time() ), 'a new reason is written' );
		$this->assertSame( 'partial_flush', FailOpenLog::latest()['reason'] );
		$this->assertSame( 'exception', ( FailOpenLog::record( 'nonsense', '/x/', time() + 1000 ) ? FailOpenLog::latest()['reason'] : '' ) );
		FailOpenLog::clear();
		$this->assertNull( FailOpenLog::latest() );
	}

	public function test_the_buffer_records_a_partial_flush_and_a_tag_too_large_to_parse() {
		PlatformManager::enable( 'google-analytics' );
		$this->go_to( home_url( '/' ) );
		OutputBuffer::start();
		ob_end_clean();
		$this->assertFalse( OutputBuffer::callback( '<html><body>first part', PHP_OUTPUT_HANDLER_FINAL ) );
		$this->assertSame( 'partial_flush', FailOpenLog::latest()['reason'] );

		FailOpenLog::clear();
		$page = '<!doctype html><html><body><iframe' . str_repeat( ' data-a="1"', 1200 ) . ' src="https://www.youtube.com/embed/x"></iframe></body></html>';
		PlatformManager::enable( 'youtube' );
		OutputBuffer::start();
		ob_end_clean();
		OutputBuffer::callback( $page, PHP_OUTPUT_HANDLER_START | PHP_OUTPUT_HANDLER_FINAL );
		$this->assertSame( 'unparsed_tag', FailOpenLog::latest()['reason'] );
	}

	public function test_a_user_agent_on_the_skip_list_with_grant_mode_is_treated_as_having_allowed_everything() {
		$_SERVER['HTTP_USER_AGENT'] = 'LittleD-App/1.0';
		$adv                        = Settings::group( 'advanced' );
		Settings::save_group( 'advanced', array_merge( $adv, array( 'skip_ua_patterns' => array( 'LittleD-App' ), 'skip_ua_mode' => 'deny' ) ) );
		$this->assertNull( ConsentReader::effective() );

		Settings::save_group( 'advanced', array_merge( Settings::group( 'advanced' ), array( 'skip_ua_mode' => 'grant' ) ) );
		$record = ConsentReader::effective();
		$this->assertTrue( ConsentReader::allows( $record, 'marketing', 'meta-pixel' ) );
		$this->assertSame( 'grant', ClientData::build( ViewModel::build() )['skipMode'] );

		$_SERVER['HTTP_USER_AGENT'] = 'Mozilla/5.0 Safari';
		$this->assertNull( ConsentReader::effective() );
		unset( $_SERVER['HTTP_USER_AGENT'] );
	}
}
