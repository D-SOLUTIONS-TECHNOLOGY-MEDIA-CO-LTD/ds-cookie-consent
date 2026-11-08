<?php
/**
 * Default visitor-facing strings (Vietnamese and English). Admin overrides live in dscc_texts_{lang}.
 *
 * Tokens: {site} = site name, {policy_link} = link to the cookie policy, {n} = a count, {name} = a name.
 * Keep both language maps on the same keys (enforced by a unit test).
 *
 * @package DSolutions\CookieConsent
 */

namespace DSolutions\CookieConsent\Settings;

final class DefaultTexts {

	const LANGS = array( 'vi', 'en' );

	/**
	 * Default strings for one language (falls back to Vietnamese).
	 *
	 * @param string $lang vi|en.
	 * @return array<string,string>
	 */
	public static function all( $lang ) {
		return 'en' === $lang ? self::en() : self::vi();
	}

	/**
	 * Keys admins can override (same for all languages).
	 *
	 * @return string[]
	 */
	public static function keys() {
		return array_keys( self::vi() );
	}

	/**
	 * Defaults merged with admin overrides. Empty overrides are ignored.
	 *
	 * @param string $lang      vi|en.
	 * @param array  $overrides Stored overrides.
	 * @return array<string,string>
	 */
	public static function merged( $lang, array $overrides ) {
		$out = self::all( $lang );
		foreach ( $overrides as $key => $value ) {
			if ( isset( $out[ $key ] ) && is_string( $value ) && '' !== trim( $value ) ) {
				$out[ $key ] = $value;
			}
		}
		return $out;
	}

