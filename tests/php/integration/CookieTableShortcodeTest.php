<?php
use DSolutions\CookieConsent\Activator;
use DSolutions\CookieConsent\Catalog\CookieRepository;
use DSolutions\CookieConsent\Catalog\FrontendCatalogCache;
use DSolutions\CookieConsent\Catalog\ServiceRepository;
use DSolutions\CookieConsent\Settings\Settings;

require_once __DIR__ . '/fixtures/PresetFixture.php';

/**
 * [dscc_cookie_table] and [dscc_last_scan], using the bundled D-Solutions preset as realistic catalog data.
 */
class Dscc_Cookie_Table_Shortcode_Test extends WP_UnitTestCase {

	public function set_up() {
		parent::set_up();
		Activator::install();
		Dscc_Preset_Fixture::load();
		FrontendCatalogCache::rebuild();
	}

	private function host() {
		return wp_parse_url( home_url(), PHP_URL_HOST );
	}

	private function set_show_functional( $on ) {
		$settings                                = Settings::all();
		$settings['advanced']['show_functional'] = (bool) $on;
		Settings::replace( $settings );
	}

	/**
	 * The four policy tables the bundled D-Solutions preset must produce, transcribed by hand: for each group, its
	 * heading and rows of Cookie | Bên đặt | Mục đích | Thời hạn | Áp dụng tại. A cookie without a domain applies to
	 * the site host. The order is the order of the preset (the order cookies were added).
	 *
	 * @return array
	 */
	private function expected_tables() {
		$host = $this->host();
		return array(
			'necessary' => array(
				'title' => 'Cookie cần thiết',
				'rows'  => array(
					array( 'dscc_consent', 'DS Cookie Consent', 'Lưu lựa chọn cookie của bạn (đồng ý, từ chối hoặc tuỳ chỉnh)', '180 ngày', $host ),
					array( 'wordpress_test_cookie', 'WordPress', 'Kiểm tra trình duyệt có cho phép cookie khi đăng nhập', 'Phiên', $host ),
					array( 'wordpress_logged_in_*', 'WordPress', 'Nhận diện người dùng đã đăng nhập vào trang quản trị', 'Phiên (14 ngày nếu chọn Ghi nhớ đăng nhập)', $host ),
					array( 'wordpress_sec_*', 'WordPress', 'Bảo mật phiên đăng nhập vào khu vực quản trị', 'Phiên (14 ngày nếu chọn Ghi nhớ đăng nhập)', $host ),
					array( 'wp-settings-*', 'WordPress', 'Ghi nhớ tuỳ chọn giao diện quản trị của người dùng đã đăng nhập', '1 năm', $host ),
					array( 'wp-settings-time-*', 'WordPress', 'Thời điểm lưu tuỳ chọn giao diện quản trị', '1 năm', $host ),
					array( 'woocommerce_cart_hash', 'WooCommerce', 'Giúp WooCommerce nhận biết khi nội dung giỏ hàng thay đổi', 'Phiên', $host ),
					array( 'woocommerce_items_in_cart', 'WooCommerce', 'Giúp WooCommerce nhận biết giỏ hàng có sản phẩm hay không', 'Phiên', $host ),
					array( 'wp_woocommerce_session_*', 'WooCommerce', 'Chứa mã duy nhất của từng khách để tìm dữ liệu giỏ hàng trong cơ sở dữ liệu', '2 ngày', $host ),
					array( 'woocommerce_recently_viewed', 'WooCommerce', 'Hiển thị danh sách sản phẩm đã xem gần đây', 'Phiên', $host ),
					array( 'store_notice*', 'WooCommerce', 'Ghi nhớ việc khách đã đóng thông báo cửa hàng', 'Phiên', $host ),
					array( '_GRECAPTCHA', 'Google reCAPTCHA', 'Phân biệt người dùng thật với bot trên biểu mẫu (phân tích rủi ro của reCAPTCHA)', '179 ngày', 'www.google.com' ),
				),
			),
			'functional' => array(
				'title' => 'Cookie chức năng',
				'rows'  => array(
					array( 'VISITOR_INFO1_LIVE', 'YouTube (video nhúng)', 'Ước tính băng thông và ghi nhận lượt xem video nhúng; với youtube-nocookie.com chỉ được ghi khi bạn phát video', '179 ngày', '.youtube.com' ),
					array( 'VISITOR_PRIVACY_METADATA', 'YouTube (video nhúng)', 'Lưu lựa chọn quyền riêng tư của khách truy cập YouTube', '180 ngày', '.youtube.com' ),
					array( 'YSC, __Secure-ROLLOUT_TOKEN', 'YouTube (video nhúng)', 'Ghi ID duy nhất để thống kê những video YouTube bạn đã xem', 'YSC: Phiên; __Secure-ROLLOUT_TOKEN: 180 ngày', '.youtube.com' ),
				),
			),
			'analytics' => array(
				'title' => 'Cookie phân tích',
				'rows'  => array(
					array( '_ga', 'Google Analytics (GA4)', 'Phân biệt người dùng, giữ trạng thái phiên truy cập', '2 năm', $host ),
					array( '_ga_<mã>', 'Google Analytics (GA4)', 'Phân biệt người dùng, giữ trạng thái phiên truy cập (theo từng thuộc tính GA4)', '2 năm', $host ),
					array( '_gid', 'Google Analytics (GA4)', 'Phân biệt người dùng trong ngày', '24 giờ', $host ),
					array( '_gat_UA-<mã>', 'Google Analytics (GA4)', 'Giới hạn tần suất gửi dữ liệu về Google Analytics', '1 phút', $host ),
					array( '_clck', 'Microsoft Clarity', 'Lưu ID người dùng Clarity và tuỳ chọn để ghi lại phiên truy cập và bản đồ nhiệt thao tác', '1 năm', $host ),
					array( '_clsk', 'Microsoft Clarity', 'Gom các lượt xem trang của một người dùng thành một bản ghi phiên Clarity', '1 ngày', $host ),
					array( 'sbjs_*', 'WooCommerce (nguồn đơn hàng)', 'Ghi nhận nguồn truy cập dẫn đến đơn hàng', 'Phiên', $host ),
					array( 'sbjs_session', 'WooCommerce (nguồn đơn hàng)', 'Lưu số trang đã xem trong phiên và đường dẫn trang hiện tại', '30 phút', $host ),
				),
			),
			'marketing' => array(
				'title' => 'Cookie tiếp thị',
				'rows'  => array(
					array( '_gcl_au', 'Google Ads', 'Đo lường chuyển đổi từ quảng cáo', '90 ngày', $host ),
					array( '_gcl_aw', 'Google Ads', 'Lưu mã nhấp chuột quảng cáo (gclid) để ghi nhận chuyển đổi Google Ads', '90 ngày', $host ),
					array( '_gcl_gb', 'Google Ads', 'Lưu thông tin nhấp chuột quảng cáo để đo lường chuyển đổi Google Ads', '90 ngày', $host ),
					array( '_gac_<mã>', 'Google Ads', 'Lưu thông tin chiến dịch quảng cáo của người dùng để đo lường chuyển đổi', '90 ngày', $host ),
					array( '_fbp', 'Meta Pixel (Facebook)', 'Đo hiệu quả quảng cáo trên Facebook, Instagram', '90 ngày', $host ),
					array( '_fbc', 'Meta Pixel (Facebook)', 'Lưu mã nhấp chuột quảng cáo Facebook (fbclid) để ghi nhận chuyển đổi', '90 ngày', $host ),
					array( 'fr', 'Meta Pixel (Facebook)', 'Hiển thị và đo lường quảng cáo của Facebook', '90 ngày', '.facebook.com' ),
					array( '_ttp', 'TikTok Pixel', 'Nhận diện khách truy cập để đo hiệu quả quảng cáo trên TikTok', 'Khoảng 13 tháng', $host ),
					array( '_tt_enable_cookie', 'TikTok Pixel', 'Cho biết TikTok Pixel được phép dùng cookie để đo hiệu quả quảng cáo', 'Khoảng 13 tháng', $host ),
					array( 'ttcsid*', 'TikTok Pixel', 'Nhận diện phiên truy cập để đo hiệu quả quảng cáo trên TikTok', '1 năm', $host ),
				),
			),
		);
	}