	/**
	 * Vietnamese defaults.
	 *
	 * @return array<string,string>
	 */
	private static function vi() {
		return array(
			// Card first view.
			'title'                 => 'Trang web này sử dụng cookie',
			'text'                  => 'Chúng tôi dùng cookie để website hoạt động ổn định, đo lường lượt truy cập và cung cấp trải nghiệm phù hợp hơn với nhu cầu của bạn. Cookie phân tích và tiếp thị chỉ được bật khi bạn đồng ý, và bạn có thể thay đổi lựa chọn bất cứ lúc nào. Xem chi tiết tại {policy_link}.',
			// Bar banner.
			'bar_text'              => '{site} dùng cookie để website hoạt động ổn định, đo lượt truy cập và cải thiện trải nghiệm của bạn. Cookie Phân tích và Tiếp thị chỉ được bật khi bạn đồng ý. Xem chi tiết tại {policy_link}.',
			'bar_aria'              => 'Thông báo cookie',
			// Buttons.
			'accept'                => 'Đồng ý',
			'reject'                => 'Từ chối',
			'customize'             => 'Tùy chỉnh',
			'accept_all'            => 'Đồng ý tất cả',
			'reject_all'            => 'Từ chối tất cả',
			'save_choices'          => 'Lưu lựa chọn',
			'save'                  => 'Lưu',
			'exit'                  => 'Thoát',
			'close'                 => 'Đóng',
			// Card preferences view.
			'prefs_title'           => 'Tùy chỉnh cookie',
			'prefs_text'            => 'Bật hoặc tắt từng nhóm cookie theo nhu cầu của bạn. Bạn có thể quay lại thay đổi bất cứ lúc nào qua biểu tượng khiên ở góc màn hình.',
			'details_link'          => 'Xem chi tiết cookie',
			// Modal.
			'modal_title'           => 'Tùy chọn cookie',
			'modal_intro_title'     => 'Tùy chọn',
			'modal_intro_text'      => 'Chọn nhóm cookie bạn cho phép {site} sử dụng rồi bấm "Lưu". Bạn có thể đổi lại bất cứ lúc nào qua biểu tượng khiên ở góc màn hình.',
			'tab_prefs'             => 'Tùy chọn',
			'tab_privacy'           => 'Chính sách bảo mật',
			'tab_cookies'           => 'Chính sách cookie',
			'widget_label'          => 'Cài đặt cookie',
			'policy_link'           => 'Chính sách Cookie',
			// Categories.
			'cat_necessary'         => 'Cookie cần thiết',
			'cat_necessary_desc'    => 'Giúp website hoạt động ổn định và an toàn, ví dụ ghi nhớ lựa chọn cookie của bạn. Nhóm này không thể tắt.',
			'cat_functional'        => 'Cookie chức năng',
			'cat_functional_desc'   => 'Ghi nhớ các tùy chọn của bạn như ngôn ngữ hoặc khu vực để mang lại trải nghiệm tiện lợi hơn.',
			'cat_analytics'         => 'Cookie phân tích',
			'cat_analytics_desc'    => 'Cho chúng tôi biết trang nào được xem nhiều, người truy cập đến từ kênh nào và rời đi ở đâu, để cải thiện nội dung website.',
			'cat_marketing'         => 'Cookie tiếp thị',
			'cat_marketing_desc'    => 'Giúp hiển thị nội dung và quảng cáo phù hợp hơn với nhu cầu của bạn trên các nền tảng khác, đồng thời đo lường hiệu quả chiến dịch quảng cáo.',
			'always_on'             => 'Luôn bật',
			'details'               => 'Chi tiết',
			'view_cookies'          => 'Xem cookie ({n})',
			'toggle_aria'           => 'Bật cookie {name}',
			'vendor_privacy'        => 'Chính sách riêng tư',
			'more_vendors'          => '+{n} nhà cung cấp khác không có công tắc riêng, theo công tắc của nhóm.',
			'more_vendors_link'     => 'Xem đầy đủ trong Chính sách cookie',
			// Cookie table.
			'th_name'               => 'Tên cookie',
			'th_provider'           => 'Bên đặt',
			'th_duration'           => 'Thời hạn',
			'th_purpose'            => 'Mục đích',
			'no_cookie_data'        => 'Chưa có thông tin cookie nào được công bố.',
			// Shortcode [dscc_cookie_table] (layout of a cookie policy page).
			'tbl_title_necessary'   => 'Cookie cần thiết',
			'tbl_title_functional'  => 'Cookie chức năng',
			'tbl_title_analytics'   => 'Cookie phân tích',
			'tbl_title_marketing'   => 'Cookie tiếp thị',
			'tbl_col_name'          => 'Cookie',
			'tbl_col_service'       => 'Bên đặt',
			'tbl_col_purpose'       => 'Mục đích',
			'tbl_col_duration'      => 'Thời hạn',
			'tbl_col_applies'       => 'Áp dụng tại',
			'tbl_empty'             => 'Chưa có cookie nào thuộc nhóm này.',
			// Policy tabs.
			'privacy_summary'       => 'Chính sách bảo mật mô tả cách chúng tôi thu thập, sử dụng và bảo vệ dữ liệu của bạn.',
			'privacy_open'          => 'Mở Chính sách bảo mật',
			'cookie_policy_summary' => 'Chính sách cookie giải thích cookie là gì, chúng tôi dùng những loại cookie nào và cách bạn kiểm soát chúng.',
			'cookie_policy_open'    => 'Mở Chính sách cookie',
			'policy_frame_title'    => 'Nội dung chính sách',
			'no_policy_page'        => 'Chưa có trang chính sách được cấu hình.',
			// Placeholder shown instead of a blocked embed (iframe).
			'ph_text'               => 'Bật cookie {category} để xem nội dung này.',
			'ph_video'              => 'Bật cookie {category} để xem video.',
			'ph_button'             => 'Bật cookie {category}',
			'ph_cat_analytics'      => 'Phân tích',
			'ph_cat_marketing'      => 'Tiếp thị',
			'ph_cat_functional'     => 'Chức năng',
		);
	}