	/**
	 * Parse the shortcode output into the shape of expected_tables().
	 *
	 * @param string $html Shortcode output.
	 * @return array
	 */
	private function parse_tables( $html ) {
		$doc = new DOMDocument();
		libxml_use_internal_errors( true );
		$doc->loadHTML( '<?xml encoding="utf-8"?><body>' . $html . '</body>' );
		libxml_clear_errors();
		$xp  = new DOMXPath( $doc );
		$out = array();
		foreach ( $xp->query( '//div[contains(@class,"dscc-cookie-table ")]' ) as $box ) {
			$class = $box->getAttribute( 'class' );
			preg_match( '/dscc-cookie-table--(\w+)/', $class, $m );
			$title = $xp->query( './/h4', $box )->item( 0 );
			$head  = array();
			foreach ( $xp->query( './/thead//th', $box ) as $th ) {
				$head[] = trim( $th->textContent );
			}
			$rows = array();
			foreach ( $xp->query( './/tbody/tr', $box ) as $tr ) {
				$cells = array();
				foreach ( $xp->query( './td', $tr ) as $td ) {
					$cells[] = trim( $td->textContent );
				}
				$rows[] = $cells;
			}
			$out[ $m[1] ] = array(
				'title' => $title ? trim( $title->textContent ) : '',
				'head'  => $head,
				'rows'  => $rows,
			);
		}
		return $out;
	}

	public function test_output_equals_the_expected_policy_tables_row_for_row() {
		$expected = $this->expected_tables();
		$actual   = $this->parse_tables( do_shortcode( '[dscc_cookie_table lang="vi"]' ) );
		$this->assertSame( array( 'necessary', 'functional', 'analytics', 'marketing' ), array_keys( $actual ), 'four groups in the policy order' );
		foreach ( $expected as $key => $table ) {
			$this->assertSame( $table['title'], $actual[ $key ]['title'], $key . ' heading' );
			$this->assertSame( array( 'Cookie', 'Bên đặt', 'Mục đích', 'Thời hạn', 'Áp dụng tại' ), $actual[ $key ]['head'], $key . ' columns' );
			$this->assertSame( $table['rows'], $actual[ $key ]['rows'], $key . ' rows' );
		}
	}

	public function test_cookies_waiting_for_review_stay_out() {
		$svc = ServiceRepository::upsert( array( 'slug' => 'littled-review', 'name' => 'LittleD Review', 'category_key' => 'analytics', 'status' => 'confirmed' ) );
		CookieRepository::upsert( array( 'name' => 'littled_waiting', 'service_id' => $svc, 'status' => 'new', 'purpose' => 'Chờ duyệt' ) );
		FrontendCatalogCache::rebuild();
		$this->assertStringNotContainsString( 'littled_waiting', do_shortcode( '[dscc_cookie_table lang="vi"]' ), 'rows still waiting for review are not published' );
	}

	public function test_vendor_cells_link_to_the_vendor_policy_with_the_external_link_attributes() {
		$html = do_shortcode( '[dscc_cookie_table category="marketing" lang="vi"]' );
		$this->assertStringContainsString( '<a href="https://www.facebook.com/privacy/policy/" target="_blank" rel="nofollow noopener noreferrer">Meta Pixel (Facebook)</a>', $html );
	}

	public function test_grouped_row_uses_the_lead_cookie_texts_and_group_order() {
		$svc = ServiceRepository::upsert( array( 'slug' => 'littled-group', 'name' => 'LittleD Group', 'category_key' => 'analytics', 'status' => 'confirmed' ) );
		CookieRepository::upsert( array( 'name' => 'lt_first', 'service_id' => $svc, 'status' => 'confirmed', 'purpose' => 'Mục đích đầu', 'duration_label' => '1 năm', 'table_group' => 'lt-group' ) );
		CookieRepository::upsert( array( 'name' => 'lt_second', 'service_id' => $svc, 'status' => 'confirmed', 'purpose' => 'Mục đích khác', 'duration_label' => '1 ngày', 'table_group' => 'lt-group' ) );
		FrontendCatalogCache::rebuild();
		$html = do_shortcode( '[dscc_cookie_table category="analytics" lang="vi" columns="name,purpose,duration"]' );
		$this->assertStringContainsString( '<td data-th="Cookie">lt_first, lt_second</td><td data-th="Mục đích">Mục đích đầu</td><td data-th="Thời hạn">1 năm</td>', $html );
	}

	public function test_cookies_merged_by_purpose_list_differing_durations_per_cookie() {
		$svc = ServiceRepository::upsert( array( 'slug' => 'littled-merge', 'name' => 'LittleD Merge', 'category_key' => 'analytics', 'status' => 'confirmed' ) );
		CookieRepository::upsert( array( 'name' => 'lt_a', 'service_id' => $svc, 'status' => 'confirmed', 'purpose' => 'Cùng mục đích', 'duration_label' => '2 năm' ) );
		CookieRepository::upsert( array( 'name' => 'lt_b', 'service_id' => $svc, 'status' => 'confirmed', 'purpose' => 'Cùng mục đích', 'duration_label' => '24 giờ' ) );
		CookieRepository::upsert( array( 'name' => 'lt_c', 'service_id' => $svc, 'status' => 'confirmed', 'purpose' => 'Khác', 'duration_label' => '1 ngày' ) );
		CookieRepository::upsert( array( 'name' => 'lt_d', 'service_id' => $svc, 'status' => 'confirmed', 'purpose' => 'Cùng mục đích', 'duration_label' => '24 giờ' ) );
		FrontendCatalogCache::rebuild();
		$html = do_shortcode( '[dscc_cookie_table category="analytics" lang="vi" columns="name,duration"]' );
		$this->assertStringContainsString( '<td data-th="Cookie">lt_a, lt_b, lt_d</td><td data-th="Thời hạn">lt_a: 2 năm; lt_b: 24 giờ; lt_d: 24 giờ</td>', $html );
		$this->assertStringContainsString( '<td data-th="Cookie">lt_c</td><td data-th="Thời hạn">1 ngày</td>', $html );
	}