	/**
	 * English defaults.
	 *
	 * @return array<string,string>
	 */
	private static function en() {
		return array(
			'title'                 => 'This website uses cookies',
			'text'                  => 'We use cookies to keep the website running reliably, measure traffic and provide an experience that better fits your needs. Analytics and marketing cookies are only enabled with your consent, and you can change your choice at any time. See details in our {policy_link}.',
			'bar_text'              => '{site} uses cookies to keep the website running reliably, measure traffic and improve your experience. Analytics and Marketing cookies are only enabled with your consent. See details in our {policy_link}.',
			'bar_aria'              => 'Cookie notice',
			'accept'                => 'Accept',
			'reject'                => 'Reject',
			'customize'             => 'Customize',
			'accept_all'            => 'Accept all',
			'reject_all'            => 'Reject all',
			'save_choices'          => 'Save choices',
			'save'                  => 'Save',
			'exit'                  => 'Exit',
			'close'                 => 'Close',
			'prefs_title'           => 'Customize cookies',
			'prefs_text'            => 'Turn each cookie category on or off. You can change this at any time with the shield icon in the corner of the screen.',
			'details_link'          => 'View cookie details',
			'modal_title'           => 'Cookie preferences',
			'modal_intro_title'     => 'Preferences',
			'modal_intro_text'      => 'Choose which cookie categories {site} may use, then press "Save". You can change this at any time with the shield icon in the corner of the screen.',
			'tab_prefs'             => 'Preferences',
			'tab_privacy'           => 'Privacy policy',
			'tab_cookies'           => 'Cookie policy',
			'widget_label'          => 'Cookie settings',
			'policy_link'           => 'Cookie Policy',
			'cat_necessary'         => 'Necessary cookies',
			'cat_necessary_desc'    => 'Keep the website stable and secure, for example by remembering your cookie choice. This category cannot be turned off.',
			'cat_functional'        => 'Functional cookies',
			'cat_functional_desc'   => 'Remember your preferences such as language or region to provide a more convenient experience.',
			'cat_analytics'         => 'Analytics cookies',
			'cat_analytics_desc'    => 'Tell us which pages are viewed most, which channels visitors come from and where they leave, so we can improve the website.',
			'cat_marketing'         => 'Marketing cookies',
			'cat_marketing_desc'    => 'Help show content and ads that better fit your needs on other platforms, and measure the effectiveness of advertising campaigns.',
			'always_on'             => 'Always on',
			'details'               => 'Details',
			'view_cookies'          => 'View cookies ({n})',
			'toggle_aria'           => 'Enable {name} cookies',
			'vendor_privacy'        => 'Privacy policy',
			'more_vendors'          => '+{n} more vendors without their own switch; they follow the switch of their group.',
			'more_vendors_link'     => 'See the full list in the Cookie Policy',
			'th_name'               => 'Cookie name',
			'th_provider'           => 'Set by',
			'th_duration'           => 'Duration',
			'th_purpose'            => 'Purpose',
			'no_cookie_data'        => 'No cookie information has been published yet.',
			'tbl_title_necessary'   => 'Necessary cookies',
			'tbl_title_functional'  => 'Functional cookies',
			'tbl_title_analytics'   => 'Analytics cookies',
			'tbl_title_marketing'   => 'Marketing cookies',
			'tbl_col_name'          => 'Cookie',
			'tbl_col_service'       => 'Set by',
			'tbl_col_purpose'       => 'Purpose',
			'tbl_col_duration'      => 'Duration',
			'tbl_col_applies'       => 'Applies to',
			'tbl_empty'             => 'No cookies in this group yet.',
			'privacy_summary'       => 'The privacy policy describes how we collect, use and protect your data.',
			'privacy_open'          => 'Open the Privacy Policy',
			'cookie_policy_summary' => 'The cookie policy explains what cookies are, which ones we use and how you can control them.',
			'cookie_policy_open'    => 'Open the Cookie Policy',
			'policy_frame_title'    => 'Policy content',
			'no_policy_page'        => 'No policy page has been configured.',
			// Placeholder shown instead of a blocked embed (iframe).
			'ph_text'               => 'Enable {category} cookies to view this content.',
			'ph_video'              => 'Enable {category} cookies to watch this video.',
			'ph_button'             => 'Enable {category} cookies',
			'ph_cat_analytics'      => 'Analytics',
			'ph_cat_marketing'      => 'Marketing',
			'ph_cat_functional'     => 'Functional',
		);
	}
}