	public function test_applies_to_falls_back_to_domain_then_site_host() {
		$svc = ServiceRepository::upsert( array( 'slug' => 'littled-app', 'name' => 'LittleD App', 'category_key' => 'marketing', 'status' => 'confirmed' ) );
		CookieRepository::upsert( array( 'name' => 'lt_text', 'service_id' => $svc, 'status' => 'confirmed', 'purpose' => 'A', 'applies_to' => 'Cửa hàng LittleD', 'applies_to_en' => 'LittleD shop' ) );
		CookieRepository::upsert( array( 'name' => 'lt_dom', 'service_id' => $svc, 'status' => 'confirmed', 'purpose' => 'B', 'domain' => '.littled.example' ) );
		CookieRepository::upsert( array( 'name' => 'lt_host', 'service_id' => $svc, 'status' => 'confirmed', 'purpose' => 'C' ) );
		FrontendCatalogCache::rebuild();
		$vi = $this->parse_tables( do_shortcode( '[dscc_cookie_table category="marketing" lang="vi" columns="name,applies"]' ) );
		$en = $this->parse_tables( do_shortcode( '[dscc_cookie_table category="marketing" lang="en" columns="name,applies"]' ) );
		$by = static function ( $table ) {
			$map = array();
			foreach ( $table['marketing']['rows'] as $row ) {
				$map[ $row[0] ] = $row[1];
			}
			return $map;
		};
		$this->assertSame( 'Cửa hàng LittleD', $by( $vi )['lt_text'] );
		$this->assertSame( 'LittleD shop', $by( $en )['lt_text'] );
		$this->assertSame( '.littled.example', $by( $vi )['lt_dom'] );
		$this->assertSame( $this->host(), $by( $vi )['lt_host'] );
	}

	public function test_english_output_and_column_selection() {
		$html = do_shortcode( '[dscc_cookie_table category="marketing" lang="en" columns="name,duration"]' );
		$this->assertStringContainsString( '<h4 class="dscc-cookie-table__title">Marketing cookies</h4>', $html );
		$this->assertStringContainsString( '<thead><tr><th scope="col">Cookie</th><th scope="col">Duration</th></tr></thead>', $html );
		$this->assertStringContainsString( '<td data-th="Duration">90 days</td>', $html );
		$this->assertStringNotContainsString( 'Purpose', $html );
	}

	public function test_unknown_columns_fall_back_to_all_and_unknown_categories_print_nothing() {
		$html = do_shortcode( '[dscc_cookie_table category="analytics" columns="bogus" lang="vi"]' );
		$this->assertSame( 5, substr_count( $html, '<th scope="col">' ) );
		$this->assertSame( '', do_shortcode( '[dscc_cookie_table category="bogus"]' ) );
	}

	public function test_functional_cookies_have_their_own_table_unless_the_category_is_turned_off() {
		$svc = ServiceRepository::upsert( array( 'slug' => 'littled-fn', 'name' => 'LittleD Fn', 'category_key' => 'functional', 'status' => 'confirmed' ) );
		CookieRepository::upsert( array( 'name' => 'littled_fn_cookie', 'service_id' => $svc, 'status' => 'confirmed', 'purpose' => 'Chức năng', 'duration_label' => '1 ngày' ) );
		FrontendCatalogCache::rebuild();

		$split = do_shortcode( '[dscc_cookie_table lang="vi"]' );
		$this->assertSame( 4, substr_count( $split, '<table' ), 'the Functional group is shown by default' );
		$this->assertStringContainsString( 'dscc-cookie-table--functional', $split );
		$this->assertStringNotContainsString( 'littled_fn_cookie', do_shortcode( '[dscc_cookie_table category="necessary" lang="vi"]' ) );
		$this->assertStringContainsString( 'littled_fn_cookie', do_shortcode( '[dscc_cookie_table category="functional" lang="vi"]' ) );

		$this->set_show_functional( false );
		$merged = do_shortcode( '[dscc_cookie_table lang="vi"]' );
		$this->assertSame( 3, substr_count( $merged, '<table' ) );
		$this->assertStringContainsString( 'littled_fn_cookie', do_shortcode( '[dscc_cookie_table category="necessary" lang="vi"]' ) );
		$this->assertStringContainsString( 'littled_fn_cookie', do_shortcode( '[dscc_cookie_table category="functional" lang="vi"]' ), 'a disabled functional category resolves to necessary' );
	}

	public function test_an_empty_category_prints_a_note_and_no_table() {
		// The preset's only functional platform is YouTube; moving it away leaves the (visible by default) group empty.
		ServiceRepository::set_category( (int) ServiceRepository::find_by_slug( 'youtube' )['id'], 'marketing' );
		FrontendCatalogCache::rebuild();
		$html = do_shortcode( '[dscc_cookie_table category="functional" lang="en"]' );
		$this->assertStringContainsString( '<p class="dscc-cookie-table__empty">No cookies in this group yet.</p>', $html );
		$this->assertStringNotContainsString( '<table', $html );
	}

	public function test_cookie_domain_is_shown_in_the_applies_column() {
		$svc = ServiceRepository::upsert( array( 'slug' => 'littled-dom', 'name' => 'LittleD Dom', 'category_key' => 'analytics', 'status' => 'confirmed' ) );
		CookieRepository::upsert( array( 'name' => 'littled_dom', 'service_id' => $svc, 'domain' => '.littled.example', 'status' => 'confirmed', 'purpose' => 'x' ) );
		FrontendCatalogCache::rebuild();
		$this->assertStringContainsString( '<td data-th="Áp dụng tại">.littled.example</td>', do_shortcode( '[dscc_cookie_table category="analytics" lang="vi"]' ) );
	}

	public function test_text_is_escaped_and_provider_links_use_the_external_link_helper() {
		$svc = ServiceRepository::upsert( array( 'slug' => 'littled-xss', 'name' => 'Little<script>', 'category_key' => 'analytics', 'status' => 'confirmed', 'privacy_url' => 'https://littled.example/p?a=1&b=2' ) );
		CookieRepository::upsert( array( 'name' => 'littled_xss', 'service_id' => $svc, 'status' => 'confirmed', 'purpose' => '<img src=x onerror=alert(1)>' ) );
		FrontendCatalogCache::rebuild();
		$html = do_shortcode( '[dscc_cookie_table category="analytics" lang="vi"]' );
		$this->assertStringNotContainsString( '<script>', $html );
		$this->assertStringNotContainsString( '<img', $html );
		$this->assertStringContainsString( 'target="_blank" rel="nofollow noopener noreferrer"', $html );
		$this->assertSame( substr_count( $html, '<a ' ), substr_count( $html, 'rel="nofollow noopener noreferrer"' ), 'every provider link carries the new-tab and nofollow attributes' );
	}

	public function test_last_scan_prints_the_scan_date_then_the_catalog_date() {
		delete_option( 'dscc_last_scan' );
		$expected = wp_date( 'd/m/Y', FrontendCatalogCache::updated_at() );
		$this->assertSame( $expected, do_shortcode( '[dscc_last_scan]' ) );

		update_option( 'dscc_last_scan', gmmktime( 12, 0, 0, 11, 5, 2026 ) );
		$this->assertSame( wp_date( 'd/m/Y', gmmktime( 12, 0, 0, 11, 5, 2026 ) ), do_shortcode( '[dscc_last_scan]' ) );
		$this->assertSame( wp_date( 'Y-m-d', gmmktime( 12, 0, 0, 11, 5, 2026 ) ), do_shortcode( '[dscc_last_scan format="Y-m-d"]' ) );

		update_option( 'dscc_last_scan', '2026-11-05 08:00:00' );
		$this->assertStringContainsString( '2026', do_shortcode( '[dscc_last_scan format="Y"]' ) );
	}

	public function test_last_scan_is_empty_without_any_data() {
		global $wpdb;
		delete_option( 'dscc_last_scan' );
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'dscc_cookies' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		$wpdb->query( 'DELETE FROM ' . $wpdb->prefix . 'dscc_services' ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery
		FrontendCatalogCache::rebuild();
		$this->assertSame( '', do_shortcode( '[dscc_last_scan]' ) );
	}
}
